<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use App\Services\MoviKnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Movi local: responde con conocimiento propio de MOVA, sin proveedor externo ni clave.
 */
class MoviLocalAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('chatbot.enabled', true);
        Config::set('chatbot.provider', 'local');
        Config::set('chatbot.gemini.api_key', null);
        Config::set('legal.support_email', 'soporte@example.test');
        Http::fake();
    }

    private function ask(string $message, ?User $as = null): string
    {
        $request = $as ? $this->actingAs($as) : $this;
        $response = $request->postJson(route('chatbot.message'), ['message' => $message])->assertOk()->assertJson(['ok' => true]);

        return (string) $response->json('reply');
    }

    public function test_it_answers_without_any_external_call(): void
    {
        $this->assertStringContainsString('Movi', $this->ask('Hola'));
        Http::assertNothingSent();
    }

    public function test_it_is_disabled_when_the_flag_is_off(): void
    {
        Config::set('chatbot.enabled', false);

        $this->postJson(route('chatbot.message'), ['message' => 'Hola'])->assertStatus(503);
    }

    public function test_how_to_request_a_class_points_to_the_request_flow(): void
    {
        $reply = $this->ask('¿Cómo busco un profesor para mi nivel?');

        $this->assertStringContainsString('[Solicitar Clase]', $reply);
    }

    public function test_credits_answer_uses_the_configured_price_and_packages(): void
    {
        $reply = $this->ask('¿Cómo funciona el sistema de créditos en MOVA?');

        $this->assertStringContainsString('S/ 2.00', $reply);
        $this->assertStringContainsString('Inicio (5 créditos, S/ 10)', $reply);
    }

    public function test_refund_answer_states_the_seven_day_policy_and_the_support_email(): void
    {
        $reply = $this->ask('¿Puedo pedir un reembolso?');

        $this->assertStringContainsString('7 días', $reply);
        $this->assertStringContainsString('soporte@example.test', $reply);
    }

    public function test_becoming_a_teacher_mentions_manual_verification(): void
    {
        $reply = $this->ask('Quiero enseñar en MOVA, ¿qué requisitos necesito?');

        $this->assertStringContainsString('verificado', $reply);
        $this->assertStringContainsString('[Voluntariado]', $reply);
    }

    public function test_founders_are_named(): void
    {
        $reply = $this->ask('¿Quiénes crearon MOVA?');

        $this->assertStringContainsString('Elias J. Paz', $reply);
        $this->assertStringContainsString('Abel Castillo', $reply);
    }

    public function test_homework_is_never_solved_and_is_redirected_to_a_teacher(): void
    {
        foreach (['Resuelve 2x + 3 = 11', '¿Cuánto es 15 + 27?', 'Necesito ayuda con mi tarea de álgebra, ejercicio 4'] as $question) {
            $reply = $this->ask($question);

            $this->assertStringContainsString('[Solicitar Clase]', $reply, $question);
            $this->assertStringNotContainsString('x = 4', $reply);
            $this->assertStringNotContainsString('42', $reply);
        }
    }

    public function test_a_clear_platform_question_beats_the_homework_detector(): void
    {
        $this->assertStringContainsString('[Solicitar Clase]', $this->ask('¿Cómo pido una clase de matemáticas para mi hijo?'));
        $this->assertStringContainsString('7 días', $this->ask('Quiero un reembolso de mis créditos'));
    }

    public function test_unknown_questions_get_a_helpful_fallback_with_the_support_email(): void
    {
        $reply = $this->ask('xyzzy plugh');

        $this->assertStringContainsString('soporte@example.test', $reply);
    }

    public function test_teacher_gets_a_credits_shortcut(): void
    {
        Role::findOrCreate('teacher', 'web');
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        // Primero anónimo: una vez hecho actingAs() la sesión de la prueba queda autenticada.
        $this->assertStringNotContainsString('[Mis créditos]', $this->ask('¿Cómo recargo créditos?'));
        $this->assertStringContainsString('[Mis créditos]', $this->ask('¿Cómo recargo créditos?', $teacher));
    }

    public function test_subjects_come_from_the_database(): void
    {
        Subject::create(['name' => 'Ajedrez', 'level' => 'todos']);

        $this->assertStringContainsString('Ajedrez', $this->ask('¿Qué materias tienen?'));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function naturalQuestions(): array
    {
        return [
            'saludo' => ['Hola buenas tardes', 'greeting'],
            'gracias' => ['muchas gracias por la ayuda', 'thanks'],
            'adios' => ['chao, hasta luego', 'bye'],
            'que es' => ['¿Qué es MOVA?', 'what_is_mova'],
            'pido clase conjugado' => ['Hola, ¿cómo pido una clase para mi hijo?', 'request_class'],
            'busco profesor' => ['busco un profesor de inglés para mi niña', 'request_class'],
            'agendar' => ['quiero agendar mi primera clase', 'request_class'],
            'solicitud' => ['¿Dónde hago la solicitud de clase?', 'request_class'],
            'cancelo' => ['¿Cómo cancelo una clase?', 'cancel_class'],
            'reprogramar' => ['necesito reprogramar la clase de mañana', 'cancel_class'],
            'creditos' => ['¿Cómo funcionan los créditos?', 'credits'],
            'recargar' => ['¿cómo recargo mi saldo?', 'credits'],
            'paquetes' => ['¿qué paquetes de recarga hay?', 'credits'],
            'precio' => ['¿Cuánto cuesta la clase?', 'parent_payment'],
            'como pago' => ['¿cómo le pago al profesor?', 'parent_payment'],
            'reembolso' => ['quiero que me devuelvan mi dinero', 'refund'],
            'reembolso2' => ['¿hacen reembolsos?', 'refund'],
            'entrar clase' => ['no puedo entrar a la videollamada', 'join_class'],
            'camara' => ['mi cámara no funciona en la clase', 'join_class'],
            'ser profe' => ['quiero ser profesor en MOVA', 'become_teacher'],
            'ensenar' => ['¿Puedo enseñar matemáticas aquí?', 'become_teacher'],
            'voluntariado' => ['¿cómo postulo al voluntariado?', 'become_teacher'],
            'seguro' => ['¿Es seguro para mis hijos?', 'verified'],
            'antecedentes' => ['¿revisan los antecedentes de los profesores?', 'verified'],
            'password' => ['olvidé mi contraseña', 'account_access'],
            'login' => ['no puedo iniciar sesión', 'account_access'],
            'correo' => ['no me llegó el correo de verificación', 'verify_email'],
            'borrar' => ['quiero eliminar mi cuenta', 'delete_account'],
            'datos' => ['¿qué hacen con mis datos personales?', 'delete_account'],
            'reclamo' => ['quiero poner un reclamo', 'complaints'],
            'libro' => ['¿dónde está el libro de reclamaciones?', 'complaints'],
            'soporte' => ['necesito hablar con soporte', 'contact'],
            'fundadores' => ['¿quién creó MOVA?', 'founders'],
            'horario' => ['¿tienen horarios en fin de semana?', 'schedule'],
            'materias' => ['¿qué materias enseñan?', 'subjects'],
            'alumno' => ['¿cómo registro a mi hijo como alumno?', 'student_account'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('naturalQuestions')]
    public function test_it_understands_natural_spanish_phrasings(string $question, string $expectedIntent): void
    {
        $this->assertSame($expectedIntent, app(MoviKnowledgeBase::class)->answer($question)['intent'], $question);
    }

    public function test_answers_stay_short_and_use_only_known_action_markers(): void
    {
        $known = ['[Crear cuenta]', '[Solicitar Clase]', '[Voluntariado]', '[Ver profesores]', '[Recuperar contraseña]', '[Libro de Reclamaciones]', '[Privacidad]', '[Mis créditos]'];
        $kb = app(MoviKnowledgeBase::class);

        foreach (['hola', 'qué es mova', 'cómo pido una clase', 'créditos', 'precio', 'reembolso', 'cancelar clase', 'cómo entro a la clase', 'ser profesor', 'es seguro', 'olvidé mi contraseña', 'no llega el correo', 'eliminar cuenta', 'reclamo', 'contacto', 'fundadores', 'horario', 'materias'] as $q) {
            $reply = $kb->answer($q, 'teacher')['reply'];
            preg_match_all('/\[[^\]]+\]/u', $reply, $markers);
            foreach ($markers[0] as $marker) {
                $this->assertContains($marker, $known, "Marcador desconocido en «{$q}»: {$marker}");
            }
            $this->assertLessThanOrEqual(900, mb_strlen($reply), "Respuesta demasiado larga para «{$q}»");
        }
    }
}
