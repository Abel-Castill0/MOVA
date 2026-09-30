<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C-P1-PHONE-VERIFICATION: el OTP solo se entrega por WhatsApp. En producción
 * con WHATSAPP_ENABLED=false la verificación es imposible, así que la UI no
 * debe dirigir a ella (redirect tras verificar email, banner de "5 créditos
 * gratis", ítem del checklist) ni simular un fallo pasajero.
 */
class PhoneVerificationAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('teacher', 'web');
    }

    private function asProductionWithoutWhatsApp(): void
    {
        $this->app['env'] = 'production';
        config(['services.whatsapp.enabled' => false]);
    }

    public function test_send_is_refused_truthfully_without_generating_a_code(): void
    {
        $this->asProductionWithoutWhatsApp();
        $user = User::factory()->create(['phone' => '+51987654321']);

        // Con env=production el CSRF deja de omitirse en tests; no es lo que
        // se prueba aquí.
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->actingAs($user)->post(route('phone.verification.send'))
            ->assertSessionHasErrors(['phone' => 'La verificación por WhatsApp todavía no está habilitada en MOVA.']);

        $this->assertNull($user->fresh()->phone_verification_code_hash);
        $this->assertFalse(RateLimiter::tooManyAttempts('whatsapp-otp-phone:51987654321', 1));
    }

    public function test_page_reports_unavailability(): void
    {
        $this->asProductionWithoutWhatsApp();
        $user = User::factory()->create(['phone' => '+51987654321']);

        $this->actingAs($user)->get(route('phone.verification.notice'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('available', false));
    }

    public function test_page_is_available_when_whatsapp_is_enabled(): void
    {
        $this->app['env'] = 'production';
        config(['services.whatsapp.enabled' => true]);
        $user = User::factory()->create(['phone' => '+51987654321']);

        $this->actingAs($user)->get(route('phone.verification.notice'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('available', true));
    }

    public function test_teacher_dashboard_does_not_require_an_impossible_phone_verification(): void
    {
        $this->asProductionWithoutWhatsApp();
        $teacher = User::factory()->create(['phone_verified_at' => null]);
        $teacher->assignRole('teacher');
        TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => false, 'credits_available' => 0, 'credits_reserved' => 0]);

        $this->actingAs($teacher)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('phone_verification_available', false)
                ->missing('profile_checklist.phone_verified'));
    }

    public function test_teacher_dashboard_keeps_the_phone_requirement_when_available(): void
    {
        config(['services.whatsapp.enabled' => true]);
        $teacher = User::factory()->create(['phone_verified_at' => null]);
        $teacher->assignRole('teacher');
        TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => false, 'credits_available' => 0, 'credits_reserved' => 0]);

        $this->actingAs($teacher)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('phone_verification_available', true)
                ->where('profile_checklist.phone_verified', false));
    }
}
