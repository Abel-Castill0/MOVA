<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\LimaClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUG-5 (docs/MOVA_AUDIT_PHASE0.md, sección Q) — `config('app.timezone')` es
 * UTC; Perú es UTC-5 sin horario de verano. Las 8pm de Lima del día 25 son
 * ya la 1am UTC del día 26 — cualquier "hoy" calculado con `today()`/
 * `whereDate(..., today())` corta el día real ~5 horas antes de lo que un
 * usuario en Perú esperaría.
 */
class LimaTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_todayRangeUtc_spans_lima_midnight_to_midnight_expressed_in_utc(): void
    {
        // 10am hora de Lima del 26 = 3pm UTC del 26.
        Carbon::setTestNow(Carbon::parse('2026-08-26 10:00:00', LimaClock::TIMEZONE));

        [$start, $end] = LimaClock::todayRangeUtc();

        // Medianoche del 26 en Lima = 05:00 UTC del 26.
        $this->assertSame('2026-08-26 05:00:00', $start->toDateTimeString());
        $this->assertSame('UTC', $start->getTimezone()->getName());
        // Medianoche del 27 en Lima = 05:00 UTC del 27.
        $this->assertSame('2026-08-27 05:00:00', $end->toDateTimeString());
    }

    public function test_a_lesson_at_8pm_lima_counts_as_todays_class_even_though_its_already_tomorrow_in_utc(): void
    {
        // 8pm Lima del 25 = 1am UTC del 26 — el caso exacto que describe BUG-5.
        Carbon::setTestNow(Carbon::parse('2026-08-25 20:00:00', LimaClock::TIMEZONE));

        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => true]);
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id, 'first_name' => 'Alumno', 'last_name' => 'Noche',
            'grade_level' => 'secundaria',
        ]);

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'start_time' => now(), // 8pm Lima del 25 = 1am UTC del 26
            'duration_minutes' => 60,
            'status' => 'scheduled',
        ]);

        [$start, $end] = LimaClock::todayRangeUtc();
        $countedAsToday = Lesson::where('start_time', '>=', $start)->where('start_time', '<', $end)->count();

        $this->assertSame(1, $countedAsToday, 'Una clase a las 8pm hora de Lima debe contar como "hoy" en Lima.');
        // Confirmación de que el caso realmente cruza la medianoche: la misma
        // marca de tiempo cae en fechas de calendario distintas según la
        // zona horaria con la que se lea — exactamente por qué whereDate()
        // en UTC puro (el bug original) la habría contado para el día
        // equivocado.
        $this->assertSame('2026-08-25', $lesson->start_time->clone()->setTimezone(LimaClock::TIMEZONE)->toDateString());
        $this->assertSame('2026-08-26', $lesson->start_time->clone()->setTimezone('UTC')->toDateString());
    }

    public function test_ai_usage_daily_limit_resets_at_lima_midnight_not_utc_midnight(): void
    {
        // 11pm Lima del 25 = 4am UTC del 26 — un log creado aquí debe seguir
        // contando para "el día de hoy" en Lima (el 25), no ya para "mañana".
        Carbon::setTestNow(Carbon::parse('2026-08-25 23:00:00', LimaClock::TIMEZONE));

        AiUsageLog::create([
            'provider' => 'openai', 'model' => 'gpt-test', 'status' => 'success',
            'total_tokens' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$start, $end] = LimaClock::todayRangeUtc();
        $count = AiUsageLog::where('status', 'success')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->count();

        $this->assertSame(1, $count);
    }
}
