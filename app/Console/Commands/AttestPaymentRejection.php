<?php

namespace App\Console\Commands;

use App\Exceptions\PaymentReviewCannotBeClosed;
use App\Models\PaymentOrder;
use App\Services\PaymentReviewResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Operador (CLI, no expuesto por web): registra UNA vez la evidencia del rechazo terminal de la creación de un intento ANTERIOR a
 * las columnas `creation_*` (cuya respuesta original solo quedó en los logs de producción). No cambia estado, importes ni ledger:
 * únicamente rellena la evidencia. Cerrar el intento sigue exigiendo la acción administrativa autenticada (con MFA), que vuelve a
 * consultar a Mercado Pago. Los intentos nuevos guardan esa evidencia solos.
 */
class AttestPaymentRejection extends Command
{
    protected $signature = 'mova:attest-payment-rejection
        {order : ID del PaymentOrder}
        {--http= : HTTP status de la respuesta original (solo 400)}
        {--code=* : Código(s) de error de la respuesta original (p. ej. 2072)}
        {--request-id= : x-request-id de la respuesta original (opcional, de los logs)}
        {--at= : Fecha/hora UTC de la respuesta original (de los logs), p. ej. "2026-10-06 04:42:43"}';

    protected $description = 'Registra (una vez) la evidencia de rechazo terminal de la creación de un intento de Mercado Pago anterior a las columnas creation_*.';

    public function handle(PaymentReviewResolutionService $service): int
    {
        $order = PaymentOrder::find($this->argument('order'));
        if (! $order) {
            $this->error('PaymentOrder no encontrado.');

            return self::FAILURE;
        }

        $codes = array_values(array_filter(array_map('strval', (array) $this->option('code'))));
        if ($this->option('http') === null || $codes === [] || $this->option('at') === null) {
            $this->error('Indica --http, al menos un --code y --at (datos de la respuesta original en los logs).');

            return self::FAILURE;
        }

        try {
            $service->attestCreationRejection(
                $order,
                (int) $this->option('http'),
                $codes,
                $this->option('request-id') ?: null,
                Carbon::parse((string) $this->option('at'), 'UTC'),
            );
        } catch (PaymentReviewCannotBeClosed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Evidencia registrada para el intento #{$order->id}. El intento NO cambió de estado; ciérralo desde el panel admin (MFA).");

        return self::SUCCESS;
    }
}
