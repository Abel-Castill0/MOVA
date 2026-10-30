<?php

namespace Tests\Feature;

use App\Channels\WhatsAppChannel;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\ClassRescheduledNotification;
use App\Notifications\InsufficientCreditsNotification;
use App\Notifications\NewClassRequestNotification;
use App\Notifications\RechargeApprovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plantillas de WhatsApp que faltaban: clase reprogramada, nueva solicitud, recarga confirmada y créditos insuficientes.
 * Todas viajan por WhatsAppChannel (solo con teléfono verificado) y no exponen datos de menores.
 */
class WahaNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $attributes = []): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(array_merge(['name' => 'Ana Pérez', 'phone_verified_at' => now(), 'email_verified_at' => now()], $attributes));
        $user->assignRole($role);

        return $user;
    }

    public function test_class_rescheduled_whatsapp_text_has_dates_and_link_but_no_free_text_reason(): void
    {
        $lesson = new Lesson();
        $lesson->forceFill(['start_time' => now()->addDays(2)->setTime(15, 30), 'reschedule_reason' => 'Mi hijo Valentina está enferma']);

        $notification = new ClassRescheduledNotification($lesson, 'el profesor');
        $text = $notification->toWhatsApp($this->user('parent'));

        $this->assertStringContainsString('Clase reprogramada', $text);
        $this->assertStringContainsString($lesson->start_time->format('d/m/Y H:i'), $text);
        $this->assertStringNotContainsString('enferma', $text);
        $this->assertContains(WhatsAppChannel::class, $notification->via($this->user('parent')));
    }

    public function test_class_rescheduled_does_not_use_whatsapp_without_a_verified_phone(): void
    {
        $lesson = new Lesson();
        $lesson->forceFill(['start_time' => now()->addDay()]);

        $channels = (new ClassRescheduledNotification($lesson, 'x'))->via($this->user('parent', ['phone_verified_at' => null]));

        $this->assertNotContains(WhatsAppChannel::class, $channels);
    }

    public function test_new_class_request_whatsapp_text_never_includes_the_minor_name(): void
    {
        $student = new Student();
        $student->forceFill(['first_name' => 'Valentina']);
        $request = new ClassRequest();
        $request->setRelation('subject', (new Subject())->forceFill(['name' => 'Matemática']));
        $request->setRelation('student', $student);

        $notification = new NewClassRequestNotification($request);
        $teacher = $this->user('teacher');
        $text = $notification->toWhatsApp($teacher);

        $this->assertStringContainsString('Matemática', $text);
        $this->assertStringNotContainsString('Valentina', $text);
        $this->assertContains(WhatsAppChannel::class, $notification->via($teacher));
    }

    public function test_recharge_approved_whatsapp_text_reports_credits_and_does_not_claim_balance(): void
    {
        $recharge = new RechargeRequest();
        $recharge->forceFill(['credits' => 10]);
        $notification = new RechargeApprovedNotification($recharge);
        $teacher = $this->user('teacher');

        $text = $notification->toWhatsApp($teacher);

        $this->assertStringContainsString('Recarga confirmada', $text);
        $this->assertStringContainsString('10 créditos', $text);
        $this->assertSame(['mail', WhatsAppChannel::class], $notification->via($teacher));
        $this->assertSame(['mail'], $notification->via($this->user('teacher', ['phone_verified_at' => null])));
    }

    public function test_insufficient_credits_text_shows_needed_and_available_without_student_data(): void
    {
        $notification = new InsufficientCreditsNotification(2.0, 0.5);
        $teacher = $this->user('teacher');

        $text = $notification->toWhatsApp($teacher);

        $this->assertStringContainsString('Créditos insuficientes', $text);
        $this->assertStringContainsString('necesita 2 créditos y tiene 0.5', $text);
        $this->assertSame(['database', 'mail', WhatsAppChannel::class], $notification->via($teacher));
    }
}
