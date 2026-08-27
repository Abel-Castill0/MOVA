<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Encontrado al verificar (a pedido explícito) que `reviewedBy:id,name`
 * en AdminController::pendingTeachers() no dejara una fuga hermana: la
 * relación `user` en el mismo método SÍ estaba sin acotar. Verificado con
 * json_encode() antes de corregir: el `User` completo llegaba a
 * Admin/PendingTeachers.vue, incluyendo `phone_verification_code_hash`,
 * `suspension_reason`, `whatsapp_opt_in_at`/`opt_out_at`, etc.
 *
 * Distinto del P0 de `/marketplace`/`/`: esta ruta exige `role:admin`
 * (verificado en routes/web.php) — no es una superficie pública. Se
 * documenta y corrige por el mismo principio de exposición mínima, no
 * porque sea la misma clase de severidad.
 */
class AdminPendingTeachersExposureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_route_requires_admin_role(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');

        $this->actingAs($teacherUser)
            ->get(route('admin.teachers.pending'))
            ->assertForbidden();
    }

    public function test_pending_and_rejected_teacher_user_relation_never_exposes_internal_user_fields(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $pendingTeacherUser = User::factory()->create();
        $pendingTeacherUser->assignRole('teacher');
        $pendingProfile = TeacherProfile::create(['user_id' => $pendingTeacherUser->id, 'is_verified' => false]);
        $pendingProfile->subjects()->attach(
            Subject::create(['name' => 'Materia pendiente', 'level' => 'secundaria'])->id,
            ['specific_rate' => 45]
        );

        $rejectedTeacherUser = User::factory()->create();
        $rejectedTeacherUser->assignRole('teacher');
        $reviewer = User::factory()->create();
        $rejectedProfile = TeacherProfile::create([
            'user_id' => $rejectedTeacherUser->id,
            'is_verified' => false,
            'rejected_at' => now(),
            'rejection_reason' => 'Motivo de prueba',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
        $rejectedProfile->subjects()->attach(
            Subject::create(['name' => 'Materia rechazada', 'level' => 'secundaria'])->id,
            ['specific_rate' => 50]
        );

        $response = $this->actingAs($admin)->get(route('admin.teachers.pending'));
        $response->assertOk();
        $page = $response->inertiaPage()['props'];

        foreach (['pendingTeachers', 'rejectedTeachers'] as $key) {
            $userPayload = $page[$key][0]['user'];
            $unexpected = array_diff(array_keys($userPayload), ['id', 'name', 'email']);
            $this->assertEmpty(
                $unexpected,
                "{$key}[0].user incluye clave(s) internas inesperadas: ".implode(', ', $unexpected)
            );

            $subjectPayload = $page[$key][0]['subjects'][0] ?? null;
            $this->assertNotNull($subjectPayload, "{$key}[0].subjects debe traer al menos una materia para probar el pivot.");
            $this->assertArrayNotHasKey('pivot', $subjectPayload, "{$key}[0].subjects[0] no debe exponer specific_rate vía pivot.");
        }

        // reviewedBy sigue funcionando correctamente (ya estaba acotado) — no regresión.
        $this->assertSame($reviewer->name, $page['rejectedTeachers'][0]['reviewed_by']['name'] ?? null);
    }
}
