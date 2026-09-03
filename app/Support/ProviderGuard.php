<?php

namespace App\Support;

use RuntimeException;

/**
 * F-03 — Resolución fail-closed de proveedores externos (pagos, WhatsApp).
 *
 * Antes, AppServiceProvider usaba `match (...) { 'meta' => ..., default => new Fake... }`.
 * Ese `default` es fail-OPEN: un typo en la variable de entorno, una variable
 * ausente, o un `config:cache` ejecutado antes de definirla hacían que MOVA
 * cayera silenciosamente en el proveedor falso. En pagos eso significa marcar
 * órdenes como pagadas sin cobro real (FakePaymentProvider siempre responde
 * 'paid'); en WhatsApp, que ningún mensaje salga mientras todo reporta éxito.
 *
 * Regla: un proveedor no reconocido detiene el arranque. Un proveedor Fake en
 * producción con la integración habilitada detiene el arranque. El Fake sigue
 * siendo perfectamente válido en local/testing — es donde debe vivir.
 */
class ProviderGuard
{
    public const FAKE = 'fake';

    /**
     * Entornos donde el proveedor Fake es legítimo. 'staging' NO está aquí a
     * propósito: si alguien quiere Fake en staging debe declararlo apagando la
     * feature (WHATSAPP_ENABLED=false / PAYMENTS_ENABLED=false), no dejando que
     * un valor por defecto lo decida por él.
     */
    private const FAKE_FRIENDLY_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Valida y devuelve el proveedor a usar, o lanza si la configuración es
     * insegura o ambigua.
     *
     * @param  string    $kind            'pagos' | 'WhatsApp' — solo para el mensaje
     * @param  string    $envVarName      nombre de la variable a corregir
     * @param  ?string   $provider        valor crudo de config
     * @param  string[]  $supportedReal   proveedores reales (sin 'fake')
     * @param  bool      $featureEnabled  si la integración está habilitada
     * @param  string    $environment     app()->environment()
     *
     * @throws RuntimeException
     */
    public static function resolve(
        string $kind,
        string $envVarName,
        ?string $provider,
        array $supportedReal,
        bool $featureEnabled,
        string $environment
    ): string {
        $provider = strtolower(trim((string) $provider));
        $allowed  = array_merge([self::FAKE], $supportedReal);

        if ($provider === '') {
            throw new RuntimeException(
                "Configuración de {$kind} inválida: {$envVarName} está vacía o no definida. "
                ."Valores válidos: ".implode(', ', $allowed).'. '
                .'MOVA no arranca con un proveedor ambiguo (antes caía silenciosamente en "fake").'
            );
        }

        if (!in_array($provider, $allowed, true)) {
            // Sugerencia por distancia de edición: el modo de fallo más
            // probable es un typo ("culqui" por "culqi"), y decirlo explícito
            // ahorra el rato de mirar la variable sin ver la diferencia.
            $suggestion = self::closestMatch($provider, $allowed);

            throw new RuntimeException(
                "Configuración de {$kind} inválida: {$envVarName}=\"{$provider}\" no es un proveedor soportado. "
                .'Valores válidos: '.implode(', ', $allowed).'.'
                .($suggestion ? " ¿Quisiste decir \"{$suggestion}\"?" : ' Revisa si es un error de escritura.')
            );
        }

        if ($provider === self::FAKE
            && $featureEnabled
            && !in_array($environment, self::FAKE_FRIENDLY_ENVIRONMENTS, true)
        ) {
            throw new RuntimeException(
                "Configuración de {$kind} insegura: el entorno es \"{$environment}\" y la integración está "
                ."habilitada, pero {$envVarName}=\"fake\". El proveedor falso no envía/cobra nada real y "
                .'reporta éxito, así que el fallo sería invisible. Configura un proveedor real ('
                .implode(', ', $supportedReal).') o deshabilita la integración.'
            );
        }

        return $provider;
    }

    /**
     * Exige un conjunto mínimo de configuración cuando la integración está
     * realmente habilitada — mismo espíritu fail-closed que resolve(), pero
     * para variables que resolve() no puede validar por sí solo (no son "el
     * nombre del proveedor", son datos específicos que el proveedor
     * necesita para operar de forma segura). Sin esto, un operador podía
     * dejar PAYMENTS_ENABLED=true + PAYMENT_PROVIDER=mercadopago con, por
     * ejemplo, el binding de cuenta vendedora (expected_collector_id) sin
     * configurar — el chequeo quedaba silenciosamente "omitido" en vez de
     * bloquear el arranque, que es exactamente el defecto que este método
     * corrige (sección de config fail-closed de la ronda de pivot a
     * Payments API).
     *
     * @param  array<string,mixed>  $required  envVarName => valor actual de config
     *
     * @throws RuntimeException
     */
    public static function requireConfig(string $kind, bool $featureEnabled, array $required): void
    {
        if (! $featureEnabled) {
            return;
        }

        $missing = [];
        foreach ($required as $envVarName => $value) {
            if ($value === null || $value === '') {
                $missing[] = $envVarName;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                "Configuración de {$kind} incompleta: faltan ".implode(', ', $missing).'. '
                .'MOVA no arranca con pagos habilitados y configuración financiera incompleta '
                .'(dejar un binding de seguridad sin configurar equivale a omitirlo en silencio).'
            );
        }
    }

    /**
     * Devuelve el valor permitido más parecido, si la diferencia es pequeña
     * (hasta 2 ediciones). Por encima de eso no es un typo sino otra cosa, y
     * sugerir algo lejano confundiría más que ayudar.
     *
     * @param  string[]  $allowed
     */
    private static function closestMatch(string $provider, array $allowed): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($allowed as $candidate) {
            $distance = levenshtein($provider, $candidate);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $bestDistance <= 2 ? $best : null;
    }
}
