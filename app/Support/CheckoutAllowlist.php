<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Acota el checkout automático (QA/staging) a una lista de correos. La lista
 * pertenece al proveedor activo: MERCADOPAGO_CHECKOUT_ALLOWLIST con
 * PAYMENT_PROVIDER=mercadopago y CULQI_CHECKOUT_ALLOWLIST con culqi. Vacía =
 * sin restricción (comportamiento original de Mercado Pago, sin cambios).
 */
class CheckoutAllowlist
{
    /** @return list<string> */
    public static function entries(): array
    {
        $provider = config('payments.provider');
        $key = $provider === 'culqi' ? 'payments.culqi.checkout_allowlist' : 'payments.mercadopago.checkout_allowlist';

        return array_values(array_filter(array_map(
            static fn (string $e) => strtolower(trim($e)),
            explode(',', (string) config($key, ''))
        )));
    }

    public static function isActive(): bool
    {
        return self::entries() !== [];
    }

    public static function permits(?Authenticatable $user): bool
    {
        $entries = self::entries();
        if ($entries === []) {
            return true;
        }

        $email = strtolower(trim((string) ($user->email ?? '')));

        return $email !== '' && in_array($email, $entries, true);
    }
}
