<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MovaCriticalFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Event::fake([Registered::class]);
    }

    public function test_teacher_registration_dynamic_subject_and_price_limit(): void
    {
        $this->get('/register')->assertOk();

        $this->post('/register', [
            'name' => 'Profesor Dinámico',
            'email' => 'profesor-dinamico@example.test',
            'phone' => '999999999',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'teacher',
            'teacher_subject_names' => ['Neurocálculo Aplicado'],
            'accepted_terms' => true,
        ])->assertRedirect(route('teacher.setup'));

        $teacher = User::where('email', 'profesor-dinamico@example.test')->firstOrFail();
        $profile = $teacher->teacherProfile()->with('subjects')->firstOrFail();

        $this->assertTrue($profile->subjects->contains('name', 'Neurocálculo Aplicado'));
        $this->assertSame(20, $profile->maxAllowedRate());

        $teacher->forceFill(['email_verified_at' => now()])->save();
        $subject = $profile->subjects->first();

        $this->actingAs($teacher)
            ->post('/class-offers', [
                'subject_id' => $subject->id,
                'title' => 'Oferta sobre precio permitido',
                'description' => 'Debe fallar porque aún no completó 5 clases.',
                'specific_rate' => 25,
            ])
            ->assertSessionHasErrors('specific_rate');

        $this->actingAs($teacher)
            ->post('/class-offers', [
                'subject_id' => $subject->id,
                'title' => 'Oferta dentro del precio permitido',
                'description' => 'Debe pasar porque respeta el límite inicial.',
                'specific_rate' => 20,
            ])
            ->assertRedirect(route('class-offers.index'));

        // El nivel 25 exige AMBOS umbrales: clases completadas y calificación
        // promedio — no solo el conteo de clases. Sin reseñas, avg_rating es
        // null y el profesor se queda en el nivel Base (ver
        // TeacherProfile::maxAllowedRate() y el test dedicado de progresión
        // de nivel en MonetizationIntegrityTest).
        $profile->update(['completed_classes_count' => 5, 'is_experienced' => true]);
        $this->assertSame(20, $profile->fresh()->maxAllowedRate());
    }

    public function test_diagnostic_creates_generic_class_request(): void
    {
        $parent = User::factory()->create(['email_verified_at' => now()]);
        $parent->assignRole('parent');

        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Diagnóstico',
            'grade_level' => 'secundaria',
        ]);

        $subject = Subject::create(['name' => 'Física', 'level' => 'secundaria']);

        $this->actingAs($parent)
            ->get('/diagnostics/create')
            ->assertOk();

        $this->actingAs($parent)
            ->post('/diagnostics', [
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'difficulty_text' => 'Necesita apoyo para entender movimiento rectilíneo uniforme.',
                'school_feedback' => 'Debe practicar ejercicios.',
                'goal' => 'prepare_exam',
                'urgency' => 'this_week',
            ])
            ->assertRedirect(route('class-requests.index'));

        $this->assertDatabaseHas('class_requests', [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => null,
            'status' => 'open',
        ]);
    }
}
