<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\NewClassRequestNotification;
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
        // Contraoferta: solo la propuesta (hora + duración), nunca ids del
        // profesor ni del padre.
        'counteroffer_time', 'counteroffer_duration_minutes',
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
        // Pre-aceptación: nombre de pila + grado, nunca el apellido.
        $this->assertEqualsCanonicalizing(['first_name', 'grade_level'], array_keys($req['student']));
        $this->assertSame('Ana', $req['student']['first_name']);

        $json = json_encode($props);
        foreach (['Pérez', 'last_name', '2014-05-01', 'Colegio Secreto', 'parent@mova.pe', '911222333', 'parent_user_id', 'birth_date', 'school'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    /**
     * Invariante de privacidad: un profesor SIN verificación vigente no recibe
     * ninguna solicitud (abierta, contraofertada ni rechazada) ni conteo.
     */
    public function test_unverified_teacher_receives_no_request_or_minor_data(): void
    {
        [$teacher] = $this->scenario();
        $profile = $teacher->teacherProfile;
        $open = ClassRequest::where('status', 'open')->firstOrFail();

        // Filas que le corresponderían en cada pestaña si estuviera verificado.
        (new ClassRequest)->forceFill([
            'student_id' => $open->student_id, 'subject_id' => $open->subject_id, 'class_offer_id' => $open->class_offer_id,
            'help_needed' => 'Fracciones contraoferta', 'status' => 'counteroffered',
            'counteroffer_time' => now()->addDays(2), 'counteroffer_duration_minutes' => 60,
            'counteroffer_teacher_profile_id' => $profile->id,
        ])->save();
        (new ClassRequest)->forceFill([
            'student_id' => $open->student_id, 'subject_id' => $open->subject_id, 'class_offer_id' => $open->class_offer_id,
            'help_needed' => 'Fracciones rechazada', 'status' => 'teacher_rejected',
            'teacher_rejected_at' => now(), 'teacher_rejection_reason' => 'Sin disponibilidad esa semana',
        ])->save();

        $profile->update(['is_verified' => false]);

        $props = $this->actingAs($teacher->fresh())->get(route('teacher.requests'))
            ->assertOk()->inertiaPage()['props'];

        $this->assertSame([], $props['requests']);
        $this->assertSame([], $props['counterofferedRequests']);
        $this->assertSame([], $props['rejectedRequests']);
        $this->assertTrue($props['verificationPending']);

        $json = json_encode($props);
        foreach (['Ana', 'Pérez', 'Fracciones', '2014-05-01', 'Colegio Secreto', 'parent_user_id', 'birth_date', 'school', 'parent@mova.pe', '911222333'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }

        $dashboard = $this->actingAs($teacher->fresh())->get(route('dashboard'))->assertOk()->inertiaPage()['props'];
        $this->assertSame(0, $dashboard['pending_requests']);
    }

    public function test_verified_teacher_still_gets_allowlisted_requests_and_count(): void
    {
        [$teacher] = $this->scenario();

        $props = $this->actingAs($teacher->fresh())->get(route('teacher.requests'))->assertOk()->inertiaPage()['props'];
        $this->assertFalse($props['verificationPending']);
        $this->assertCount(1, $props['requests']);
        $this->assertEqualsCanonicalizing(self::REQUEST_KEYS, array_keys($props['requests'][0]));

        $dashboard = $this->actingAs($teacher->fresh())->get(route('dashboard'))->assertOk()->inertiaPage()['props'];
        $this->assertSame(1, $dashboard['pending_requests']);
    }

    public function test_suspended_teacher_is_blocked_from_the_request_list(): void
    {
        [$teacher] = $this->scenario();
        $teacher->update(['suspended_at' => now()]);

        $response = $this->actingAs($teacher->fresh())->get(route('teacher.requests'));

        $response->assertRedirect(route('suspended'));
        $this->assertStringNotContainsString('Ana', (string) $response->getContent());
    }

    public function test_parent_index_only_exposes_teacher_name(): void
    {
        [, $parent] = $this->scenario();

        $props = $this->actingAs($parent)->get(route('class-requests.index'))
            ->assertOk()->inertiaPage()['props'];

        $req = $props['requests'][0];
        $this->assertEqualsCanonicalizing([...self::REQUEST_KEYS, 'class_offer', 'counteroffer_teacher', 'counteroffer_ref'], array_keys($req));
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

    // P0-02 (auditoría Codex): ningún payload docente lleva identificadores ni
    // datos del padre, ni datos sensibles del menor.
    public function test_teacher_lessons_and_accept_payloads_exclude_parent_and_minor_data(): void
    {
        [$teacher] = $this->scenario();
        $requestId = ClassRequest::where('status', 'open')->value('id');

        $payloads = [
            'teacher.lessons' => $this->actingAs($teacher)->get(route('teacher.lessons'))->assertOk()->inertiaPage()['props']['lessons'],
            'teacher.requests.accept' => $this->actingAs($teacher)->get(route('teacher.requests.accept', $requestId))->assertOk()->inertiaPage()['props']['classRequest'],
        ];

        foreach ($payloads as $name => $payload) {
            $this->assertNotEmpty($payload, $name);
            $keys = $this->allKeys($payload);
            foreach (['parent_user_id', 'birth_date', 'school', 'parent'] as $forbidden) {
                $this->assertNotContains($forbidden, $keys, "{$name} no debe exponer '{$forbidden}'.");
            }
            $json = json_encode($payload);
            foreach (['parent@mova.pe', '911222333', 'Colegio Secreto', '2014-05-01'] as $needle) {
                $this->assertStringNotContainsString($needle, $json, "{$name} filtra '{$needle}'.");
            }
            $this->assertStringContainsString('Ana', $json, "{$name} debe seguir mostrando el nombre del alumno.");
        }

        // La pantalla de aceptación sigue siendo PRE-aceptación (la solicitud
        // está 'open'): sin apellido del menor.
        $accept = $payloads['teacher.requests.accept'];
        // full_name es un accessor `$appends` de Student; sin last_name
        // seleccionado se reduce al nombre de pila.
        $this->assertEqualsCanonicalizing(['id', 'first_name', 'grade_level', 'full_name'], array_keys($accept['student']));
        $this->assertSame('Ana', trim($accept['student']['full_name']));
        $this->assertStringNotContainsString('Pérez', json_encode($accept));
    }

    public function test_new_request_notification_to_teachers_carries_only_the_first_name(): void
    {
        [$teacher] = $this->scenario();
        $request = ClassRequest::where('status', 'open')->firstOrFail();

        $n = new NewClassRequestNotification($request);
        $mail = $n->toMail($teacher);
        $json = json_encode([$n->toArray($teacher), $mail->subject, $mail->introLines]);

        $this->assertStringContainsString('Ana', $json);
        foreach (['Pérez', '2014-05-01', 'Colegio Secreto', 'parent@mova.pe'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_parent_index_keeps_the_full_student_name(): void
    {
        [, $parent] = $this->scenario();

        $props = $this->actingAs($parent)->get(route('class-requests.index'))->assertOk()->inertiaPage()['props'];

        $this->assertSame('Pérez', $props['requests'][0]['student']['last_name']);
    }

    private function allKeys(array $data): array
    {
        $keys = [];
        foreach ($data as $k => $v) {
            $keys[] = (string) $k;
            if (is_array($v)) {
                $keys = [...$keys, ...$this->allKeys($v)];
            }
        }

        return $keys;
    }
}
