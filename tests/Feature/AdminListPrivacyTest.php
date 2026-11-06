<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminListPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_and_lessons_only_send_table_data_not_nested_models(): void
    {
        foreach (['admin', 'parent', 'teacher'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $parent = User::factory()->create(['phone_verification_code_hash' => 'parent-private-otp']);
        $parent->assignRole('parent');
        $teacher = User::factory()->create(['phone_verification_code_hash' => 'teacher-private-otp']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => true]);
        $subject = Subject::firstOrCreateByName('Matemática');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'grade_level' => 'secundaria',
        ]);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'status' => 'accepted',
            'help_needed' => 'Reforzamiento de matemática.',
        ]);
        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => now()->addDay(),
            'duration_minutes' => 60,
            'price_frozen_pen' => 20,
            'status' => 'scheduled',
        ]);

        $requestResponse = $this->actingAs($admin)->get(route('admin.requests'));
        $requestResponse->assertOk();
        $requests = $requestResponse->inertiaPage()['props']['requests']['data'];
        $row = collect($requests)->firstWhere('id', $request->id);
        $this->assertNotNull($row);
        $this->assertEqualsCanonicalizing(
            ['id', 'subject_name', 'student_name', 'parent_name', 'teacher_name', 'status', 'teacher_rejection_reason', 'created_at'],
            array_keys($row)
        );
        $this->assertSame('Ana Pérez', $row['student_name']);
        $this->assertSame($parent->name, $row['parent_name']);

        $lessonResponse = $this->actingAs($admin)->get(route('admin.lessons'));
        $lessonResponse->assertOk();
        $lessons = $lessonResponse->inertiaPage()['props']['lessons']['data'];
        $lessonRow = collect($lessons)->firstWhere('id', $lesson->id);
        $this->assertNotNull($lessonRow);
        $this->assertEqualsCanonicalizing(
            ['id', 'subject_name', 'student_name', 'parent_name', 'teacher_name', 'start_time', 'status', 'cancel_reason'],
            array_keys($lessonRow)
        );
        $this->assertSame($teacher->name, $lessonRow['teacher_name']);

        foreach ([$requests, $lessons] as $rows) {
            $payload = json_encode($rows);
            $this->assertStringNotContainsString('parent-private-otp', $payload);
            $this->assertStringNotContainsString('teacher-private-otp', $payload);
            $this->assertStringNotContainsString('parent_user_id', $payload);
        }
    }
}
