<?php

namespace App\Payment\Culqi;

use RuntimeException;

/**
 * Cifrado híbrido AES-256-GCM + RSA-OAEP(SHA-256/MGF1-SHA-256) del payload de
 * los POST/PATCH hacia Culqi, siguiendo el ejemplo oficial (apidocs.culqi.com,
 * "Encriptación AES/RSA"): key aleatoria de 32 bytes, iv aleatorio de 16 bytes,
 * ciphertext en base64 (sin la etiqueta GCM, igual que el ejemplo oficial) y
 * key/iv cifrados por separado con la llave RSA pública. Se envía junto a la
 * cabecera `x-culqi-rsa-id`.
 *
 * Es opcional (CULQI_ENCRYPT_PAYLOADS=false por defecto): el tráfico ya va por
 * TLS. NO verificado contra el descifrado real de Culqi (el sandbox acepta
 * peticiones en claro); probarlo en el sandbox antes de activarlo.
 *
 * RSA-OAEP con SHA-256 no existe en la extensión openssl de PHP, así que se
 * usa phpseclib, que llega como dependencia de laravel/socialite (v4:
 * namespace phpseclib4; v3: phpseclib3). Si faltara, falla cerrado en vez de
 * enviar el payload en claro.
 */
final class CulqiPayloadEncryptor
{
    public function __construct(private readonly string $rsaPublicKeyPem)
    {
    }

    public static function fromConfig(): ?self
    {
        if (! (bool) config('payments.culqi.encrypt_payloads', false)) {
            return null;
        }

        $pem = config('payments.culqi.rsa_public_key');
        $id = config('payments.culqi.rsa_id');

        if (! is_string($pem) || trim($pem) === '' || ! is_string($id) || trim($id) === '') {
            throw new RuntimeException('CULQI_ENCRYPT_PAYLOADS=true requiere CULQI_RSA_ID y CULQI_RSA_PUBLIC_KEY.');
        }

        // Los .env de una línea suelen traer el PEM con "\n" literales.
        return new self(str_replace('\n', "\n", trim($pem)));
    }

    /** Namespace raíz de phpseclib disponible ('phpseclib4' | 'phpseclib3') o null. */
    public static function phpseclibNamespace(): ?string
    {
        foreach (['phpseclib4', 'phpseclib3'] as $ns) {
            if (class_exists($ns.'\\Crypt\\PublicKeyLoader')) {
                return $ns;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{encrypted_data: string, encrypted_key: string, encrypted_iv: string}
     */
    public function encrypt(array $payload): array
    {
        $ns = self::phpseclibNamespace();
        if ($ns === null) {
            throw new RuntimeException('CulqiPayloadEncryptor: phpseclib no está disponible; no se envía el payload sin cifrar.');
        }

        $key = random_bytes(32);
        $iv = random_bytes(16);
        $tag = '';

        $ciphertext = openssl_encrypt(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('CulqiPayloadEncryptor: falló el cifrado AES-256-GCM.');
        }

        try {
            $loader = $ns.'\\Crypt\\PublicKeyLoader';
            $rsaClass = $ns.'\\Crypt\\RSA';

            $rsa = $loader::load($this->rsaPublicKeyPem)
                ->withPadding($rsaClass::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256');

            return [
                'encrypted_data' => base64_encode($ciphertext),
                'encrypted_key' => base64_encode($rsa->encrypt($key)),
                'encrypted_iv' => base64_encode($rsa->encrypt($iv)),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException('CulqiPayloadEncryptor: la llave pública RSA no es válida.', previous: $e);
        }
    }
}
