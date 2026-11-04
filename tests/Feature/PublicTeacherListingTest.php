<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicTeacherListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('teacher', 'web');
        Role::findOrCreate('admin', 'web');
    }

    public function test_unlisted_verified_teacher_is_absent_from_all_public_discovery_and_direct_urls(): void
    {
        $visible = $this->teacher();
        $hidden = $this->teacher();
        $hidden->is_publicly_listed = false;
        $hidden->save();

        $this->get(route('welcome'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('featuredTeachers', 1)
                ->where('featuredTeachers.0.id', $visible->id)
                ->where('stats.teachers', 1));

        $this->get(route('marketplace'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('teachers.data', 1)
                ->where('teachers.data.0.id', $visible->id)
                ->where('stats.teachers', 1));

        $this->get(route('about'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.teachers', 1));

        $this->get(route('teachers.show', $visible))->assertOk();
        $this->get(route('teachers.show', $hidden))->assertNotFound();

        $this->get(route('sitemap'))
            ->assertSeeText(route('teachers.show', $visible), false)
            ->assertDontSeeText(route('teachers.show', $hidden), false);

        // Ocultar la ficha comercial no cambia el permiso operativo de enseñar.
        $this->assertTrue($hidden->fresh()->is_verified);
    }

    public function test_only_an_admin_can_change_the_public_listing_state(): void
    {
        $teacher = $this->teacher();
        $stranger = User::factory()->create();
        $stranger->assignRole('teacher');

        $this->actingAs($stranger)
            ->post(route('admin.teachers.public-listing', $teacher), ['published' => false])
            ->assertForbidden();
        $this->assertTrue($teacher->fresh()->is_publicly_listed);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.teachers.public-listing', $teacher), ['published' => 'invalid'])
            ->assertSessionHasErrors('published');
        $this->assertTrue($teacher->fresh()->is_publicly_listed);

        $this->actingAs($admin)
            ->post(route('admin.teachers.public-listing', $teacher), ['published' => false])
            ->assertRedirect();
        $this->assertFalse($teacher->fresh()->is_publicly_listed);
        $this->assertTrue($teacher->fresh()->is_verified);

        $this->actingAs($admin)
            ->post(route('admin.teachers.public-listing', $teacher), ['published' => true])
            ->assertRedirect();
        $this->assertTrue($teacher->fresh()->is_publicly_listed);
    }

    private function teacher(): TeacherProfile
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');

        return TeacherProfile::create([
            'user_id' => $user->id,
            'is_verified' => true,
            'bio' => 'Profesor de ejemplo con presentación completa.',
            'hourly_rate' => 30,
        ]);
    }
}
