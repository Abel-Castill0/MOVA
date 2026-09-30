<?php

namespace Tests\Feature;

use App\Console\Commands\GmailAuthUrl;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * C-P0-EMAIL — contrato del flujo OAuth de Gmail: scope mínimo (solo envío),
 * redirect coherente entre autorización e intercambio, y ningún secreto en la
 * salida. Configuración sintética; sin red real.
 */
class GmailOAuthCommandsTest extends TestCase
{
    private const FAKE_CLIENT_ID = 'fake-client-id.apps.example';
    private const FAKE_SECRET = 'FAKE-CLIENT-SECRET-NOT-REAL';
    private const FAKE_REFRESH = 'FAKE-REFRESH-TOKEN-NOT-REAL';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gmail.client_id' => self::FAKE_CLIENT_ID,
            'services.gmail.client_secret' => self::FAKE_SECRET,
            'services.gmail.refresh_token' => self::FAKE_REFRESH,
        ]);
    }

    /** @return array{0: string, 1: string, 2: array<string, string>} */
    private function authUrl(): array
    {
        $exit = Artisan::call('mova:gmail-auth-url');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame(1, preg_match('#https://accounts\.google\.com/o/oauth2/v2/auth\?\S+#', $output, $m));
        parse_str((string) parse_url($m[0], PHP_URL_QUERY), $query);

        return [$output, $m[0], $query];
    }

    public function test_authorization_url_requests_only_the_send_scope(): void
    {
        [, $url, $query] = $this->authUrl();

        $this->assertSame('https://www.googleapis.com/auth/gmail.send', $query['scope']);
        $this->assertStringContainsString('gmail.send', $url);
        $this->assertStringNotContainsString('gmail.readonly', $url);
        $this->assertStringNotContainsString('gmail.modify', $url);
    }

    public function test_authorization_url_keeps_offline_consent_and_expected_redirect(): void
    {
        [, , $query] = $this->authUrl();

        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('http://localhost', $query['redirect_uri']);
        $this->assertSame(self::FAKE_CLIENT_ID, $query['client_id']);
    }

    public function test_authorization_command_prints_no_secret_and_no_stale_wording(): void
    {
        [$output] = $this->authUrl();

        $this->assertStringNotContainsString(self::FAKE_SECRET, $output);
        $this->assertStringNotContainsString(self::FAKE_REFRESH, $output);
        $this->assertStringNotContainsString('Read email', $output);
        $this->assertStringNotContainsString('Railway', $output);
        $this->assertStringContainsString('HUMAN-INTERACTIVE SECRET', $output);
    }

    public function test_authorization_requires_a_client_id(): void
    {
        config(['services.gmail.client_id' => null]);

        $this->assertSame(1, Artisan::call('mova:gmail-auth-url'));
    }

    public function test_exchange_uses_the_same_redirect_as_authorization(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['refresh_token' => self::FAKE_REFRESH])]);

        $this->assertSame(0, Artisan::call('mova:gmail-exchange-code', ['code' => 'FAKE-CODE']));

        Http::assertSent(fn ($request) => $request['redirect_uri'] === GmailAuthUrl::REDIRECT_URI
            && $request['redirect_uri'] === 'http://localhost'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'FAKE-CODE');
    }

    public function test_exchange_failure_does_not_echo_secrets(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertSame(1, Artisan::call('mova:gmail-exchange-code', ['code' => 'FAKE-CODE']));

        $output = Artisan::output();
        $this->assertStringNotContainsString(self::FAKE_SECRET, $output);
        $this->assertStringNotContainsString('Railway', $output);
    }
}
