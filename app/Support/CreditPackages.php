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
    /** Presentación exacta en centavos; nunca recalcula una recarga ya creada. */
    public static function pricing(string $code, array $package): array
    {
        $credits = (int) $package['credits'];
        $amount = self::minor((string) $package['amount_pen']);
        $base = self::minor((string) config('credits.credit_price_pen', '2.00')) * $credits;
        $saving = max(0, $base - $amount);

        return ['code' => $code] + $package + [
            'currency' => 'PEN',
            'amount_minor' => $amount,
            'base_amount_minor' => $base,
            'saving_minor' => $saving,
            'base_amount_pen' => self::decimal($base),
            'discount_percent' => 0,
            'saving_pen' => self::decimal($saving),
            // Redondeo half-up del precio unitario, solo para presentación.
            'price_per_credit' => $credits > 0 ? self::decimal(intdiv(2 * $amount + $credits, 2 * $credits)) : null,
        ];
    }

    private static function minor(string $amount): int
    {
        if (! preg_match('/^(\d+)\.(\d{2})$/D', $amount, $parts)) {
            throw new \InvalidArgumentException('El precio debe expresarse en soles con dos decimales.');
        }

        return (int) $parts[1] * 100 + (int) $parts[2];
    }

    private static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

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
