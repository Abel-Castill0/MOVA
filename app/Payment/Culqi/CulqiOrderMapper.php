<?php

namespace App\Payment\Culqi;

/**
 * Normaliza el objeto `order` de Culqi (verdad server-to-server de una orden de pago:
 * PagoEfectivo, billeteras, Cuotéalo…) a los estados de MOVA. Fail-closed.
 *
 * Estados documentados en la especificación OpenAPI: "created", "pending", "deleted" y
 * "expired"; la guía de órdenes y del webhook `order.status.changed` nombra además "paid".
 * Lo observado en el sandbox real es solo `created` (orden recién creada, sin CIP). El estado
 * `paid` NO se ha observado contra la API real (CulqiPanel no ofrece aquí simulación
 * documentada): se acredita únicamente con `state="paid"` Y `paid_at` informado; cualquier
 * otra combinación es 'review'.
 *
 *   paid      pagada y confirmada → única que acredita.
 *   pending   created | pending → todavía sin pago: NO acredita.
 *   expired   venció sin pago.
 *   cancelled deleted | canceled.
 *   review    cualquier cosa no reconocida o inconsistente.
 */
final class CulqiOrderMapper
{
    /** @param array<string, mixed> $order */
    public static function normalize(array $order): string
    {
        $state = is_string($order['state'] ?? null) ? strtolower($order['state']) : null;
        $paidAt = $order['paid_at'] ?? null;
        $hasPaidAt = $paidAt !== null && $paidAt !== '' && $paidAt !== 0;

        if ($state === 'paid') {
            return $hasPaidAt ? 'paid' : 'review';
        }

        if ($hasPaidAt) {
            return 'review'; // paid_at sin state=paid: inconsistente, nunca se acredita
        }

        return match ($state) {
            'created', 'pending' => 'pending',
            'expired' => 'expired',
            'deleted', 'canceled', 'cancelled' => 'cancelled',
            default => 'review',
        };
    }
}
