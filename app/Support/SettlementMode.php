<?php

namespace App\Support;

use RuntimeException;

/**
 * F-02 — Modo operativo de la liquidación automática (C-1).
 *
 * Antes, el Kernel agendaba `mova:settle-lessons --dry-run` con la bandera
 * hardcodeada. Eso dejaba C-1 implementado pero operacionalmente inactivo:
 * el scheduler detectaba cada hora lo que debería liquidar y no liquidaba
 * nada. Búsqueda global confirmó que no existía ninguna otra invocación real
 * fuera de los tests — los créditos de clases nunca cerradas quedaban
 * reservados indefinidamente, que es exactamente la patología que C-1 existía
 * para eliminar.
 *
 * Ahora el modo es configuración explícita (LESSON_SETTLEMENT_MODE), no una
 * bandera escondida en el código. Activar la liquidación real sigue siendo una
 * decisión de negocio deliberada, pero ahora es visible, auditable y detectable
 * como configuración peligrosa cuando producción sigue en dry_run.
 */
class SettlementMode
{
    public const DRY_RUN = 'dry_run';
    public const LIVE    = 'live';

    public const VALID = [self::DRY_RUN, self::LIVE];

    /**
     * Modo configurado. Lanza si el valor no es reconocido — un
     * LESSON_SETTLEMENT_MODE mal escrito no debe degradar silenciosamente a
     * ninguno de los dos comportamientos: ni liquidar sin querer, ni dejar de
     * liquidar creyendo que sí.
     */
    public static function current(): string
    {
        $mode = strtolower(trim((string) config('credits.settlement_mode', self::DRY_RUN)));

        if (!in_array($mode, self::VALID, true)) {
            throw new RuntimeException(
                "LESSON_SETTLEMENT_MODE=\"{$mode}\" no es válido. Valores permitidos: "
                .implode(', ', self::VALID).'. '
                .'Un modo ambiguo podría liquidar créditos sin querer o dejar de liquidarlos creyendo que sí.'
            );
        }

        return $mode;
    }

    public static function isLive(): bool
    {
        return self::current() === self::LIVE;
    }

    /**
     * Configuración peligrosa: producción con la liquidación apagada. No es un
     * error de arranque —MOVA funciona— pero es un estado que no debe pasar
     * inadvertido durante meses, porque los créditos se acumulan reservados
     * mientras nadie mira. Lo consume `mova:health-check`.
     */
    public static function isDangerousInProduction(string $environment): bool
    {
        return $environment === 'production' && !self::isLive();
    }

    public static function describe(): string
    {
        return self::isLive()
            ? 'live — la liquidación automática escribe en el ledger'
            : 'dry_run — la liquidación automática solo reporta, NO escribe';
    }
}
