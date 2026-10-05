<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Entrega de correo con un SERVIDOR SMTP REAL (Mailpit, solo captura).
 *
 * A diferencia del resto de la suite (mailer `array`), aquí el transporte
 * `smtp` de Laravel abre una conexión SMTP de verdad y el correo se LEE de
 * vuelta por la API del servidor de captura. Demuestra que los flujos
 * esenciales —verificación de correo, recuperación de contraseña y constancia
 * del Libro de Reclamaciones— generan un mensaje completo, con enlaces al
 * dominio configurado, y que una caída del SMTP no rompe el registro ni
 * simula un envío.
 *
 * No prueba un proveedor externo (Gmail, Resend, etc.) ni la entrega a un
 * buzón real: eso exige credenciales y un destinatario de prueba (ver
 * `mova:mail-smoke` y el ledger).
 *
 * Solo corre si MAIL_SMOKE_MAILPIT_API está definida (docker-compose.qa.yml:
 * servicio `mail_qa`); en el resto de entornos se omite.
 */
class MailDeliverySmokeTest extends TestCase
{
    use RefreshDatabase;

    private string $api = '';

    protected function setUp(): void
    {
        parent::setUp();

        $api = getenv('MAIL_SMOKE_MAILPIT_API') ?: '';
        if ($api === '') {
            $this->markTestSkipped('MAIL_SMOKE_MAILPIT_API no está definida (servidor SMTP de captura no disponible).');
        }

        $this->api = rtrim($api, '/');
        Http::get($this->api.'/api/v1/info')->throw();
        Http::send('DELETE', $this->api.'/api/v1/messages');

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role);
        }

        $this->useSmtp((string) (getenv('MAIL_SMOKE_SMTP_HOST') ?: 'mail_qa'), 1025);
    }

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        parent::tearDown();
    }

    private function useSmtp(string $host, int $port): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => $host,
                'port' => $port,
                'encryption' => null,
                'username' => null,
                'password' => null,
                'timeout' => 5,
            ],
            'mail.from' => ['address' => 'notificaciones@movaeduca.me', 'name' => 'MOVA'],
        ]);
        app('mail.manager')->purge();
    }

    /** @return array<int, array<string, mixed>> mensajes recibidos para un destinatario */
    private function inboxOf(string $address): array
    {
        $messages = Http::get($this->api.'/api/v1/messages')->json('messages') ?? [];

        return array_values(array_filter(
            $messages,
            fn ($m) => collect($m['To'] ?? [])->contains(fn ($to) => strtolower($to['Address'] ?? '') === strtolower($address))
        ));
    }

    private function bodyOf(array $message): string
    {
        $full = Http::get($this->api.'/api/v1/message/'.$message['ID'])->json();

        return ($full['Text'] ?? '').' '.($full['HTML'] ?? '');
    }

    private function register(string $email): void
    {
        $this->post(route('register'), [
            'name' => 'Familia Prueba',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'parent',
            'accepted_terms' => true,
        ])->assertSessionHasNoErrors();
    }

    // ── Verificación de correo ───────────────────────────────────────────

    public function test_registration_delivers_a_complete_verification_email_over_real_smtp(): void
    {
        $this->register('verifica@example.test');

        $inbox = $this->inboxOf('verifica@example.test');
        $this->assertCount(1, $inbox, 'Debe llegar exactamente un correo de verificación.');
        $this->assertNotSame('', trim((string) $inbox[0]['Subject']));
        $this->assertSame('notificaciones@movaeduca.me', $inbox[0]['From']['Address']);

        $body = $this->bodyOf($inbox[0]);
        $this->assertStringContainsString('/verify-email/', $body);
        $this->assertStringContainsString('signature=', $body, 'El enlace de verificación debe ir firmado.');
    }

    public function test_verification_links_use_the_configured_public_domain_not_staging(): void
    {
        // En producción AppServiceProvider fuerza https; aquí se reproduce esa
        // condición junto con el dominio canónico.
        config(['app.url' => 'https://movaeduca.me']); // lo que fija APP_URL en producción
        URL::forceRootUrl('https://movaeduca.me');
        URL::forceScheme('https');

        $this->register('dominio@example.test');

        $body = $this->bodyOf($this->inboxOf('dominio@example.test')[0]);
        $this->assertMatchesRegularExpression('#https://movaeduca\.me/verify-email/#', $body);
        $this->assertStringNotContainsString('staging.', $body);
        $this->assertStringNotContainsString('localhost', $body);
    }

    // ── Recuperación de contraseña ───────────────────────────────────────

    public function test_password_reset_delivers_a_signed_reset_link(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $user->assignRole('parent');

        $this->post(route('password.email'), ['email' => 'reset@example.test'])->assertSessionHasNoErrors();

        $inbox = $this->inboxOf('reset@example.test');
        $this->assertCount(1, $inbox);

        $body = $this->bodyOf($inbox[0]);
        $this->assertStringContainsString('/reset-password/', $body);
        $this->assertStringContainsString('email=reset%40example.test', $body);
    }

    public function test_password_reset_for_an_unknown_address_sends_nothing(): void
    {
        $this->post(route('password.email'), ['email' => 'nadie@example.test']);

        $this->assertCount(0, $this->inboxOf('nadie@example.test'));
    }

    // ── Libro de Reclamaciones ───────────────────────────────────────────

    public function test_complaint_receipt_is_delivered_with_its_code(): void
    {
        $this->post(route('complaints.store'), [
            'type' => 'reclamo',
            'consumer_name' => 'María Quispe',
            'document_type' => 'DNI',
            'document_number' => '12345678',
            'address' => 'Av. Siempre Viva 123, Lima',
            'email' => 'reclamo@example.test',
            'phone' => '987654321',
            'is_minor' => false,
            'good_type' => 'servicio',
            'amount' => '40.00',
            'good_description' => 'Clase de matemáticas',
            'detail' => 'El profesor no se conectó a la clase programada.',
            'consumer_request' => 'Devolución de créditos.',
            'accepted' => true,
        ])->assertSessionHasNoErrors()->assertSessionHas('complaint_code');

        $inbox = $this->inboxOf('reclamo@example.test');
        $this->assertCount(1, $inbox);
        $this->assertStringContainsString('MOVA-'.now()->year.'-', $this->bodyOf($inbox[0]));
    }

    // ── Caída del SMTP: sin falso "enviado" y sin romper el registro ─────

    public function test_an_unreachable_smtp_server_keeps_the_account_and_never_claims_the_mail_was_sent(): void
    {
        $this->useSmtp('127.0.0.1', 1); // nada escucha en el puerto 1

        $response = $this->post(route('register'), [
            'name' => 'Familia Caida',
            'email' => 'caida@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'parent',
            'accepted_terms' => true,
        ]);

        $user = User::where('email', 'caida@example.test')->first();
        $this->assertNotNull($user, 'La cuenta debe conservarse aunque el correo falle.');
        $this->assertNull($user->email_verified_at);
        $response->assertRedirect(route('verification.notice'));
        $this->assertNotNull(session('flash.error') ?? session('error'), 'Debe avisar del fallo en vez de simular éxito.');
        $this->assertNotSame('verification-link-sent', session('status'));
        $this->assertCount(0, $this->inboxOf('caida@example.test'));
    }
}
