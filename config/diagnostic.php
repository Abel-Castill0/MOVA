<?php

return [
    /*
     * Fase 4A.2 — Feature flag para IA futura.
     *
     * Cuando sea true, DiagnosticAiEnrichmentService intentará enriquecer
     * el diagnóstico con keywords y sugerencias adicionales.
     *
     * IMPORTANTE: La IA NUNCA decide qué profesores recomendar.
     * El scoring determinista siempre se ejecuta primero.
     * La IA solo agrega contexto opcional (keywords, resumen).
     *
     * Requiere: OPENAI_API_KEY configurado (no incluir en git).
     */
    'ai_enabled' => env('DIAGNOSTIC_AI_ENABLED', false),
];
