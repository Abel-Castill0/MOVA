<?php

namespace Tests\Feature;

use App\Support\MailAllowlist;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Staging no puede escribir a direcciones existentes: con MAIL_ALLOWLIST solo
 * salen los correos hacia destinatarios autorizados, con cualquier transporte.
 */
class MailAllowlistTest extends TestCase
{
    private function message(array $to, array $cc = [], array $bcc = []): MessageSending
    {
        $email = (new Email())->from('mova@example.test')->subject('x')->text('x');
        if ($to) {
            $email->to(...$to);
        }
        if ($cc) {
            $email->cc(...$cc);
        }
        if ($bcc) {
            $email->bcc(...$bcc);
        }

        return new MessageSending($email);
    }

    private function recipients(MessageSending $event): array
    {
        return array_map(fn ($a) => $a->getAddress(), array_merge($event->message->getTo(), $event->message->getCc(), $event->message->getBcc()));
    }

    public function test_without_an_allowlist_nothing_is_filtered(): void
    {
        config(['mail.allowlist' => null]);
        $event = $this->message(['cualquiera@real.com']);

        $this->assertNull((new MailAllowlist())->handle($event));
        $this->assertSame(['cualquiera@real.com'], $this->recipients($event));
    }

    public function test_only_allowed_recipients_remain_in_to_cc_and_bcc(): void
    {
        config(['mail.allowlist' => 'Dueno@Example.com, @equipo.test']);
        $event = $this->message(['dueno@example.com', 'usuario@real.com'], ['ana@equipo.test', 'otro@real.com'], ['bcc@real.com']);

        $this->assertNull((new MailAllowlist())->handle($event));
        $this->assertEqualsCanonicalizing(['dueno@example.com', 'ana@equipo.test'], $this->recipients($event));
    }

    public function test_a_message_with_no_allowed_recipient_is_cancelled(): void
    {
        config(['mail.allowlist' => 'dueno@example.com']);
        $event = $this->message(['usuario@real.com'], ['otro@real.com']);

        $this->assertFalse((new MailAllowlist())->handle($event));
    }

    public function test_a_domain_entry_does_not_match_a_lookalike_suffix(): void
    {
        config(['mail.allowlist' => '@equipo.test']);

        $this->assertTrue(MailAllowlist::allows('ana@equipo.test'));
        $this->assertFalse(MailAllowlist::allows('ana@malo-equipo.test'));
        $this->assertFalse(MailAllowlist::allows('ana@equipo.test.evil.com'));
        $this->assertFalse(MailAllowlist::allows('equipo.test'));
    }

    public function test_it_is_wired_into_the_real_mailer_pipeline(): void
    {
        config(['mail.default' => 'array', 'mail.allowlist' => 'dueno@example.com']);

        Mail::raw('hola', fn ($m) => $m->to('usuario@real.com')->subject('s'));
        Mail::raw('hola', fn ($m) => $m->to(['dueno@example.com', 'usuario@real.com'])->subject('s'));

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent, 'El correo a un destinatario no autorizado no debe llegar al transporte.');
        $recipients = array_map(fn ($a) => $a->getAddress(), $sent[0]->getOriginalMessage()->getTo());
        $this->assertSame(['dueno@example.com'], $recipients);
    }
}
