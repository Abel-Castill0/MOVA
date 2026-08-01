<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\StudentDiagnostic;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_teacher_cannot_edit_another_teachers_class_offer(): void
    {
        [, $ownerOffer] = $this->classOffer();
        $intruder = $this->userWithRole('teacher');
        $this->teacherProfile($intruder);

        $this->actingAs($intruder)
            ->get(route('class-offers.edit', $ownerOffer))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->patch(route('class-offers.update', $ownerOffer), ['title' => 'Hackeado', 'subject_id' => $ownerOffer->subject_id])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->delete(route('class-offers.destroy', $ownerOffer))
            ->assertForbidden();

        $this->assertDatabaseHas('class_offers', ['id' => $ownerOffer->id, 'title' => $ownerOffer->title]);
    }

    public function test_parent_cannot_edit_another_parents_student(): void
    {
        $owner = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $owner->id,
            'first_name' => 'Alumno',
            'last_name' => 'Original',
            'grade_level' => 'secundaria',
        ]);

        $intruder = $this->userWithRole('parent');

        $this->actingAs($intruder)
            ->get(route('students.edit', $student))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->patch(route('students.update', $student), [
                'first_name' => 'Hackeado',
                'last_name' => 'X',
                'grade_level' => 'secundaria',
            ])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->delete(route('students.destroy', $student))
            ->assertForbidden();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'first_name' => 'Alumno']);
    }

    public function test_parent_cannot_view_another_parents_diagnostic_results(): void
    {
        $owner = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $owner->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);
        $subject = Subject::create(['name' => 'Materia Test', 'level' => 'secundaria']);
        $diagnostic = StudentDiagnostic::create([
            'parent_user_id' => $owner->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'level' => 'secundaria',
            'difficulty_text' => 'Necesita ayuda con fracciones y no entiende el tema en clase.',
            'goal' => 'solve_homework',
            'urgency' => 'this_week',
            'status' => 'draft',
        ]);

        $intruder = $this->userWithRole('parent');

        $this->actingAs($intruder)
            ->get(route('diagnostics.results', $diagnostic))
            ->assertForbidden();
    }

    public function test_parent_cannot_approve_another_parents_class_request(): void
    {
        $owner = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $owner->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);
        $subject = Subject::create(['name' => 'Materia Test', 'level' => 'secundaria']);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'pending_parent_approval',
        ]);

        $intruder = $this->userWithRole('parent');

        $this->actingAs($intruder)
            ->post(route('class-requests.approve', $request))
            ->assertForbidden();

        $this->assertDatabaseHas('class_requests', ['id' => $request->id, 'status' => 'pending_parent_approval']);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    private function teacherProfile(User $teacher): TeacherProfile
    {
        return TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);
    }

    private function classOffer(): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = $this->teacherProfile($teacher);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);
        $profile->subjects()->attach($subject->id);

        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Clases originales',
            'is_active' => true,
        ]);

        return [$teacher, $offer];
    }
}
