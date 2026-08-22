<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubjectProfanityFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_subject_firstorcreatebyname_rejects_a_blocked_word(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Subject::firstOrCreateByName('Pinga');
    }

    public function test_subject_firstorcreatebyname_is_case_and_accent_insensitive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Subject::firstOrCreateByName('PUTA');
    }

    // El caso explícitamente advertido en el diseño: no bloquear una materia
    // legítima solo porque comparte una palabra parecida (comparación por
    // palabra completa, nunca substring).
    public function test_legitimate_subjects_with_sensitive_sounding_words_are_not_blocked(): void
    {
        $subject = Subject::firstOrCreateByName('Educación Sexual');

        $this->assertSame('Educación Sexual', $subject->name);
    }

    public function test_registration_rejects_a_teacher_subject_name_before_creating_any_records(): void
    {
        $this->post(route('register'), [
            'name' => 'Profesor Test',
            'email' => 'profanity-test@example.test',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
            'role' => 'teacher',
            'accepted_terms' => true,
            'teacher_subject_names' => ['Matemáticas', 'Verga'],
        ])->assertSessionHasErrors('teacher_subject_names.1');

        // Ningún registro a medio crear: ni el usuario, ni el perfil.
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('teacher_profiles', 0);
    }

    public function test_teacher_profile_update_rejects_a_blocked_subject_name(): void
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = \App\Models\TeacherProfile::create(['user_id' => $user->id]);

        $this->actingAs($user)->patch(route('teacher.profile.update'), [
            'bio' => 'Bio de prueba',
            'mentorship_slots_total' => 0,
            'subject_names' => ['Mierda'],
        ])->assertSessionHasErrors('subject_names.0');
    }
}
