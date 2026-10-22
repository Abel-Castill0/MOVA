<?php

namespace App\Payment\Culqi;

use RuntimeException;

/**
 * Lectura y validación de la configuración de Culqi. Nunca devuelve ni
 * registra el valor de una llave: solo su presencia, formato y modo.
 *
 * Fail-closed: el modo declarado por un humano (CULQI_ENV) debe coincidir con
 * el prefijo de AMBAS llaves (pk_/sk_ + test|live), y una llave live solo es
 * válida con APP_ENV=production. Así un .env a medio copiar entre staging y
 * producción aborta el arranque en vez de cobrar (o dejar de cobrar) en
 * silencio.
 */
final class CulqiConfig
{
    private const KEY_FORMAT = '/^(pk|sk)_(test|live)_[A-Za-z0-9]{8,}$/';

    public const MIN_WEBHOOK_SECRET_LENGTH = 32;

    public static function secretKey(): ?string
    {
        return self::clean(config('payments.culqi.private_key'));
    }

    public static function publicKey(): ?string
    {
        return self::clean(config('payments.culqi.public_key'));
    }

    /** Modo declarado por el operador: 'test' | 'live'. Cualquier otro valor se trata como inválido. */
    public static function declaredEnv(): string
    {
        return strtolower(trim((string) config('payments.culqi.env', 'test')));
    }

    public static function isTestMode(): bool
    {
        return self::declaredEnv() === 'test';
    }

    /** 'test' | 'live' según el prefijo de la llave, o null si no tiene el formato esperado. */
    public static function keyMode(?string $key, string $kind): ?string
    {
        if ($key === null || ! preg_match(self::KEY_FORMAT, $key, $m) || $m[1] !== $kind) {
            return null;
        }

        return $m[2];
    }

    /**
     * Lista de problemas de configuración (texto sin valores secretos). Vacía = coherente.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];
        $declared = self::declaredEnv();

        if (! in_array($declared, ['test', 'live'], true)) {
            $problems[] = 'CULQI_ENV debe ser "test" o "live".';
        }

        $secretMode = self::keyMode(self::secretKey(), 'sk');
        $publicMode = self::keyMode(self::publicKey(), 'pk');

        if (self::secretKey() === null) {
            $problems[] = 'CULQI_SECRET_KEY no está configurada.';
        } elseif ($secretMode === null) {
            $problems[] = 'CULQI_SECRET_KEY no tiene el formato sk_test_… / sk_live_… (¿es un valor de ejemplo?).';
        }

        if (self::publicKey() === null) {
            $problems[] = 'CULQI_PUBLIC_KEY no está configurada.';
        } elseif ($publicMode === null) {
            $problems[] = 'CULQI_PUBLIC_KEY no tiene el formato pk_test_… / pk_live_… (¿es un valor de ejemplo?).';
        }

        if ($secretMode !== null && $publicMode !== null && $secretMode !== $publicMode) {
            $problems[] = "Las llaves mezclan modos (secret={$secretMode}, public={$publicMode}).";
        }

        foreach ([$secretMode, $publicMode] as $mode) {
            if ($mode !== null && in_array($declared, ['test', 'live'], true) && $mode !== $declared) {
                $problems[] = "CULQI_ENV={$declared} no coincide con el modo de las llaves ({$mode}).";
                break;
            }
        }

        if (($secretMode === 'live' || $publicMode === 'live') && ! app()->environment('production')) {
            $problems[] = 'Llaves live fuera de APP_ENV=production: las credenciales live solo van a producción.';
        }

        // Culqi no firma sus webhooks: el secreto de la URL es la única barrera frente a tráfico ajeno,
        // así que debe ser largo (fuerza bruta limitada solo por el throttle por IP).
        if ((bool) config('payments.culqi.webhooks_enabled', false)) {
            $webhookSecret = config('payments.culqi.webhook_secret');
            if (! is_string($webhookSecret) || strlen($webhookSecret) < self::MIN_WEBHOOK_SECRET_LENGTH) {
                $problems[] = 'CULQI_WEBHOOK_SECRET debe tener al menos '.self::MIN_WEBHOOK_SECRET_LENGTH.' caracteres con CULQI_WEBHOOKS_ENABLED=true (p. ej. `openssl rand -hex 24`).';
            }
        }

        // La llave secreta solo debe viajar a los hosts oficiales de Culqi (los dobles de prueba
        // se permiten únicamente en local/testing).
        if (! app()->environment(['local', 'testing'])) {
            foreach (['base_url' => 'api.culqi.com', 'secure_url' => 'secure.culqi.com'] as $key => $expectedHost) {
                $host = parse_url((string) config("payments.culqi.{$key}"), PHP_URL_HOST);
                if ($host !== $expectedHost || parse_url((string) config("payments.culqi.{$key}"), PHP_URL_SCHEME) !== 'https') {
                    $problems[] = "CULQI_".strtoupper($key)." debe ser https://{$expectedHost} fuera de local/testing.";
                }
            }
        }

        return $problems;
    }

    public static function assertConsistent(): void
    {
        $problems = self::problems();

        if ($problems !== []) {
            throw new RuntimeException('Configuración de Culqi inválida: '.implode(' ', $problems));
        }
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
