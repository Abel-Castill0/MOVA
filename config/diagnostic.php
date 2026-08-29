<?php

return [
    /*
     * Feature flag para IA opcional.
     *
     * Cuando sea true, DiagnosticAiEnrichmentService enriquece el diagnóstico
     * con keywords, nivel detectado y resumen para el padre.
     *
     * IMPORTANTE:
     *   - La IA NUNCA elige ni rankea profesores.
     *   - El scoring determinista (DiagnosticRecommendationService) siempre corre.
     *   - Si la IA falla, el wizard continúa sin enriquecimiento (fallback garantizado).
     *   - goal=solve_homework nunca activa IA (integridad académica).
     *   - difficulty_text se anonimiza antes de enviarse (se remueven nombres propios).
     */
    'ai_enabled'             => env('DIAGNOSTIC_AI_ENABLED', false),
    'ai_provider'            => env('DIAGNOSTIC_AI_PROVIDER', 'openai'),  // openai | gemini
    'openai_model'           => env('DIAGNOSTIC_AI_MODEL', 'gpt-4o-mini'),
    'gemini_model'           => env('DIAGNOSTIC_GEMINI_MODEL', 'gemini-2.5-flash-lite'),
    'timeout_seconds'        => (int) env('DIAGNOSTIC_AI_TIMEOUT', 8),
    'max_tokens'             => (int) env('DIAGNOSTIC_AI_MAX_TOKENS', 300),

    // Rate limits — when exceeded, fallback to deterministic scoring
    'daily_limit'            => (int) env('DIAGNOSTIC_AI_DAILY_LIMIT', 50),
    'monthly_limit'          => (int) env('DIAGNOSTIC_AI_MONTHLY_LIMIT', 500),
    // NOT IMPLEMENTED (encontrado auditando Diagnostics, 2026-08-28): ningún
    // código en DiagnosticAiEnrichmentService lee esta clave — cambiarla no
    // tiene ningún efecto hoy. Se deja documentado en vez de borrarla en
    // silencio (podría ser intención de una fase futura), pero activarla
    // esperando un auto-apagado tras errores consecutivos sería un supuesto
    // falso. Si true, auto-disables AI after 3 consecutive errors in one hour
    // — cuando (si) se implemente.
    'auto_disable_on_error'  => (bool) env('DIAGNOSTIC_AI_AUTO_DISABLE_ON_ERROR', false),

    'gemini_api_key'         => env('GEMINI_API_KEY'),
    'openai_api_key'         => env('OPENAI_API_KEY'),
];
