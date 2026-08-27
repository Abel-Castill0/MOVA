<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cierra los dos hallazgos P2 registrados en docs/MOVA_DESIGN_AUDIT_FINAL.md
 * durante la auditoría de contrato de negocio de ClassRequests/Create.vue —
 * reclasificados de P3 a P2 tras revisión: "botón deshabilitado" es
 * deduplicación de UI, no idempotencia de request, y "la aceptación bloquea
 * después" no es lo mismo que "no crear un estado inválido desde el
 * principio".
 *
 * 1. Validación temprana de oferta: antes solo se comprobaba
 *    is_active/is_verified en el camino de mentoría — una solicitud normal
 *    podía quedar vinculada a una oferta inactiva o de un profesor no
 *    verificado y nacer sin que nadie autorizado pudiera aceptarla nunca
 *    (solicitud fantasma).
 * 2. Deduplicación de reintento: un timeout de red que reenvía exactamente
 *    la misma intención (mismo alumno, materia, texto, profesor) dentro de
 *    una ventana corta debe colapsar en una sola ClassRequest, no crear una
 *    segunda — el botón deshabilitado en Create.vue cubre el doble-click,
 *    no un reintento de red genuino.
 */
class ClassRequestStoreIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_a_request_cannot_be_bound_to_an_inactive_offer(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [, $profile, $subject] = $this->verifiedTeacherWithSubject();
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Oferta inactiva',
            'is_active' => false,
        ]);

        // Encontrado al preparar el rediseño de Create.vue: el
        // abort_unless() original no era una respuesta Inertia utilizable
        // — Laravel devolvía su página de error HTML genérica y
        // `session('errors')` quedaba `null` (verificado en vivo antes de
        // corregir). Ahora es un ValidationException real. Verificado
        // también el mecanismo: Inertia NO usa un 422+JSON top-level para
        // errores de formulario en un router.post() normal — el propio
        // middleware base de Inertia comparte `errors` desde la SESIÓN
        // como prop de página tras un redirect-back (`assertSessionHasErrors`
        // es la aserción correcta; probar 422+JSON aquí habría sido
        // probar un mecanismo que Inertia no usa en este flujo).
        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect()->assertSessionHasErrors(['class_offer_id' => 'Esta oferta ya no está disponible.']);

        $this->assertSame(0, ClassRequest::count(), 'No debe crearse ninguna solicitud contra una oferta inactiva.');
    }

    public function test_a_request_cannot_be_bound_to_an_offer_from_an_unverified_teacher(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacherUser->id, 'is_verified' => false]);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Oferta de profesor no verificado',
            'is_active' => true,
        ]);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect()->assertSessionHasErrors(['class_offer_id' => 'Esta oferta ya no está disponible.']);

        $this->assertSame(0, ClassRequest::count());
    }

    /**
     * Camino de mentoría en la CREACIÓN (distinto del ya cubierto en
     * MentorshipRequestTest, que prueba la ACEPTACIÓN vía
     * LessonController::store()) — sin test hasta ahora, encontrado al
     * corregir el mismo abort_unless() a ValidationException.
     */
    public function test_a_mentorship_request_cannot_be_bound_to_an_offer_with_no_available_slots(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [, $profile, $subject] = $this->verifiedTeacherWithSubject();
        $profile->update(['mentorship_slots_total' => 1, 'mentorship_slots_taken' => 1]);
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Oferta de mentoría sin cupo',
            'is_active' => true,
        ]);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'is_mentorship' => true,
            'help_needed' => 'Necesita acompañamiento continuo.',
        ])->assertRedirect()->assertSessionHasErrors(['is_mentorship' => 'Este profesor tiene la agenda llena para acompañamiento continuo.']);

        $this->assertSame(0, ClassRequest::count());
    }

    public function test_a_request_bound_to_a_valid_active_offer_still_succeeds(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [, $profile, $subject] = $this->verifiedTeacherWithSubject();
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Oferta activa',
            'is_active' => true,
        ]);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect(route('class-requests.index'));

        $this->assertSame(1, ClassRequest::count());
        $this->assertSame($offer->id, ClassRequest::first()->class_offer_id);
    }

    public function test_resubmitting_the_exact_same_intent_within_the_window_does_not_duplicate(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);

        $payload = [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema, es lo mismo dos veces.',
        ];

        // Simula un reintento de red genuino: el mismo POST llega dos veces.
        $this->actingAs($parent)->post(route('class-requests.store'), $payload)
            ->assertRedirect(route('class-requests.index'));
        $this->actingAs($parent)->post(route('class-requests.store'), $payload)
            ->assertRedirect(route('class-requests.index'));

        $this->assertSame(1, ClassRequest::count(), 'Dos POSTs idénticos en la ventana de dedup deben colapsar en una sola solicitud.');
    }

    public function test_a_genuinely_different_request_right_after_is_not_treated_as_a_duplicate(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $subjectA = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $subjectB = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subjectA->id,
            'help_needed' => 'Necesita ayuda con A.',
        ])->assertRedirect();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subjectB->id,
            'help_needed' => 'Necesita ayuda con B.',
        ])->assertRedirect();

        $this->assertSame(2, ClassRequest::count(), 'Dos intenciones genuinamente distintas (materia distinta) no deben deduplicarse.');
    }

    /**
     * Matriz completa de la huella de deduplicación — pedida explícitamente
     * para que la regla quede mantenible, no solo "funciona en el caso
     * feliz". `duration_minutes` NO forma parte de esta huella a propósito:
     * no existe como campo en `ClassRequestController::store()` en absoluto
     * — la duración se decide recién en `LessonController::store()`, al
     * aceptar, no al crear la solicitud.
     *
     * @dataProvider distinctIntentProvider
     */
    public function test_changing_any_field_of_the_intent_produces_a_new_request(string $label, \Closure $mutate): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $teacherA = $this->verifiedTeacherWithReferralCode();

        $basePayload = [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'is_mentorship' => false,
            'teacher_referral_code' => $teacherA->referral_code,
        ];

        $this->actingAs($parent)->post(route('class-requests.store'), $basePayload)->assertRedirect();
        $this->actingAs($parent)->post(route('class-requests.store'), $mutate($basePayload, $this))->assertRedirect();

        $this->assertSame(2, ClassRequest::count(), "'{$label}' debe producir una segunda solicitud distinta, no deduplicarse.");
    }

    public static function distinctIntentProvider(): array
    {
        return [
            'distinto profesor (código de referido)' => ['distinto profesor', function (array $payload, self $test) {
                $payload['teacher_referral_code'] = $test->verifiedTeacherWithReferralCode()->referral_code;

                return $payload;
            }],
            'distinto is_mentorship' => ['distinto is_mentorship', function (array $payload) {
                $payload['is_mentorship'] = true;

                return $payload;
            }],
            'distinto texto de help_needed' => ['distinto mensaje', function (array $payload) {
                // Deliberado: el texto SÍ participa en la huella de
                // deduplicación hoy. Un cambio mínimo de texto produce una
                // solicitud nueva — es el comportamiento heurístico
                // documentado, no un bug. Ver la nota de "Temporal Semantic
                // Deduplication" en docs/MOVA_DESIGN_AUDIT_FINAL.md.
                $payload['help_needed'] = $payload['help_needed'].' (editado)';

                return $payload;
            }],
        ];
    }

    /**
     * El límite es determinista e inclusivo — "creada durante los últimos
     * 30 segundos" — verificado en ambos bordes, no asumido de la lectura
     * del código: exactamente a los 30s todavía deduplica, a los 31s ya no.
     */
    public function test_the_30_second_window_boundary_is_inclusive_then_expires(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $payload = [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
        ];

        \Illuminate\Support\Carbon::setTestNow('2026-01-01 10:00:00');
        $this->actingAs($parent)->post(route('class-requests.store'), $payload)->assertRedirect();

        \Illuminate\Support\Carbon::setTestNow('2026-01-01 10:00:30');
        $this->actingAs($parent)->post(route('class-requests.store'), $payload)->assertRedirect();
        $this->assertSame(1, ClassRequest::count(), 'A exactamente 30s todavía debe deduplicarse (límite inclusivo).');

        \Illuminate\Support\Carbon::setTestNow('2026-01-01 10:00:31');
        $this->actingAs($parent)->post(route('class-requests.store'), $payload)->assertRedirect();
        $this->assertSame(2, ClassRequest::count(), 'A 31s de la solicitud ORIGINAL (10:00:00) ya debe ser una solicitud nueva.');

        \Illuminate\Support\Carbon::setTestNow();
    }

    /**
     * No es una prueba de concurrencia real (dos requests en paralelo,
     * hilos/procesos distintos) — PHPUnit ejecuta un test por proceso,
     * secuencial. Sigue exactamente el mismo patrón ya establecido en
     * FinancialConcurrencyTest::test_the_same_class_request_cannot_be_accepted_twice()
     * (llamada A, luego llamada B, se comprueba que B ve el estado que A
     * ya confirmó) — es la técnica real que usa esta base de código para
     * probar invariantes de carrera, no un atajo inventado aquí.
     *
     * La garantía real contra concurrencia VERDADERA no viene de este
     * test — viene del ORDEN de las operaciones dentro de la transacción,
     * verificado leyendo ClassRequestController::store(): se bloquea
     * (`lockForUpdate()`) la fila del Student ANTES de buscar el
     * duplicado y ANTES de crear — no al revés. Si dos requests
     * verdaderamente simultáneos llegaran, el segundo bloquea en el lock
     * hasta que el primero confirme, y entonces SÍ ve la fila ya creada
     * por el primero (el motivo por el que el orden lock→check→create, y
     * no check→lock→create, importa — ese segundo orden sí tendría TOCTOU).
     */
    public function test_two_sequential_submissions_for_the_same_intent_never_produce_two_rows_matching_the_locking_order(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $payload = [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Reintento de red simulado: la respuesta del primer POST se perdió.',
        ];

        $this->actingAs($parent)->post(route('class-requests.store'), $payload);
        $this->actingAs($parent)->post(route('class-requests.store'), $payload);

        $this->assertSame(1, ClassRequest::count());
        $this->assertSame($student->id, ClassRequest::sole()->student_id);
    }

    private function verifiedTeacherWithReferralCode(): TeacherProfile
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');

        return TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'hourly_rate' => 20]);
    }

    private function verifiedTeacherWithSubject(): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'hourly_rate' => 20]);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $profile->subjects()->attach($subject->id);

        return [$user, $profile, $subject];
    }

    private function parentWithStudent(): array
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);

        return [$parent, $student];
    }
}
