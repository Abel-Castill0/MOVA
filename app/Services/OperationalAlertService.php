<?php

namespace App\Services;

use App\Models\OperationalAlert;
use App\Models\User;
use App\Notifications\OperationalAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Punto único por el que una incidencia operativa se vuelve visible para un
 * administrador.
 *
 * PROBLEMA QUE RESUELVE (docs/MOVA_SYSTEM_MAP.md §29.3, R-04):
 *
 * MOVA ya detectaba correctamente sus propias anomalías —un pago que no cuadra,
 * una clase varada, el ledger descuadrado— y las dejaba escritas en una columna
 * (`payment_orders.review_reason`) o en un `Log::error`. Ninguna de las dos
 * llega a un humano. El diseño fail-closed estaba completo hasta el último
 * paso: "marcar para revisión" no llevaba a ninguna parte.
 *
 * TRES GARANTÍAS:
 *
 *   1. DEDUPLICACIÓN POR INCIDENCIA, NO POR EVENTO. `alert_key` es UNIQUE. El
 *      barrido de `mercadopago:reconcile` corre cada 5 minutos y puede volver a
 *      encontrar el mismo PaymentOrder en revisión indefinidamente: eso sube
 *      `occurrences`, no crea filas ni vuelve a avisar.
 *
 *   2. AVISO EXACTAMENTE UNA VEZ POR EPISODIO. `notified_at` es la guarda. Una
 *      incidencia resuelta que reaparece SÍ vuelve a avisar, porque resolve()
 *      limpia esa marca — un problema que vuelve es información nueva.
 *
 *   3. NUNCA ROMPE AL LLAMANTE. raise() se invoca desde dentro de transacciones
 *      financieras (MercadoPagoPaymentReconciliationService::markReview()). Si
 *      alertar fallara y la excepción se propagara, tumbaría una operación de
 *      dinero legítima por un fallo de observabilidad. Por eso todo va en
 *      try/catch — pero el catch NO es silencioso: registra y hace report(), que
 *      desde H-01 sí llega a Sentry.
 *
 * LO QUE ESTE SERVICIO NO HACE: no corrige nada. No toca créditos, ni estados,
 * ni pagos. Solo observa y avisa.
 */
class OperationalAlertService
{
    /**
     * Registra (o vuelve a ver) una incidencia.
     *
     * @param  string  $key       Identidad ESTABLE de la incidencia, no del evento.
     *                            Ej: "payment_order:42:review". Dos detecciones del
     *                            mismo problema deben producir la misma clave.
     * @param  array<string,mixed>  $context  Datos para el diagnóstico. NUNCA
     *                            secretos ni datos personales del menor.
     */
    public function raise(
        string $key,
        string $type,
        string $title,
        string $message,
        array $context = [],
        string $severity = OperationalAlert::SEVERITY_WARNING,
    ): ?OperationalAlert {
        try {
            [$alert, $shouldNotify] = DB::transaction(function () use ($key, $type, $title, $message, $context, $severity) {
                $alert = OperationalAlert::where('alert_key', $key)->lockForUpdate()->first();

                if ($alert === null) {
                    $alert = OperationalAlert::create([
                        'alert_key' => $key,
                        'type' => $type,
                        'severity' => $severity,
                        'title' => $title,
                        'message' => $message,
                        'context' => $context ?: null,
                        'first_detected_at' => now(),
                        'last_detected_at' => now(),
                        'occurrences' => 1,
                    ]);

                    return [$alert, true];
                }

                // Reaparición tras haberse dado por resuelta: se reabre y vuelve
                // a avisar. Un problema que regresa no es ruido.
                $wasResolved = $alert->resolved_at !== null;

                $alert->forceFill([
                    'severity' => $severity,
                    'title' => $title,
                    'message' => $message,
                    'context' => $context ?: null,
                    'last_detected_at' => now(),
                    'occurrences' => $wasResolved ? 1 : $alert->occurrences + 1,
                    'resolved_at' => null,
                    'notified_at' => $wasResolved ? null : $alert->notified_at,
                ])->save();

                return [$alert, $alert->notified_at === null];
            });

            // El log SÍ se escribe siempre (con el contador), aunque no se
            // vuelva a notificar: es la traza de que el problema sigue vivo.
            Log::warning('[OperationalAlert] '.$alert->title, [
                'alert_key' => $alert->alert_key,
                'type' => $alert->type,
                'severity' => $alert->severity,
                'occurrences' => $alert->occurrences,
                'context' => $alert->context,
            ]);

            if ($shouldNotify) {
                $this->notifyAdmins($alert);
            }

            return $alert;
        } catch (Throwable $e) {
            // Alertar nunca puede tumbar la operación que estaba en curso.
            Log::error('[OperationalAlert] No se pudo registrar la incidencia.', [
                'alert_key' => $key,
                'error' => $e->getMessage(),
            ]);
            report($e);

            return null;
        }
    }

    /**
     * La incidencia dejó de estar activa. Limpia `notified_at` para que una
     * reaparición futura vuelva a avisar.
     *
     * Es un no-op si la clave no existe o ya estaba resuelta, así que puede
     * llamarse incondicionalmente desde el camino feliz.
     */
    public function resolve(string $key): void
    {
        try {
            OperationalAlert::where('alert_key', $key)
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at' => now(),
                    'notified_at' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::error('[OperationalAlert] No se pudo resolver la incidencia.', [
                'alert_key' => $key,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }

    /**
     * Marca `notified_at` ANTES de enviar, no después.
     *
     * Si se marcara después y el envío fallara a mitad, el siguiente barrido
     * volvería a intentarlo y el admin podría recibir el mismo aviso muchas
     * veces. Se prefiere el riesgo opuesto —perder un aviso puntual, con la
     * incidencia igualmente registrada en la tabla y en el log— sobre inundar
     * la bandeja del admin.
     */
    private function notifyAdmins(OperationalAlert $alert): void
    {
        // Claim atómico: dos procesos pueden haber leído notified_at=NULL
        // antes de salir de raise(). Solo uno puede adjudicarse el aviso.
        $claimedAt = now();
        $claimed = OperationalAlert::whereKey($alert->id)
            ->whereNull('notified_at')
            ->whereNull('resolved_at')
            ->update(['notified_at' => $claimedAt, 'updated_at' => $claimedAt]);

        if ($claimed !== 1) {
            return;
        }

        $alert->notified_at = $claimedAt;

        $admins = User::role('admin')->get();

        if ($admins->isEmpty()) {
            Log::warning('[OperationalAlert] No hay ningún administrador al que avisar.', [
                'alert_key' => $alert->alert_key,
            ]);

            return;
        }

        foreach ($admins as $admin) {
            $admin->notify(new OperationalAlertNotification($alert));
        }
    }
}
