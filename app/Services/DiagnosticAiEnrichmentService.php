<?php

namespace App\Services;

use App\Models\StudentDiagnostic;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4A.3 — Enriquecimiento opcional de diagnóstico con IA.
 *
 * Contrato de privacidad:
 *   - Solo recibe: subject_name, level, difficulty_text ANONIMIZADO, goal, urgency.
 *   - Nunca recibe: student_id, parent_id, nombre del alumno/padre, email, teléfono,
 *     school_feedback, ni IDs internos.
 *   - La IA NUNCA decide qué profesores recomendar.
 *   - DiagnosticRecommendationService es siempre el árbitro final.
 *   - Si falla, el flujo continúa con fallback determinista (nunca lanza excepción).
 */
class DiagnosticAiEnrichmentService
{
    private const VALID_LEVELS = ['básico', 'intermedio', 'avanzado', 'desconocido'];
    private const VALID_GOALS  = ['reinforce_topic', 'prepare_exam', 'recover_grades', 'solve_homework', 'continuous_support'];
    private const VALID_FLAGS  = ['homework_request', 'exam_in_hours', 'inappropriate_content', 'vague_description'];

    public function isEnabled(): bool
    {
        return (bool) config('diagnostic.ai_enabled', false);
    }

    /**
     * Enrich the diagnostic with AI-generated metadata.
     * Returns null if disabled, goal is solve_homework, or any error occurs.
     * Side effects: updates ai_* columns on the $diagnostic model.
     */
    public function enrich(StudentDiagnostic $diagnostic): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Never use AI for homework solving — academic integrity
        if ($diagnostic->goal === 'solve_homework') {
            return null;
        }

        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) {
            Log::warning('DiagnosticAI: OPENAI_API_KEY not configured', ['diagnostic_id' => $diagnostic->id]);
            $this->saveFallback($diagnostic);
            return null;
        }

        try {
            $payload = $this->buildPayload($diagnostic);
            $result  = $this->callOpenAi($apiKey, $payload);

            if ($result === null) {
                $this->saveFallback($diagnostic);
                return null;
            }

            $validated = $this->validate($result);
            if ($validated === null) {
                $this->saveFallback($diagnostic);
                return null;
            }

            $diagnostic->update([
                'ai_keywords'       => $validated['suggested_subject_keywords'],
                'ai_detected_level' => $validated['detected_level'],
                'ai_summary'        => $validated['parent_friendly_summary'],
                'ai_suggested_goal' => $validated['suggested_goal'],
                'ai_risk_flags'     => $validated['risk_flags'],
                'ai_confidence'     => $validated['confidence_score'],
                'ai_used_fallback'  => false,
                'ai_enriched_at'    => now(),
            ]);

            return $validated;

        } catch (\Throwable $e) {
            Log::warning('DiagnosticAI: unexpected error', [
                'diagnostic_id' => $diagnostic->id,
                'error'         => $e->getMessage(),
            ]);
            $this->saveFallback($diagnostic);
            return null;
        }
    }

    private function buildPayload(StudentDiagnostic $diagnostic): array
    {
        $subjectName = $diagnostic->subject?->name ?? 'materia no especificada';
        $level       = $diagnostic->level ?? 'no especificado';
        $anonymized  = $this->anonymize($diagnostic->difficulty_text ?? '');
        $goal        = $diagnostic->goal;
        $urgency     = $diagnostic->urgency;

        $systemPrompt = "Eres un asistente académico que ayuda a identificar necesidades de aprendizaje de estudiantes en Perú. " .
            "Analiza el diagnóstico y responde ÚNICAMENTE en JSON válido con la estructura solicitada. " .
            "RESTRICCIONES: No menciones nombres de personas. No evalúes al estudiante ni al padre. " .
            "No generes contenido académico ni respondas la tarea. No recomiendes profesores específicos. " .
            "Si la descripción es vaga, usa confidence_score bajo (< 40).";

        $userPrompt = "DIAGNÓSTICO:\n" .
            "- Materia: {$subjectName}\n" .
            "- Nivel educativo: {$level}\n" .
            "- Descripción: \"{$anonymized}\"\n" .
            "- Objetivo: {$goal}\n" .
            "- Urgencia: {$urgency}\n\n" .
            "Responde con este JSON exacto (sin texto adicional):\n" .
            '{"suggested_subject_keywords":["keyword1"],"detected_level":"básico","parent_friendly_summary":"Resumen.","suggested_goal":"reinforce_topic","risk_flags":[],"confidence_score":75}';

        return [
            'model'           => config('diagnostic.openai_model', 'gpt-4o-mini'),
            'max_tokens'      => (int) config('diagnostic.max_tokens', 300),
            'temperature'     => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ];
    }

    private function callOpenAi(string $apiKey, array $payload): ?array
    {
        $timeout = (int) config('diagnostic.timeout_seconds', 8);

        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if (!$response->successful()) {
            Log::warning('DiagnosticAI: OpenAI HTTP error', ['status' => $response->status()]);
            return null;
        }

        $content = $response->json('choices.0.message.content');
        if (!$content) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Validates and sanitizes the AI response. Returns null if structurally invalid.
     */
    private function validate(array $data): ?array
    {
        $required = [
            'suggested_subject_keywords', 'detected_level', 'parent_friendly_summary',
            'suggested_goal', 'risk_flags', 'confidence_score',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                return null;
            }
        }

        // keywords: array, max 5, each string max 30, strip HTML
        if (!is_array($data['suggested_subject_keywords'])) {
            return null;
        }
        $keywords = array_values(array_filter(
            array_map(function ($k) {
                if (!is_string($k)) return null;
                $k = mb_substr(strip_tags($k), 0, 30);
                return mb_strlen(trim($k)) > 0 ? trim($k) : null;
            }, array_slice($data['suggested_subject_keywords'], 0, 5))
        ));

        // detected_level: whitelist
        $level = in_array($data['detected_level'], self::VALID_LEVELS, true)
            ? $data['detected_level']
            : 'desconocido';

        // summary: string, max 200, strip HTML and markdown
        if (!is_string($data['parent_friendly_summary'])) {
            return null;
        }
        $summary = strip_tags($data['parent_friendly_summary']);
        $summary = preg_replace('/[*_`#>]/', '', $summary);
        $summary = mb_substr(trim($summary), 0, 200);

        // suggested_goal: whitelist
        $suggestedGoal = in_array($data['suggested_goal'], self::VALID_GOALS, true)
            ? $data['suggested_goal']
            : null;

        // risk_flags: whitelist only
        $riskFlags = [];
        if (is_array($data['risk_flags'])) {
            foreach (array_slice($data['risk_flags'], 0, 3) as $flag) {
                if (is_string($flag) && in_array($flag, self::VALID_FLAGS, true)) {
                    $riskFlags[] = $flag;
                }
            }
        }

        // confidence_score: int 0-100
        $confidence = max(0, min(100, (int) ($data['confidence_score'] ?? 0)));

        return [
            'suggested_subject_keywords' => $keywords,
            'detected_level'             => $level,
            'parent_friendly_summary'    => $summary,
            'suggested_goal'             => $suggestedGoal,
            'risk_flags'                 => $riskFlags,
            'confidence_score'           => $confidence,
        ];
    }

    /**
     * Remove sequences of 2+ capitalized words mid-sentence to anonymize proper nouns.
     */
    private function anonymize(string $text): string
    {
        $text = preg_replace(
            '/(?<=[a-záéíóúüñ ,])\b([A-ZÁÉÍÓÚÜÑ][a-záéíóúüñ]+(?:\s+[A-ZÁÉÍÓÚÜÑ][a-záéíóúüñ]+)+)\b/',
            '[NOMBRE]',
            $text
        );
        return mb_substr($text, 0, 400);
    }

    private function saveFallback(StudentDiagnostic $diagnostic): void
    {
        $diagnostic->update(['ai_used_fallback' => true]);
    }
}
