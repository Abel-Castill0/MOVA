<?php

namespace App\Services;

use App\Exceptions\PaymentReviewCannotBeClosed;
use App\Models\CreditTransaction;
use App\Models\OperationalAlert;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Payment\MercadoPagoPaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cierre administrativo ACOTADO de un intento de Mercado Pago que quedó en revisión.
 *
 * Caso único que resuelve: la creación del pago fue RECHAZADA de forma terminal por Mercado Pago (HTTP 400 con un
 * código documentado de validación de request, persistido en el propio intento) y una búsqueda remota por
 * `external_reference` confirma AHORA que NO existe ningún pago. Solo entonces el intento pasa a `failed` (el profesor
 * podrá iniciar un intento nuevo, nunca uno automático).
 *
 * NO es una herramienta financiera genérica. Nunca: acredita, reembolsa, borra el intento, cambia importes, escribe en el
 * ledger ni cierra como failed si la existencia del pago sigue siendo incierta (búsqueda fallida, algún resultado, estado
 * 'submitting', ledger con movimientos, sin evidencia persistida del rechazo). Idempotente: repetirla sobre un intento ya
 * cerrado no cambia nada.
 */
class PaymentReviewResolutionService
{
    public const CLOSED = 'closed';

    public const ALREADY_CLOSED = 'already_closed';

    /** Tiempo mínimo desde el rechazo para fiarse de que la búsqueda remota ya no tiene retardo de indexación. */
    public const MIN_MINUTES_AFTER_REJECTION = 10;

    /**
     * @return string self::CLOSED | self::ALREADY_CLOSED
     *
     * @throws PaymentReviewCannotBeClosed
     */
    public function closeRejectedAttempt(PaymentOrder $order, User $actor, string $reason, ?MercadoPagoPaymentProvider $provider = null): string
    {
        $order = $order->fresh() ?? throw new PaymentReviewCannotBeClosed('El intento ya no existe.');

        if ($this->isAlreadyClosed($order)) {
            return self::ALREADY_CLOSED;
        }

        $this->assertEligible($order);

        // La verdad remota se lee FUERA de cualquier lock (red nunca dentro de una transacción).
        $provider ??= app(MercadoPagoPaymentProvider::class);
        $results = $provider->searchPaymentsByExternalReference($order->externalReference());

        if ($results === null) {
            throw new PaymentReviewCannotBeClosed('No se pudo consultar a Mercado Pago ahora: no se cierra nada mientras la existencia del pago siga incierta. Reintenta en unos minutos.');
        }

        if (count($results) > 0) {
            Log::warning('[PaymentReview] La búsqueda remota encontró pagos para un intento que se quería cerrar como rechazado — no se cierra.', [
                'payment_order_id' => $order->id,
                'resultados' => count($results),
            ]);

            throw new PaymentReviewCannotBeClosed('Mercado Pago informa que SÍ existe al menos un pago para este intento: no se puede cerrar como rechazado. Usa la reconciliación normal.');
        }

        $closed = DB::transaction(function () use ($order) {
            $locked = PaymentOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($this->isAlreadyClosed($locked)) {
                return false;
            }

            // El estado pudo cambiar mientras se consultaba el proveedor: se revalida bajo lock.
            $this->assertEligible($locked);

            $locked->update([
                'status' => 'failed',
                'submission_status' => null,
                'review_resolved_at' => now(),
                'last_verified_at' => now(),
            ]);

            return true;
        });

        if (! $closed) {
            return self::ALREADY_CLOSED;
        }

        $alert = OperationalAlert::where('alert_key', "payment_order:{$order->id}:review")->first();
        if ($alert !== null) {
            app(OperationalAlertService::class)->closeManually(
                $alert,
                $actor,
                'Intento cerrado como rechazado (HTTP '.$order->creation_http_status.', códigos '.$order->creation_error_codes
                .'; búsqueda remota por referencia sin pagos). Motivo del admin: '.$reason
            );
        }

        Log::notice('[PaymentReview] Intento en revisión cerrado como rechazado por un admin.', [
            'payment_order_id' => $order->id,
            'recharge_request_id' => $order->recharge_request_id,
            'admin_id' => $actor->id,
            'creation_http_status' => $order->creation_http_status,
            'creation_error_codes' => $order->creation_error_codes,
            'reason' => $reason,
        ]);

        return self::CLOSED;
    }

