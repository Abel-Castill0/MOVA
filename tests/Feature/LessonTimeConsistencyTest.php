<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use App\Notifications\ClassConfirmedNotification;
use App\Notifications\ClassReminderNotification;
use App\Notifications\ClassRescheduledNotification;
use App\Support\LimaClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Incidente 2026-11-18: un WhatsApp decía 09:30 y el calendario 04:30 para la misma clase (instante guardado
 * 2026-11-16 09:30 UTC = 04:30 Lima). Contrato: se guarda el instante UTC y TODO canal muestra la hora de Lima.
 */
class LessonTimeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public static function cases(): array
    {
        return [
            'A 09:30 Lima' => ['2026-11-16 14:30:00', '16/11/2026', '09:30'],
            'B 04:30 Lima (clase de Abel)' => ['2026-11-16 09:30:00', '16/11/2026', '04:30'],
            'C 23:30 Lima = día siguiente en UTC' => ['2026-11-17 04:30:00', '16/11/2026', '23:30'],
            'D 00:00 Lima' => ['2026-11-16 05:00:00', '16/11/2026', '00:00'],
            'D 23:59 Lima' => ['2026-11-17 04:59:00', '16/11/2026', '23:59'],
        ];
    }

    /** @dataProvider cases */
    public function test_every_channel_shows_the_lima_wall_clock(string $utc, string $day, string $hm): void
    {
        $lesson = new Lesson(['start_time' => Carbon::parse($utc, 'UTC'), 'original_start_time' => Carbon::parse($utc, 'UTC')->subDay(), 'duration_minutes' => 60]);
        $user = User::factory()->make(['name' => 'Persona']);
        $expected = "{$day} {$hm} (hora de Perú)";

        $this->assertSame($expected, LimaClock::dateTime($lesson->start_time));
        $this->assertStringContainsString("Fecha: {$expected}", (new ClassConfirmedNotification($lesson))->toWhatsApp($user));
        $this->assertStringContainsString($expected, (new ClassReminderNotification($lesson, '2h'))->toWhatsApp($user));
        $this->assertStringContainsString($expected, (new ClassRescheduledNotification($lesson, 'el profesor'))->toWhatsApp($user));
        // El instante almacenado no se altera al formatear.
        $this->assertSame($utc, $lesson->start_time->format('Y-m-d H:i:s'));
    }

    public function test_the_api_serialises_an_unambiguous_utc_instant_so_the_browser_cannot_misread_it(): void
    {
        $lesson = new Lesson(['start_time' => Carbon::parse('2026-11-16 09:30:00', 'UTC'), 'duration_minutes' => 60]);

        $this->assertMatchesRegularExpression('/^2026-11-16T09:30:00(\.0+)?Z$/', $lesson->toArray()['start_time']);
    }
}
