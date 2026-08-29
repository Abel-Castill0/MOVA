<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\StudentDiagnostic;
use App\Support\LimaClock;
use App\Support\TextRedactor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4A.3 — Enriquecimiento opcional de diagnóstico con IA.
 *
 * Contrato de privacidad (revisado en F-05 — la versión anterior prometía una
 * garantía que la implementación no podía sostener):
 *
 *   GARANTIZADO POR CONSTRUCCIÓN:
 *   - Solo se envía: subject_name, level, difficulty_text REDACTADO, goal, urgency.
 *   - NUNCA se envían campos estructurados identificativos: student_id, parent_id,
 *     nombre del alumno/padre, email, teléfono, school, school_feedback ni IDs
 *     internos. No forman parte del prompt: verificable en buildPrompts().
 *   - La IA NUNCA decide qué profesores recomendar; DiagnosticRecommendationService
 *     es siempre el árbitro final.
 *   - Si falla, el flujo continúa con fallback determinista (nunca lanza excepción).
 *   - ai_usage_logs registra llamadas SIN guardar prompts, respuestas ni difficulty_text.
 *   - goal='solve_homework' se salta la IA por completo.
 *   - Desactivada por defecto (DIAGNOSTIC_AI_ENABLED=false).
 *
 *   BEST-EFFORT, NO GARANTIZADO:
 *   - difficulty_text es texto libre escrito por un padre sobre un menor. Se pasa
 *     por App\Support\TextRedactor (emails, URLs, secuencias numéricas de 6+
 *     dígitos, nombres tras marcador de parentesco, nombres propios capitalizados),
 *     pero NINGUNA redacción léxica puede garantizar la eliminación de todo
 *     identificador. Un nombre de pila en minúscula y sin marcador no es
 *     distinguible de una palabra común. Ver la advertencia en TextRedactor.
 *
 *   Por eso la protección real es la minimización y el feature flag, no la
 *   redacción. Activar la IA en producción es una decisión que debe tomarse
 *   sabiendo esto.
 */
class DiagnosticAiEnrichmentService
{
    private const VALID_LEVELS = ['básico', 'intermedio', 'avanzado', 'desconocido'];
    private const VALID_GOALS  = ['reinforce_topic', 'prepare_exam', 'recover_grades', 'solve_homework', 'continuous_support'];
    private const VALID_FLAGS  = ['homework_request', 'exam_in_hours', 'inappropriate_content', 'vague_description'];

    private ?array $lastTokenUsage = null;

    public function isEnabled(): bool
    {
        return (bool) config('diagnostic.ai_enabled', false);
    }

    /**
     * Enrich the diagnostic with AI-generated metadata.
     * Returns null if disabled, rate-limited, goal=solve_homework, or any error.
     * Side effects: updates ai_* columns on $diagnostic; writes to ai_usage_logs.
     */
    public function enrich(StudentDiagnostic $diagnostic): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($diagnostic->goal === 'solve_homework') {
            return null;
        }

        $provider = config('diagnostic.ai_provider', 'openai');
        $model    = $provider === 'gemini'
            ? config('diagnostic.gemini_model', 'gemini-2.5-flash-lite')
            : config('diagnostic.openai_model', 'gpt-4o-mini');

        // Rate limit checks
        if ($this->isDailyLimitExceeded()) {
            $this->writeLog($diagnostic, $provider, $model, 'skipped', null, 'limit_daily');
            $this->saveFallback($diagnostic);
            return null;
        }

        if ($this->isMonthlyLimitExceeded()) {
            $this->writeLog($diagnostic, $provider, $model, 'skipped', null, 'limit_monthly');
            $this->saveFallback($diagnostic);
            return null;
        }

        $this->lastTokenUsage = null;

        try {
            [$systemPrompt, $userPrompt] = $this->buildPrompts($diagnostic);

            $result = match ($provider) {
                'gemini' => $this->callGemini($systemPrompt, $userPrompt, $diagnostic->id),
                default  => $this->callOpenAi($systemPrompt, $userPrompt, $diagnostic->id),
            };

            if ($result === null) {
                $this->writeLog($diagnostic, $provider, $model, 'fallback', null, 'http_error');
                $this->saveFallback($diagnostic);
                return null;
            }

            $validated = $this->validate($result);
            if ($validated === null) {
                $this->writeLog($diagnostic, $provider, $model, 'fallback', null, 'validation_fail');
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

            $this->writeLog($diagnostic, $provider, $model, 'success', $this->lastTokenUsage);

            return $validated;

        } catch (\Throwable $e) {
            Log::warning('DiagnosticAI: unexpected error', [
                'diagnostic_id' => $diagnostic->id,
                'error'         => $e->getMessage(),
                'provider'      => $provider,
            ]);
            $this->writeLog($diagnostic, $provider, $model, 'error', null, 'exception');
            $this->saveFallback($diagnostic);
            return null;
        }
    }

    private function isDailyLimitExceeded(): bool
    {
        $limit = (int) config('diagnostic.daily_limit', 50);
        if ($limit <= 0) return false;
        // BUG-5: el límite diario debe resetearse a medianoche en Lima, no a
        // medianoche UTC (7pm hora de Lima) — ver LimaClock.
        [$todayStart, $todayEnd] = LimaClock::todayRangeUtc();
        $count = AiUsageLog::where('status', 'success')
            ->where('created_at', '>=', $todayStart)
            ->where('created_at', '<', $todayEnd)
            ->count();
        return $count >= $limit;
    }

    private function isMonthlyLimitExceeded(): bool
    {
        $limit = (int) config('diagnostic.monthly_limit', 500);
        if ($limit <= 0) return false;
        $count = AiUsageLog::where('status', 'success')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();
        return $count >= $limit;
    }

