<?php

namespace App\Providers;

use App\Channels\SafeMailChannel;
use App\Payment\Contracts\PaymentProviderContract;
use App\Payment\CulqiPaymentProvider;
use App\Payment\FakePaymentProvider;
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
            $provider = ProviderGuard::resolve(
                kind: 'pagos',
                envVarName: 'PAYMENT_PROVIDER',
                provider: config('payments.provider'),
                supportedReal: ['culqi'],
                featureEnabled: (bool) config('payments.enabled', false),
                environment: $this->app->environment(),
            );

            // Sin rama `default`: cada proveedor se nombra explícitamente. Si
            // alguien añade uno a ProviderGuard y olvida instanciarlo aquí,
            // PHP lanza UnhandledMatchError en lugar de devolver el Fake en
            // silencio — que es el fallo que F-03 vino a eliminar.
            return match ($provider) {
                'culqi'              => new CulqiPaymentProvider(),
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
