<?php

namespace Tests\Feature;

use App\Mail\Transport\GmailApiTransport;
use App\Services\GmailApiMailService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Tests\TestCase;

/**
 * H-05 — El mailer `gmail_api` es real.
 *
 * El hallazgo (docs/MOVA_SYSTEM_MAP.md §20.2) era que `MAIL_MAILER=gmail_api`
 * estaba en producción y `gmail_api` NO existía como mailer de Laravel:
 * funcionaba solo porque SafeMailChannel lo interceptaba antes. Cualquier
 * Mailable o `Mail::send()` habría reventado con "Mailer [gmail_api] is not
 * defined".
 *
 * Estos tests fijan que el pipeline estándar del framework funciona.
 */
class MailerArchitectureTest extends TestCase
{
    public function test_gmail_api_is_a_registered_mailer(): void
    {
        $this->assertArrayHasKey(
            'gmail_api',
            config('mail.mailers'),
            'gmail_api debe existir en config/mail.php, no solo como valor de MAIL_MAILER.'
        );
    }

    /**
     * La prueba que el hallazgo pedía: resolver el mailer por su nombre no debe
     * lanzar "Unsupported mail transport". Es exactamente lo que ocurriría en el
     * primer `Mail::send()` que alguien añadiera.
     */
    public function test_resolving_the_gmail_api_mailer_does_not_throw(): void
    {
        $mailer = Mail::mailer('gmail_api');

        $this->assertInstanceOf(
            GmailApiTransport::class,
            $mailer->getSymfonyTransport(),
            'El mailer gmail_api debe resolverse a su transporte real.'
        );
    }

    /**
     * El respaldo se expresa con el mecanismo del framework, no con una
     * reasignación de config en caliente.
     */
    public function test_failover_chains_gmail_api_before_smtp(): void
    {
        $this->assertSame(
            ['gmail_api', 'smtp'],
            config('mail.mailers.failover.mailers'),
            'El orden importa: Gmail API es el canal primario actual.'
        );

        $this->assertInstanceOf(
            FailoverTransport::class,
            Mail::mailer('failover')->getSymfonyTransport()
        );
    }

    /**
     * NO DOBLE ENVÍO. `failover` solo pasa al siguiente transporte cuando el
     * anterior LANZA; un envío con éxito jamás continúa la cadena. Se comprueba
     * observando cuántas veces se llama a la Gmail API.
     */
    public function test_a_successful_send_never_continues_down_the_failover_chain(): void
    {
        config([
            'services.gmail.client_id' => 'test-client',
            'services.gmail.client_secret' => 'test-secret',
            'services.gmail.refresh_token' => 'test-refresh',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-de-prueba'], 200),
            'gmail.googleapis.com/*' => Http::response(['id' => 'msg-1'], 200),
        ]);

        Mail::mailer('failover')->raw('Cuerpo de prueba', function ($message) {
            $message->to('destinatario@example.com')->subject('Asunto de prueba');
        });

        Http::assertSentCount(2); // 1 token + 1 envío. Ni un envío de más.
    }

    /**
     * El transporte debe LANZAR cuando la API rechaza, porque es lo que permite
     * a `failover` pasar al siguiente. Tragarse el error dejaría a MOVA creyendo
     * que el correo salió.
     */
    public function test_the_transport_throws_when_the_gmail_api_rejects_the_send(): void
    {
        config([
            'services.gmail.client_id' => 'test-client',
            'services.gmail.client_secret' => 'test-secret',
            'services.gmail.refresh_token' => 'test-refresh',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-de-prueba'], 200),
            'gmail.googleapis.com/*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $this->expectException(RuntimeException::class);

        app(GmailApiMailService::class)->sendRawMessage("To: a@example.com\r\nSubject: x\r\n\r\ncuerpo");
    }

    public function test_the_transport_throws_when_the_refresh_token_is_rejected(): void
    {
        config([
            'services.gmail.client_id' => 'test-client',
            'services.gmail.client_secret' => 'test-secret',
            'services.gmail.refresh_token' => 'caducado',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(RuntimeException::class);

        app(GmailApiMailService::class)->sendRawMessage("To: a@example.com\r\nSubject: x\r\n\r\ncuerpo");
    }

    /**
     * H-04 — La dependencia `resend/resend-laravel` se retiró por no tener
     * ningún consumidor, y con ella su ruta pública sin middleware.
     */
    public function test_the_unauthenticated_resend_webhook_route_no_longer_exists(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('resend.webhook'),
            'POST /resend/webhook era un endpoint público sin middleware de un paquete sin uso.'
        );

        $this->post('/resend/webhook')->assertNotFound();
    }

    public function test_resend_is_no_longer_a_configured_mailer_or_service(): void
    {
        $this->assertArrayNotHasKey('resend', config('mail.mailers'));
        $this->assertNull(config('services.resend'));
    }

    /**
     * C-10 — El correo usaba el indigo de Breeze (#4f46e5), un color que ninguna
     * decisión de marca eligió. Ahora la identidad vive en el tema de Laravel.
     */
    public function test_email_uses_the_mova_brand_theme(): void
    {
        $this->assertSame('mova', config('mail.markdown.theme'));

        $themePath = resource_path('views/vendor/mail/html/themes/mova.css');
        $this->assertFileExists($themePath);

        $css = file_get_contents($themePath);
        $this->assertStringContainsString('#0D409A', $css, 'El tema debe usar el azul de marca (brand-700).');
        $this->assertStringNotContainsString('#4f46e5', $css, 'El indigo de Breeze no debe sobrevivir.');
    }
}
