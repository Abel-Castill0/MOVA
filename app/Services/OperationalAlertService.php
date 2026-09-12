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
                    // Reabrir debe borrar el rastro del cierre anterior. Si no,
                    // una incidencia ABIERTA seguiria mostrando "cerrada por X
                    // porque Y": una contradiccion en pantalla y una pista falsa
                    // en una auditoria. Se acepta perder ese historico —
                    // conservarlo exigiria una tabla aparte— porque el cierre ya
                    // quedo registrado en el log con actor y motivo.
                    'resolved_by' => $wasResolved ? null : $alert->resolved_by,
                    'resolved_by_name' => $wasResolved ? null : $alert->resolved_by_name,
                    'resolution_note' => $wasResolved ? null : $alert->resolution_note,
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
     * Un admin CIERRA A MANO una incidencia.
     *
     * Cerrar la incidencia NO arregla el recurso: no toca el PaymentOrder,
     * ni la Lesson, ni la recarga, ni el ledger. Solo declara que el asunto
     * ya no requiere atencion en el panel. De ahi que se llame "cerrar" y no
     * "resolver": esta accion no resuelve nada del dominio.
     *
     * NO ES UN "OCULTAR". Lo que hace seguro este botón es que `raise()`
     * REABRE una incidencia resuelta cuando la condición vuelve a detectarse
     * (y vuelve a avisar, porque `notified_at` se limpia): si el problema
     * sigue vivo, el siguiente barrido lo devuelve al panel. Cerrar a mano algo
     * que no se ha arreglado no lo esconde, solo retrasa su reaparición hasta
     * la siguiente pasada del productor.
     *
     * CON UNA SALVEDAD IMPORTANTE: esa red de seguridad solo existe para los
     * productores PERIÓDICOS (mova:health-check cada hora, mova:reconcile-ledger
     * a diario, mova:settle-lessons). Las incidencias de un solo disparo
     * —reversión manual bloqueada, webhook que agotó reintentos— no las
     * re-emite nadie: si se cierran sin arreglar el recurso, no vuelven. Por eso
     * el motivo es obligatorio y queda registrado con actor y fecha: en esos
     * casos la trazabilidad es la única garantía que queda.
     *
     * Devuelve false si la incidencia ya estaba resuelta, para que dos admins
     * pulsando a la vez no se pisen el motivo ni la autoría. La condición
     * `whereNull('resolved_at')` la resuelve la propia base de datos.
     */
    public function closeManually(OperationalAlert $alert, User $actor, string $reason): bool
    {
        $claimed = OperationalAlert::whereKey($alert->id)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
                // Instantánea: el usuario puede borrarse y `resolved_by` es
                // nullOnDelete, así que sin esto el cierre quedaría sin autor.
                'resolved_by_name' => $actor->name,
                'resolution_note' => $reason,
                // Igual que en resolve(): si el problema reaparece, debe volver
                // a avisar en vez de quedarse callado por un notified_at viejo.
                'notified_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        Log::info('[OperationalAlert] Incidencia cerrada manualmente por un admin.', [
            'alert_key' => $alert->alert_key,
            'type' => $alert->type,
            'severity' => $alert->severity,
            'admin_id' => $actor->id,
            'reason' => $reason,
        ]);

        return true;
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
