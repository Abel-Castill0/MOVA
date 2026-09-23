<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P0-B — ClassRequestController::index()/teacherIndex() serializaban el
 * Student completo (birth_date, school, parent_user_id) y el User completo
 * del profesor. Contrato: allowlist explícita, nada más.
 */
class ClassRequestIndexExposureTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST_KEYS = [
        'id', 'status', 'is_mentorship', 'help_needed', 'preferred_times',
        'teacher_rejected_at', 'teacher_rejection_reason', 'created_at', 'student', 'subject',
    ];

    private function scenario(): array
    {
        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $teacherUser = User::factory()->create(['email' => 'teacher@mova.pe', 'phone' => '999888777']);
        $teacherUser->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacherUser->id, 'is_verified' => true, 'hourly_rate' => 30]);
        $subject = Subject::create(['name' => 'Matemáticas', 'level' => 'secundaria']);
        $profile->subjects()->attach($subject->id);
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Clases',
            'is_active' => true,
        ]);

        $parent = User::factory()->create(['email' => 'parent@mova.pe', 'phone' => '911222333']);
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'birth_date' => '2014-05-01',
            'grade_level' => 'primaria',
            'school' => 'Colegio Secreto',
        ]);

        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Fracciones',
            'status' => 'open',
        ]);

        Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => now()->addDay(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'jitsi_room' => 'mova-exposure-test',
        ]);

        return [$teacherUser, $parent];
    }

    public function test_teacher_index_never_exposes_minor_sensitive_fields(): void
    {
        [$teacher] = $this->scenario();

        $props = $this->actingAs($teacher)->get(route('teacher.requests'))
            ->assertOk()->inertiaPage()['props'];

        $this->assertCount(1, $props['requests']);
        $req = $props['requests'][0];
        $this->assertEqualsCanonicalizing(self::REQUEST_KEYS, array_keys($req));
        $this->assertEqualsCanonicalizing(['first_name', 'last_name', 'grade_level'], array_keys($req['student']));
        $this->assertSame('Ana', $req['student']['first_name']);

        $json = json_encode($props);
        foreach (['2014-05-01', 'Colegio Secreto', 'parent@mova.pe', '911222333', 'parent_user_id', 'birth_date'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_parent_index_only_exposes_teacher_name(): void
    {
        [, $parent] = $this->scenario();

        $props = $this->actingAs($parent)->get(route('class-requests.index'))
            ->assertOk()->inertiaPage()['props'];

        $req = $props['requests'][0];
        $this->assertEqualsCanonicalizing([...self::REQUEST_KEYS, 'class_offer'], array_keys($req));
        $this->assertSame(['name'], array_keys($req['class_offer']['teacher_profile']['user']));

        $json = json_encode($props['requests']);
        foreach (['teacher@mova.pe', '999888777', 'user_id', 'hourly_rate'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_dashboards_do_not_leak_minor_or_counterpart_contact_data(): void
    {
        [$teacher, $parent] = $this->scenario();

        $teacherJson = json_encode($this->actingAs($teacher)->get(route('dashboard'))->assertOk()->inertiaPage()['props']['upcoming'] ?? []);
        $this->assertStringContainsString('Ana', $teacherJson);
        foreach (['2014-05-01', 'Colegio Secreto', 'parent@mova.pe', 'mova-exposure-test'] as $needle) {
            $this->assertStringNotContainsString($needle, $teacherJson);
        }

        $props = $this->actingAs($parent)->get(route('dashboard'))->assertOk()->inertiaPage()['props'];
        $parentJson = json_encode([$props['upcoming'] ?? [], $props['next_lesson'] ?? null]);
        $this->assertStringContainsString('Ana', $parentJson);
        foreach (['teacher@mova.pe', '999888777', 'mova-exposure-test', 'credits_available'] as $needle) {
            $this->assertStringNotContainsString($needle, $parentJson);
        }
    }
}
