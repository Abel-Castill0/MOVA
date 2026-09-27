<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Invariante de privacidad de menores: solo un profesor con verificación
 * VIGENTE se entera de una solicitud (lista, conteo, notificación), y una
 * solicitud nunca queda dirigida a un profesor distinto del que el padre eligió.
 */
class ClassRequestVerificationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->subject = Subject::create(['name' => 'Matemáticas', 'level' => 'secundaria']);
    }

    private function teacher(string $code, bool $verified = true): TeacherProfile
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => $verified, 'hourly_rate' => 30]);
        $profile->forceFill(['referral_code' => $code])->save();
        $profile->subjects()->attach($this->subject->id);

        return $profile;
    }

    private function offerOf(TeacherProfile $profile): ClassOffer
    {
        return ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $this->subject->id,
            'title' => 'Clases',
            'is_active' => true,
        ]);
    }

    private function parentWithStudent(): array
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'birth_date' => '2014-05-01',
            'grade_level' => 'primaria',
            'school' => 'Colegio Secreto',
        ]);

        return [$parent, $student];
    }

    public function test_notifications_never_target_a_teacher_whose_verification_was_revoked(): void
    {
        $profile = $this->teacher('AAAAAA');
        [, $student] = $this->parentWithStudent();

        $viaOffer = ClassRequest::create([
            'student_id' => $student->id, 'subject_id' => $this->subject->id,
            'class_offer_id' => $this->offerOf($profile)->id, 'help_needed' => 'Fracciones', 'status' => 'open',
        ]);
        $viaCode = ClassRequest::create([
            'student_id' => $student->id, 'subject_id' => $this->subject->id,
            'help_needed' => 'Fracciones', 'status' => 'open',
        ]);
        $viaCode->teacher_profile_id = $profile->id;
        $viaCode->save();

        $this->assertCount(1, $viaOffer->fresh()->eligibleTeacherUsers());
        $this->assertCount(1, $viaCode->fresh()->eligibleTeacherUsers());

        $profile->update(['is_verified' => false]);

        $this->assertCount(0, $viaOffer->fresh()->eligibleTeacherUsers());
        $this->assertCount(0, $viaCode->fresh()->eligibleTeacherUsers());
    }

    public function test_offer_and_referral_code_of_different_teachers_are_rejected(): void
    {
        $offerTeacher = $this->teacher('OFFERA');
        $this->teacher('OTHERB');
        [$parent, $student] = $this->parentWithStudent();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'class_offer_id' => $this->offerOf($offerTeacher)->id,
            'teacher_referral_code' => 'OTHERB',
            'help_needed' => 'Necesita repasar fracciones.',
        ])->assertSessionHasErrors('teacher_referral_code');

        $this->assertSame(0, ClassRequest::count());
    }

    public function test_offer_and_matching_referral_code_are_accepted(): void
    {
        $offerTeacher = $this->teacher('OFFERA');
        [$parent, $student] = $this->parentWithStudent();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'class_offer_id' => $this->offerOf($offerTeacher)->id,
            'teacher_referral_code' => 'OFFERA',
            'help_needed' => 'Necesita repasar fracciones.',
        ])->assertSessionHasNoErrors();

        $this->assertSame($offerTeacher->id, ClassRequest::sole()->teacher_profile_id);
    }

    public function test_dashboard_count_respects_referral_exclusivity(): void
    {
        $me = $this->teacher('MEMEME');
        $other = $this->teacher('OTHERB');
        [, $student] = $this->parentWithStudent();

        ClassRequest::create([
            'student_id' => $student->id, 'subject_id' => $this->subject->id,
            'help_needed' => 'Abierta para la materia', 'status' => 'open',
        ]);
        $exclusive = ClassRequest::create([
            'student_id' => $student->id, 'subject_id' => $this->subject->id,
            'help_needed' => 'Exclusiva de otro profesor', 'status' => 'open',
        ]);
        $exclusive->teacher_profile_id = $other->id;
        $exclusive->save();

        $mine = $this->actingAs($me->user->fresh())->get(route('dashboard'))->assertOk()->inertiaPage()['props'];
        $theirs = $this->actingAs($other->user->fresh())->get(route('dashboard'))->assertOk()->inertiaPage()['props'];

        $this->assertSame(1, $mine['pending_requests']);
        $this->assertSame(2, $theirs['pending_requests']);
    }
}
