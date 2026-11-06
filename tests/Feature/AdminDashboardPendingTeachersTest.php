<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardPendingTeachersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_teacher_counter_excludes_profiles_already_rejected(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        TeacherProfile::create([
            'user_id' => User::factory()->create()->id,
            'is_verified' => false,
        ]);
        TeacherProfile::create([
            'user_id' => User::factory()->create()->id,
            'is_verified' => false,
            'rejected_at' => now(),
            'rejection_reason' => 'Prueba de rechazo anterior',
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
        $this->assertSame(1, $response->inertiaPage()['props']['stats']['pending_teachers']);
    }
}
