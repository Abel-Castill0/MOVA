<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Reply-To global opcional (config nativa de Laravel `mail.reply_to`): inerte sin configurar y activo con un correo válido. */
class MailReplyToTest extends TestCase
{
    private function sendAndGetReplyTo(): ?string
    {
        Mail::purge('array');
        Mail::raw('prueba', fn ($m) => $m->to('destino@example.com')->subject('Prueba'));

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $replyTo = $sent->getReplyTo();

        return $replyTo === [] ? null : $replyTo[0]->getAddress();
    }

    public function test_no_reply_to_is_added_when_not_configured(): void
    {
        config(['mail.default' => 'array', 'mail.reply_to.address' => null]);

        $this->assertNull($this->sendAndGetReplyTo());
    }

    public function test_a_valid_address_becomes_the_reply_to_of_outgoing_mail(): void
    {
        config(['mail.default' => 'array', 'mail.reply_to.address' => 'soporte@example.com', 'mail.reply_to.name' => 'Soporte MOVA']);

        $this->assertSame('soporte@example.com', $this->sendAndGetReplyTo());
    }
}
