<?php

namespace App\Providers;

use App\Channels\SafeMailChannel;
use App\Payment\Contracts\PaymentProviderContract;
use App\Payment\CulqiPaymentProvider;
use App\Payment\FakePaymentProvider;
use App\Payment\MercadoPagoPaymentProvider;
use App\Support\ProviderGuard;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use App\WhatsApp\FakeWhatsAppProvider;
use App\WhatsApp\MetaCloudApiProvider;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Replace the default mail channel so email failures never crash a notification job
        $this->app->bind(MailChannel::class, SafeMailChannel::class);

        // Resuelve el provider de pagos según config/payments.php — un solo
        // punto de decisión, para que nada en el código instancie
        // FakePaymentProvider/CulqiPaymentProvider directamente.
        //
        // F-03: ProviderGuard es fail-CLOSED. Antes había un `default =>
        // new FakePaymentProvider()` que convertía cualquier valor no
        // reconocido (typo, variable ausente, config:cache prematuro) en el
        // proveedor falso — que responde 'paid' a todo. Ahora un proveedor
        // desconocido, o un Fake en producción con pagos habilitados,
        // detiene el arranque.
        $this->app->bind(PaymentProviderContract::class, function () {
            $paymentsEnabled = (bool) config('payments.enabled', false);

            $provider = ProviderGuard::resolve(
                kind: 'pagos',
                envVarName: 'PAYMENT_PROVIDER',
                provider: config('payments.provider'),
                supportedReal: ['culqi', 'mercadopago'],
                featureEnabled: $paymentsEnabled,
                environment: $this->app->environment(),
            );

            // Config financiera mínima cuando Mercado Pago está realmente
            // habilitado (sección de config fail-closed, pivot a Payments
            // API) — webhook_secret queda deliberadamente FUERA: solo se
            // exige cuando el webhook esté configurado/activo, no al
            // arrancar (ver config/payments.php).
            if ($provider === 'mercadopago') {
                ProviderGuard::requireConfig('Mercado Pago', $paymentsEnabled, [
                    'MERCADOPAGO_ACCESS_TOKEN' => config('payments.mercadopago.access_token'),
                    'MERCADOPAGO_APPLICATION_ID' => config('payments.mercadopago.application_id'),
                    'MERCADOPAGO_EXPECTED_COLLECTOR_ID' => config('payments.mercadopago.expected_collector_id'),
                    // CORREGIDO (hardening final): antes tenía un default
                    // silencioso (false = TEST) — TEST/PRODUCTION ahora debe
                    // ser una decisión explícita del operador al habilitar
                    // Mercado Pago, no algo heredado de un valor por defecto
                    // ambiguo.
                    'MERCADOPAGO_EXPECTED_LIVE_MODE' => config('payments.mercadopago.expected_live_mode'),
                ]);

                // WEBHOOK ENABLEMENT (ronda de hardening distribuido):
                // webhook_secret solo es obligatorio cuando el webhook está
                // REALMENTE declarado activo (MERCADOPAGO_WEBHOOKS_ENABLED=true)
                // — no al simple hecho de tener Mercado Pago habilitado
                // como proveedor de pagos (createPaymentAttempt()/
                // fetchPayment()/search no necesitan webhook_secret para
                // nada). Antes esto era una regla implícita sin ningún
                // flag que la representara; ahora es un chequeo explícito
                // y separado.
                if ((bool) config('payments.mercadopago.webhooks_enabled', false)) {
                    ProviderGuard::requireConfig('Mercado Pago Webhooks', $paymentsEnabled, [
                        'MERCADOPAGO_WEBHOOK_SECRET' => config('payments.mercadopago.webhook_secret'),
                    ]);
                }
            }

            // Sin rama `default`: cada proveedor se nombra explícitamente. Si
            // alguien añade uno a ProviderGuard y olvida instanciarlo aquí,
            // PHP lanza UnhandledMatchError en lugar de devolver el Fake en
            // silencio — que es el fallo que F-03 vino a eliminar.
            return match ($provider) {
                'culqi'              => new CulqiPaymentProvider(),
                'mercadopago'        => new MercadoPagoPaymentProvider(),
                ProviderGuard::FAKE  => new FakePaymentProvider(),
            };
        });

        // singleton() aquí, no bind(): FakeWhatsAppProvider guarda en memoria
        // lo que se "envió" para que los tests puedan inspeccionarlo — si
        // fuera bind() normal, cada app(WhatsAppProviderContract::class)
        // devolvería una instancia nueva y vacía, y las aserciones nunca
        // verían nada.
        $this->app->singleton(WhatsAppProviderContract::class, function () {
            // F-03: mismo guard fail-closed que en pagos. Un WHATSAPP_PROVIDER
            // mal escrito con WHATSAPP_ENABLED=true en producción significaba
            // que ningún mensaje salía mientras los logs y whatsapp_messages
            // reportaban normalidad — el fallo más caro de detectar.
            $provider = ProviderGuard::resolve(
                kind: 'WhatsApp',
                envVarName: 'WHATSAPP_PROVIDER',
                provider: config('services.whatsapp.provider'),
                supportedReal: ['meta'],
                featureEnabled: (bool) config('services.whatsapp.enabled', false),
                environment: $this->app->environment(),
            );

            return match ($provider) {
                'meta'              => new MetaCloudApiProvider(),
                ProviderGuard::FAKE => new FakeWhatsAppProvider(),
            };
        });
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
