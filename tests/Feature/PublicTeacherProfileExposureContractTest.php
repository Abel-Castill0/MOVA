<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Contrato de exposición pública, forward-looking — distinto de
 * TeacherPublicVisibilityTest.php y WelcomeFeaturedTeachersExposureTest.php
 * (esos prueban una lista negra: "estos campos concretos no aparecen").
 * Este archivo prueba una ALLOWLIST: "las claves del payload público son un
 * subconjunto de las permitidas, sean las que sean" — así que si mañana se
 * añade `bank_account`, `tax_id`, `whatsapp_number` o cualquier campo nuevo
 * a `TeacherProfile` y termina alcanzando este payload por cualquier vía
 * (una migración nueva, un fillable ampliado, un `with()` distinto), este
 * test falla aunque nadie haya escrito una aserción explícita para ese
 * campo todavía. Es la protección contra la CLASE de bug (proyección
 * pública insuficientemente estricta), no solo contra los síntomas ya
 * conocidos — ver la sección de causa raíz en
 * docs/MOVA_DESIGN_AUDIT_FINAL.md.
 *
 * Usa el macro `inertiaPage()` (Inertia\Testing\TestResponseMacros) para
 * leer el array real de props tal como Inertia lo serializó para la
 * respuesta — la misma serialización que recibe un navegador real, no una
 * inspección directa del controller.
 */
class PublicTeacherProfileExposureContractTest extends TestCase
{
    use RefreshDatabase;

    /** Único contrato de campos permitidos para un profesor en CUALQUIER superficie pública. */
    private const ALLOWED_TEACHER_KEYS = ['id', 'bio', 'hourly_rate', 'avg_rating', 'review_count', 'classes_count', 'user', 'subjects'];

    private const ALLOWED_USER_KEYS = ['id', 'name', 'avatar_url'];

    private const ALLOWED_SUBJECT_KEYS = ['id', 'name', 'level'];

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('teacher', 'web');

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacherUser->id,
            'is_verified' => true,
            'bio' => 'Bio de prueba',
            'hourly_rate' => 30,
            'yape_number' => '999888777',
            'plin_number' => '111222333',
            'rejection_reason' => 'no debería importar, is_verified=true',
        ]);
        $profile->subjects()->attach(
            Subject::create(['name' => 'Materia de prueba', 'level' => 'secundaria'])->id
        );
    }

    public function test_marketplace_teacher_payload_keys_are_a_subset_of_the_public_allowlist(): void
    {
        $response = $this->get(route('marketplace'));
        $response->assertOk();

        $teacher = $response->inertiaPage()['props']['teachers']['data'][0] ?? null;

        $this->assertNotNull($teacher, 'La respuesta no trajo ningún profesor — revisar el fixture del test.');
        $this->assertAllowlisted($teacher, self::ALLOWED_TEACHER_KEYS, 'Marketplace teacher');
        $this->assertAllowlisted($teacher['user'], self::ALLOWED_USER_KEYS, 'Marketplace teacher.user');
        $this->assertNotEmpty($teacher['subjects'], 'El fixture debe traer al menos una materia para probar su allowlist.');
        $this->assertAllowlisted($teacher['subjects'][0], self::ALLOWED_SUBJECT_KEYS, 'Marketplace teacher.subjects[0]');
    }

    public function test_homepage_featured_teacher_payload_keys_are_a_subset_of_the_public_allowlist(): void
    {
        $response = $this->get(route('welcome'));
        $response->assertOk();

        $teacher = $response->inertiaPage()['props']['featuredTeachers'][0] ?? null;

        $this->assertNotNull($teacher, 'La respuesta no trajo ningún profesor destacado — revisar el fixture del test.');
        $this->assertAllowlisted($teacher, self::ALLOWED_TEACHER_KEYS, 'Welcome featuredTeacher');
        $this->assertAllowlisted($teacher['user'], self::ALLOWED_USER_KEYS, 'Welcome featuredTeacher.user');
        $this->assertNotEmpty($teacher['subjects'], 'El fixture debe traer al menos una materia para probar su allowlist.');
        $this->assertAllowlisted($teacher['subjects'][0], self::ALLOWED_SUBJECT_KEYS, 'Welcome featuredTeacher.subjects[0]');
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $allowedKeys
     */
    private function assertAllowlisted(array $payload, array $allowedKeys, string $label): void
    {
        $unexpected = array_diff(array_keys($payload), $allowedKeys);

        $this->assertEmpty(
            $unexpected,
            "{$label} incluye clave(s) fuera del allowlist público: ".implode(', ', $unexpected)
            .'. Si el campo es genuinamente público, añádelo a ALLOWED_TEACHER_KEYS con intención explícita '
            .'— este test existe para que esa decisión sea deliberada, no accidental.'
        );
    }
}
