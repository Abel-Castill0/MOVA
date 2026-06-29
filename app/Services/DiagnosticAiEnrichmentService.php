<?php

namespace App\Services;

use App\Models\StudentDiagnostic;

/**
 * Fase 4A.2 — Placeholder para enriquecimiento de diagnóstico con IA.
 *
 * Actualmente retorna null (feature flag desactivado por defecto).
 *
 * Cuando DIAGNOSTIC_AI_ENABLED=true y se integre OpenAI:
 *   - Input: subject, grade_level, difficulty_text anonimizado, goal, urgency
 *   - Output: keywords[], suggested_extra_subjects[], brief_explanation
 *   - NO se usa para decidir qué profesores recomendar
 *   - El scoring determinista (DiagnosticRecommendationService) es siempre el árbitro final
 *   - Si la IA falla, el flujo continúa sin enriquecimiento (fallback garantizado)
 *   - NUNCA se usa cuando goal = 'solve_homework' (integridad académica)
 */
class DiagnosticAiEnrichmentService
{
    public function enrich(StudentDiagnostic $diagnostic): ?array
    {
        if (!config('diagnostic.ai_enabled', false)) {
            return null;
        }

        // Fase 4A.3: integrar OpenAI aquí
        // No instalar SDK, no llamar API, no guardar API keys hasta esa fase.
        return null;
    }

    public function isEnabled(): bool
    {
        return (bool) config('diagnostic.ai_enabled', false);
    }
}
