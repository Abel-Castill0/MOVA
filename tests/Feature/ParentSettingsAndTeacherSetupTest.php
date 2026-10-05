<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rutas V1 sin evidencia directa hasta ahora: el interruptor de control parental
 * (`parent.settings.update`, afecta a menores) y el alta inicial del perfil del profesor
 * (`teacher.setup.store`).
 */
class ParentSettingsAndTeacherSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['teacher', 'parent', 'student'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_parent_can_toggle_parental_control_and_it_changes_new_request_status(): void
    {
        $parent = $this->userWithRole('parent', ['parental_control' => false]);
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);

        $this->actingAs($parent)
            ->patch(route('parent.settings.update'), ['parental_control' => true])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($parent->fresh()->parental_control);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect();

        $this->assertSame('pending_parent_approval', ClassRequest::sole()->status);

        $this->actingAs($parent)
            ->patch(route('parent.settings.update'), ['parental_control' => false])
            ->assertRedirect();

        $this->assertFalse($parent->fresh()->parental_control);
    }

    public function test_parental_control_rejects_invalid_values(): void
    {
        $parent = $this->userWithRole('parent', ['parental_control' => true]);

        $this->actingAs($parent)
            ->patch(route('parent.settings.update'), ['parental_control' => 'quizas'])
            ->assertSessionHasErrors('parental_control');
        $this->actingAs($parent)
            ->patch(route('parent.settings.update'), [])
            ->assertSessionHasErrors('parental_control');

        $this->assertTrue($parent->fresh()->parental_control);
    }

    public function test_only_parents_can_change_parental_control(): void
    {
        $teacher = $this->userWithRole('teacher', ['parental_control' => true]);
        $this->actingAs($teacher)
            ->patch(route('parent.settings.update'), ['parental_control' => false])
            ->assertForbidden();
        $this->assertTrue($teacher->fresh()->parental_control);

        auth()->logout();
        $this->patch(route('parent.settings.update'), ['parental_control' => false])
            ->assertRedirect(route('login'));
    }

    public function test_teacher_setup_saves_profile_and_syncs_subjects(): void
    {
        $teacher = $this->userWithRole('teacher');
        TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => false]);
        $math = Subject::create(['name' => 'Matemática Setup', 'level' => 'todos']);

        $this->actingAs($teacher)->post(route('teacher.setup.store'), [
            'bio' => 'Docente con experiencia.',
            'mentorship_slots_total' => 5,
            'subject_ids' => [$math->id],
        ])->assertRedirect(route('dashboard'))->assertSessionHas('success');

        $profile = $teacher->teacherProfile()->first();
        $this->assertSame('Docente con experiencia.', $profile->bio);
        $this->assertSame(5, (int) $profile->mentorship_slots_total);
        $this->assertEquals([$math->id], $profile->subjects()->pluck('subjects.id')->all());
        $this->assertFalse((bool) $profile->is_verified, 'El alta no debe verificar al profesor.');
    }

    public function test_teacher_setup_requires_at_least_one_subject_and_valid_slots(): void
    {
        $teacher = $this->userWithRole('teacher');
        TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => false]);

        $this->actingAs($teacher)->post(route('teacher.setup.store'), [
            'mentorship_slots_total' => 5,
        ])->assertStatus(422);

        $this->actingAs($teacher)->post(route('teacher.setup.store'), [
            'mentorship_slots_total' => 500,
            'subject_ids' => [999999],
        ])->assertSessionHasErrors(['mentorship_slots_total', 'subject_ids.0']);
    }

    public function test_teacher_setup_is_not_available_to_parents(): void
    {
        $parent = $this->userWithRole('parent');

        $this->actingAs($parent)->post(route('teacher.setup.store'), [
            'mentorship_slots_total' => 5,
        ])->assertForbidden();
    }

    public function test_notification_inbox_is_scoped_to_the_owner(): void
    {
        $owner = $this->userWithRole('parent');
        $other = $this->userWithRole('parent');
        $mine = $this->storeNotification($owner);
        $theirs = $this->storeNotification($other);

        $this->actingAs($owner)->getJson(route('notifications.index'))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mine);

        // Marcar una notificación ajena no existe para el usuario (404) y no la toca.
        $this->actingAs($owner)->postJson(route('notifications.read', $theirs))->assertNotFound();
        $this->assertNull($other->notifications()->find($theirs)->read_at);

        $this->actingAs($owner)->postJson(route('notifications.read', $mine))->assertOk();
        $this->assertNotNull($owner->notifications()->find($mine)->read_at);

        $this->actingAs($other)->postJson(route('notifications.readAll'))->assertOk();
        $this->assertNotNull($other->notifications()->find($theirs)->read_at);
    }

    private function storeNotification(User $user): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $user->notifications()->create([
            'id' => $id,
            'type' => 'App\Notifications\Test',
            'data' => ['message' => 'hola'],
        ]);

        return $id;
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
