<?php

namespace Tests\Feature;

use App\Models\LegalAcceptance;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * «Mis créditos» → «Recargar créditos»: lo que ve y puede hacer cada rol, con el pago en línea activo (staging de
 * integración con Culqi) y con el pago cerrado (producción: comercio sin aprobar). El control de acceso es del servidor.
 */
class CreditsPurchaseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['teacher', 'parent', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function culqiOn(string $allowlist = ''): void
    {
        config([
            'payments.enabled' => true,
            'payments.provider' => 'culqi',
            'payments.culqi.env' => 'test',
            'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789',
            'payments.culqi.private_key' => 'sk_test_ZyXwVu9876543210',
            'payments.culqi.webhook_secret' => 'whsec_0123456789abcdef0123456789abcdef01234567',
            'payments.culqi.webhooks_enabled' => true,
            'payments.culqi.methods' => 'card',
            'payments.culqi.checkout_allowlist' => $allowlist,
        ]);
    }

    private function productionLike(): void
    {
        config([
            'payments.enabled' => true,
            'payments.provider' => 'mercadopago',
            'payments.mercadopago.new_payments_enabled' => false,
            'payments.mercadopago.public_key' => 'APP_USR-public',
            'payments.culqi.public_key' => null,
            'payments.culqi.private_key' => null,
            'credits.recharges.enabled' => false,
        ]);
    }

    private function user(string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => 'password']);
        $user->assignRole($role);
        LegalAcceptance::recordMissingCurrent($user, null);
        if ($role === 'teacher') {
            TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);
        }

        return $user;
    }

    private const PRICES = ['inicio' => [5, '10.00'], 'esencial' => [10, '19.80'], 'impulso' => [15, '29.40'], 'avance' => [20, '38.80'], 'pro' => [30, '57.60']];

    public function test_the_evaluator_sees_five_server_priced_packages_and_the_culqi_checkout_with_the_test_label_state(): void
    {
        $this->culqiOn('culqi.revision@movaeduca.me');
        $evaluator = $this->user('teacher', 'culqi.revision@movaeduca.me');

        $this->actingAs($evaluator)->get(route('teacher.credits.index'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('checkoutProvider', 'culqi')
                ->where('payments.online', true)
                ->where('payments.testMode', true)
                ->has('packages', 5)
                ->where('packages.1.code', 'esencial')
                ->where('packages.1.credits', 10)
                ->where('packages.1.amount_pen', '19.80')
                ->where('packages.1.description', fn ($d) => is_string($d) && $d !== ''));

        $this->actingAs($evaluator)->post(route('teacher.credits.checkout.store'), ['package_code' => 'esencial', 'amount_pen' => '0.01'])
            ->assertRedirect();
        $recharge = RechargeRequest::firstOrFail();
        $this->assertSame('culqi', $recharge->payment_method);
        $this->assertSame(10, $recharge->credits);
        $this->assertSame('19.80', (string) $recharge->amount_pen);
        $this->assertSame(0, $evaluator->teacherProfile->fresh()->credits_available, 'Crear la orden no acredita nada');
    }

    public function test_the_five_packages_keep_their_agreed_credits_and_prices(): void
    {
        $this->culqiOn();
        $teacher = $this->user('teacher', 'profesor@example.com');

        $packages = collect($this->actingAs($teacher)->get(route('teacher.credits.index'))->original->getData()['page']['props']['packages'])
            ->mapWithKeys(fn ($p) => [$p['code'] => [$p['credits'], (string) $p['amount_pen']]])->all();

        $this->assertSame(self::PRICES, $packages);
    }

    public function test_another_teacher_outside_the_allowlist_gets_a_read_only_view_and_cannot_pay(): void
    {
        $this->culqiOn('culqi.revision@movaeduca.me');
        $other = $this->user('teacher', 'otro-profesor@example.com');

        $this->actingAs($other)->get(route('teacher.credits.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('checkoutProvider', null)->where('payments.online', false)->has('packages', 5));

        $this->actingAs($other)->post(route('teacher.credits.checkout.store'), ['package_code' => 'esencial'])->assertStatus(503);
        $this->assertSame(0, RechargeRequest::count());
    }

    public function test_with_payments_closed_a_teacher_sees_the_prices_but_the_server_never_creates_a_charge(): void
    {
        $this->productionLike();
        $teacher = $this->user('teacher', 'profesor@example.com');

        $this->actingAs($teacher)->get(route('teacher.credits.index'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('checkoutProvider', null)
                ->where('rechargesEnabled', false)
                ->where('payments.online', false)
                ->has('packages', 5));

        $this->actingAs($teacher)->post(route('teacher.credits.checkout.store'), ['package_code' => 'esencial'])->assertStatus(503);
        $this->actingAs($teacher)->post(route('teacher.credits.recharge'), ['package_code' => 'esencial', 'payment_method' => 'yape', 'operation_number' => '123456'])->assertStatus(503);
        $this->assertSame(0, RechargeRequest::count());
    }

    public function test_parents_admins_and_guests_cannot_see_credits_nor_start_a_purchase(): void
    {
        $this->culqiOn();
        $parent = $this->user('parent', 'padre@example.com');
        $admin = $this->user('admin', 'admin@example.com');

        foreach ([$parent, $admin] as $user) {
            $this->actingAs($user)->get(route('teacher.credits.index'))->assertForbidden();
            $this->actingAs($user)->post(route('teacher.credits.checkout.store'), ['package_code' => 'esencial'])->assertForbidden();
        }
        $this->assertSame(0, RechargeRequest::count());

        auth()->logout();
        $this->get(route('teacher.credits.index'))->assertRedirect(route('login'));
        $this->post(route('teacher.credits.checkout.store'), ['package_code' => 'esencial'])->assertRedirect(route('login'));
    }

    public function test_the_public_catalogue_is_visible_to_guests_who_must_sign_in_as_a_teacher_to_buy(): void
    {
        $this->culqiOn('culqi.revision@movaeduca.me');

        $this->get(route('catalog.index'))->assertOk()->assertInertia(fn ($page) => $page->has('packages', 5));
        $this->get(route('catalog.package', 'esencial'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('viewer.authenticated', false)->where('payments.online', false));
    }
}
