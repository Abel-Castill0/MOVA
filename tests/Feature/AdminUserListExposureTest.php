<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserListExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_list_contains_only_the_fields_used_by_the_table(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('teacher', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $teacher = User::factory()->create([
            'phone' => '987654321',
            'phone_verification_code_hash' => 'secret-hash-not-for-client',
            'phone_verification_attempts' => 3,
            'suspension_reason' => 'private-reason',
        ]);
        $teacher->assignRole('teacher');

        $response = $this->actingAs($admin)->get(route('admin.users'));
        $response->assertOk();

        $users = $response->inertiaPage()['props']['users']['data'];
        $row = collect($users)->firstWhere('id', $teacher->id);
        $this->assertNotNull($row);
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'phone', 'suspended_at', 'created_at', 'roles', 'teacher_profile'],
            array_keys($row)
        );
        $this->assertSame([['name' => 'teacher']], $row['roles']);
        $this->assertNull($row['teacher_profile']);
        $this->assertSame($teacher->name, $row['name']);
        $this->assertSame('987654321', $row['phone']);
        $this->assertStringNotContainsString('secret-hash-not-for-client', json_encode($users));
        $this->assertStringNotContainsString('private-reason', json_encode($users));
    }
}
