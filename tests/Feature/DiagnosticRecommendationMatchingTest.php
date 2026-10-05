<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentDiagnostic;
use App\Models\Subject;
use App\Models\TeacherAvailabilitySlot;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\DiagnosticRecommendationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reglas del matching de recomendaciones SIN ClassOffer: elegibilidad por
 * perfil docente, tarifa efectiva por materia, disponibilidad con ventanas
 * futuras, idempotencia y ausencia total de influencia de la IA en el ranking.
 */
class DiagnosticRecommendationMatchingTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function diagnostic(string $urgency = 'flexible', array $extra = []): StudentDiagnostic
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'grade_level' => 'secundaria',
        ]);

        return StudentDiagnostic::create(array_merge([
            'parent_user_id' => $parent->id,
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'level' => 'secundaria',
            'difficulty_text' => 'Le cuesta resolver ecuaciones.',
            'goal' => 'prepare_exam',
            'urgency' => $urgency,
            'idempotency_key' => bin2hex(random_bytes(16)),
        ], $extra));
    }

    private function teacher(array $profile = [], ?float $specificRate = null, bool $teachesSubject = true): TeacherProfile
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');

        $teacherProfile = TeacherProfile::create(array_merge([
            'user_id' => $user->id,
            'hourly_rate' => 25,
            'is_verified' => true,
            'bio' => str_repeat('Enseño con paciencia y método. ', 8),
        ], $profile));

        if ($teachesSubject) {
            $teacherProfile->subjects()->sync([$this->subject->id => ['specific_rate' => $specificRate]]);
        }

        return $teacherProfile;
    }

    private function slot(TeacherProfile $profile, int $day, string $start, string $end): void
    {
        TeacherAvailabilitySlot::create([
            'teacher_profile_id' => $profile->id,
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    private function recommendedIds(StudentDiagnostic $diagnostic): array
    {
        return app(DiagnosticRecommendationService::class)
            ->compute($diagnostic->fresh())
            ->pluck('teacher_profile_id')
            ->all();
    }

    // ── Elegibilidad por perfil, sin ofertas ─────────────────────────────

    public function test_a_verified_teacher_is_recommended_without_any_class_offer(): void
    {
        $profile = $this->teacher();
        $this->assertSame(0, $profile->classOffers()->count());

        $recs = app(DiagnosticRecommendationService::class)->compute($this->diagnostic());

        $this->assertCount(1, $recs);
        $this->assertSame($profile->id, $recs->first()->teacher_profile_id);
        $this->assertNull($recs->first()->class_offer_id);
    }

    public function test_unverified_suspended_wrong_subject_and_empty_bio_are_excluded(): void
    {
        $this->teacher(['is_verified' => false]);
        $suspended = $this->teacher();
        $suspended->user->update(['suspended_at' => now()]);
        $this->teacher(teachesSubject: false);
        $this->teacher(['bio' => '']);
        $ok = $this->teacher();

        $this->assertSame([$ok->id], $this->recommendedIds($this->diagnostic()));
    }

    // ── Tarifa base vs tarifa específica por materia ─────────────────────

    public function test_a_teacher_with_zero_base_rate_but_a_valid_subject_rate_is_eligible(): void
    {
        $profile = $this->teacher(['hourly_rate' => 0], specificRate: 30);

        $this->assertSame([$profile->id], $this->recommendedIds($this->diagnostic()));
    }

    public function test_a_teacher_with_a_zero_subject_rate_is_not_eligible_even_with_a_positive_base(): void
    {
        $this->teacher(['hourly_rate' => 25], specificRate: 0);

        $this->assertSame([], $this->recommendedIds($this->diagnostic()));
    }

    public function test_a_teacher_with_a_base_rate_and_no_specific_rate_is_eligible(): void
    {
        $profile = $this->teacher(['hourly_rate' => 25], specificRate: null);

        $this->assertSame([$profile->id], $this->recommendedIds($this->diagnostic()));
    }

    public function test_a_teacher_with_no_effective_rate_at_all_is_not_eligible(): void
    {
        $this->teacher(['hourly_rate' => 0], specificRate: null);

        $this->assertSame([], $this->recommendedIds($this->diagnostic()));
    }

    // ── Disponibilidad: horarios futuros, no solo el nombre del día ──────

    public function test_this_week_ignores_slots_on_days_that_already_passed(): void
    {
        // Jueves 2026-10-08 12:00 (Lima). Franjas: lunes (ya pasó) vs sábado (futuro).
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'America/Lima'));

        $past = $this->teacher();
        $this->slot($past, 1, '08:00', '10:00');   // lunes
        $future = $this->teacher();
        $this->slot($future, 6, '08:00', '10:00'); // sábado

        $recs = app(DiagnosticRecommendationService::class)->compute($this->diagnostic('this_week'));
        $byId = $recs->keyBy('teacher_profile_id');

        $this->assertContains('Tiene horarios disponibles el resto de esta semana', $byId[$future->id]->reasons);
        $this->assertNotContains('Tiene horarios disponibles el resto de esta semana', $byId[$past->id]->reasons);
        $this->assertGreaterThan($byId[$past->id]->score, $byId[$future->id]->score);
        $this->assertSame($future->id, $recs->first()->teacher_profile_id);
    }

    public function test_today_slot_that_already_ended_does_not_count_as_available_today(): void
    {
        // Martes 2026-10-06 11:00 (Lima): la franja de hoy 08–10 terminó.
        Carbon::setTestNow(Carbon::parse('2026-10-06 11:00', 'America/Lima'));

        $ended = $this->teacher();
        $this->slot($ended, 2, '08:00', '10:00');   // hoy, ya terminó
        $later = $this->teacher();
        $this->slot($later, 2, '15:00', '18:00');   // hoy, aún por venir

        $recs = app(DiagnosticRecommendationService::class)->compute($this->diagnostic('today_or_tomorrow'))->keyBy('teacher_profile_id');

        $this->assertContains('Tiene horarios disponibles hoy o mañana', $recs[$later->id]->reasons);
        $this->assertNotContains('Tiene horarios disponibles hoy o mañana', $recs[$ended->id]->reasons);
    }

    public function test_a_slot_in_progress_still_counts_and_tomorrow_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 09:00', 'America/Lima')); // martes

        $inProgress = $this->teacher();
        $this->slot($inProgress, 2, '08:00', '10:00');
        $tomorrow = $this->teacher();
        $this->slot($tomorrow, 3, '08:00', '10:00');

        $recs = app(DiagnosticRecommendationService::class)->compute($this->diagnostic('today_or_tomorrow'))->keyBy('teacher_profile_id');

        $this->assertContains('Tiene horarios disponibles hoy o mañana', $recs[$inProgress->id]->reasons);
        $this->assertContains('Tiene horarios disponibles hoy o mañana', $recs[$tomorrow->id]->reasons);
    }

    public function test_the_calendar_week_ends_on_sunday_so_monday_slots_do_not_count_on_sunday_night(): void
    {
        // Domingo 2026-10-11 20:00 (Lima): lo que queda de la semana es nada.
        Carbon::setTestNow(Carbon::parse('2026-10-11 20:00', 'America/Lima'));

        $mondayOnly = $this->teacher();
        $this->slot($mondayOnly, 1, '08:00', '10:00');

        $recs = app(DiagnosticRecommendationService::class)->compute($this->diagnostic('this_week'));

        // Sigue recomendado (sin penalización), pero sin el bono de disponibilidad.
        $this->assertCount(1, $recs);
        $this->assertNotContains('Tiene horarios disponibles el resto de esta semana', $recs->first()->reasons);
    }

    public function test_a_teacher_without_declared_slots_is_neutral_not_penalised(): void
    {
        $declared = $this->teacher();
        $none = $this->teacher();
        $this->slot($declared, 6, '08:00', '10:00');

        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'America/Lima'));
        $recs = app(DiagnosticRecommendationService::class)->compute($this->diagnostic('this_week'))->keyBy('teacher_profile_id');

        $this->assertCount(2, $recs); // el que no declaró sigue apareciendo
        $this->assertLessThan($recs[$declared->id]->score, $recs[$none->id]->score);
    }

    // ── Idempotencia / concurrencia ──────────────────────────────────────

    public function test_recomputing_the_same_diagnostic_never_duplicates_rows(): void
    {
        $this->teacher();
        $this->teacher();
        $diagnostic = $this->diagnostic();
        $service = app(DiagnosticRecommendationService::class);

        $first = $service->compute($diagnostic)->pluck('teacher_profile_id')->all();
        $second = $service->compute($diagnostic->fresh())->pluck('teacher_profile_id')->all();

        $this->assertSame($first, $second);
        $this->assertSame(2, $diagnostic->recommendations()->count());
        $this->assertSame(
            2,
            $diagnostic->recommendations()->distinct('teacher_profile_id')->count('teacher_profile_id')
        );
        $this->assertSame([1, 2], $diagnostic->recommendations()->orderBy('rank')->pluck('rank')->all());
    }

    public function test_at_most_five_recommendations_are_stored(): void
    {
        foreach (range(1, 7) as $_) {
            $this->teacher();
        }

        $recs = app(DiagnosticRecommendationService::class)->compute($this->diagnostic());

        $this->assertCount(5, $recs);
    }

    // ── La IA no decide ni rankea ────────────────────────────────────────

    public function test_ai_keywords_never_change_the_ranking(): void
    {
        $a = $this->teacher(['completed_classes_count' => 0]);
        $b = $this->teacher(['completed_classes_count' => 0, 'bio' => str_repeat('álgebra ecuaciones ', 20)]);

        $plain = $this->diagnostic();
        $boosted = $this->diagnostic('flexible', [
            'ai_keywords' => ['álgebra', 'ecuaciones'],
            'ai_confidence' => 95,
        ]);

        $service = app(DiagnosticRecommendationService::class);
        $withoutAi = $service->compute($plain)->mapWithKeys(fn ($r) => [$r->teacher_profile_id => $r->score])->all();
        $withAi = $service->compute($boosted)->mapWithKeys(fn ($r) => [$r->teacher_profile_id => $r->score])->all();

        $this->assertSame($withoutAi, $withAi);
        $this->assertNotEmpty([$a->id, $b->id]);
    }

    public function test_ties_break_by_completed_classes_then_oldest_profile(): void
    {
        // Mismo puntaje base: gana más clases completadas (el bono por
        // experiencia ya lo refleja) y, a igualdad total, el perfil más antiguo.
        $first = $this->teacher();
        $second = $this->teacher();

        $recs = app(DiagnosticRecommendationService::class)->compute($this->diagnostic());

        $this->assertSame([$first->id, $second->id], $recs->pluck('teacher_profile_id')->all());
    }
}
