<?php

namespace App\Payment\Culqi;

use App\Payment\Contracts\PaymentMethodKind;
use App\Payment\Contracts\TokenizedPaymentInstrument;

/**
 * Token que Culqi Custom Checkout entrega al navegador (`Culqi.token.id`): de tarjeta
 * (`tkn_…`) o de Yape (`ype_…`). El tipo se deduce del prefijo en el servidor — nunca
 * de un campo que mande el cliente. Opcionalmente trae el `device_finger_print_id` de
 * Culqi3DS y, en el segundo paso del 3DS, los `parameters3DS` que devolvió el banco.
 * Nunca contiene PAN, CVV ni fecha de la tarjeta.
 */
final class CulqiTokenInstrument implements TokenizedPaymentInstrument
{
    /** @param array<string, mixed>|null $threeDs */
    public function __construct(
        public readonly string $token,
        public readonly ?string $deviceFingerprintId = null,
        public readonly ?array $threeDs = null,
    ) {
    }

    public function kind(): PaymentMethodKind
    {
        return str_starts_with($this->token, 'ype_') ? PaymentMethodKind::Yape : PaymentMethodKind::Card;
    }
}
