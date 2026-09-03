<?php

namespace Tests\Feature;

use App\Payment\MercadoPago\MercadoPagoPaymentStatusMapper;
use Tests\TestCase;

/**
 * Cubre la tabla completa de mapeo status/status_detail de Payments API →
 * vocabulario de 5 estados de MOVA — reemplaza a
 * MercadoPagoOrderStatusMapperTest (eliminado en el pivot a Payments API).
 * Regla explícita: cualquier combinación no reconocida cae en 'review',
 * nunca en 'paid' ni 'reversed' por default.
 */
class MercadoPagoPaymentStatusMapperTest extends TestCase
{
    /** @dataProvider mappings */
    public function test_normalizes_mercadopago_payment_status_to_mova_domain_status(string $status, ?string $statusDetail, string $expected): void
    {
        $this->assertSame($expected, MercadoPagoPaymentStatusMapper::normalize($status, $statusDetail));
    }

    public static function mappings(): array
    {
        return [
            'approved/accredited → paid' => ['approved', 'accredited', 'paid'],

            'approved/partially_refunded → review (nunca full reversal automático)' => ['approved', 'partially_refunded', 'review'],
            'approved sin status_detail reconocido → review' => ['approved', 'pending_review_manual', 'review'],

            'authorized/pending_capture → pending' => ['authorized', 'pending_capture', 'pending'],
            'in_process cualquier detail → pending' => ['in_process', 'pending_review_manual', 'pending'],
            'in_process sin detail → pending' => ['in_process', null, 'pending'],
            'pending cualquier detail → pending' => ['pending', 'pending_contingency', 'pending'],

            'rejected cualquier detail → failed' => ['rejected', 'cc_rejected_insufficient_amount', 'failed'],
            'cancelled cualquier detail → failed' => ['cancelled', 'by_collector', 'failed'],
            // status_detail 'expired' documentado como el estado TERMINAL de
            // expiración del proveedor (nunca confundir con la ventana ~5min
            // del Challenge de MOVA, three_ds_expires_at — ver
            // MercadoPagoPaymentReconciliationService::applyFailed()) — cae
            // en el mismo match arm que cualquier otro detail bajo
            // 'cancelled', sin una rama separada que probar.
            'cancelled/expired (terminal de proveedor) → failed' => ['cancelled', 'expired', 'failed'],

            'refunded/refunded (total) → reversed' => ['refunded', 'refunded', 'reversed'],
            'refunded/by_admin → reversed' => ['refunded', 'by_admin', 'reversed'],
            'refunded con status_detail distinto → review' => ['refunded', 'partially_refunded', 'review'],

            'in_mediation/pending → review' => ['in_mediation', 'pending', 'review'],

            'charged_back/in_process → review' => ['charged_back', 'in_process', 'review'],
            'charged_back/settled → review (candidato a reversal, no automatizado)' => ['charged_back', 'settled', 'review'],
            'charged_back/reimbursed → review' => ['charged_back', 'reimbursed', 'review'],
            'charged_back sin status_detail → review' => ['charged_back', null, 'review'],

            'status desconocido → review (fail-closed, nunca paid por default)' => ['algo_nuevo_de_mercadopago', 'x', 'review'],
            'authorized sin pending_capture → review' => ['authorized', 'otro_detail', 'review'],
        ];
    }
}
