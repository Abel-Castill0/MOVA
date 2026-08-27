<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * MarketplaceController::index() y TeacherPublicController::show() son las
 * dos únicas superficies públicas (sin auth) que exponen datos de
 * TeacherProfile — sin test dedicado hasta ahora (grep de toda la suite:
 * el único hit era SitemapTest, que solo comprueba la URL, no el contenido
 * ni la autorización). Cubre lo que un cambio futuro en cualquiera de los
 * dos controllers podría romper en silencio:
 *
 * - visibilidad (verificado sí, no verificado no — 404, no solo "oculto");
 * - que `referral_code` nunca llegue a un visitante sin relación con el
 *   profesor, y sí llegue con el texto correcto para el dueño y para un
 *   padre con historial (bug real encontrado y corregido en esta pasada:
 *   `is_own_profile` antes se aproximaba en el frontend con "¿el usuario
 *   tiene el rol teacher?", que podía mostrar el texto equivocado si un
 *   mismo User tenía ambos roles);
 * - que Marketplace jamás exponga `referral_code` en su listado (columnas
 *   explícitas en el controller, ver el comentario ahí).
 */
class TeacherPublicVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_a_verified_teacher_profile_is_publicly_viewable(): void
    {
        $teacher = $this->verifiedTeacher();

        $this->get(route('teachers.show', $teacher))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teachers/Show')
                ->where('teacher.id', $teacher->id)
                ->where('teacher.is_verified', true)
            );
    }

    public function test_an_unverified_teacher_profile_is_not_publicly_viewable(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $teacher = TeacherProfile::create(['user_id' => $teacherUser->id, 'is_verified' => false]);

        $this->get(route('teachers.show', $teacher))->assertNotFound();
    }

    public function test_marketplace_index_only_lists_verified_teachers(): void
    {
        $verified = $this->verifiedTeacher();
        $unverifiedUser = User::factory()->create();
        $unverifiedUser->assignRole('teacher');
        TeacherProfile::create(['user_id' => $unverifiedUser->id, 'is_verified' => false]);

        $this->get(route('marketplace'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Marketplace/Index')
                ->has('teachers.data', 1)
                ->where('teachers.data.0.id', $verified->id)
            );
    }

    /**
     * P0 real encontrado escribiendo este test, no hipotético: el segundo
     * argumento de columnas de paginate() (['id','user_id','bio',
     * 'hourly_rate']) se ignoraba silenciosamente en cuanto el query ya
     * tenía withAvg()/withCount() encadenados — MarketplaceController
     * devolvía la fila COMPLETA de TeacherProfile (yape_number,
     * plin_number, referral_code, rejection_reason, credits_available/
     * reserved, todo) a este endpoint público sin autenticación.
     * Confirmado con json_encode() del resultado real antes de corregirlo.
     * Corregido moviendo el allow-list a un ->select() explícito antes de
     * with()/withAvg()/withCount().
     */
    public function test_marketplace_index_never_exposes_sensitive_teacher_profile_fields(): void
    {
        $teacher = $this->verifiedTeacher();
        $teacher->update(['yape_number' => '999888777', 'plin_number' => '111222333']);

        $this->get(route('marketplace'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('teachers.data.0.referral_code')
                ->missing('teachers.data.0.yape_number')
                ->missing('teachers.data.0.plin_number')
                ->missing('teachers.data.0.rejection_reason')
                ->missing('teachers.data.0.reviewed_by')
                ->missing('teachers.data.0.credits_available')
                ->missing('teachers.data.0.credits_reserved')
                ->has('teachers.data.0.id')
                ->has('teachers.data.0.bio')
                ->has('teachers.data.0.hourly_rate')
            );
    }

    public function test_an_anonymous_visitor_never_receives_the_referral_code(): void
    {
        $teacher = $this->verifiedTeacher();

        $this->get(route('teachers.show', $teacher))
            ->assertInertia(fn (Assert $page) => $page
                ->where('teacher.referral_code', null)
                ->where('teacher.is_own_profile', false)
            );
    }

    public function test_an_unrelated_logged_in_parent_never_receives_the_referral_code(): void
    {
        $teacher = $this->verifiedTeacher();
        $parent = User::factory()->create();
        $parent->assignRole('parent');

        $this->actingAs($parent)
            ->get(route('teachers.show', $teacher))
            ->assertInertia(fn (Assert $page) => $page
                ->where('teacher.referral_code', null)
                ->where('teacher.is_own_profile', false)
            );
    }

    public function test_the_teacher_viewing_their_own_profile_receives_the_code_and_the_owner_flag(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $teacher = TeacherProfile::create(['user_id' => $teacherUser->id, 'is_verified' => true]);

        $this->actingAs($teacherUser)
            ->get(route('teachers.show', $teacher))
            ->assertInertia(fn (Assert $page) => $page
                ->where('teacher.referral_code', $teacher->referral_code)
                ->where('teacher.is_own_profile', true)
            );
    }

    /**
     * El caso que motivó separar `is_own_profile` del rol del usuario: un
     * padre con una clase COMPLETADA con este profesor sí recibe el código
     * (para repetirlo), pero NO es el dueño del perfil — el texto debe ser
     * "tu código CON este profesor", no "tu código para compartir".
     */
    public function test_a_repeat_parent_receives_the_code_but_is_not_flagged_as_the_owner(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacherUser->id, 'is_verified' => true]);

        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);
        $parentUser = User::factory()->create();
        $parentUser->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parentUser->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'accepted',
        ]);
        Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => now()->subDay(),
            'duration_minutes' => 60,
            'price_frozen_pen' => 20.00,
            'status' => 'completed',
        ]);

        $this->actingAs($parentUser)
            ->get(route('teachers.show', $profile))
            ->assertInertia(fn (Assert $page) => $page
                ->where('teacher.referral_code', $profile->referral_code)
                ->where('teacher.is_own_profile', false)
            );
    }

    private function verifiedTeacher(): TeacherProfile
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');

        return TeacherProfile::create([
            'user_id' => $teacherUser->id,
            'is_verified' => true,
            'bio' => 'Profesor de prueba.',
            'hourly_rate' => 30,
        ]);
    }
}
