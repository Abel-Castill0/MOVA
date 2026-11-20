<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\ClassRequestTeacherDecline;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\NewClassRequestNotification;
use App\Services\SubjectNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Incidencia real (producción, 2026-11-16): un padre publicó solicitudes generales de «Matemáticas» y «Física» y el
 * profesor de prueba no las vio. Causa: nadie verificado enseñaba esos ids —el catálogo tenía «Matemáticas» (id 1) y
 * «Matemática» (id 7) como materias distintas— y MOVA respondía «Solicitud publicada» igualmente.
 *
 * Aquí se fija que (1) las variantes de un mismo nombre cuentan como la misma materia en la bandeja, el contador, la
 * autorización y los avisos, y (2) el padre sabe, antes y después de enviar, si hoy algún profesor puede recibirla.
 */
class ClassRequestSubjectEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['parent', 'teacher'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_match_key_only_merges_plural_variants_of_the_same_name(): void
    {
        $this->assertSame(SubjectNormalizer::matchKey('Matemáticas'), SubjectNormalizer::matchKey('  matemática '));
        $this->assertSame(SubjectNormalizer::matchKey('Ciencias Naturales'), SubjectNormalizer::matchKey('ciencia  NATURALES'));
        $this->assertNotSame(SubjectNormalizer::matchKey('Física'), SubjectNormalizer::matchKey('Química'));
        $this->assertNotSame(SubjectNormalizer::matchKey('Matemática'), SubjectNormalizer::matchKey('Matemática financiera'));
        $this->assertNotSame(SubjectNormalizer::matchKey('Inglés'), SubjectNormalizer::matchKey('Inglés técnico'));
        $this->assertNotSame(SubjectNormalizer::matchKey('Álgebra'), SubjectNormalizer::matchKey('Algoritmos'));
    }

    public function test_a_general_request_reaches_every_verified_teacher_of_an_equivalent_subject_in_list_counter_policy_and_notification(): void
    {
        Notification::fake();
        $plural = Subject::create(['name' => 'Matemáticas', 'level' => 'todos']);
        $singular = Subject::create(['name' => 'Matemática', 'level' => 'todos']);
        $other = Subject::create(['name' => 'Física', 'level' => 'todos']);
        [$parent, $student] = $this->parentWithStudent();
        [$teacherA] = $this->teacher($singular);
        [$teacherB] = $this->teacher($plural);
        [$teacherOther] = $this->teacher($other);
        [$unverified] = $this->teacher($singular, verified: false);

        // El padre elige la variante que el profesor A NO tiene registrada (el caso real).
        $this->actingAs($parent)->post(route('class-requests.store'), [...\Tests\Support\Day::tomorrow(),
            'student_id' => $student->id, 'subject_id' => $plural->id, 'help_needed' => 'Apoyo con fracciones.',
        ])->assertRedirect(route('class-requests.index'))
            ->assertSessionHas('success', 'Solicitud publicada. Los profesores verificados de la materia ya pueden verla en su panel.');

        $request = ClassRequest::sole();
        $this->assertSame('open', $request->status);
        $this->assertSame('published', $request->deliveryState());

        foreach ([$teacherA, $teacherB] as $teacher) {
            $list = $this->actingAs($teacher)->get(route('teacher.requests'))->assertOk()->inertiaPage()['props'];
            $this->assertSame([$request->id], array_column($list['requests'], 'id'));
            $dashboard = $this->actingAs($teacher)->get(route('dashboard'))->assertOk()->inertiaPage()['props'];
            $this->assertSame(1, $dashboard['pending_requests']);
            $this->assertTrue($teacher->can('accept', $request));
        }
        Notification::assertSentTo([$teacherA, $teacherB], NewClassRequestNotification::class);

        foreach ([$teacherOther, $unverified] as $teacher) {
            $list = $this->actingAs($teacher)->get(route('teacher.requests'))->assertOk()->inertiaPage()['props'];
            $this->assertSame([], $list['requests']);
            $this->assertSame(0, $this->actingAs($teacher)->get(route('dashboard'))->inertiaPage()['props']['pending_requests']);
            $this->assertFalse($teacher->can('accept', $request));
        }
        Notification::assertNotSentTo([$teacherOther, $unverified], NewClassRequestNotification::class);
    }

    public function test_a_general_request_with_no_verified_teacher_is_saved_and_the_parent_is_told_the_truth(): void
    {
        Notification::fake();
        $physics = Subject::create(['name' => 'Física', 'level' => 'todos']);
        $algebra = Subject::create(['name' => 'Álgebra', 'level' => 'todos']);
        [$parent, $student] = $this->parentWithStudent();
        [$algebraTeacher] = $this->teacher($algebra);
        $this->teacher($physics, verified: false); // un perfil sin verificar no cuenta como destinatario

        // Antes de enviar: el formulario ya sabe qué materias tienen profesor.
        $subjects = collect($this->actingAs($parent)->get(route('class-requests.create'))->assertOk()->inertiaPage()['props']['subjects'])->keyBy('id');
        $this->assertFalse($subjects[$physics->id]['has_teachers']);
        $this->assertTrue($subjects[$algebra->id]['has_teachers']);

        $response = $this->actingAs($parent)->post(route('class-requests.store'), [...\Tests\Support\Day::tomorrow(),
            'student_id' => $student->id, 'subject_id' => $physics->id, 'help_needed' => 'Cinemática.',
        ])->assertRedirect(route('class-requests.index'));
        $this->assertStringContainsString('hoy no hay profesores verificados de esta materia', session('success'));
        $this->assertStringNotContainsString('publicada', session('success'));

        $request = ClassRequest::sole();
        $this->assertSame('open', $request->status, 'Se guarda igual: si un profesor se incorpora antes de vencer, la verá.');
        $this->assertSame('no_teachers', $request->deliveryState());
        Notification::assertNothingSent();

        $index = $this->actingAs($parent)->get(route('class-requests.index'))->assertOk()->inertiaPage()['props'];
        $this->assertSame('no_teachers', $index['requests'][0]['delivery']);
        $this->assertSame([], $this->actingAs($algebraTeacher)->get(route('teacher.requests'))->inertiaPage()['props']['requests']);

        // Un profesor verificado añade después la materia: la solicitud abierta le aparece sin tocar nada más.
        [$late] = $this->teacher($physics);
        $this->assertSame([$request->id], array_column($this->actingAs($late)->get(route('teacher.requests'))->inertiaPage()['props']['requests'], 'id'));
        $this->assertSame('published', $request->fresh()->deliveryState());
    }

    public function test_a_request_directed_by_code_stays_exclusive_even_when_other_teachers_share_an_equivalent_subject(): void
    {
        Notification::fake();
        $plural = Subject::create(['name' => 'Matemáticas', 'level' => 'todos']);
        $singular = Subject::create(['name' => 'Matemática', 'level' => 'todos']);
        $robotics = Subject::create(['name' => 'Robótica', 'level' => 'todos']);
        [$parent, $student] = $this->parentWithStudent();
        [$target, $targetProfile] = $this->teacher($robotics); // su materia ni siquiera coincide
        $targetProfile->forceFill(['referral_code' => 'ABC123'])->save();
        [$sameSubject] = $this->teacher($singular);

        $this->actingAs($parent)->post(route('class-requests.store'), [...\Tests\Support\Day::tomorrow(),
            'student_id' => $student->id, 'subject_id' => $plural->id, 'help_needed' => 'Geometría.', 'teacher_referral_code' => 'abc123',
        ])->assertSessionHas('success', 'Solicitud enviada. El profesor que elegiste ya puede verla en su panel.');

        $request = ClassRequest::sole();
        $this->assertSame('directed', $request->deliveryState());
        $this->assertSame([$request->id], array_column($this->actingAs($target)->get(route('teacher.requests'))->inertiaPage()['props']['requests'], 'id'));
        $this->assertSame([], $this->actingAs($sameSubject)->get(route('teacher.requests'))->inertiaPage()['props']['requests']);
        $this->assertFalse($sameSubject->can('accept', $request));
        Notification::assertNotSentTo($sameSubject, NewClassRequestNotification::class);
    }

    public function test_parental_control_decline_and_expiry_keep_working_through_equivalent_subjects(): void
    {
        Notification::fake();
        $plural = Subject::create(['name' => 'Matemáticas', 'level' => 'todos']);
        $singular = Subject::create(['name' => 'Matemática', 'level' => 'todos']);
        [$parent, $student] = $this->parentWithStudent(parentalControl: true);
        [$teacher, $profile] = $this->teacher($singular);
        $inbox = fn () => array_column($this->actingAs($teacher)->get(route('teacher.requests'))->inertiaPage()['props']['requests'], 'id');

        $this->actingAs($parent)->post(route('class-requests.store'), [...\Tests\Support\Day::tomorrow(),
            'student_id' => $student->id, 'subject_id' => $plural->id, 'help_needed' => 'Álgebra básica.',
        ])->assertSessionHas('success', 'Solicitud guardada. Está pendiente de tu aprobación antes de llegar a los profesores.');
        $request = ClassRequest::sole();
        $this->assertSame('awaiting_parent', $request->deliveryState());
        $this->assertSame([], $inbox());
        Notification::assertNotSentTo($teacher, NewClassRequestNotification::class);

        $this->actingAs($parent)->post(route('class-requests.approve', $request))->assertRedirect();
        $this->assertSame([$request->id], $inbox());

        // Rechazo individual: deja de verla solo ese profesor.
        ClassRequestTeacherDecline::create(['class_request_id' => $request->id, 'teacher_profile_id' => $profile->id, 'declined_at' => now(), 'reason' => 'Sin horario']);
        $this->assertSame([], $inbox());
        $this->assertSame('no_teachers', $request->fresh()->deliveryState());
        ClassRequestTeacherDecline::query()->delete();

        // Vencida: desaparece de la bandeja y conserva la fila.
        $request->update(['status' => 'expired']);
        $this->assertSame([], $inbox());
        $this->assertNull($request->fresh()->deliveryState());
        $this->assertDatabaseHas('class_requests', ['id' => $request->id, 'status' => 'expired']);
    }

    public function test_a_teacher_typing_a_variant_reuses_the_catalogue_subject_instead_of_creating_a_near_duplicate(): void
    {
        $catalogue = Subject::create(['name' => 'Matemáticas', 'level' => 'todos']);

        $this->assertSame($catalogue->id, Subject::firstOrCreateByName('Matemática')->id);
        $this->assertSame($catalogue->id, Subject::firstOrCreateByName('  MATEMATICAS ')->id);
        $this->assertSame(1, Subject::count());

        $new = Subject::firstOrCreateByName('Matemática financiera');
        $this->assertNotSame($catalogue->id, $new->id);
        $this->assertSame([$new->id], Subject::equivalentIds([$new->id])->all());
    }

    private function parentWithStudent(bool $parentalControl = false): array
    {
        $parent = User::factory()->create(['parental_control' => $parentalControl]);
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Sintético',
            'grade_level' => 'secundaria',
        ]);

        return [$parent, $student];
    }

    private function teacher(Subject $subject, bool $verified = true): array
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => $verified, 'hourly_rate' => 20]);
        $profile->subjects()->attach($subject->id);

        return [$teacher, $profile];
    }
}
