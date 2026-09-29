<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C-P1-TEACHER-PROFILE-SCORE: el porcentaje de perfil completo exigía
 * 'active_offer', pero la creación de ofertas ya no existe (rutas
 * class-offers sin create/store). Un profesor que cumple todo lo que el
 * producto le permite hacer debe llegar al 100%.
 */
class TeacherDashboardProfileScoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('teacher', 'web');
    }

    public function test_teacher_meeting_every_actionable_requirement_reaches_100_without_any_offer(): void
    {
        $teacher = $this->teacher(complete: true);

        $this->actingAs($teacher)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Teacher')
                ->where('profile_score', 100)
                ->where('profile_checklist', [
                    'bio'            => true,
                    'subjects'       => true,
                    'phone_verified' => true,
                    'email_verified' => true,
                    'is_verified'    => true,
                ]));
    }

    public function test_checklist_never_contains_the_obsolete_offer_requirement(): void
    {
        $teacher = $this->teacher(complete: false);

        $this->actingAs($teacher)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('profile_checklist.active_offer')
                ->where('profile_checklist.bio', false)
                ->where('profile_checklist.subjects', false)
                ->where('profile_checklist.phone_verified', false)
                ->where('profile_checklist.is_verified', false)
                // 1 de 5 (email verificado por la factory) = 20%.
                ->where('profile_score', 20));
    }

    private function teacher(bool $complete): User
    {
        $user = User::factory()->create([
            'phone_verified_at' => $complete ? now() : null,
        ]);
        $user->assignRole('teacher');

        $profile = TeacherProfile::create([
            'user_id'           => $user->id,
            'bio'               => $complete ? 'Profesor de matemática con experiencia.' : null,
            'is_verified'       => $complete,
            'credits_available' => 0,
            'credits_reserved'  => 0,
        ]);

        if ($complete) {
            $subject = Subject::create(['name' => 'Matemática', 'level' => 'secundaria']);
            $profile->subjects()->attach($subject->id);
        }

        return $user;
    }
}
