<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Infraestructura del código de referido (ver TeacherProfile::booted()) —
 * base para el futuro flujo de solicitud-por-código. Este test cubre solo la
 * generación, no el enrutamiento de solicitudes (todavía sin decidir).
 */
class TeacherReferralCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('teacher', 'web');
    }

    public function test_a_new_teacher_profile_gets_a_referral_code_automatically(): void
    {
        $profile = $this->makeProfile();

        $this->assertNotNull($profile->referral_code);
        $this->assertSame(6, strlen($profile->referral_code));
    }

    public function test_referral_code_never_contains_vowels_or_ambiguous_characters(): void
    {
        foreach (range(1, 20) as $i) {
            $profile = $this->makeProfile();
            $this->assertMatchesRegularExpression('/^[BCDFGHJKMNPQRSTVWXYZ23456789]{6}$/', $profile->referral_code);
        }
    }

    public function test_referral_codes_are_unique_across_profiles(): void
    {
        $codes = collect(range(1, 15))->map(fn () => $this->makeProfile()->referral_code);

        $this->assertSame($codes->count(), $codes->unique()->count());
    }

    public function test_referral_code_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');

        $profile = TeacherProfile::create([
            'user_id' => $user->id,
            'referral_code' => 'HACKED',
        ]);

        $this->assertNotSame('HACKED', $profile->referral_code);
    }

    private function makeProfile(): TeacherProfile
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');

        return TeacherProfile::create(['user_id' => $user->id]);
    }
}
