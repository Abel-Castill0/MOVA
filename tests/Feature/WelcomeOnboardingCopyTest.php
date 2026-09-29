<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\WelcomeEmailNotification;
use App\Notifications\WelcomeParentNotification;
use App\Notifications\WelcomeTeacherNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C-P1-TEACHER-ONBOARDING: las bienvenidas describían un producto que ya no
 * existe — el profesor "creaba ofertas" y fijaba "tarifa por hora", y el
 * padre "solicitaba desde la oferta del profesor". El flujo vigente es:
 * solicitud abierta por materia o con código de referido → el profesor
 * acepta o contrapropone horario. Se verifican TODOS los canales de cada
 * notificación (database, mail, WhatsApp) para que no diverjan.
 */
class WelcomeOnboardingCopyTest extends TestCase
{
    use RefreshDatabase;

    /** Frases del modelo de ofertas/tarifa que ya no existe. */
    private const OBSOLETE = ['oferta', 'tarifa', 'marketplace'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_teacher_welcome_describes_the_request_flow_on_every_channel(): void
    {
        $teacher = $this->user('teacher');
        $n = new WelcomeTeacherNotification;

        $channels = $this->channelTexts($n, $teacher);

        foreach ($channels as $channel => $text) {
            foreach (self::OBSOLETE as $phrase) {
                $this->assertStringNotContainsStringIgnoringCase($phrase, $text, "[$channel] no debe mencionar '$phrase'.");
            }
        }
        $this->assertStringContainsString('solicitudes abiertas de sus materias', $channels['mail']);
        $this->assertStringContainsString('proponga otro horario', $channels['mail']);
        $this->assertStringContainsString('solicitudes abiertas de sus materias', $channels['whatsapp']);
        $this->assertStringContainsString('código de profesor', $channels['whatsapp']);
    }

    public function test_parent_welcome_describes_code_or_open_request_on_every_channel(): void
    {
        $parent = $this->user('parent');
        $n = new WelcomeParentNotification;

        $channels = $this->channelTexts($n, $parent);

        foreach ($channels as $channel => $text) {
            foreach (self::OBSOLETE as $phrase) {
                $this->assertStringNotContainsStringIgnoringCase($phrase, $text, "[$channel] no debe mencionar '$phrase'.");
            }
        }
        $this->assertStringContainsString('profesores verificados de la materia', $channels['mail']);
        $this->assertStringContainsString('código de profesor', $channels['whatsapp']);
    }

    public function test_post_verification_welcome_email_uses_the_same_flow(): void
    {
        foreach (['teacher', 'parent'] as $role) {
            $mail = (new WelcomeEmailNotification)->toMail($this->user($role));
            $text = implode(' ', $mail->introLines);

            foreach (self::OBSOLETE as $phrase) {
                $this->assertStringNotContainsStringIgnoringCase($phrase, $text, "[$role] no debe mencionar '$phrase'.");
            }
        }
    }

    /** @return array{database: string, mail: string, whatsapp: string} */
    private function channelTexts($notification, User $user): array
    {
        $mail = $notification->toMail($user);

        return [
            'database' => $notification->toArray($user)['message'],
            'mail'     => $mail->subject.' '.implode(' ', $mail->introLines),
            'whatsapp' => $notification->toWhatsApp($user),
        ];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['phone_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }
}
