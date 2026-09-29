<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentDataConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C-P0-MINOR-CONSENT: registrar a un menor exige un consentimiento explícito,
 * específico para ese alumno, con evidencia append-only (padre autenticado,
 * versión de la declaración y de la Política de Privacidad, fecha, IP y
 * user-agent) creada en la misma transacción que el alumno.
 */
class StudentDataConsentTest extends TestCase
{
    use RefreshDatabase;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['parent', 'teacher'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->parent = User::factory()->create();
        $this->parent->assignRole('parent');
    }

    public function test_create_form_shows_the_configured_consent_statement(): void
    {
        $this->actingAs($this->parent)->get(route('students.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Students/Create')
                ->where('consentStatement', config('legal.student_consent.statement')));
    }

    public function test_student_is_not_created_without_explicit_consent(): void
    {
        foreach ([[], ['data_consent' => false], ['data_consent' => '0']] as $consent) {
            $this->actingAs($this->parent)
                ->post(route('students.store'), [...$this->studentData(), ...$consent])
                ->assertSessionHasErrors('data_consent');
        }

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('student_data_consents', 0);
    }

    public function test_consent_is_recorded_for_that_student_with_current_versions(): void
    {
        config(['legal.versions.privacy' => '2099-01-01', 'legal.student_consent.version' => 'stmt-7']);

        $this->actingAs($this->parent)
            ->withHeader('User-Agent', 'ConsentTest/1.0')
            ->post(route('students.store'), [...$this->studentData(), 'data_consent' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $student = Student::sole();
        $consent = StudentDataConsent::sole();

        $this->assertSame($student->id, $consent->student_id);
        $this->assertSame($this->parent->id, $consent->parent_user_id);
        $this->assertSame('stmt-7', $consent->statement_version);
        $this->assertSame('2099-01-01', $consent->privacy_version);
        $this->assertNotNull($consent->accepted_at);
        $this->assertSame('127.0.0.1', $consent->ip);
        $this->assertSame('ConsentTest/1.0', $consent->user_agent);
        $this->assertTrue($student->dataConsents()->exists());
    }

    public function test_student_and_consent_are_created_atomically(): void
    {
        // Fuerza el fallo del INSERT de consentimiento: el alumno no debe
        // quedar creado sin su evidencia.
        StudentDataConsent::creating(fn () => throw new \RuntimeException('consent insert failed'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->parent)
                ->post(route('students.store'), [...$this->studentData(), 'data_consent' => '1']);
            $this->fail('Se esperaba que la creación fallara.');
        } catch (\RuntimeException $e) {
            $this->assertSame('consent insert failed', $e->getMessage());
        }

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('student_data_consents', 0);
    }

    public function test_consent_evidence_is_append_only(): void
    {
        $this->actingAs($this->parent)
            ->post(route('students.store'), [...$this->studentData(), 'data_consent' => '1']);
        $consent = StudentDataConsent::sole();

        try {
            $consent->update(['privacy_version' => 'otra']);
            $this->fail('update debe estar prohibido');
        } catch (LogicException) {
        }

        try {
            $consent->delete();
            $this->fail('delete debe estar prohibido');
        } catch (LogicException) {
        }

        $this->assertSame(1, StudentDataConsent::count());
    }

    public function test_client_cannot_forge_consent_fields(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->parent)->post(route('students.store'), [
            ...$this->studentData(),
            'data_consent'      => '1',
            'parent_user_id'    => $other->id,
            'statement_version' => 'forged',
            'privacy_version'   => 'forged',
        ])->assertSessionHasNoErrors();

        $consent = StudentDataConsent::sole();
        $this->assertSame($this->parent->id, $consent->parent_user_id);
        $this->assertSame($this->parent->id, Student::sole()->parent_user_id);
        $this->assertNotSame('forged', $consent->statement_version);
        $this->assertNotSame('forged', $consent->privacy_version);
    }

    public function test_editing_an_existing_student_does_not_require_or_record_new_consent(): void
    {
        $student = $this->parent->students()->create($this->studentData());

        $this->actingAs($this->parent)
            ->put(route('students.update', $student), [...$this->studentData(), 'first_name' => 'Luisa'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Luisa', $student->fresh()->first_name);
        $this->assertDatabaseCount('student_data_consents', 0);
    }

    public function test_teacher_cannot_register_students(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        $this->actingAs($teacher)
            ->post(route('students.store'), [...$this->studentData(), 'data_consent' => '1'])
            ->assertForbidden();

        $this->assertDatabaseCount('students', 0);
    }

    private function studentData(): array
    {
        return [
            'first_name'  => 'Ana',
            'last_name'   => 'Pérez',
            'birth_date'  => '2014-05-01',
            'grade_level' => 'primaria',
            'school'      => 'Colegio Ejemplo',
        ];
    }
}
