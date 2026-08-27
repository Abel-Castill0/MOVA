<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Mismo bug que MarketplaceController::index() (ver
 * TeacherPublicVisibilityTest.php y el P0 de
 * docs/MOVA_DESIGN_AUDIT_FINAL.md), encontrado en un segundo controller
 * mientras se hacía la auditoría acotada de superficies públicas que
 * ese incidente recomendó: WelcomeController::index() — la HOME PÚBLICA,
 * la ruta de mayor tráfico de todo MOVA — tenía el mismo patrón:
 * `->get(['id', 'user_id', 'bio', 'hourly_rate'])` DESPUÉS de
 * `withCount()`/`withAvg()`. `get($columns)` no es más determinista que
 * `paginate($n, $columns)` en esta situación — de hecho `paginate()` llama
 * a `get($columns)` internamente, es el mismo mecanismo.
 *
 * Verificado en vivo con json_encode() antes de corregir: la home pública
 * devolvía yape_number, plin_number, referral_code, rejection_reason y
 * reviewed_by de cada profesor destacado (6 por defecto, sin
 * autenticación, en la página que Google indexa primero).
 *
 * Este archivo prueba las DOS direcciones del contrato, no solo "no fuga
 * secretos" (eso por sí solo no garantiza "expone lo necesario"):
 * - negative: los campos sensibles nunca aparecen;
 * - positive: los campos que Welcome.vue realmente consume (verificado
 *   leyendo la plantilla: t.id, t.user.name, t.avg_rating, t.classes_count,
 *   t.bio, t.subjects, t.hourly_rate) sí llegan.
 *
 * Y prueba la combinación peligrosa completa end-to-end (select + with +
 * withCount + withAvg + orderBy + limit), no solo el JSON final aislado —
 * es una request HTTP real contra la ruta real, así que si alguien quita
 * el ->select() mañana, este test vuelve a fallar exactamente como falló
 * aquí antes de la corrección (verificado revirtiendo el controller).
 */
class WelcomeFeaturedTeachersExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_homepage_never_exposes_sensitive_teacher_profile_fields(): void
    {
        $teacher = $this->verifiedFeaturedTeacher();
        $teacher->update(['yape_number' => '999888777', 'plin_number' => '111222333']);

        $this->get(route('welcome'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('featuredTeachers.0.yape_number')
                ->missing('featuredTeachers.0.plin_number')
                ->missing('featuredTeachers.0.referral_code')
                ->missing('featuredTeachers.0.rejection_reason')
                ->missing('featuredTeachers.0.reviewed_by')
                ->missing('featuredTeachers.0.user_id')
                ->missing('featuredTeachers.0.credits_available')
                ->missing('featuredTeachers.0.credits_reserved')
            );
    }

    public function test_the_public_homepage_exposes_exactly_what_the_featured_teacher_card_needs(): void
    {
        $this->verifiedFeaturedTeacher();

        $this->get(route('welcome'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('featuredTeachers.0.id')
                ->has('featuredTeachers.0.bio')
                ->has('featuredTeachers.0.hourly_rate')
                ->has('featuredTeachers.0.classes_count')
                ->has('featuredTeachers.0.user.name')
                ->has('featuredTeachers.0.subjects')
            );
    }

    public function test_the_homepage_only_features_verified_teachers(): void
    {
        $this->verifiedFeaturedTeacher();
        $unverifiedUser = User::factory()->create();
        $unverifiedUser->assignRole('teacher');
        TeacherProfile::create(['user_id' => $unverifiedUser->id, 'is_verified' => false]);

        $this->get(route('welcome'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('featuredTeachers', 1)
            );
    }

    private function verifiedFeaturedTeacher(): TeacherProfile
    {
        \Spatie\Permission\Models\Role::findOrCreate('teacher', 'web');

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');

        return TeacherProfile::create([
            'user_id' => $teacherUser->id,
            'is_verified' => true,
            'bio' => 'Profesor destacado de prueba.',
            'hourly_rate' => 30,
        ]);
    }
}
