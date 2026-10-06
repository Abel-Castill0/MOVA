<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Catálogo de paquetes ofrecidos por el checkout automático.
 *
 * Es config('credits.packages') más, SOLO para el propietario, el paquete de verificación de cobro (1 sol). Fail closed: el
 * paquete extra exige interruptor encendido, allowlist de checkout NO vacía y usuario incluido en ella; con la allowlist vacía
 * (apertura pública) nunca aparece.
 */
class CreditPackages
{
    /** @return array<string, array{name: string, credits: int, amount_pen: string}> */
    public static function forCheckout(?Authenticatable $user): array
    {
        $packages = (array) config('credits.packages');
        $extra = (array) config('credits.verification_package');

        if (($extra['enabled'] ?? false) === true
            && CheckoutAllowlist::isActive()
            && CheckoutAllowlist::permits($user)
            && ! empty($extra['code'])) {
            $packages[$extra['code']] = [
                'name' => $extra['name'],
                'credits' => (int) $extra['credits'],
                'amount_pen' => $extra['amount_pen'],
            ];
        }

        return $packages;
    }
}
