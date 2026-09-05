<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * H-06 — Quién puede abrir GET /lessons/{lesson}/report.
 *
 * La ruta vivía dentro del grupo `role:teacher`, así que el padre recibía 403
 * en el reporte de SU PROPIO hijo — pese a que `LessonPolicy::view()` lo
 * autoriza explícitamente y a que ya veía los mismos datos en /my-reports
 * (docs/MOVA_SYSTEM_MAP.md H-06, C-1).
 *
 * Estos tests atacan la RUTA directamente, que es donde estaba el fallo: los
 * tests de policy existentes pasaban porque nunca llegaban a la policy.
 */
class LessonReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role);
        }
    }

    /**
     * @return array{teacher: User, parent: User, lesson: Lesson}
     */
    private function lessonWithReport(): array
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'hourly_rate' => 20,
            'is_verified' => true,
        ]);

        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'grade_level' => 'secundaria',
        ]);

        $subject = Subject::firstOrCreateByName('Matemática');
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita apoyo con álgebra.',
            'status' => 'accepted',
        ]);

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => now()->subDays(2),
            'duration_minutes' => 60,
            'status' => 'pending_parent_confirmation',
        ]);

        LessonReport::create([
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'topic_covered' => 'Ecuaciones de primer grado',
            'student_performance' => 'Buen avance',
            'sent_to_parent_at' => now(),
        ]);

        return ['teacher' => $teacher, 'parent' => $parent, 'lesson' => $lesson];
    }

    public function test_the_parent_of_the_student_can_open_the_report(): void
    {
        ['parent' => $parent, 'lesson' => $lesson] = $this->lessonWithReport();

        $this->actingAs($parent)
            ->get(route('lesson-reports.show', $lesson))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('LessonReports/Show'));
    }

    public function test_the_teacher_who_taught_it_can_open_the_report(): void
    {
        ['teacher' => $teacher, 'lesson' => $lesson] = $this->lessonWithReport();

        $this->actingAs($teacher)
            ->get(route('lesson-reports.show', $lesson))
            ->assertOk();
    }

    public function test_an_admin_can_open_the_report(): void
    {
        ['lesson' => $lesson] = $this->lessonWithReport();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('lesson-reports.show', $lesson))
            ->assertOk();
    }

    // ── Cross-tenant: abrir la ruta NO puede saltarse la policy ──────────

    public function test_another_parent_cannot_open_someone_elses_report(): void
    {
        ['lesson' => $lesson] = $this->lessonWithReport();

        $stranger = User::factory()->create();
        $stranger->assignRole('parent');

        $this->actingAs($stranger)
            ->get(route('lesson-reports.show', $lesson))
            ->assertForbidden();
    }

    public function test_another_teacher_cannot_open_someone_elses_report(): void
    {
        ['lesson' => $lesson] = $this->lessonWithReport();

        $stranger = User::factory()->create();
        $stranger->assignRole('teacher');
        TeacherProfile::create(['user_id' => $stranger->id, 'hourly_rate' => 20]);

        $this->actingAs($stranger)
            ->get(route('lesson-reports.show', $lesson))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_open_a_report(): void
    {
        ['lesson' => $lesson] = $this->lessonWithReport();

        $this->get(route('lesson-reports.show', $lesson))
            ->assertRedirect(route('login'));
    }

    // ── Crear el reporte SIGUE siendo exclusivo del profesor ─────────────

    public function test_a_parent_still_cannot_create_or_submit_a_report(): void
    {
        ['parent' => $parent, 'lesson' => $lesson] = $this->lessonWithReport();

        $this->actingAs($parent)
            ->get(route('lesson-reports.create', $lesson))
            ->assertForbidden();

        $this->actingAs($parent)
            ->post(route('lesson-reports.store', $lesson), [
                'topic_covered' => 'Intento indebido',
                'student_performance' => 'Intento indebido',
            ])
            ->assertForbidden();
    }
}
