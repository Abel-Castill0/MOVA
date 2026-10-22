<?php

namespace App\Payment\Culqi;

/**
 * Normaliza el objeto `charge` de Culqi (la VERDAD server-to-server) a los
 * estados de MOVA. Fail-closed: cualquier combinación que no se reconozca
 * explícitamente es 'review' — jamás 'paid'.
 *
 * EVIDENCIA DE SANDBOX (2026-10-22, API real en modo test) — difiere de la
 * documentación OpenAPI:
 *   - Venta exitosa: HTTP 201, outcome.type="venta_exitosa", outcome.code="AUT0000",
 *     capture=true y `paid=false` (el campo `paid` NO es fiable para decidir).
 *   - Rechazo: HTTP 400 con objeto error {type:"operacion_denegada"|"venta_denegada",
 *     code:"DNGE0015"|"DNGA9999"…, charge_id}; el cargo rechazado existe y consta
 *     como outcome.type="operacion_denegada", paid=false.
 * Se acepta además la variante documentada (outcome.code="succesfull_charge"
 * con paid=true) por compatibilidad.
 *
 *   paid     venta exitosa capturada, sin devolución ni disputa.
 *   failed   rechazo definitivo (operacion_denegada / venta_denegada / card_declined).
 *   reversed devolución TOTAL ya aplicada sobre el cargo.
 *   review   todo lo demás: devolución parcial, disputa/fraude, cargo sin
 *            capturar, resultado desconocido, datos inconsistentes.
 */
final class CulqiChargeMapper
{
    public const OUTCOME_SUCCESS_TYPE = 'venta_exitosa';
    public const OUTCOME_SUCCESS_CODE = 'AUT0000';
    public const OUTCOME_SUCCESS_CODE_LEGACY = 'succesfull_charge'; // sic: así lo escribe la documentación
    public const OUTCOME_DECLINED_TYPES = ['operacion_denegada', 'venta_denegada'];
    public const OUTCOME_DECLINED_CODE_LEGACY = 'card_declined';

    /** @param array<string, mixed> $charge */
    public static function normalize(array $charge): string
    {
        $amount = self::intOrNull($charge['amount'] ?? null);
        $refunded = self::intOrNull($charge['amount_refunded'] ?? 0) ?? 0;
        $outcome = is_array($charge['outcome'] ?? null) ? $charge['outcome'] : [];
        $type = $outcome['type'] ?? null;
        $code = $outcome['code'] ?? null;
        $captured = ($charge['capture'] ?? true) === true;

        if ($amount === null || $amount <= 0) {
            return 'review';
        }

        if ($refunded > 0) {
            return $refunded >= $amount ? 'reversed' : 'review';
        }

        if (($charge['dispute'] ?? false) === true) {
            return 'review';
        }

        $approved = ($type === self::OUTCOME_SUCCESS_TYPE && $code === self::OUTCOME_SUCCESS_CODE)
            || ($code === self::OUTCOME_SUCCESS_CODE_LEGACY && ($charge['paid'] ?? false) === true);

        if ($approved && $captured) {
            return 'paid';
        }

        if (! $approved && (in_array($type, self::OUTCOME_DECLINED_TYPES, true) || $code === self::OUTCOME_DECLINED_CODE_LEGACY)) {
            return 'failed';
        }

        return 'review';
    }

    /** Importe efectivamente devuelto (céntimos); 0 si no hay devolución. */
    public static function refundedMinor(array $charge): int
    {
        return self::intOrNull($charge['amount_refunded'] ?? 0) ?? 0;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return is_float($value) && floor($value) === $value ? (int) $value : null;
    }
}
