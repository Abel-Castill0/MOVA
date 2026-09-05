<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente de la Gmail API: obtiene un access token a partir del refresh token de
 * OAuth y entrega un mensaje MIME ya construido.
 *
 * ACLARACIÓN IMPORTANTE (recogida en docs/MOVA_SYSTEM_MAP.md §20.1): MOVA envía
 * correo por la **Gmail API con OAuth2**, no por SMTP de Gmail ni con una
 * "contraseña de aplicación". Son mecanismos distintos con credenciales
 * distintas.
 *
 * H-05 — Esta clase ya NO construye el MIME. Antes concatenaba cabeceras a mano
 * y derivaba el texto plano con `strip_tags()`; ahora recibe el mensaje completo
 * que Symfony ya sabe construir bien (ver App\Mail\Transport\GmailApiTransport).
 * Su única responsabilidad es la autenticación y el transporte HTTP.
 */
class GmailApiMailService
{
    /**
     * Entrega un mensaje MIME completo (RFC 822) por la Gmail API.
     *
     * LANZA en vez de devolver false, a propósito: es un transporte de Symfony,
     * y el contrato de un transporte es fallar ruidosamente para que el mailer
     * `failover` pueda pasar al siguiente. Tragarse el error aquí dejaría a
     * MOVA creyendo que el correo salió.
     *
     * @throws RuntimeException
     */
    public function sendRawMessage(string $rawMimeMessage): void
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            throw new RuntimeException('Gmail API: no se pudo obtener un access token con el refresh token configurado.');
        }

        // base64url, como exige el campo `raw` de users.messages.send.
        $encoded = rtrim(strtr(base64_encode($rawMimeMessage), '+/', '-_'), '=');

        $response = Http::timeout(15)
            ->withToken($accessToken)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $encoded,
            ]);

        if ($response->failed()) {
            // El cuerpo de la respuesta de Google puede contener detalles del
            // remitente; se registra el estado, nunca el payload completo.
            Log::error('[Gmail] La API rechazó el envío.', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Gmail API devolvió el estado HTTP '.$response->status().' al enviar el mensaje.');
        }
    }

    /**
     * ¿Hay credenciales suficientes para intentar siquiera un envío?
     *
     * Lo usa AppServiceProvider para no registrar el transporte cuando no está
     * configurado, y así el `failover` caiga directamente a SMTP en vez de
     * gastar un intento fallido contra Google en cada correo.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.gmail.client_id'))
            && filled(config('services.gmail.client_secret'))
            && filled(config('services.gmail.refresh_token'));
    }

    private function getAccessToken(): ?string
    {
        $response = Http::timeout(10)
            ->asForm()
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.gmail.client_id'),
                'client_secret' => config('services.gmail.client_secret'),
                'refresh_token' => config('services.gmail.refresh_token'),
                'grant_type' => 'refresh_token',
            ]);

        if ($response->failed()) {
            Log::error('[Gmail] No se pudo renovar el access token.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        return $response->json('access_token');
    }
}
