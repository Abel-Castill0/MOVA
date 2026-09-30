<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Notifications\ComplaintFiledNotification;
use App\Notifications\WelcomeEmailNotification;
use App\Models\User;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * C-P0-EMAIL — SafeMailChannel decide según los canales de la notificación:
 * solo correo => relanza (reintento de cola / error al llamador síncrono);
 * multicanal => registra y sigue (no duplica canales ya entregados).
 *
 * Transportes sintéticos: ninguna prueba toca Gmail ni la red.
 */
class SafeMailChannelReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public static int $attempts = 0;
    public static int $otherChannelSends = 0;
    public static bool $fail = true;

    protected function setUp(): void
    {
        parent::setUp();

        self::$attempts = 0;
        self::$otherChannelSends = 0;
        self::$fail = true;

        Mail::extend('synthetic', fn () => new SyntheticTransport());
        config(['mail.mailers.synthetic' => ['transport' => 'synthetic'], 'mail.default' => 'synthetic']);
        Log::spy();
    }

    private function send(Notification $notification): void
    {
        NotificationFacade::route('mail', 'secret-recipient@mova.test')->notify($notification);
    }

    public function test_mail_only_failure_is_rethrown_and_logged_without_the_recipient(): void
    {
        try {
            $this->send(new MailOnlySync());
            $this->fail('El fallo de un correo solo-correo debe propagarse.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            return ($context['mail_only'] ?? null) === true
                && ! str_contains(json_encode($context), 'secret-recipient')
                && ! str_contains(json_encode($context), 'boom');
        })->once();
    }

    public function test_multi_channel_failure_does_not_throw_and_other_channels_are_delivered_once(): void
    {
        $this->send(new MultiChannelSync());

        $this->assertSame(1, self::$otherChannelSends);
        $this->assertSame(1, self::$attempts);
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c) => ($c['mail_only'] ?? null) === false)->once();
    }

    public function test_mail_only_success_is_unchanged(): void
    {
        self::$fail = false;

        $this->send(new MailOnlySync());

        $this->assertSame(1, self::$attempts);
        Log::shouldNotHaveReceived('error');
    }

    public function test_array_and_log_mailers_still_skip_silently(): void
    {
        foreach (['array', 'log'] as $mailer) {
            config(['mail.default' => $mailer]);

            $this->send(new MailOnlySync());
        }

        $this->assertSame(0, self::$attempts);
    }

    public function test_a_via_that_throws_is_treated_as_multi_channel_so_nothing_is_duplicated(): void
    {
        $this->send(new BrokenViaSync());

        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c) => ($c['mail_only'] ?? null) === false)->once();
    }

    // ── Semántica de cola, con el worker real ───────────────────────────

    private function work(): void
    {
        // Mismos --tries/--backoff que infra/azure/apps.bicep; backoff 0 solo
        // para que el reintento sea inmediato dentro de la prueba.
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--backoff' => 0, '--sleep' => 0, '--timeout' => 60]);
    }

    public function test_a_queued_mail_only_failure_is_retried_then_lands_in_failed_jobs(): void
    {
        config(['queue.default' => 'database']);

        $this->send(new MailOnlyQueued());
        $this->assertSame(1, DB::table('jobs')->count());

        $this->work();

        $this->assertSame(3, self::$attempts, '1 intento + 2 reintentos (--tries=3).');
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_queued_mail_only_that_recovers_on_retry_is_delivered_and_not_failed(): void
    {
        config(['queue.default' => 'database']);

        $this->send(new MailOnlyQueued());
        // Falla el primer intento, funciona el segundo.
        SyntheticTransport::$failFirst = 1;
        self::$fail = false;

        $this->work();

        $this->assertSame(2, self::$attempts);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_queued_multi_channel_notification_is_not_retried_because_mail_failed(): void
    {
        config(['queue.default' => 'database']);

        $this->send(new MultiChannelQueued());
        $this->work();

        $this->assertSame(1, self::$otherChannelSends, 'El canal ya entregado no se duplica.');
        $this->assertSame(1, self::$attempts);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    // ── Contratos de los flujos críticos reales ─────────────────────────

    public function test_critical_real_flows_are_mail_only_by_channel_semantics(): void
    {
        $user = new User();

        $this->assertSame(['mail'], (new ComplaintFiledNotification(new Complaint()))->via($user));
        $this->assertSame(['mail'], (new WelcomeEmailNotification())->via($user));
        $this->assertSame(['mail'], (new VerifyEmail())->via($user));
        $this->assertSame(['mail'], (new ResetPassword('token'))->via($user));
    }

    public function test_verification_and_reset_run_synchronously_in_the_request(): void
    {
        // Sin ShouldQueue: la excepción de SafeMailChannel llega al llamador (HTTP).
        $this->assertNotInstanceOf(ShouldQueue::class, new VerifyEmail());
        $this->assertNotInstanceOf(ShouldQueue::class, new ResetPassword('token'));
        $this->assertNotContains(ShouldQueue::class, class_implements(SendEmailVerificationNotification::class));
        // Los correos de cola de MOVA sí reintentan.
        $this->assertInstanceOf(ShouldQueue::class, new ComplaintFiledNotification(new Complaint()));
        $this->assertInstanceOf(ShouldQueue::class, new WelcomeEmailNotification());
    }

    public function test_password_reset_failure_reaches_the_caller_instead_of_looking_sent(): void
    {
        $user = User::factory()->create();
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);

        $this->post('/forgot-password', ['email' => $user->email]);
    }
}

class SyntheticTransport extends AbstractTransport
{
    public static int $failFirst = 0;

    protected function doSend(SentMessage $message): void
    {
        SafeMailChannelReliabilityTest::$attempts++;

        if (self::$failFirst > 0) {
            self::$failFirst--;
            throw new RuntimeException('boom');
        }

        if (SafeMailChannelReliabilityTest::$fail) {
            throw new RuntimeException('boom');
        }
    }

    public function __toString(): string
    {
        return 'synthetic';
    }
}

class SpyChannel
{
    public function send($notifiable, Notification $notification): void
    {
        SafeMailChannelReliabilityTest::$otherChannelSends++;
    }
}

abstract class BaseSynthetic extends Notification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('synthetic')->line('body');
    }
}

class MailOnlySync extends BaseSynthetic
{
    public function via($notifiable): array
    {
        return ['mail'];
    }
}

class MultiChannelSync extends BaseSynthetic
{
    public function via($notifiable): array
    {
        return [SpyChannel::class, 'mail'];
    }
}

class BrokenViaSync extends BaseSynthetic
{
    private int $calls = 0;

    // Funciona para NotificationSender (1ª llamada) y lanza en la 2ª.
    public function via($notifiable): array
    {
        if (++$this->calls > 1) {
            throw new RuntimeException('via exploded');
        }

        return ['mail'];
    }
}

class MailOnlyQueued extends BaseSynthetic implements ShouldQueue
{
    use Queueable;

    public function via($notifiable): array
    {
        return ['mail'];
    }
}

class MultiChannelQueued extends BaseSynthetic implements ShouldQueue
{
    use Queueable;

    public function via($notifiable): array
    {
        return [SpyChannel::class, 'mail'];
    }
}
