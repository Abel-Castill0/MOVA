<?php

namespace Tests\Feature;

use App\Models\LegalAcceptance;
use App\Models\RechargeRequest;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Requisitos de revisión comercial de Culqi: catálogo público con imagen, descripción y precio visibles, botón de compra
 * funcional, información del comercio y páginas legales accesibles SIN iniciar sesión. Los precios salen del backend
 * (config/credits.php), nunca de los componentes Vue.
 */
class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pago en línea cerrado (estado real de producción hoy): la web debe decirlo con honestidad.
        config(['payments.enabled' => true, 'payments.provider' => 'mercadopago', 'payments.mercadopago.new_payments_enabled' => false]);
    }

    public function test_the_catalog_is_public_and_shows_every_credit_package_with_image_description_and_price(): void
    {
        $response = $this->get(route('catalog.index'));

        $response->assertOk()->assertInertia(function ($page) {
            $page->component('Catalog/Index')->has('packages', count(config('credits.packages')));
            foreach (config('credits.packages') as $code => $package) {
                $page->where('packages', fn ($packages) => collect($packages)->contains(
                    fn ($p) => $p['code'] === $code
                        && $p['amount_pen'] === $package['amount_pen']
                        && $p['credits'] === $package['credits']
                        && $p['currency'] === 'PEN'
                        && filled($p['description'])
                        && filled($p['image'])
                        && filled($p['alt'])
                ));
            }
        });
    }

    public function test_prices_come_from_the_backend_config_not_from_the_components(): void
    {
        config(['credits.packages.inicio.amount_pen' => '12.50', 'credits.packages.inicio.credits' => 5]);

        $this->get(route('catalog.index'))->assertInertia(fn ($page) => $page->where('packages', fn ($packages) => collect($packages)
            ->contains(fn ($p) => $p['code'] === 'inicio' && $p['amount_pen'] === '12.50' && $p['price_per_credit'] === '2.50')));

        $this->get(route('catalog.package', 'inicio'))->assertInertia(fn ($page) => $page->where('package.amount_pen', '12.50'));
    }

    public function test_every_image_referenced_by_the_catalog_exists_in_public(): void
    {
        $subject = Subject::create(['name' => 'Matemáticas', 'level' => 'todos']);
        $response = $this->get(route('catalog.index'))->assertOk();
        $props = $response->viewData('page')['props'];

        foreach (array_merge($props['packages'], $props['subjects']) as $item) {
            $this->assertFileExists(public_path(ltrim($item['image'], '/')), "Falta la imagen {$item['image']}");
            $this->assertNotSame('', trim($item['alt']), 'Cada imagen necesita texto alternativo.');
        }
        $this->assertSame($subject->id, $props['subjects'][0]['id']);
    }

    public function test_subjects_show_the_real_number_of_verified_teachers_and_never_claim_availability_they_do_not_have(): void
    {
        $math = Subject::create(['name' => 'Matemáticas', 'level' => 'todos']);
        $english = Subject::create(['name' => 'Inglés', 'level' => 'todos']);

        $verified = TeacherProfile::create(['user_id' => User::factory()->create()->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);
        $pending = TeacherProfile::create(['user_id' => User::factory()->create()->id, 'is_verified' => false, 'credits_available' => 0, 'credits_reserved' => 0]);
        $verified->subjects()->attach($math->id, ['specific_rate' => 20]);
        $pending->subjects()->attach($english->id, ['specific_rate' => 20]);

        $subjects = collect($this->get(route('catalog.index'))->viewData('page')['props']['subjects'])->keyBy('name');

        $this->assertSame(1, $subjects['Matemáticas']['verified_teachers']);
        $this->assertSame(0, $subjects['Inglés']['verified_teachers'], 'Un profesor sin verificar no cuenta como disponibilidad.');
        $this->assertSame((int) config('credits.class_rates.base'), $subjects['Matemáticas']['rate_cap']);
    }

    public function test_a_guest_can_open_a_package_detail_and_the_choice_survives_login(): void
    {
        $this->get(route('catalog.package', 'impulso'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Package')
                ->where('package.code', 'impulso')
                ->where('viewer.authenticated', false)
                ->where('package.amount_pen', config('credits.packages.impulso.amount_pen')))
            ->assertSessionHas('url.intended', route('catalog.package', 'impulso'));

        $teacher = $this->teacher();
        $this->post('/login', ['email' => $teacher->email, 'password' => 'password'])
            ->assertRedirect(route('catalog.package', 'impulso'));
    }

    public function test_an_unknown_package_is_a_404_and_the_page_never_accepts_amounts_from_the_url(): void
    {
        $this->get('/comprar-creditos/inventado')->assertNotFound();
        $this->get('/comprar-creditos/inicio?amount=0.01&credits=9999')
            ->assertInertia(fn ($page) => $page->where('package.amount_pen', config('credits.packages.inicio.amount_pen'))->where('package.credits', config('credits.packages.inicio.credits')));
    }

    public function test_the_payment_state_is_honest_when_online_payments_are_not_active(): void
    {
        $this->get(route('catalog.index'))->assertInertia(fn ($page) => $page
            ->where('payments.online', false)->where('payments.testMode', false)->where('payments.methods', []));

        $teacher = $this->teacher();
        $this->actingAs($teacher)->get(route('catalog.package', 'inicio'))
            ->assertInertia(fn ($page) => $page->where('viewer.isTeacher', true)->where('payments.online', false));

        // El botón «Continuar al pago» existe pero el backend jamás crea una recarga con el pago cerrado.
        $this->actingAs($teacher)->post(route('teacher.credits.checkout.store'), ['package_code' => 'inicio'])->assertStatus(503);
        $this->assertSame(0, RechargeRequest::count());
    }

    public function test_with_culqi_in_integration_mode_the_site_says_so_and_the_purchase_creates_a_server_priced_recharge(): void
    {
        config([
            'payments.provider' => 'culqi', 'payments.culqi.env' => 'test',
            'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789', 'payments.culqi.private_key' => 'sk_test_ZyXwVu9876543210',
            'payments.culqi.webhooks_enabled' => false, 'payments.culqi.encrypt_payloads' => false, 'payments.culqi.methods' => 'card',
        ]);
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get(route('catalog.package', 'pro'))->assertInertia(fn ($page) => $page
            ->where('payments.online', true)->where('payments.testMode', true)->where('payments.methods', ['card']));

        $this->actingAs($teacher)->post(route('teacher.credits.checkout.store'), ['package_code' => 'pro', 'amount_pen' => '0.01', 'credits' => 9999]);

        $recharge = RechargeRequest::firstOrFail();
        $this->assertSame('culqi', $recharge->payment_method);
        $this->assertSame(config('credits.packages.pro.amount_pen'), $recharge->amount_pen);
        $this->assertSame(config('credits.packages.pro.credits'), $recharge->credits);
    }

    public function test_a_parent_sees_that_credits_are_for_teachers(): void
    {
        $parent = User::factory()->create(['password' => 'password']);
        $parent->assignRole('parent');

        $this->actingAs($parent)->get(route('catalog.package', 'inicio'))
            ->assertInertia(fn ($page) => $page->where('viewer.isTeacher', false)->where('viewer.role', 'parent'));
    }

    public function test_the_public_legal_pages_the_refund_policy_and_the_complaints_book_are_reachable_without_login(): void
    {
        foreach (['legal.terms', 'legal.privacy', 'legal.refunds', 'complaints.create', 'catalog.index'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('legal.refunds'))->assertInertia(fn ($page) => $page
            ->component('Legal/Refunds')->where('refundDays', (int) config('credits.refund_days'))->where('complaintResponseDays', (int) config('legal.complaint_response_days')));
    }

    public function test_the_merchant_identity_and_contact_are_shared_only_when_configured_and_socials_must_be_https(): void
    {
        config([
            'legal.provider.business_name' => 'Comercio de Prueba S.A.C.', 'legal.provider.ruc' => '20123456789', 'legal.provider.address' => 'Av. Prueba 123, Lima',
            'legal.support_phone' => '+51 999 999 999',
            'legal.social.instagram' => 'https://www.instagram.com/mova-prueba', 'legal.social.facebook' => 'http://inseguro.example', 'legal.social.tiktok' => null,
        ]);

        $this->get(route('catalog.index'))->assertInertia(fn ($page) => $page
            ->where('merchant.business_name', 'Comercio de Prueba S.A.C.')->where('merchant.ruc', '20123456789')->where('merchant.address', 'Av. Prueba 123, Lima')
            ->where('support.phone', '+51 999 999 999')
            ->where('support.social', ['instagram' => 'https://www.instagram.com/mova-prueba']));
    }

    public function test_the_sitemap_lists_the_catalog_and_the_refund_policy(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(route('catalog.index'), $xml);
        $this->assertStringContainsString(route('legal.refunds'), $xml);
    }

    private function teacher(): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole('teacher');
        LegalAcceptance::recordMissingCurrent($user, null);
        TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);

        return $user->fresh();
    }
}
