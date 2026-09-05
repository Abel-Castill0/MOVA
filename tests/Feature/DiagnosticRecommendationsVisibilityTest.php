<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\Student;
use App\Models\StudentDiagnostic;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * H-08 — Las recomendaciones del diagnóstico llegan a la pantalla.
 *
 * `DiagnosticRecommendationService` calculaba y persistía hasta 5
 * recomendaciones con su ranking y sus razones, y `DiagnosticsController::results()`
 * devolvía `'recommendations' => []` FIJO sobre una vista que ni siquiera
 * declaraba esa prop (docs/MOVA_SYSTEM_MAP.md H-08).
 *
 * El algoritmo NO se ha tocado. Estos tests cubren el recorrido completo
 * (diagnóstico → resultado) y, sobre todo, la revalidación de elegibilidad en
 * la lectura: el ranking es una foto, y entre que se calcula y el padre entra,
 * un profesor puede haber sido suspendido.
 */
class DiagnosticRecommendationsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role);
        }

        $this->subject = Subject::firstOrCreateByName('Matemática');
    }

    /**
     * @return array{0: User, 1: Student}
     */
    private function parentWithStudent(): array
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'grade_level' => 'secundaria',
        ]);

        return [$parent, $student];
    }

    /**
     * Un profesor elegible con una oferta activa: la materia prima del
     * algoritmo. Debe pasar todos los filtros de compute().
     */
    private function eligibleTeacherWithOffer(string $name = 'Profe Elegible'): array
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('teacher');

        $profile = TeacherProfile::create([
            'user_id' => $user->id,
            'hourly_rate' => 25,
            'is_verified' => true,
            'bio' => str_repeat('Enseño con paciencia y método. ', 12),
        ]);
        $profile->subjects()->sync([$this->subject->id => ['specific_rate' => null]]);

        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $this->subject->id,
            'title' => 'Refuerzo de álgebra para secundaria',
            'description' => 'Clases enfocadas en ecuaciones y funciones.',
            'is_active' => true,
        ]);

        return [$user, $profile, $offer];
    }

    private function runDiagnostic(User $parent, Student $student): StudentDiagnostic
    {
        $this->actingAs($parent)->post(route('diagnostics.store'), [
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'difficulty_text' => 'Le cuesta resolver ecuaciones de primer grado.',
            'goal' => 'prepare_exam',
            'urgency' => 'this_week',
        ])->assertSessionHasNoErrors();

        return StudentDiagnostic::where('parent_user_id', $parent->id)->latest('id')->firstOrFail();
    }

    // ── Recorrido completo ───────────────────────────────────────────────

    public function test_the_parent_sees_the_recommendations_that_were_computed(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [$teacher] = $this->eligibleTeacherWithOffer();

        $diagnostic = $this->runDiagnostic($parent, $student);

        // El servicio persistió el ranking durante el store().
        $this->assertGreaterThan(0, $diagnostic->recommendations()->count());

        $this->actingAs($parent)
            ->get(route('diagnostics.results', $diagnostic))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Diagnostics/Results')
                ->has('recommendations', 1)
                ->where('recommendations.0.teacher_name', $teacher->name)
            );
    }

    public function test_the_reasons_reach_the_screen_in_plain_language(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $this->eligibleTeacherWithOffer();

        $diagnostic = $this->runDiagnostic($parent, $student);

        $this->actingAs($parent)
            ->get(route('diagnostics.results', $diagnostic))
            ->assertInertia(function ($page) {
                $reasons = $page->toArray()['props']['recommendations'][0]['reasons'];

                $this->assertNotEmpty($reasons);
                $this->assertContains('Profesor verificado por MOVA', $reasons);
            });
    }

    /**
     * El `score` interno (0–100) no significa nada para un padre. Se traduce a
     * una banda y el número crudo NO viaja al cliente.
     */
    public function test_the_raw_technical_score_is_never_exposed(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $this->eligibleTeacherWithOffer();

        $diagnostic = $this->runDiagnostic($parent, $student);

        $this->actingAs($parent)
            ->get(route('diagnostics.results', $diagnostic))
            ->assertInertia(function ($page) {
                $recommendation = $page->toArray()['props']['recommendations'][0];

                $this->assertArrayNotHasKey('score', $recommendation);
                $this->assertNotEmpty($recommendation['match_label']);
            });
    }

    // ── Solo profesores REALMENTE elegibles ──────────────────────────────

    public function test_a_teacher_suspended_after_the_ranking_is_not_recommended(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [$teacher] = $this->eligibleTeacherWithOffer();

        $diagnostic = $this->runDiagnostic($parent, $student);
        $this->assertSame(1, $diagnostic->recommendations()->count());

        // El mundo cambia entre el cálculo y la visita.
        $teacher->update(['suspended_at' => now(), 'suspension_reason' => 'Incumplimiento']);

        $this->actingAs($parent)
            ->get(route('diagnostics.results', $diagnostic))
            ->assertInertia(fn ($page) => $page->has('recommendations', 0));
    }

    public function test_a_teacher_unverified_after_the_ranking_is_not_recommended(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [, $profile] = $this->eligibleTeacherWithOffer();

        $diagnostic = $this->runDiagnostic($parent, $student);

        $profile->update(['is_verified' => false, 'rejected_at' => now()]);

        $this->actingAs($parent)
            ->get(route('diagnostics.results', $diagnostic))
            ->assertInertia(fn ($page) => $page->has('recommendations', 0));
    }

    public function test_a_deactivated_offer_is_not_recommended(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [, , $offer] = $this->eligibleTeacherWithOffer();

        $diagnostic = $this->runDiagnostic($parent, $student);

        $offer->update(['is_active' => false]);

        $this->actingAs($parent)
            ->get(route('diagnostics.results', $diagnostic))
            ->assertInertia(fn ($page) => $page->has('recommendations', 0));
    }

    /**
     * Filtrar en la lectura NO borra el registro de qué se calculó y por qué:
     * las filas siguen ahí para auditoría.
     */
    public function test_filtering_does_not_delete_the_stored_ranking(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [$teacher] = $this->eligibleTeacherWithOffer();

        $diagnostic = $this->runDiagnostic($parent, $student);
        $teacher->update(['suspended_at' => now()]);

        $this->actingAs($parent)->get(route('diagnostics.results', $diagnostic));

        $this->assertSame(1, $diagnostic->recommendations()->count());
    }

    // ── Estado vacío real ────────────────────────────────────────────────

    public function test_a_diagnostic_without_eligible_teachers_renders_an_empty_list(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        // Ningún profesor con oferta.

        $diagnostic = $this->runDiagnostic($parent, $student);

        $this->actingAs($parent)
            ->get(route('diagnostics.results', $diagnostic))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('recommendations', 0));
    }

    // ── Autorización ─────────────────────────────────────────────────────

    public function test_another_parent_cannot_see_someone_elses_recommendations(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $this->eligibleTeacherWithOffer();
        $diagnostic = $this->runDiagnostic($parent, $student);

        [$stranger] = $this->parentWithStudent();

        $this->actingAs($stranger)
            ->get(route('diagnostics.results', $diagnostic))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_see_recommendations(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $this->eligibleTeacherWithOffer();
        $diagnostic = $this->runDiagnostic($parent, $student);

        // runDiagnostic() dejó la sesión autenticada como el padre: hay que
        // cerrarla de verdad para probar el acceso anónimo.
        Auth::logout();
        $this->flushSession();

        $this->get(route('diagnostics.results', $diagnostic))
            ->assertRedirect(route('login'));
    }

    // ── No recalcular ────────────────────────────────────────────────────

    /**
     * Ver la pantalla es de SOLO LECTURA. Recalcular en cada visita cambiaría
     * el orden bajo los pies del usuario (el ranking depende de la hora, vía
     * scoreAvailability) y borraría/recrearía filas en cada carga.
     */
    public function test_viewing_the_results_never_recomputes_the_ranking(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $this->eligibleTeacherWithOffer();

        $diagnostic = $this->runDiagnostic($parent, $student);
        $originalIds = $diagnostic->recommendations()->pluck('id')->all();

        $this->actingAs($parent)->get(route('diagnostics.results', $diagnostic));
        $this->actingAs($parent)->get(route('diagnostics.results', $diagnostic));

        $this->assertSame(
            $originalIds,
            $diagnostic->recommendations()->pluck('id')->all(),
            'Las filas deben ser las mismas: ni se borran ni se recrean al mirar.'
        );
    }
}
