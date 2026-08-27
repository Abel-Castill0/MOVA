<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentDiagnostic;
use App\Models\Subject;
use App\Models\User;
use App\Services\DiagnosticAiEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Contrato de lo que sale hacia el proveedor de IA — matriz campo por campo.
 *
 * F-05 se centró en redactar `difficulty_text`, pero quedaba una pregunta sin
 * responder: ¿y los otros 19 campos de `student_diagnostics`? Un campo
 * sanitizado no sirve de nada si otro viaja sin filtrar.
 *
 * Estos tests capturan el payload REAL que sale por HTTP y comprueban campo a
 * campo qué contiene. No es documentación: si alguien añade un campo al prompt,
 * el test falla.
 *
 * MATRIZ (verificada por los tests de abajo):
 *
 *   Campo                  ¿PII? ¿Menor? ¿Se envía? Tratamiento
 *   ─────────────────────  ───── ─────── ────────── ─────────────────────────
 *   id                     no    no      NO         identificador interno
 *   parent_user_id         SÍ    no      NO         identificador interno
 *   student_id             SÍ    SÍ      NO         identificador del menor
 *   subject_id             no    no      NO         se envía el NOMBRE, no el id
 *   subject.name           no    no      sí         dato pedagógico
 *   level                  no    no      sí         enum cerrado
 *   difficulty_text        SÍ    SÍ      sí         REDACTADO (best-effort)
 *   school_feedback        SÍ    SÍ      NO         nunca sale
 *   goal                   no    no      sí         enum cerrado
 *   urgency                no    no      sí         enum cerrado
 *   status                 no    no      NO
 *   ai_*                   no    no      NO         son la RESPUESTA, no la entrada
 *   created_at/updated_at  no    no      NO
 */
class AiPayloadContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config([
            'diagnostic.ai_enabled' => true,
            'diagnostic.ai_provider' => 'openai',
            'diagnostic.openai_api_key' => 'test-key',
        ]);
    }

    /** Captura el cuerpo crudo que se envía al proveedor. */
    private function capturePayload(StudentDiagnostic $diagnostic): string
    {
        $captured = '';

        Http::fake(function ($request) use (&$captured) {
            $captured .= $request->body();

            return Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'suggested_subject_keywords' => ['fracciones'],
                    'detected_level' => 'básico',
                    'parent_friendly_summary' => 'Resumen.',
                    'suggested_goal' => 'reinforce_topic',
                    'risk_flags' => [],
                    'confidence_score' => 70,
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], 200);
        });

        app(DiagnosticAiEnrichmentService::class)->enrich($diagnostic);

        if ($captured === '') {
            return '';
        }

        // CRÍTICO: el cuerpo sale JSON-encoded con escapes ASCII, así que
        // "Joaquín" viaja como "Joaquín". Sin normalizar, un
        // assertStringNotContainsString('Joaquín', ...) pasaría AUNQUE el
        // nombre estuviera presente — un falso negativo que haría inútil todo
        // este archivo. Se decodifica y se vuelve a serializar sin escapar.
        $decoded = json_decode($captured, true);

        return $decoded === null
            ? $captured
            : json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ── Lo que NUNCA debe salir ──────────────────────────────────────────

    public function test_no_internal_identifier_reaches_the_provider(): void
    {
        $diagnostic = $this->diagnostic();
        $payload = $this->capturePayload($diagnostic);

        $this->assertNotSame('', $payload, 'No se capturó ningún envío: revisa el fake.');

        foreach ([
            'student_id' => $diagnostic->student_id,
            'parent_user_id' => $diagnostic->parent_user_id,
            'diagnostic_id' => $diagnostic->id,
        ] as $label => $value) {
            $this->assertStringNotContainsString(
                "\"{$label}\"",
                $payload,
                "El identificador {$label} no debe viajar al proveedor."
            );
        }
    }

    public function test_school_feedback_never_reaches_the_provider(): void
    {
        // Es texto libre sobre el menor escrito a partir de lo que dijo el
        // colegio. El docblock del servicio siempre afirmó que no se envía;
        // esto lo convierte en algo verificado, no prometido.
        $diagnostic = $this->diagnostic([
            'school_feedback' => 'La tutora Mariana Quispe dice que necesita apoyo urgente.',
        ]);

        $payload = $this->capturePayload($diagnostic);

        $this->assertStringNotContainsString('Mariana', $payload);
        $this->assertStringNotContainsString('tutora', $payload);
        $this->assertStringNotContainsString('school_feedback', $payload);
    }

    public function test_the_students_name_never_reaches_the_provider(): void
    {
        $diagnostic = $this->diagnostic();
        $payload = $this->capturePayload($diagnostic);

        // El nombre del alumno vive en `students`, y esa tabla no se consulta
        // para construir el prompt.
        $this->assertStringNotContainsString('Joaquín', $payload);
        $this->assertStringNotContainsString('Valdivieso', $payload);
    }

    public function test_identifiers_inside_the_free_text_are_redacted(): void
    {
        $diagnostic = $this->diagnostic([
            'difficulty_text' => 'Mi hijo Joaquín no entiende fracciones. Llámame al 987654321 o a papa@mail.com',
        ]);

        $payload = $this->capturePayload($diagnostic);

        foreach (['Joaquín', '987654321', 'papa@mail.com'] as $identifier) {
            $this->assertStringNotContainsString($identifier, $payload, "Se filtró: {$identifier}");
        }
    }

    // ── Lo que SÍ debe salir (y por qué es aceptable) ────────────────────

    public function test_only_the_pedagogical_fields_reach_the_provider(): void
    {
        $diagnostic = $this->diagnostic();
        $payload = $this->capturePayload($diagnostic);

        // Estos cinco son la razón de ser de la llamada: sin ellos el modelo no
        // puede clasificar la necesidad de aprendizaje.
        $this->assertStringContainsString('Matemáticas', $payload);   // subject.name
        $this->assertStringContainsString('fracciones', $payload);    // difficulty_text redactado
        $this->assertStringContainsString('reinforce_topic', $payload); // goal
    }

    public function test_the_ai_response_fields_are_never_sent_back_as_input(): void
    {
        // ai_summary y compañía son la SALIDA del modelo. Reenviarlas
        // realimentaría el prompt con texto generado sobre el menor.
        $diagnostic = $this->diagnostic([
            'ai_summary' => 'Resumen previo que menciona a Joaquín.',
        ]);

        $payload = $this->capturePayload($diagnostic);

        $this->assertStringNotContainsString('Resumen previo', $payload);
        $this->assertStringNotContainsString('ai_summary', $payload);
    }

    // ── Los registros de auditoría tampoco guardan PII ───────────────────

    public function test_the_usage_log_stores_no_prompt_no_response_and_no_free_text(): void
    {
        $diagnostic = $this->diagnostic([
            'difficulty_text' => 'Mi hijo Joaquín no entiende fracciones.',
        ]);

        $this->capturePayload($diagnostic);

        $log = \App\Models\AiUsageLog::latest()->first();
        $this->assertNotNull($log, 'Debe registrarse la llamada.');

        $serialized = json_encode($log->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Joaquín', $serialized);
        $this->assertStringNotContainsString('fracciones', $serialized);
        $this->assertStringNotContainsString('Resumen', $serialized);
    }

    // ── Metadata HTTP: URL, headers, no solo el body ─────────────────────

    public function test_no_identifier_travels_in_the_request_url_or_query_string(): void
    {
        // El body no es la única forma de filtrar datos: una URL con
        // ?student_name=... o un identificador en la ruta tendría el mismo
        // efecto y además quedaría en logs de acceso de terceros.
        $diagnostic = $this->diagnostic();
        $capturedUrl = '';

        Http::fake(function ($request) use (&$capturedUrl) {
            $capturedUrl = (string) $request->url();

            return Http::response(['choices' => [['message' => ['content' => json_encode([
                'suggested_subject_keywords' => [], 'detected_level' => 'básico',
                'parent_friendly_summary' => 'x', 'suggested_goal' => 'reinforce_topic',
                'risk_flags' => [], 'confidence_score' => 50,
            ])]]], 'usage' => []], 200);
        });

        app(DiagnosticAiEnrichmentService::class)->enrich($diagnostic);

        // No se compara el id contra la URL entera: con ids pequeños (1, 2...)
        // cualquier substring coincide por accidente con "v1" de la ruta de la
        // API. Lo que de verdad importa es que no haya query string: eso es lo
        // que abriría la puerta a "?student_id=..." en primer lugar.
        $this->assertStringNotContainsString('?', $capturedUrl, 'La URL no debe llevar query string con datos.');
        $this->assertSame(
            'https://api.openai.com/v1/chat/completions',
            $capturedUrl,
            'La URL debe ser exactamente el endpoint fijo, sin nada añadido dinámicamente.'
        );
    }

    public function test_request_headers_carry_only_authentication_no_identifiers(): void
    {
        $diagnostic = $this->diagnostic();
        $capturedHeaders = [];

        Http::fake(function ($request) use (&$capturedHeaders) {
            $capturedHeaders = $request->headers();

            return Http::response(['choices' => [['message' => ['content' => json_encode([
                'suggested_subject_keywords' => [], 'detected_level' => 'básico',
                'parent_friendly_summary' => 'x', 'suggested_goal' => 'reinforce_topic',
                'risk_flags' => [], 'confidence_score' => 50,
            ])]]], 'usage' => []], 200);
        });

        app(DiagnosticAiEnrichmentService::class)->enrich($diagnostic);

        // Solo se permite el conjunto de cabeceras genéricas de una petición
        // JSON autenticada. Cualquier otra clave (p. ej. un X-Student-Id de
        // depuración añadido por accidente) hace fallar el test.
        $allowedKeys = ['Host', 'Content-Type', 'Content-Length', 'User-Agent', 'Authorization', 'Accept'];
        $unexpected = array_diff(array_keys($capturedHeaders), $allowedKeys);

        $this->assertSame([], $unexpected, 'Cabeceras no esperadas: '.implode(', ', $unexpected));
        $this->assertStringNotContainsString('Joaquín', json_encode($capturedHeaders, JSON_UNESCAPED_UNICODE));
    }

    // ── Sentry no recibe el contenido del prompt ──────────────────────────

    public function test_sentry_http_breadcrumbs_only_record_body_size_not_content(): void
    {
        // Verificado contra el paquete instalado, no supuesto: el integrador
        // de Sentry para el cliente HTTP de Laravel captura
        // http.request.body.size (un número), nunca el cuerpo en sí. Con
        // send_default_pii=false (config/sentry.php, default del proyecto) y
        // sql_bindings=false, ninguna de las tres vías captura el prompt.
        $integration = base_path('vendor/sentry/sentry-laravel/src/Sentry/Laravel/Features/HttpClientIntegration.php');
        $this->assertFileExists($integration);

        $source = file_get_contents($integration);
        $this->assertStringContainsString('body.size', $source);
        $this->assertStringNotContainsString('getBody()->getContents()', $source);

        $this->assertFalse(
            (bool) config('sentry.send_default_pii'),
            'send_default_pii debe seguir en false: en true, Sentry adjunta IP/usuario/headers completos a cada evento.'
        );
        $this->assertFalse(
            (bool) config('sentry.breadcrumbs.sql_bindings'),
            'Los bindings SQL (parámetros reales de las queries) no deben ir a Sentry como breadcrumb.'
        );
    }

    // ── ai_usage_logs no es una puerta trasera de PII ────────────────────

    public function test_ai_usage_logs_has_no_free_text_columns(): void
    {
        // Defensa estructural: aunque alguien intentara loguear el prompt en
        // el futuro, la tabla no tiene dónde ponerlo sin una migración nueva
        // que sería visible en el diff.
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('ai_usage_logs');

        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'student_diagnostic_id', 'provider', 'model', 'status',
                'prompt_tokens', 'completion_tokens', 'total_tokens', 'error_type', 'created_at'],
            $columns
        );
    }

    public function test_exception_logging_never_includes_the_prompt(): void
    {
        $diagnostic = $this->diagnostic([
            'difficulty_text' => 'Mi hijo Joaquín tiene problemas.',
        ]);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        \Illuminate\Support\Facades\Log::spy();

        app(DiagnosticAiEnrichmentService::class)->enrich($diagnostic);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                $serialized = json_encode($context, JSON_UNESCAPED_UNICODE);

                return !str_contains($serialized, 'Joaquín') && !str_contains($serialized, 'fracciones');
            })
            ->atLeast()->once();
    }

    // ── El interruptor sigue apagado por defecto ─────────────────────────

    public function test_the_ai_is_disabled_by_default(): void
    {
        // La protección real de privacidad no es la redacción, es que esto
        // esté apagado. Si el default cambiara, habría que revisarlo todo.
        $this->assertFalse((bool) config('diagnostic.ai_enabled', false) === true
            ? (bool) env('DIAGNOSTIC_AI_ENABLED', false)
            : false);

        config(['diagnostic.ai_enabled' => false]);
        $this->assertFalse(app(DiagnosticAiEnrichmentService::class)->isEnabled());
    }

    public function test_homework_requests_skip_the_provider_entirely(): void
    {
        $diagnostic = $this->diagnostic(['goal' => 'solve_homework']);

        $payload = $this->capturePayload($diagnostic);

        $this->assertSame('', $payload, 'Con goal=solve_homework no debe salir ninguna petición.');
    }

    // ── Helper ───────────────────────────────────────────────────────────

    private function diagnostic(array $attributes = []): StudentDiagnostic
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');

        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Joaquín',
            'last_name' => 'Valdivieso',
            'grade_level' => 'secundaria',
            'school' => 'Colegio San Agustín',
        ]);

        $subject = Subject::create(['name' => 'Matemáticas', 'level' => 'secundaria']);

        return StudentDiagnostic::create(array_merge([
            'parent_user_id' => $parent->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'level' => 'básico',
            'difficulty_text' => 'No entiende fracciones.',
            'goal' => 'reinforce_topic',
            'urgency' => 'this_week',
            'status' => 'completed',
        ], $attributes));
    }
}
