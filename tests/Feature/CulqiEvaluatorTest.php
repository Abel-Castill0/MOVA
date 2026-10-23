<?php

namespace Tests\Feature;

use App\Console\Commands\CulqiEvaluator;
use App\Models\LegalAcceptance;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cuenta de evaluación de Culqi: privilegios mínimos, sin contraseña conocida y revocable sin tocar registros financieros.
 */
class CulqiEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'revision.culqi@correo-real.pe';

    public function test_creating_the_evaluator_gives_a_least_privilege_teacher_with_an_unknown_password(): void
    {
        $this->artisan('mova:culqi-evaluator', ['action' => 'create', 'email' => self::EMAIL])->assertExitCode(0);

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $profile = TeacherProfile::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(CulqiEvaluator::NAME, $user->name);
        $this->assertSame(['teacher'], $user->getRoleNames()->all());
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse((bool) $profile->is_verified, 'No debe figurar como profesor verificado.');
        $this->assertSame(0, (int) $profile->credits_available);
        $this->assertSame(0, $profile->subjects()->count());
        $this->assertNull($user->suspended_at);
        // Contraseña desconocida: hash aleatorio de 64 caracteres, nunca una contraseña conocida ni impresa.
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('password', $user->password));
    }

    public function test_the_evaluator_cannot_reach_admin_areas_nor_appear_in_the_public_catalog(): void
    {
        $this->artisan('mova:culqi-evaluator', ['action' => 'create', 'email' => self::EMAIL])->assertExitCode(0);
        $user = User::where('email', self::EMAIL)->firstOrFail();
        LegalAcceptance::recordMissingCurrent($user, null);

        $this->actingAs($user)->get('/admin/complaints')->assertStatus(403);
        $this->actingAs($user)->get('/admin/recharges')->assertStatus(403);
        $this->actingAs($user)->get(route('marketplace'))->assertInertia(fn ($page) => $page->where('teachers.total', 0));
        // Sí puede ver su propia pantalla de créditos (único flujo que debe recorrer).
        $this->actingAs($user)->get(route('teacher.credits.index'))->assertOk();
    }

    public function test_it_refuses_to_touch_an_existing_non_evaluator_account(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'name' => 'Profesora Real']);

        $this->artisan('mova:culqi-evaluator', ['action' => 'create', 'email' => self::EMAIL])->assertExitCode(1);
        $this->artisan('mova:culqi-evaluator', ['action' => 'revoke', 'email' => self::EMAIL])->assertExitCode(1);

        $this->assertSame('Profesora Real', User::where('email', self::EMAIL)->value('name'));
    }

    public function test_revoking_deletes_an_unused_evaluator(): void
    {
        $this->artisan('mova:culqi-evaluator', ['action' => 'create', 'email' => self::EMAIL])->assertExitCode(0);
        $this->artisan('mova:culqi-evaluator', ['action' => 'revoke', 'email' => self::EMAIL])->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_revoking_an_evaluator_with_purchases_suspends_it_and_keeps_every_financial_record(): void
    {
        $this->artisan('mova:culqi-evaluator', ['action' => 'create', 'email' => self::EMAIL])->assertExitCode(0);
        $user = User::where('email', self::EMAIL)->firstOrFail();
        $profile = TeacherProfile::where('user_id', $user->id)->firstOrFail();
        RechargeRequest::create([
            'teacher_profile_id' => $profile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5, 'amount_pen' => '10.00',
            'payment_method' => 'culqi', 'operation_number' => null, 'operation_number_normalized' => null, 'status' => 'pending',
        ]);
        $oldHash = $user->password;

        $this->artisan('mova:culqi-evaluator', ['action' => 'revoke', 'email' => self::EMAIL])->assertExitCode(0);

        $user->refresh();
        $this->assertNotNull($user->suspended_at);
        $this->assertNotSame($oldHash, $user->password, 'La contraseña queda invalidada.');
        $this->assertSame(1, RechargeRequest::where('teacher_profile_id', $profile->id)->count());

        LegalAcceptance::recordMissingCurrent($user, null);
        $this->actingAs($user)->get(route('teacher.credits.index'))->assertRedirect(route('suspended'));
    }

    public function test_status_never_prints_secrets(): void
    {
        $this->artisan('mova:culqi-evaluator', ['action' => 'create', 'email' => self::EMAIL])->assertExitCode(0);

        $this->artisan('mova:culqi-evaluator', ['action' => 'status', 'email' => self::EMAIL])
            ->expectsOutputToContain('Cuenta de evaluación')
            ->doesntExpectOutputToContain('password')
            ->assertExitCode(0);
    }
}
