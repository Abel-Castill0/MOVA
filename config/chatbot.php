<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Movi Chatbot Configuration
    |--------------------------------------------------------------------------
    |
    | Asistente Movi sobre Google Gemini API (generativelanguage). APAGADO por
    | defecto: un endpoint público que gasta cuota de un proveedor externo
    | solo se enciende por decisión explícita de despliegue, nunca por
    | omisión de una variable.
    |
    */

    'enabled' => (bool) env('CHATBOT_ENABLED', false),

    'provider' => env('CHATBOT_PROVIDER', 'gemini'),

    'gemini' => [
        // Clave dedicada al chatbot. Nunca se devuelve al navegador ni se
        // registra en logs.
        'api_key' => env('GEMINI_API_KEY'),

        // IDs vigentes en la documentación oficial de Google (verificados
        // 2026-09-26). Primario 3.5 Flash-Lite; un único fallback (3.8
        // Flash) solo ante saturación/indisponibilidad del primario — sin
        // IDs especulativos.
        'model'          => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.8-flash'),

        'timeout'     => (int) env('GEMINI_TIMEOUT', 20),
        'max_tokens'  => (int) env('GEMINI_MAX_TOKENS', 600),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.7),
    ],

    // Por usuario autenticado o IP, por minuto (limitador 'chatbot').
    'rate_limit_per_minute' => (int) env('CHATBOT_RATE_LIMIT_PER_MINUTE', 10),

    // Techo GLOBAL diario (todos los usuarios juntos) — conservador a
    // propósito: el endpoint es público y anónimo.
    'daily_limit' => (int) env('CHATBOT_DAILY_LIMIT', 300),

    // Límites de entrada (validados en ChatbotController).
    'max_message_chars' => 1000,
    'max_history_items' => 10,
    'history_sent_to_provider' => 6,
];
