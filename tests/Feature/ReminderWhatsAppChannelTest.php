<?php

namespace Tests\Feature;

use App\Channels\WhatsAppChannel;
use App\Models\Lesson;
use App\Models\User;
use App\Notifications\ClassReminderNotification;
use Tests\TestCase;

/** WhatsApp solo en los recordatorios de 24 h y 2 h; correo e in-app siguen en los tres. */
class ReminderWhatsAppChannelTest extends TestCase
{
    private function channels(string $interval): array
    {
        $user = new User(['name' => 'Prueba']);
        $user->email_verified_at = now();
        $user->phone_verified_at = now();

        return (new ClassReminderNotification(new Lesson(), $interval))->via($user);
    }

    public function test_the_24h_and_2h_reminders_use_whatsapp(): void
    {
        $this->assertContains(WhatsAppChannel::class, $this->channels('24h'));
        $this->assertContains(WhatsAppChannel::class, $this->channels('2h'));
    }

    public function test_the_10m_reminder_does_not_use_whatsapp_but_keeps_mail_and_in_app(): void
    {
        $channels = $this->channels('10m');

        $this->assertNotContains(WhatsAppChannel::class, $channels);
        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
    }
}
