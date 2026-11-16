<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use App\Notifications\ClassCancelledNotification;
use App\Notifications\ClassConfirmedNotification;
use App\Notifications\ClassReminderNotification;
use App\Notifications\ClassRescheduledNotification;
use App\Support\LimaClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las clases se guardan en UTC. Perú es UTC-5 sin horario de verano: una clase a las 20:00 en Lima es la 01:00 UTC del día
 * siguiente. WhatsApp, correo y campana deben decir 20:00 del día correcto, con la referencia «hora de Perú», y no 01:00
 * del día siguiente.
 */
class LessonNotificationLimaTimeTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(string $utc, ?string $originalUtc = null): Lesson
    {
        return new Lesson([
            'start_time' => Carbon::parse($utc, 'UTC'),
            'original_start_time' => $originalUtc ? Carbon::parse($originalUtc, 'UTC') : null,
            'duration_minutes' => 60,
        ]);
    }

    private function user(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['name' => 'Persona de prueba']);
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_the_formatter_converts_a_copy_and_never_touches_the_original_instant(): void
    {
        $moment = Carbon::parse('2026-11-26 01:00:00', 'UTC');

        $this->assertSame('25/11/2026 20:00 (hora de Perú)', LimaClock::dateTime($moment));
        $this->assertSame('25/11/2026 a las 20:00 (hora de Perú)', LimaClock::dateTimeLong($moment));
        $this->assertSame('25/11/2026', LimaClock::date($moment));
        $this->assertSame('UTC', $moment->getTimezone()->getName());
        $this->assertSame('2026-11-26 01:00:00', $moment->format('Y-m-d H:i:s'));

        // Convertir dos veces no desplaza la hora: se parte siempre del instante, no del texto.
        $this->assertSame(LimaClock::dateTime($moment), LimaClock::dateTime(LimaClock::at($moment)));
    }

    public function test_an_8pm_lima_class_reads_8pm_of_the_same_day_in_every_channel_of_every_notification(): void
    {
        $lesson = $this->lesson('2026-11-26 01:00:00', '2026-11-25 23:30:00'); // original: 18:30 Lima
        $teacher = $this->user('teacher');
        $parent = $this->user('parent');
        $wrong = ['01:00', '26/11/2026'];

        $confirmed = new ClassConfirmedNotification($lesson);
        $reminder = new ClassReminderNotification($lesson, '24h');
        $cancelled = new ClassCancelledNotification($lesson);
        $rescheduled = new ClassRescheduledNotification($lesson, 'el profesor');

        foreach ([$teacher, $parent] as $who) {
            $texts = [
                'confirmed.whatsapp' => $confirmed->toWhatsApp($who),
                'confirmed.mail' => $this->mailText($confirmed->toMail($who)),
                'confirmed.db' => $confirmed->toArray($who)['message'],
                'reminder.whatsapp' => $reminder->toWhatsApp($who),
                'reminder.mail' => $this->mailText($reminder->toMail($who)),
                'cancelled.whatsapp' => $cancelled->toWhatsApp($who),
                'cancelled.mail' => $this->mailText($cancelled->toMail($who)),
                'cancelled.db' => $cancelled->toArray($who)['message'],
                'rescheduled.whatsapp' => $rescheduled->toWhatsApp($who),
                'rescheduled.mail' => $this->mailText($rescheduled->toMail($who)),
                'rescheduled.db' => $rescheduled->toArray($who)['message'],
            ];
            foreach ($texts as $where => $text) {
                $this->assertStringContainsString('25/11/2026', $text, $where);
                if (! in_array($where, ['confirmed.db', 'cancelled.db'], true)) { // esos dos mensajes llevan solo la fecha
                    $this->assertStringContainsString('20:00', $text, $where);
                }
                foreach ($wrong as $bad) {
                    $this->assertStringNotContainsString($bad, $text, "$where muestra la hora en UTC");
                }
            }
            foreach (['confirmed.whatsapp', 'reminder.whatsapp', 'cancelled.whatsapp', 'rescheduled.whatsapp', 'confirmed.mail', 'reminder.mail', 'rescheduled.mail'] as $where) {
                $this->assertStringContainsString('hora de Perú', $texts[$where], $where);
            }
            $this->assertStringContainsString('18:30', $texts['rescheduled.mail'], 'La fecha original también va en hora de Lima.');
        }

        // El instante que viaja a la campana/JSON sigue siendo UTC sin ambigüedad (lo formatea cada cliente en Lima).
        $this->assertSame('2026-11-26T01:00:00.000000Z', $confirmed->toArray($parent)['start_time']);
    }

    public function test_the_same_instant_has_the_same_lima_time_across_a_utc_day_change_and_a_queued_serialisation(): void
    {
        foreach ([['2026-11-26 04:59:00', '25/11/2026 23:59'], ['2026-11-26 05:00:00', '26/11/2026 00:00'], ['2026-12-31 23:30:00', '31/12/2026 18:30']] as [$utc, $lima]) {
            $notification = new ClassConfirmedNotification($this->lesson($utc));
            $this->assertStringContainsString($lima, $notification->toWhatsApp($this->user('parent')), $utc);
        }

        // Un aviso encolado se reconstruye desde la BD: el valor que importa es el instante, no su texto.
        $teacher = $this->user('teacher');
        $stored = Lesson::unguarded(fn () => new Lesson(['start_time' => '2026-11-26 01:00:00', 'duration_minutes' => 60]));
        $this->assertStringContainsString('25/11/2026 20:00', (new ClassReminderNotification($stored, '2h'))->toWhatsApp($teacher));
    }

    private function mailText($mail): string
    {
        return implode("\n", array_merge($mail->introLines, $mail->outroLines));
    }
}