    private function writeLog(
        StudentDiagnostic $diagnostic,
        string $provider,
        string $model,
        string $status,
        ?array $tokens,
        ?string $errorType = null
    ): void {
        try {
            AiUsageLog::create([
                // Bug real encontrado auditando Diagnostics: StudentDiagnostic
                // no tiene atributo `user_id` (el campo real es
                // `parent_user_id`) — esta línea escribía siempre NULL en
                // ai_usage_logs.user_id desde que la tabla existe. Sin
                // impacto observable hoy (AiUsageController::index() nunca
                // proyecta esa columna), pero es un dato roto para cualquier
                // futura vista que quiera atribuir consumo de IA a un padre.
                'user_id'               => $diagnostic->parent_user_id,
                'student_diagnostic_id' => $diagnostic->id,
                'provider'              => $provider,
                'model'                 => $model,
                'status'                => $status,
                'prompt_tokens'         => $tokens['prompt'] ?? null,
                'completion_tokens'     => $tokens['completion'] ?? null,
                'total_tokens'          => $tokens['total'] ?? null,
                'error_type'            => $errorType,
                'created_at'            => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('DiagnosticAI: failed to write usage log', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Builds shared system + user prompts used by both OpenAI and Gemini.
     * Privacy: only anonymized difficulty_text, subject, level, goal, urgency are included.
     *
     * @return array{0: string, 1: string}
     */
    private function buildPrompts(StudentDiagnostic $diagnostic): array
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

        return [$systemPrompt, $userPrompt];
    }

    private function callOpenAi(string $systemPrompt, string $userPrompt, int $diagnosticId): ?array
    {
        $apiKey = config('diagnostic.openai_api_key');
        if (!$apiKey) {
            Log::warning('DiagnosticAI: OPENAI_API_KEY not configured', ['diagnostic_id' => $diagnosticId]);
            return null;
        }

        $timeout = (int) config('diagnostic.timeout_seconds', 8);

        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'           => config('diagnostic.openai_model', 'gpt-4o-mini'),
                'max_tokens'      => (int) config('diagnostic.max_tokens', 300),
                'temperature'     => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
            ]);

        if (!$response->successful()) {
            Log::warning('DiagnosticAI: OpenAI HTTP error', [
                'diagnostic_id' => $diagnosticId,
                'status'        => $response->status(),
            ]);
            return null;
        }

        $usage = $response->json('usage');
        if ($usage) {
            $this->lastTokenUsage = [
                'prompt'     => $usage['prompt_tokens'] ?? null,
                'completion' => $usage['completion_tokens'] ?? null,
                'total'      => $usage['total_tokens'] ?? null,
            ];
        }

        return $this->decodeJsonContent($response->json('choices.0.message.content'));
    }

    private function callGemini(string $systemPrompt, string $userPrompt, int $diagnosticId): ?array
    {
        $apiKey = config('diagnostic.gemini_api_key');
        if (!$apiKey) {
            Log::warning('DiagnosticAI: GEMINI_API_KEY not configured', ['diagnostic_id' => $diagnosticId]);
            return null;
        }

        $model   = config('diagnostic.gemini_model', 'gemini-2.5-flash-lite');
        $timeout = (int) config('diagnostic.timeout_seconds', 8);
        $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $response = Http::timeout($timeout)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'maxOutputTokens'  => (int) config('diagnostic.max_tokens', 300),
                    'temperature'      => 0.1,
                ],
            ]);

        if (!$response->successful()) {
            Log::warning('DiagnosticAI: Gemini HTTP error', [
                'diagnostic_id' => $diagnosticId,
                'status'        => $response->status(),
            ]);
            return null;
        }

        $meta = $response->json('usageMetadata');
        if ($meta) {
            $this->lastTokenUsage = [
                'prompt'     => $meta['promptTokenCount'] ?? null,
                'completion' => $meta['candidatesTokenCount'] ?? null,
                'total'      => $meta['totalTokenCount'] ?? null,
            ];
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        return $this->decodeJsonContent($text);
    }

    private function decodeJsonContent(?string $content): ?array
    {
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

        $level = in_array($data['detected_level'], self::VALID_LEVELS, true)
            ? $data['detected_level']
            : 'desconocido';

        if (!is_string($data['parent_friendly_summary'])) {
            return null;
        }
        $summary = strip_tags($data['parent_friendly_summary']);
        $summary = preg_replace('/[*_`#>]/', '', $summary);
        $summary = mb_substr(trim($summary), 0, 200);

        $suggestedGoal = in_array($data['suggested_goal'], self::VALID_GOALS, true)
            ? $data['suggested_goal']
            : null;

        $riskFlags = [];
        if (is_array($data['risk_flags'])) {
            foreach (array_slice($data['risk_flags'], 0, 3) as $flag) {
                if (is_string($flag) && in_array($flag, self::VALID_FLAGS, true)) {
                    $riskFlags[] = $flag;
                }
            }
        }

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
     * F-05 — La redacción vive ahora en App\Support\TextRedactor, con tests
     * adversariales propios.
     *
     * El regex anterior estaba aquí y exigía DOS palabras capitalizadas con un
     * lookbehind que impedía detectar nombres al inicio del texto. No cubría
     * teléfonos, emails ni DNI, así que "Juan no entiende fracciones" o
     * "llámame al 987654321" se enviaban literales al proveedor externo — pese
     * a que el docblock de esta clase afirmaba lo contrario.
     *
     * Sigue siendo best-effort: ver la advertencia en TextRedactor.
     */
    private function anonymize(string $text): string
    {
        return mb_substr(TextRedactor::redact($text), 0, 400);
    }

    private function saveFallback(StudentDiagnostic $diagnostic): void
    {
        $diagnostic->update(['ai_used_fallback' => true]);
    }
}
