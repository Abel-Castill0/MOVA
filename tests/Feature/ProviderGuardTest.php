<?php

namespace Tests\Feature;

use App\Payment\Contracts\PaymentProviderContract;
use App\Payment\FakePaymentProvider;
use App\Support\ProviderGuard;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use App\WhatsApp\FakeWhatsAppProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * F-03 / GAP-05 — El fallback silencioso a los proveedores Fake era fail-OPEN:
 * un typo en la variable de entorno, una variable ausente o un config:cache
 * prematuro hacían que producción operase con FakePaymentProvider (que
 * responde 'paid' a todo) o FakeWhatsAppProvider (que no envía nada) sin
 * ninguna señal. Estos tests fijan el comportamiento fail-CLOSED.
 */
class ProviderGuardTest extends TestCase
{
    // ── Proveedor desconocido ────────────────────────────────────────────

    public function test_an_unknown_provider_aborts_instead_of_falling_back_to_fake(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no es un proveedor soportado/');

        ProviderGuard::resolve('pagos', 'PAYMENT_PROVIDER', 'culqui', ['culqi'], false, 'production');
    }

    public function test_a_typo_is_not_silently_accepted_even_when_the_feature_is_disabled(): void
    {
        // Deliberado: un typo es un error de configuración aunque la feature
        // esté apagada. Si se tolerara aquí, reaparecería el día que alguien
        // encienda la feature y nadie relacionaría el fallo con el typo.
        $this->expectException(RuntimeException::class);

        ProviderGuard::resolve('WhatsApp', 'WHATSAPP_PROVIDER', 'metaa', ['meta'], false, 'local');
    }

    public function test_an_empty_or_missing_provider_aborts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/vacía o no definida/');

        ProviderGuard::resolve('pagos', 'PAYMENT_PROVIDER', null, ['culqi'], false, 'production');
    }

    public function test_whitespace_only_provider_aborts(): void
    {
        $this->expectException(RuntimeException::class);

        ProviderGuard::resolve('pagos', 'PAYMENT_PROVIDER', '   ', ['culqi'], false, 'production');
    }

    // ── Fake en producción ───────────────────────────────────────────────

    public function test_fake_payments_in_production_with_payments_enabled_aborts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/insegura/');

        ProviderGuard::resolve('pagos', 'PAYMENT_PROVIDER', 'fake', ['culqi'], true, 'production');
    }

    public function test_fake_whatsapp_in_production_with_whatsapp_enabled_aborts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/insegura/');

        ProviderGuard::resolve('WhatsApp', 'WHATSAPP_PROVIDER', 'fake', ['meta'], true, 'production');
    }

    public function test_staging_is_not_treated_as_fake_friendly(): void
    {
        // Si alguien quiere Fake en staging debe apagar la feature de forma
        // explícita, no heredarlo de un valor por defecto.
        $this->expectException(RuntimeException::class);

        ProviderGuard::resolve('pagos', 'PAYMENT_PROVIDER', 'fake', ['culqi'], true, 'staging');
    }

    // ── Configuraciones legítimas ────────────────────────────────────────

    public function test_fake_in_production_is_allowed_while_the_feature_is_disabled(): void
    {
        // Es el estado real de MOVA hoy: Culqi no existe todavía, así que
        // pagos apagados + fake es correcto y no debe romper el arranque.
        $this->assertSame(
            'fake',
            ProviderGuard::resolve('pagos', 'PAYMENT_PROVIDER', 'fake', ['culqi'], false, 'production')
        );
    }

    public function test_fake_is_allowed_in_local_and_testing_even_with_the_feature_enabled(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $this->assertSame(
                'fake',
                ProviderGuard::resolve('WhatsApp', 'WHATSAPP_PROVIDER', 'fake', ['meta'], true, $environment),
                "El proveedor fake debe seguir siendo válido en {$environment}."
            );
        }
    }

    public function test_a_real_provider_is_accepted_in_production(): void
    {
        $this->assertSame(
            'meta',
            ProviderGuard::resolve('WhatsApp', 'WHATSAPP_PROVIDER', 'meta', ['meta'], true, 'production')
        );
    }

    public function test_provider_matching_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame(
            'meta',
            ProviderGuard::resolve('WhatsApp', 'WHATSAPP_PROVIDER', '  META ', ['meta'], true, 'production')
        );
    }

    // ── Integración real con el contenedor ───────────────────────────────

    public function test_the_container_still_resolves_the_fake_providers_under_the_test_environment(): void
    {
        $this->assertInstanceOf(FakePaymentProvider::class, app(PaymentProviderContract::class));
        $this->assertInstanceOf(FakeWhatsAppProvider::class, app(WhatsAppProviderContract::class));
    }

    public function test_the_container_binding_aborts_on_an_unknown_payment_provider(): void
    {
        config(['payments.provider' => 'stripe-typo']);
        $this->app->forgetInstance(PaymentProviderContract::class);

        $this->expectException(RuntimeException::class);

        app(PaymentProviderContract::class);
    }

    public function test_the_container_binding_aborts_on_an_unknown_whatsapp_provider(): void
    {
        config(['services.whatsapp.provider' => 'twilio']);
        $this->app->forgetInstance(WhatsAppProviderContract::class);

        $this->expectException(RuntimeException::class);

        app(WhatsAppProviderContract::class);
    }
}
