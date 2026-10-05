<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Acota el checkout automático de Mercado Pago a usuarios concretos (QA).
 *
 * `MERCADOPAGO_CHECKOUT_ALLOWLIST` (config `payments.mercadopago.checkout_allowlist`):
 * correos exactos separados por comas. Con la lista definida, SOLO esos usuarios
 * ven y pueden usar el checkout automático; el resto recibe «no disponible».
 * Vacía/no definida → sin restricción (producción).
 *
 * Existe para que un staging con cuentas existentes pueda probar pagos sandbox con
 * un único profesor sintético sin abrir el flujo (ni la generación de créditos de
 * prueba) a todas las cuentas. No afecta a webhooks ni a la recuperación, que no
 * dependen de quién inició el pago. Fail closed: con lista activa y sin usuario
 * autenticado, no se permite.
 */
class CheckoutAllowlist
{
    /** @return list<string> */
    public static function entries(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $e) => strtolower(trim($e)),
            explode(',', (string) config('payments.mercadopago.checkout_allowlist', ''))
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
