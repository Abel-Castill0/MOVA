<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * C-P0-EMAIL — `mova:health-check` debe decir la verdad sobre el correo saliente.
 *
 * Solo configuración: ninguna prueba hace red (Http::fake + assertNothingSent) ni
 * imprime valores de credenciales.
 */
class HealthCheckMailReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_VALUES = ['s3cr3t-client-id', 's3cr3t-client-secret', 's3cr3t-refresh', 's3cr3t-smtp-pass'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->app['env'] = 'production';
        $this->setGmail(complete: true);
        $this->setSmtp(complete: false);
        config(['mail.from.address' => 'no-reply@mova.test']);
    }

    private function setGmail(bool $complete): void
    {
        config([
            'services.gmail.client_id'     => $complete ? self::SECRET_VALUES[0] : null,
            'services.gmail.client_secret' => $complete ? self::SECRET_VALUES[1] : null,
            'services.gmail.refresh_token' => $complete ? self::SECRET_VALUES[2] : null,
        ]);
    }

    private function setSmtp(bool $complete): void
    {
        config([
            'mail.mailers.smtp.url'      => null,
            'mail.mailers.smtp.host'     => 'smtp.mova.test',
            'mail.mailers.smtp.username' => $complete ? 'user' : null,
            'mail.mailers.smtp.password' => $complete ? self::SECRET_VALUES[3] : null,
        ]);
    }

    /** @return array{warnings: array<int,array{code:string,message:string}>, checks: array<string,string>, raw: string} */
    private function check(string $mailer): array
    {
        config(['mail.default' => $mailer]);
        Artisan::call('mova:health-check', ['--json' => true]);
        $raw = Artisan::output();
        $json = json_decode($raw, true);

        Http::assertNothingSent();

        return ['warnings' => $json['warnings'], 'checks' => $json['checks'], 'raw' => $raw];
    }

    private function codes(array $result): array
    {
        return array_column($result['warnings'], 'code');
    }

    /** @return list<string> */
    private function mailCodes(array $result): array
    {
        return array_values(array_filter($this->codes($result), fn ($c) => str_starts_with($c, 'MAIL_')));
    }

    public function test_array_and_log_mailers_are_critical_in_production(): void
    {
        foreach (['array', 'log'] as $mailer) {
            $this->assertContains('MAIL_MAILER_NON_DELIVERING', $this->codes($this->check($mailer)), $mailer);
        }
    }

    public function test_non_delivering_mailer_is_not_flagged_outside_production(): void
    {
        $this->app['env'] = 'local';

        $this->assertSame([], $this->mailCodes($this->check('array')));
    }

    public function test_a_complete_gmail_api_configuration_passes(): void
    {
        $result = $this->check('gmail_api');

        $this->assertSame([], $this->mailCodes($result));
        $this->assertSame('gmail_api', $result['checks']['mail_mailer']);
    }

    public function test_gmail_api_reports_the_missing_variable_names_only(): void
    {
        config(['services.gmail.refresh_token' => null]);

        $result = $this->check('gmail_api');

        $this->assertContains('MAIL_GMAIL_CONFIG_MISSING', $this->codes($result));
        $message = collect($result['warnings'])->firstWhere('code', 'MAIL_GMAIL_CONFIG_MISSING')['message'];
        $this->assertStringContainsString('GMAIL_REFRESH_TOKEN', $message);
        $this->assertStringNotContainsString('GMAIL_CLIENT_ID', $message);
    }

    public function test_failover_with_an_unconfigured_smtp_is_not_reported_as_redundant(): void
    {
        $result = $this->check('failover');

        $this->assertContains('MAIL_FAILOVER_NO_USABLE_FALLBACK', $this->codes($result));
        $this->assertSame('gmail_api → smtp', $result['checks']['mail_chain']);
    }

    public function test_failover_with_both_transports_configured_passes(): void
    {
        $this->setSmtp(complete: true);

        $this->assertSame([], $this->mailCodes($this->check('failover')));
    }

    public function test_failover_with_smtp_url_counts_as_a_usable_fallback(): void
    {
        config(['mail.mailers.smtp.url' => 'smtp://user:pass@smtp.mova.test:587']);

        $this->assertSame([], $this->mailCodes($this->check('failover')));
    }

    public function test_failover_with_broken_gmail_still_flags_gmail_even_if_smtp_works(): void
    {
        $this->setGmail(complete: false);
        $this->setSmtp(complete: true);

        $codes = $this->codes($this->check('failover'));

        $this->assertContains('MAIL_GMAIL_CONFIG_MISSING', $codes);
        $this->assertContains('MAIL_FAILOVER_NO_USABLE_FALLBACK', $codes);
    }

    public function test_failover_made_only_of_non_delivering_mailers_is_critical(): void
    {
        config(['mail.mailers.failover.mailers' => ['array', 'log']]);

        $this->assertContains('MAIL_MAILER_NON_DELIVERING', $this->codes($this->check('failover')));
    }

    public function test_a_valid_real_transport_that_mova_does_not_inspect_is_not_rejected(): void
    {
        config(['mail.mailers.ses' => ['transport' => 'ses']]);

        $this->assertSame([], $this->mailCodes($this->check('ses')));
    }

    public function test_an_unknown_mailer_is_reported(): void
    {
        $this->assertContains('MAIL_MAILER_INVALID', $this->codes($this->check('nope')));
    }

    public function test_a_placeholder_from_address_is_reported_for_a_delivering_mailer(): void
    {
        config(['mail.from.address' => 'hello@example.com']);

        $this->assertContains('MAIL_FROM_ADDRESS_MISSING', $this->codes($this->check('gmail_api')));
    }

    public function test_no_credential_value_ever_reaches_the_output(): void
    {
        $this->setSmtp(complete: true);

        foreach (['array', 'gmail_api', 'failover'] as $mailer) {
            $raw = $this->check($mailer)['raw'];
            foreach (self::SECRET_VALUES as $secret) {
                $this->assertStringNotContainsString($secret, $raw, "{$mailer} leaked a credential value");
            }
        }
    }
}
