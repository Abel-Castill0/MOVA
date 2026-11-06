<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GrantCourtesyCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('teacher', 'web');
        Role::findOrCreate('parent', 'web');
    }

    private function teacher(string $email = 'profe@example.test', int $balance = 3): TeacherProfile
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole('teacher');

        return TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => $balance, 'credits_reserved' => 0]);
    }

    public function test_grants_once_as_an_auditable_ledger_deposit_without_inventing_a_payment(): void
    {
        $profile = $this->teacher();

        $this->artisan('mova:grant-credits', ['email' => 'PROFE@example.test', 'credits' => 10, '--reference' => 'cortesia-20261105-profe'])
            ->expectsOutputToContain('Saldo disponible: 13')->assertSuccessful();

        $this->assertSame(13, $profile->fresh()->credits_available);
        $tx = CreditTransaction::where('teacher_profile_id', $profile->id)->sole();
        $this->assertSame(['deposit', 10, 'grant:cortesia-20261105-profe'], [$tx->type, $tx->amount, $tx->idempotency_key]);
        $this->assertNull($tx->recharge_request_id);
        $this->assertSame(0, RechargeRequest::count(), 'no se simula ninguna recarga ni pago');
    }

    public function test_repeating_the_same_reference_never_credits_twice(): void
    {
        $profile = $this->teacher();
        $args = ['email' => 'profe@example.test', 'credits' => 10, '--reference' => 'cortesia-20261105-profe'];

        $this->artisan('mova:grant-credits', $args)->assertSuccessful();
        $this->artisan('mova:grant-credits', $args)->expectsOutputToContain('ya estaba aplicada')->assertSuccessful();

        $this->assertSame(13, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('teacher_profile_id', $profile->id)->count());
    }

    public function test_reusing_a_reference_for_another_teacher_or_amount_fails_cleanly(): void
    {
        $this->teacher();
        $other = $this->teacher('otro@example.test');
        $this->artisan('mova:grant-credits', ['email' => 'profe@example.test', 'credits' => 10, '--reference' => 'cortesia-unica'])->assertSuccessful();

        $this->artisan('mova:grant-credits', ['email' => 'otro@example.test', 'credits' => 10, '--reference' => 'cortesia-unica'])
            ->expectsOutputToContain('ya se usó')->assertFailed();
        $this->artisan('mova:grant-credits', ['email' => 'profe@example.test', 'credits' => 5, '--reference' => 'cortesia-unica'])
            ->expectsOutputToContain('ya se usó')->assertFailed();

        $this->assertSame(3, $other->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::count());
        $this->assertSame('Cortesía MOVA', CreditTransaction::sole()->description);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $profile = $this->teacher();

        $this->artisan('mova:grant-credits', ['email' => 'profe@example.test', 'credits' => 10, '--reference' => 'cortesia-20261105-profe', '--dry-run' => true])
            ->expectsOutputToContain('Nada cambió')->assertSuccessful();

        $this->assertSame(3, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_refuses_non_teachers_closed_suspended_unknown_accounts_and_bad_input(): void
    {
        $parent = User::factory()->create(['email' => 'padre@example.test']);
        $parent->assignRole('parent');
        $this->artisan('mova:grant-credits', ['email' => 'padre@example.test', 'credits' => 10, '--reference' => 'cortesia-padre'])->assertFailed();

        $closed = $this->teacher('cerrada@example.test');
        $closed->user->forceFill(['account_closed_at' => now()])->save();
        $this->artisan('mova:grant-credits', ['email' => 'cerrada@example.test', 'credits' => 10, '--reference' => 'cortesia-cerrada'])->assertFailed();

        $suspended = $this->teacher('suspendida@example.test');
        $suspended->user->forceFill(['suspended_at' => now()])->save();
        $this->artisan('mova:grant-credits', ['email' => 'suspendida@example.test', 'credits' => 10, '--reference' => 'cortesia-susp'])->assertFailed();

        $this->artisan('mova:grant-credits', ['email' => 'nadie@example.test', 'credits' => 10, '--reference' => 'cortesia-nadie'])->assertFailed();

        $this->teacher('ok@example.test');
        foreach ([0, 101, 'diez'] as $bad) {
            $this->artisan('mova:grant-credits', ['email' => 'ok@example.test', 'credits' => $bad, '--reference' => 'cortesia-mala'])->assertFailed();
        }
        $this->artisan('mova:grant-credits', ['email' => 'ok@example.test', 'credits' => 10])->assertFailed();

        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_the_ledger_stays_reconciled_after_a_grant(): void
    {
        $this->teacher(balance: 0);
        $this->artisan('mova:grant-credits', ['email' => 'profe@example.test', 'credits' => 10, '--reference' => 'cortesia-20261105-profe'])->assertSuccessful();

        $this->artisan('mova:reconcile-ledger')->assertSuccessful();
    }
}
