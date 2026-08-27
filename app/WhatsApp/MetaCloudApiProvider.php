<?php

namespace App\WhatsApp;

use App\Models\WhatsAppMessage;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Integración directa con la WhatsApp Cloud API de Meta (Graph API), sin
 * BSP intermediario. Reemplaza la integración previa vía Twilio (ver
 * docs/whatsapp-architecture.md para el porqué del cambio y lo que queda
 * pendiente).
 *
 * $params se pasa como lista posicional de strings — se mapean 1:1 a las
 * variables {{1}}, {{2}}... del body de la plantilla en Meta, en el mismo
 * orden. MOVA no rearma la plantilla ni pretende adivinar su forma; solo
 * necesita saber cuántos parámetros tiene y en qué orden van, algo que se
 * confirma al aprobar la plantilla en el Business Manager de Meta.
 */
class MetaCloudApiProvider implements WhatsAppProviderContract
{
    public function sendTemplate(string $to, string $templateKey, array $params, ?string $clientReference = null): bool
    {
        $phoneNumberId = config('services.meta_whatsapp.phone_number_id');
        $accessToken = config('services.meta_whatsapp.access_token');

        if (! $phoneNumberId || ! $accessToken) {
            Log::warning('[WhatsApp/Meta] Credenciales no configuradas (META_WHATSAPP_PHONE_NUMBER_ID, META_WHATSAPP_ACCESS_TOKEN) — no se envió.', [
                'client_reference' => $clientReference,
            ]);

            return false;
        }

        $template = config("services.meta_whatsapp.templates.{$templateKey}");
        if (! $template || empty($template['name'])) {
            Log::warning("[WhatsApp/Meta] Plantilla '{$templateKey}' no configurada (o aún no aprobada por Meta) — no se envió.", [
                'client_reference' => $clientReference,
            ]);

            return false;
        }

        $version = config('services.meta_whatsapp.api_version', 'v20.0');
        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";

        $components = [];
        if (! empty($params)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($value) => ['type' => 'text', 'text' => (string) $value],
                    array_values($params)
                ),
            ];
        }

        // Plantillas AUTHENTICATION con botón OTP (copy_code) necesitan un
        // componente "button" además del body. IMPORTANTE: sub_type='url'
        // se verificó contra documentación de dos BSP (MessageBird,
        // 360dialog) que reflejan la Cloud API de Meta — NO contra
        // developers.facebook.com directamente (inaccesible en esta
        // sesión). NO tratar como confirmado hasta probarlo contra un envío
        // real — ver docs/whatsapp-architecture.md.
        if (! empty($template['button']['enabled']) && ! empty($params)) {
            $components[] = [
                'type' => 'button',
                'sub_type' => $template['button']['sub_type'] ?? 'url',
                'index' => 0,
                'parameters' => [
                    ['type' => 'text', 'text' => (string) array_values($params)[0]],
                ],
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($to, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template['name'],
                'language' => ['code' => $template['language'] ?? 'es_PE'],
                'components' => $components,
            ],
        ];

        // Sin retry automático a propósito: si Http::post() lanza (timeout,
        // conexión rechazada, DNS, etc.), MOVA no puede saber si Meta ya
        // procesó el envío o no — Meta no ofrece una idempotency key propia
        // para mensajes. Reintentar "a ciegas" aquí es exactamente el
        // escenario de doble envío que se quiere evitar. Por eso esa rama
        // NO se registra como 'failed' (un rechazo definitivo que MOVA sí
        // conoce) sino como 'unknown' (resultado incierto — ver
        // WhatsAppMessageStatus y mova:reconcile-whatsapp).
        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful()) {
                $messageId = $response->json('messages.0.id');

                // F-23: un 2xx sin "messages[0].id" NO es 'sent' — 'sent'
                // debe significar "MOVA tiene con qué correlacionar un
                // webhook futuro", y sin id eso es imposible: el webhook
                // busca por provider_message_id, así que esta fila jamás
                // podría avanzar a delivered/read por más que Meta sí lo
                // haya entregado. Antes esto se registraba como 'sent' con
                // id null — sintácticamente válido pero operacionalmente
                // inútil, indistinguible de un envío sano que solo está
                // esperando su webhook (mova:reconcile-whatsapp los marca
                // igual como "stuck_in_sent" recién a los 60 minutos, sin
                // forma de distinguir "Meta va lento" de "esta fila nunca
                // podrá resolverse"). 'unknown' es la clasificación honesta:
                // Meta respondió éxito HTTP, pero MOVA no puede afirmar que
                // sabe qué mensaje es. Mismo motivo que ya justifica
                // 'unknown' tras una excepción — un resultado externo que no
                // se puede determinar con certeza suficiente, no solo "hubo
                // una excepción de red".
                if ($messageId === null) {
                    Log::warning('[WhatsApp/Meta] Respuesta 200 sin id de mensaje — resultado incierto, no se puede correlacionar con un webhook futuro.', [
                        'to' => $to,
                        'template' => $templateKey,
                        'client_reference' => $clientReference,
                    ]);
                    $this->logAttempt(
                        $to, $templateKey, WhatsAppMessageStatus::Unknown, null,
                        'Meta respondió 2xx sin "messages[0].id" en el body', $clientReference
                    );

                    return false;
                }

                Log::info('[WhatsApp/Meta] Enviado.', ['to' => $to, 'template' => $templateKey, 'id' => $messageId, 'client_reference' => $clientReference]);
                $this->logAttempt($to, $templateKey, WhatsAppMessageStatus::Sent, $messageId, null, $clientReference);

                return true;
            }

            $error = $this->safeErrorFromResponse($response);
            Log::error('[WhatsApp/Meta] Error de la API — Meta rechazó el envío.', [
                'to' => $to,
                'template' => $templateKey,
                'status' => $response->status(),
                'client_reference' => $clientReference,
                // Meta devuelve {error: {message, type, code, error_subcode, fbtrace_id}}
                // — nunca el access_token, el OTP/params, ni el payload
                // completo en el log.
                'error' => $error,
            ]);
            $this->logAttempt($to, $templateKey, WhatsAppMessageStatus::Failed, null, "HTTP {$response->status()}: {$error}", $clientReference);

            return false;
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp/Meta] Resultado incierto (excepción antes de una respuesta de Meta) — no se reintenta.', [
                'to' => $to,
                'template' => $templateKey,
                'client_reference' => $clientReference,
                'error' => $e->getMessage(),
            ]);
            $this->logAttempt($to, $templateKey, WhatsAppMessageStatus::Unknown, null, $e->getMessage(), $clientReference);

            return false;
        }
    }

    /**
     * response->json('error') puede fallar/devolver null si Meta respondió
     * con un body no-JSON o malformado (visto en la práctica en fallos de
     * gateway/proxy intermedios) — nunca dejar que el logging en sí mismo
     * lance una excepción no capturada. Nunca incluye el body crudo
     * completo sin truncar (podría contener metadata no destinada a logs).
     */
    private function safeErrorFromResponse($response): string
    {
        try {
            $error = $response->json('error');

            return $error ? json_encode($error) : ('respuesta no-JSON: '.Str::limit($response->body(), 200));
        } catch (\Throwable) {
            return 'no se pudo interpretar la respuesta de Meta';
        }
    }

    private function logAttempt(
        string $to,
        string $templateKey,
        WhatsAppMessageStatus $status,
        ?string $providerMessageId,
        ?string $error,
        ?string $clientReference
    ): void {
        try {
            WhatsAppMessage::create([
                'to' => $to,
                'template_key' => $templateKey,
                'client_reference' => $clientReference,
                'provider' => 'meta',
                'provider_message_id' => $providerMessageId,
                'status' => $status,
                'status_updated_at' => now(),
                'error' => $error ? Str::limit($error, 500, '') : null,
            ]);
        } catch (\Throwable $e) {
            // El registro de auditoría nunca debe hacer fallar un envío que
            // por lo demás ya se resolvió (éxito, fracaso o incierto) — pero
            // el fallo de persistencia en sí SÍ debe quedar visible, con
            // suficiente contexto para investigarlo (a diferencia de un
            // simple "algo falló").
            Log::error('[WhatsApp/Meta] No se pudo registrar el intento en whatsapp_messages — el envío en sí NO se vio afectado.', [
                'to' => $to,
                'template' => $templateKey,
                'attempted_status' => $status->value,
                'provider_message_id' => $providerMessageId,
                'client_reference' => $clientReference,
                'persistence_error' => $e->getMessage(),
            ]);
        }
    }
}
