<?php

namespace App\Services;

use Firebase\JWT\JWT;

/**
 * Firma los JWT que autorizan a un usuario a entrar a una sala de JaaS
 * (jaas.8x8.vc) — reemplaza al meet.jit.si público, que corta el embed a
 * los 5 minutos en producción. JaaS valida la firma contra la public key
 * asociada al App ID (subida en la consola de JaaS), así que solo un
 * backend que tenga la private key puede emitir tokens válidos.
 */
class JaasService
{
    public function generateToken(string $roomName, string $userName, bool $isModerator): string
    {
        $appId = config('jaas.app_id');
        $privateKey = config('jaas.private_key');
        $keyId = config('jaas.key_id');

        abort_unless($appId && $privateKey, 500, 'JaaS no está configurado (JAAS_APP_ID / JAAS_PRIVATE_KEY).');
        abort_unless($keyId, 500, 'JaaS no está configurado (falta JAAS_KEY_ID).');

        $now = time();

        $payload = [
            'aud' => 'jitsi',
            'iss' => 'chat',
            'sub' => $appId,
            'room' => $roomName,
            'exp' => $now + (24 * 60 * 60),
            'nbf' => $now - 10,
            'context' => [
                'user' => [
                    'name' => $userName,
                    'moderator' => $isModerator,
                ],
                'features' => [
                    'livestreaming' => false,
                    'recording' => false,
                    'transcription' => false,
                ],
            ],
        ];

        // JaaS necesita el `kid` (Key ID de la API key subida en su consola,
        // distinto del App ID) en el header del JWT para saber con qué
        // public key verificar la firma — sin esto rechaza el token con
        // "Missing Key ID (kid)" aunque la firma sea correcta.
        $headers = [
            'kid' => $keyId,
            'typ' => 'JWT',
            'alg' => 'RS256',
        ];

        return JWT::encode($payload, $privateKey, 'RS256', null, $headers);
    }
}
