<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Movi Chatbot Configuration
    |--------------------------------------------------------------------------
    |
    | Define la configuración para el asistente inteligente Movi en MOVA.
    | Utiliza Google Gemini API a través del endpoint oficial generativelanguage.
    |
    */

    'enabled' => (bool) env('CHATBOT_ENABLED', true),

    'provider' => env('CHATBOT_PROVIDER', 'gemini'),

    'gemini' => [
        // Clave de API de Google Gemini (Google AI Studio)
        'api_key'     => env('GEMINI_API_KEY'),

        // Modelo principal: gemini-3.5-flash-lite (ultra rápido, con respuesta en ~1.5s y disponible en Free Tier)
        'model'       => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),

        // Tiempo máximo de espera en segundos
        'timeout'     => (int) env('GEMINI_TIMEOUT', 20),

        // Límite de tokens en la respuesta
        'max_tokens'  => (int) env('GEMINI_MAX_TOKENS', 600),

        // Creatividad / temperatura (0.0 a 1.0)
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.7),
    ],

    // Máximo número de mensajes por minuto por IP/usuario
    'rate_limit_per_minute' => (int) env('CHATBOT_RATE_LIMIT_PER_MINUTE', 25),
];
