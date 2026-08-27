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
    /**
     * @param  \DateTimeInterface|null  $expiresAt  Momento en que el token deja
     *   de ser válido. F-06: antes era SIEMPRE ahora+24h, una ventana enorme
     *   para una clase de 60 minutos — si el JWT se filtraba (historial del
     *   navegador, captura de DevTools, extensión, proxy corporativo), daba
     *   acceso a una videollamada con un menor durante un día entero. Ahora
     *   LessonController::join() lo acota al final de la ventana en que esa
     *   misma llamada habría concedido el acceso.
     *
     *   Se mantiene el fallback de 24h para cualquier llamador que no pase el
     *   valor, en vez de romper: el endurecimiento no debe depender de que
     *   todos los llamadores se acuerden.
     */
    public function generateToken(
        string $roomName,
        string $userName,
        bool $isModerator,
        ?\DateTimeInterface $expiresAt = null
    ): string {
        $appId = config('jaas.app_id');
        $privateKey = config('jaas.private_key');
        $keyId = config('jaas.key_id');

        abort_unless($appId && $privateKey, 500, 'JaaS no está configurado (JAAS_APP_ID / JAAS_PRIVATE_KEY).');
        abort_unless($keyId, 500, 'JaaS no está configurado (falta JAAS_KEY_ID).');

        $now = time();
        $exp = $expiresAt ? $expiresAt->getTimestamp() : $now + (24 * 60 * 60);

        // Nunca emitir un token ya vencido ni de duración ridícula: si la
        // ventana calculada quedó en el pasado por desfase de reloj, se
        // concede un mínimo operativo en lugar de un token inservible.
        $exp = max($exp, $now + 300);

        $payload = [
            'aud' => 'jitsi',
            'iss' => 'chat',
            'sub' => $appId,
            'room' => $roomName,
            'exp' => $exp,
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