    /**
     * Persiste, por una sola vez y desde la CLI de un operador, la evidencia del rechazo terminal de un intento anterior a la
     * columna de evidencia (cuya respuesta original solo quedó en los logs). NO cambia estado, importes ni ledger: únicamente
     * rellena las columnas `creation_*`; cerrar el intento sigue exigiendo closeRejectedAttempt() (búsqueda remota incluida).
     *
     * @param  list<string>  $codes
     *
     * @throws PaymentReviewCannotBeClosed
     */
    public function attestCreationRejection(PaymentOrder $order, int $httpStatus, array $codes, ?string $providerRequestId, \DateTimeInterface $at): void
    {
        DB::transaction(function () use ($order, $httpStatus, $codes, $providerRequestId, $at) {
            $locked = PaymentOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->creation_http_status !== null) {
                throw new PaymentReviewCannotBeClosed('Este intento ya tiene evidencia de rechazo persistida; no se sobrescribe.');
            }
            if ($httpStatus !== 400 || ! MercadoPagoPaymentProvider::areTerminalValidationCodes($codes)) {
                throw new PaymentReviewCannotBeClosed('Solo se puede atestiguar un HTTP 400 con un código documentado de validación de request.');
            }
            if ($locked->provider !== 'mercadopago' || $locked->provider_order_id !== null || $locked->paid_at !== null || $locked->status !== 'pending' || $locked->review_reason === null) {
                throw new PaymentReviewCannotBeClosed('El intento no está en revisión sin ID de proveedor: no se atestigua.');
            }

            $locked->update([
                'creation_http_status' => $httpStatus,
                'creation_error_codes' => substr(implode(',', $codes), 0, 191),
                'creation_provider_request_id' => $providerRequestId !== null ? substr($providerRequestId, 0, 100) : null,
                'creation_failed_at' => $at,
            ]);
        });
    }

    /** ¿Ya se cerró este intento por esta vía? (idempotencia) */
    private function isAlreadyClosed(PaymentOrder $order): bool
    {
        return $order->status === 'failed' && $order->review_resolved_at !== null && $order->provider_order_id === null;
    }

    /** @throws PaymentReviewCannotBeClosed */
    private function assertEligible(PaymentOrder $order): void
    {
        $fail = static function (string $why): never {
            throw new PaymentReviewCannotBeClosed($why);
        };

        $order->loadMissing('rechargeRequest');

        if ($order->provider !== 'mercadopago') {
            $fail('Solo aplica a intentos de Mercado Pago.');
        }
        if ($order->status !== 'pending' || $order->paid_at !== null || $order->provider_order_id !== null) {
            $fail('El intento no está pendiente sin pago: no se puede cerrar como rechazado.');
        }
        if ($order->review_reason === null || $order->review_resolved_at !== null) {
            $fail('El intento no está en revisión.');
        }
        if ($order->submission_status === 'submitting') {
            $fail('El envío del pago podría seguir en curso: no se cierra.');
        }
        if ($order->creation_http_status !== 400 || ! MercadoPagoPaymentProvider::areTerminalValidationCodes(array_values(array_filter(explode(',', (string) $order->creation_error_codes))))) {
            $fail('No hay evidencia persistida de que Mercado Pago rechazara la creación de forma terminal: no se cierra.');
        }
        if ($order->creation_failed_at === null || $order->creation_failed_at->gt(now()->subMinutes(self::MIN_MINUTES_AFTER_REJECTION))) {
            $fail('El rechazo es demasiado reciente para fiarse de la búsqueda remota: espera unos minutos.');
        }

        $recharge = $order->rechargeRequest;
        if ($recharge === null || $recharge->status !== 'pending') {
            $fail('La recarga ya no está pendiente.');
        }
        $latest = PaymentOrder::where('recharge_request_id', $order->recharge_request_id)->max('attempt_number');
        if ((int) $latest !== (int) $order->attempt_number) {
            $fail('Existe un intento posterior para esta recarga.');
        }
        if (CreditTransaction::where('recharge_request_id', $order->recharge_request_id)->exists()) {
            $fail('La recarga tiene movimientos en el ledger: no se cierra como rechazada.');
        }
    }
}
