<?php

namespace Tests\Feature;

use App\Support\SentryScrubber;
use Illuminate\Support\Facades\Log;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Tests\TestCase;

/**
 * El secreto del webhook de Culqi viaja en la RUTA (Culqi no firma): no debe aparecer en la salida de los
 * comandos automáticos, en los logs ni en lo que Sentry recibe.
 */
class CulqiSecretsTest extends TestCase
{
    private const SECRET = 'whsec_0123456789abcdef0123456789abcdef01234567';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payments.culqi.webhook_secret' => self::SECRET, 'app.url' => 'https://staging.example.test']);
    }

    public function test_the_default_output_never_contains_the_secret_or_its_length(): void
    {
        $this->artisan('culqi:webhook-url')
            ->expectsOutputToContain('https://staging.example.test/api/webhooks/culqi/<CULQI_WEBHOOK_SECRET>')
            ->expectsOutputToContain('order.status.changed')
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertExitCode(0);
    }

    public function test_reveal_needs_an_interactive_terminal_and_an_explicit_yes(): void
    {
        // Ejecución no interactiva (pipe, CI, agente): jamás imprime el secreto.
        $this->artisan('culqi:webhook-url', ['--reveal' => true, '--no-interaction' => true])
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertExitCode(1);

        // Interactiva pero sin confirmar: tampoco.
        $this->artisan('culqi:webhook-url', ['--reveal' => true])
            ->expectsConfirmation('Vas a mostrar el secreto del webhook de Culqi en pantalla. ¿Estás en una terminal privada y sin grabación?', 'no')
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertExitCode(1);
    }

    public function test_reveal_with_confirmation_prints_it_once_and_leaves_an_audit_line_without_the_secret(): void
    {
        Log::spy();

        $this->artisan('culqi:webhook-url', ['--reveal' => true])
            ->expectsConfirmation('Vas a mostrar el secreto del webhook de Culqi en pantalla. ¿Estás en una terminal privada y sin grabación?', 'yes')
            ->expectsOutputToContain('/api/webhooks/culqi/'.self::SECRET)
            ->assertExitCode(0);

        Log::shouldHaveReceived('notice')->once()->withArgs(fn ($message, $context) => str_contains($message, 'revelado') && ! str_contains(json_encode($context), self::SECRET));
    }

    public function test_sentry_events_lose_the_secret_from_the_request_url_transaction_message_and_exceptions(): void
    {
        $url = 'https://movaeduca.me/api/webhooks/culqi/'.self::SECRET.'?x=1';
        $event = Event::createEvent();
        $event->setRequest(['url' => $url, 'query_string' => 'x=1', 'method' => 'POST']);
        $event->setTransaction('POST /api/webhooks/culqi/'.self::SECRET);
        $event->setMessage('falló '.$url);
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('x'), null)]);

        $clean = SentryScrubber::beforeSend($event);

        $dump = json_encode([$clean->getRequest(), $clean->getTransaction(), $clean->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringContainsString('/api/webhooks/culqi/[REDACTADO]', $dump);
        $this->assertSame('POST', $clean->getRequest()['method']);
    }

    public function test_sentry_tracing_data_and_spans_also_lose_the_secret(): void
    {
        $path = '/api/webhooks/culqi/'.self::SECRET;
        $event = Event::createTransaction();
        $event->setContext('trace', ['trace_id' => 'abc', 'data' => ['url' => $path, 'http.request.method' => 'POST']]);
        $event->setExtra(['ruta' => $path]);
        $span = new \Sentry\Tracing\Span();
        $span->setDescription('POST '.$path);
        $span->setData(['http.url' => $path]);
        $event->setSpans([$span]);

        $clean = SentryScrubber::beforeSendTransaction($event);

        $dump = json_encode([$clean->getContexts(), $clean->getExtra(), $span->getDescription(), $span->getData()], JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringContainsString('/api/webhooks/culqi/[REDACTADO]', $dump);
        $this->assertSame('POST', $clean->getContexts()['trace']['data']['http.request.method']);
    }

    public function test_the_scrubber_is_registered_as_a_cacheable_callable_in_the_sentry_config(): void
    {
        $this->assertSame([SentryScrubber::class, 'beforeSend'], config('sentry.before_send'));
        $this->assertSame([SentryScrubber::class, 'beforeSendTransaction'], config('sentry.before_send_transaction'));
        $this->assertSame('/api/webhooks/culqi/[REDACTADO]/x', SentryScrubber::redact('/api/webhooks/culqi/'.self::SECRET.'/x'));
        $this->assertSame('/api/webhooks/mercadopago', SentryScrubber::redact('/api/webhooks/mercadopago'));
    }
}
