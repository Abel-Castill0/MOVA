<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * §7 — Una reversión MANUAL dejaría el saldo de créditos en negativo.
 *
 * POR QUÉ UNA CLASE PROPIA Y NO UN `abort(422)` GENÉRICO:
 *
 * `RechargeApprovalService::reverse()` ya usaba `abort(422)` para otra cosa
 * distinta —"solo una recarga aprobada puede revertirse"—, que es un error de
 * ESTADO normal y esperado. Si el controller capturase cualquier 422, trataría
 * ambos casos igual: abriría una incidencia operativa crítica y avisaría a los
 * administradores cada vez que alguien pulsa "Revertir" sobre una recarga
 * pendiente, que no es una anomalía financiera sino un clic en el sitio
 * equivocado.
 *
 * Extiende `HttpException` con estado 422 para que, si nadie la captura, siga
 * comportándose exactamente igual que antes en el límite HTTP.
 */
class ReversalWouldGoNegative extends HttpException
{
    public function __construct(
        public readonly int $availableCredits,
        public readonly int $requestedCredits,
    ) {
        parent::__construct(422, sprintf(
            'No se puede revertir manualmente: el profesor tiene %d crédito(s) disponible(s) y esta recarga '
            .'aportó %d. Descontarlos dejaría el saldo en negativo, que AGENTS.md prohíbe. Se ha registrado '
            .'una incidencia para resolución administrativa.',
            $availableCredits,
            $requestedCredits
        ));
    }
}
