<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Movi — asistente de ayuda de uso de MOVA sobre Google Gemini API.
 *
 * Contrato con el navegador (ver ChatbotController):
 *   ['ok' => true,  'reply' => '...']              respuesta del modelo
 *   ['ok' => false, 'reason' => 'unavailable'|'provider_error'|'daily_limit']
 * El navegador NUNCA recibe detalles de configuración (qué variable falta,
 * que exista un .env), ni mensajes/códigos del proveedor, ni la clave.
 *
 * Solo viaja al proveedor lo que el usuario escribió en el chat (mensaje +
 * historial acotado). Jamás datos de la sesión, del perfil, de menores ni de
 * la base de datos de MOVA.
 */
class ChatbotService
{
    public const UNAVAILABLE = 'unavailable';

    public const PROVIDER_ERROR = 'provider_error';

    public const DAILY_LIMIT = 'daily_limit';

    /** Clave del techo GLOBAL diario (compartida por todos los clientes). */
    public const DAILY_LIMIT_KEY = 'chatbot:global-daily';

    /**
     * @param  array<int, array{sender:string,text:string}>  $history
     * @return array{ok:bool, reply?:string, reason?:string}
     */
    public function reply(string $userMessage, array $history = []): array
    {
        if (! config('chatbot.enabled', false)) {
            return ['ok' => false, 'reason' => self::UNAVAILABLE];
        }

        $apiKey = config('chatbot.gemini.api_key');

        if (empty($apiKey)) {
            // Fail closed. Se registra el problema de configuración para el
            // operador — sin valores, sin instrucciones hacia el navegador.
            Log::error('[Chatbot] CHATBOT_ENABLED=true pero la clave del proveedor no está configurada — respondiendo no disponible.');

            return ['ok' => false, 'reason' => self::UNAVAILABLE];
        }

        $models = array_values(array_unique(array_filter([
            config('chatbot.gemini.model', 'gemini-3.5-flash-lite'),
            config('chatbot.gemini.fallback_model', 'gemini-3.8-flash'),
        ])));

        $systemPrompt = $this->buildSystemPrompt();
        $contents = $this->formatContents($history, $userMessage);

        foreach ($models as $index => $model) {
            // Techo GLOBAL diario de llamadas al proveedor. Se cuenta AQUÍ —
            // tras validar el cuerpo y confirmar que Movi está activo y
            // configurado — y por CADA llamada (el fallback también gasta
            // cuota), no en el throttle de la ruta: peticiones inválidas o con
            // Movi apagado no agotan la cuota del día. hit() incrementa de
            // forma atómica en el store de caché y devuelve el total, así dos
            // peticiones concurrentes no pueden pasar ambas por el último cupo.
            if (RateLimiter::hit(self::DAILY_LIMIT_KEY, 86400) > (int) config('chatbot.daily_limit', 300)) {
                return ['ok' => false, 'reason' => self::DAILY_LIMIT];
            }

            try {
                $response = Http::timeout((int) config('chatbot.gemini.timeout', 20))
                    ->acceptJson()
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                        'contents' => $contents,
                        'generationConfig' => [
                            'temperature' => (float) config('chatbot.gemini.temperature', 0.7),
                            'maxOutputTokens' => (int) config('chatbot.gemini.max_tokens', 600),
                        ],
                    ]);
            } catch (Throwable $e) {
                Log::warning('[Chatbot] Excepción al contactar al proveedor.', [
                    'model' => $model,
                    'error' => Str::limit($e->getMessage(), 200),
                ]);

                continue;
            }

            if ($response->successful()) {
                $text = $response->json('candidates.0.content.parts.0.text');

                if (is_string($text) && trim($text) !== '') {
                    return ['ok' => true, 'reply' => trim($text)];
                }

                Log::warning('[Chatbot] Respuesta del proveedor sin texto utilizable.', ['model' => $model]);

                return ['ok' => false, 'reason' => self::PROVIDER_ERROR];
            }

            $status = $response->status();
            Log::warning('[Chatbot] Respuesta no exitosa del proveedor.', [
                'model' => $model,
                'status' => $status,
                'error' => Str::limit((string) $response->json('error.status', ''), 60),
            ]);

            // Solo saturación/indisponibilidad del modelo justifica probar el
            // fallback. Un 4xx (clave rechazada, cuota, facturación, request
            // inválido) no mejora cambiando de modelo — se corta aquí.
            $retryable = in_array($status, [404, 429, 500, 502, 503, 504], true);
            if (! $retryable || $index === count($models) - 1) {
                break;
            }
        }

        return ['ok' => false, 'reason' => self::PROVIDER_ERROR];
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Eres Movi, el asistente virtual oficial de MOVA (una plataforma EdTech peruana con enfoque social). Tu único objetivo es guiar a padres, alumnos y profesores sobre cómo usar la plataforma. Eres amable, paciente y usas un lenguaje muy sencillo, sin tecnicismos. Nunca resuelvas problemas de matemáticas o escolares; si te piden ayuda con una tarea, explícales que nuestros profesores están listos para ayudarles y dales el enlace para solicitar una clase.
Tus respuestas deben ser muy cortas (máximo 2 párrafos).
Usa estos enlaces exactos cuando debas redirigir al usuario:
Para registrarse: [Crear cuenta]
Para pedir un profesor: [Solicitar Clase]
Para postular como practicante: [Voluntariado]

CREADORES Y FUNDADORES DE MOVA:
- Los creadores y fundadores oficiales de MOVA son **Elias J. Paz** y **Abel Castillo**. Si te preguntan quiénes son los creadores, fundadores o quiénes crearon la plataforma, dilo directamente, con orgullo y sin dudar.

REGLAS ADICIONALES DE RESPUESTA:
- Mantén siempre una longitud máxima de 2 párrafos breves.
- Cada vez que menciones solicitar clase, usar [Solicitar Clase].
- Cada vez que menciones registrarse o crear cuenta, usar [Crear cuenta].
- Cada vez que menciones ser profesor, postular o voluntariado, usar [Voluntariado].
- Jamás desarrolles ejercicios de álgebra, geometría, física, química, tareas o problemas escolares directamente; amablemente deriva al usuario a pedir un profesor con [Solicitar Clase].
- Nunca pidas ni repitas datos personales (teléfono, correo, DNI, dirección, datos de menores).
PROMPT;
    }

    /**
     * Historial acotado en roles user/model alternados, como exige Gemini.
     *
     * @param  array<int, array{sender:string,text:string}>  $history
     */
    private function formatContents(array $history, string $userMessage): array
    {
        $contents = [];
        $lastRole = null;

        foreach (array_slice($history, -(int) config('chatbot.history_sent_to_provider', 6)) as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $role = ($item['sender'] ?? '') === 'user' ? 'user' : 'model';

            if ($role === $lastRole) {
                $contents[count($contents) - 1]['parts'][0]['text'] .= "\n".$text;

                continue;
            }

            // Gemini espera que la conversación empiece con un turno 'user'.
            if ($contents === [] && $role !== 'user') {
                continue;
            }

            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
            $lastRole = $role;
        }

        if ($lastRole === 'user') {
            $contents[count($contents) - 1]['parts'][0]['text'] .= "\n".trim($userMessage);
        } else {
            $contents[] = ['role' => 'user', 'parts' => [['text' => trim($userMessage)]]];
        }

        return $contents;
    }
}
