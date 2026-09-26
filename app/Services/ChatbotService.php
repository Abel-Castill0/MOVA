<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotService
{
    /**
     * Responde a una consulta del usuario mediante Google Gemini API.
     *
     * @param string $userMessage Mensaje enviado por el usuario.
     * @param array $history Historial previo de la conversación [['sender' => 'user'|'bot', 'text' => '...']].
     * @return array
     */
    public function reply(string $userMessage, array $history = []): array
    {
        $enabled = config('chatbot.enabled', true);
        if (!$enabled) {
            return [
                'success'    => true,
                'configured' => true,
                'reply'      => 'El asistente virtual Movi se encuentra temporalmente en mantenimiento. Por favor, vuelve a consultar más tarde.',
            ];
        }

        $apiKey = config('chatbot.gemini.api_key');

        // Si la clave no está configurada, devolvemos un mensaje amigable indicando qué falta
        if (empty($apiKey)) {
            return [
                'success'    => true,
                'configured' => false,
                'reply'      => "¡Hola! Soy **Movi**, tu asistente inteligente en MOVA 🐿️.\n\nTodo mi sistema está listo y conectado. Para que pueda responderte en tiempo real con Inteligencia Artificial, solo falta que ingreses tu clave en el servidor (`GEMINI_API_KEY=...` en el archivo `.env`).\n\n¡En cuanto la configures, estaré listo para resolver todas tus consultas al instante! 🎓",
            ];
        }

        $configuredModel = config('chatbot.gemini.model', 'gemini-3.5-flash-lite');
        $timeout         = (int) config('chatbot.gemini.timeout', 20);
        $maxTokens       = (int) config('chatbot.gemini.max_tokens', 600);
        $temperature     = (float) config('chatbot.gemini.temperature', 0.7);

        // Modelos a intentar en cascada en caso de 503 (alta demanda) o 404
        $modelsToTry = array_values(array_unique([
            $configuredModel,
            'gemini-3.5-flash-lite',
            'gemini-3.5-flash',
            'gemini-3.8-flash',
        ]));

        $systemPrompt = $this->buildSystemPrompt();
        $contents     = $this->formatContents($systemPrompt, $history, $userMessage);

        $http = Http::timeout($timeout);
        if (app()->environment('local')) {
            $http = $http->withoutVerifying();
        }

        $lastStatus = 500;
        $lastError = 'No se pudo obtener respuesta del modelo';

        foreach ($modelsToTry as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

            try {
                $response = $http
                    ->withHeaders([
                        'x-goog-api-key' => $apiKey,
                        'Content-Type'   => 'application/json',
                    ])
                    ->post($url, [
                        'system_instruction' => [
                            'parts' => [
                                ['text' => $systemPrompt],
                            ],
                        ],
                        'contents'         => $contents,
                        'generationConfig' => [
                            'temperature'     => $temperature,
                            'maxOutputTokens' => $maxTokens,
                        ],
                    ]);

                if ($response->successful()) {
                    $candidate = $response->json('candidates.0.content.parts.0.text');

                    if (!empty($candidate)) {
                        return [
                            'success'    => true,
                            'configured' => true,
                            'reply'      => trim($candidate),
                        ];
                    }
                }

                $lastStatus = $response->status();
                $lastError  = $response->json('error.message') ?? 'Error en la API';

                Log::warning('ChatbotService: Respuesta no exitosa para modelo', [
                    'model'  => $model,
                    'status' => $lastStatus,
                    'error'  => $lastError,
                ]);

                // Si es 402, no reintentamos otros modelos porque es a nivel de cuenta/proyecto
                if ($lastStatus === 402) {
                    return [
                        'success'    => false,
                        'configured' => true,
                        'reply'      => "Google Gemini devolvió un aviso de facturación (**Error 402: Prepayment credits depleted**).\n\nEsto ocurre cuando la API Key pertenece a un proyecto de Google Cloud con facturación activada pero sin saldo prepagado.\n\n👉 **Cómo solucionarlo gratis:** En [Google AI Studio](https://aistudio.google.com/), genera una nueva API Key seleccionando **\"Create API key in new project\"** (sin vincular tarjeta de crédito/facturación) para usar el **Free Tier (100% gratuito)**.",
                    ];
                }

                // Si es 400 o 403, la clave misma es rechazada
                if ($lastStatus === 400 || $lastStatus === 403) {
                    return [
                        'success'    => false,
                        'configured' => true,
                        'reply'      => "La clave API de Gemini no fue aceptada por Google (**Error {$lastStatus}**). Por favor verifica que la clave esté activa y correctamente copiada desde [Google AI Studio](https://aistudio.google.com/).",
                    ];
                }

                // Si es 503 o 404, continuamos con el siguiente modelo de la lista
            } catch (\Throwable $e) {
                Log::warning('ChatbotService: Excepción intentando modelo', [
                    'model'   => $model,
                    'message' => $e->getMessage(),
                ]);
                $lastError = $e->getMessage();
            }
        }

        return [
            'success'    => false,
            'configured' => true,
            'reply'      => 'Movi tuvo una pequeña dificultad al conectar con el servicio de IA de Google. Por favor, reintenta tu pregunta en unos segundos.',
        ];
    }

    /**
     * Construye las instrucciones y conocimiento base sobre MOVA para Movi.
     */
    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
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
PROMPT;
    }

    /**
     * Formatea el historial de conversación en la estructura esperada por Gemini API.
     * Gemini requiere que los roles sean 'user' o 'model' y que alternen estrictamente.
     */
    private function formatContents(string $systemPrompt, array $history, string $userMessage): array
    {
        $contents = [];

        // Tomar únicamente los últimos 6 mensajes del historial para no saturar tokens
        $recentHistory = array_slice($history, -6);

        $lastRole = null;
        foreach ($recentHistory as $item) {
            $sender = $item['sender'] ?? '';
            $text   = trim($item['text'] ?? '');

            if ($text === '') {
                continue;
            }

            $role = ($sender === 'user') ? 'user' : 'model';

            // Evitar turnos consecutivos del mismo rol
            if ($role === $lastRole) {
                // Si el mismo rol repite, concatenamos
                $lastIndex = count($contents) - 1;
                $contents[$lastIndex]['parts'][0]['text'] .= "\n" . $text;
                continue;
            }

            // Gemini prefiere que la conversación comience con un mensaje de 'user'
            if (empty($contents) && $role !== 'user') {
                continue;
            }

            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => $text]],
            ];
            $lastRole = $role;
        }

        // Agregar el mensaje actual del usuario
        if ($lastRole === 'user' && !empty($contents)) {
            $lastIndex = count($contents) - 1;
            $contents[$lastIndex]['parts'][0]['text'] .= "\n" . trim($userMessage);
        } else {
            $contents[] = [
                'role'  => 'user',
                'parts' => [['text' => trim($userMessage)]],
            ];
        }

        return $contents;
    }
}
