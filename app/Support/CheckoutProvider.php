<?php

namespace App\Support;

use App\Payment\Culqi\CulqiConfig;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decide, con la configuración real, qué pasarela atiende el checkout automático de créditos.
 * Un solo proveedor activo a la vez (PAYMENT_PROVIDER): las recargas ya creadas conservan su
 * `payment_method` original, así que cambiar de proveedor no toca pagos en curso ni históricos.
 */
final class CheckoutProvider
{
    public const METHODS = ['card', 'yape', 'order'];

    /** 'mercadopago' | 'culqi' | null (checkout automático apagado o mal configurado). */
    public static function active(?Authenticatable $user = null): ?string
    {
        $user ??= auth()->user();

        if (self::mercadoPagoEnabled($user)) {
            return 'mercadopago';
        }

        return self::culqiEnabled($user) ? 'culqi' : null;
    }

    public static function label(?string $provider): string
    {
        return match ($provider) {
            'culqi' => 'Culqi',
            'mercadopago' => 'Mercado Pago',
            default => 'el proveedor',
        };
    }

    /**
     * ¿Se ofrecen compras NUEVAS con Mercado Pago? Retirado por defecto (MERCADOPAGO_NEW_PAYMENTS_ENABLED=false):
     * con Culqi como proveedor exclusivo, esto jamás vuelve a ser el respaldo automático de Culqi.
     */
    public static function mercadoPagoEnabled(?Authenticatable $user = null): bool
    {
        return (bool) config('payments.enabled')
            && (bool) config('payments.mercadopago.new_payments_enabled', false)
            && config('payments.provider') === 'mercadopago'
            && filled(config('payments.mercadopago.public_key'))
            && CheckoutAllowlist::permits($user ?? auth()->user());
    }

    /**
     * Mercado Pago como sistema HISTÓRICO: ¿hay que seguir verificando, conciliando y consultando el estado de los
     * pagos ya creados con Mercado Pago? Independiente del proveedor activo y de la bandera de pagos nuevos: mientras
     * existan credenciales y el webhook esté habilitado, las operaciones anteriores se cierran correctamente.
     */
    public static function mercadoPagoLegacyProcessing(): bool
    {
        return (bool) config('payments.enabled')
            && (config('payments.provider') === 'mercadopago'
                || (filled(config('payments.mercadopago.access_token')) && (bool) config('payments.mercadopago.webhooks_enabled', false)));
    }

    /**
     * Culqi como sistema de conciliación: ¿hay que seguir verificando los cargos/órdenes de Culqi ya creados? Igual que
     * en Mercado Pago, sobrevive a un rollback del proveedor de compras nuevas mientras haya llave secreta y el webhook
     * de Culqi esté habilitado (o Culqi sea el proveedor activo).
     */
    public static function culqiLegacyProcessing(): bool
    {
        return (bool) config('payments.enabled')
            && CulqiConfig::secretKey() !== null
            && (config('payments.provider') === 'culqi' || (bool) config('payments.culqi.webhooks_enabled', false));
    }

    /**
     * Fail-closed: pagos encendidos, Culqi como proveedor, configuración coherente (llaves, modo, hosts)
     * y el usuario permitido por la allowlist de QA si está definida.
     */
    public static function culqiEnabled(?Authenticatable $user = null): bool
    {
        return (bool) config('payments.enabled')
            && config('payments.provider') === 'culqi'
            && CulqiConfig::publicKey() !== null
            && CulqiConfig::problems() === []
            && CheckoutAllowlist::permits($user ?? auth()->user());
    }

    /**
     * Medios que el comercio ofrece (CULQI_METHODS). Por defecto solo `card`; `yape` y `order`
     * requieren que CulqiPanel los tenga habilitados y se activan explícitamente.
     *
     * @return list<string>
     */
    public static function culqiMethods(): array
    {
        $configured = array_map('trim', explode(',', strtolower((string) config('payments.culqi.methods', 'card'))));

        return array_values(array_intersect(self::METHODS, $configured));
    }
}
