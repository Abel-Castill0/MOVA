<?php

namespace App\Services;

use App\Models\DiagnosticRecommendation;
use App\Models\Lesson;
use App\Models\StudentDiagnostic;
use App\Models\TeacherAvailabilitySlot;
use App\Models\TeacherProfile;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recomendaciones del diagnóstico, calculadas ÚNICAMENTE con datos del perfil
 * docente (verificación, materias, nivel, experiencia, disponibilidad
 * declarada y relación previa). No dependen de `ClassOffer` — ya no se crean
 * ofertas nuevas — y la IA no interviene en el ranking: el texto y las
 * palabras clave que genera el proveedor de IA son informativos y jamás
 * suman ni restan puntos a un profesor.
 */
class DiagnosticRecommendationService
{
    public const MAX_RECOMMENDATIONS = 5;

    /**
     * Compute scored recommendations for a diagnostic and persist them.
     * Returns the top-5 recommendations ordered by score.
     *
     * Scoring (0–100), todo determinista y explicable al padre:
     *   35 — base: el profesor está verificado, enseña la materia y no está suspendido
     *   15 — nivel educativo: la materia del profesor coincide con el nivel del alumno
     *   15 — historial: ya dio clases a este alumno
     *   10 — experiencia: ≥ 5 clases completadas (10) o ≥ 1 (5)
     *   10 — perfil: biografía detallada ≥ 200 caracteres (10) o presente (3)
     *   15 — disponibilidad declarada acorde con la urgencia
     *        (sin franjas declaradas → neutral: ni bono ni penalización)
     */
    public function compute(StudentDiagnostic $diagnostic): Collection
    {
        return DB::transaction(function () use ($diagnostic) {
            // Serializa el cálculo del MISMO diagnóstico: dos requests
            // simultáneas (doble click, reintento) esperan aquí; la segunda
            // recalcula con las mismas reglas y reemplaza el resultado, así que
            // nunca quedan filas duplicadas ni un ranking a medias.
            StudentDiagnostic::whereKey($diagnostic->getKey())->lockForUpdate()->first();

            // Delete stale recommendations if re-computing
            $diagnostic->recommendations()->delete();

            $studentId = $diagnostic->student_id;
            $gradeLevel = $diagnostic->level ?? $diagnostic->student->grade_level ?? null;

            $profiles = $this->candidateProfiles($diagnostic);

            if ($profiles->isEmpty()) {
                $diagnostic->update(['status' => 'completed']);

                return collect();
            }

            // Pre-load prior lesson history for this student
            $priorTeacherIds = Lesson::whereHas('classRequest', fn ($q) =>
                $q->where('student_id', $studentId)
            )->pluck('teacher_profile_id')->unique()->all();

            $scored = $profiles->map(function (TeacherProfile $profile) use ($gradeLevel, $priorTeacherIds, $diagnostic) {
                $score = 35;
                $reasons = [];

                // Con subject_id del diagnóstico, esa es LA materia; si no
                // viniera, se toma la primera materia del profesor.
                $subject = $diagnostic->subject_id
                    ? $profile->subjects->firstWhere('id', $diagnostic->subject_id)
                    : $profile->subjects->first();

                $subjectName = $subject?->name ?? 'la materia seleccionada';
                $reasons[] = "Enseña {$subjectName}";
                $reasons[] = 'Profesor verificado por MOVA';

                $subjectLevel = $subject?->level;
                if ($gradeLevel && $subjectLevel && ($subjectLevel === $gradeLevel || $subjectLevel === 'todos')) {
                    $score += 15;
                    $reasons[] = 'Atiende el nivel educativo de tu hijo';
                }

                if (in_array($profile->id, $priorTeacherIds, true)) {
                    $score += 15;
                    $reasons[] = 'Ya tiene experiencia con tu hijo';
                }

                $completed = (int) $profile->completed_classes_count;
                if ($completed >= 5) {
                    $score += 10;
                    $reasons[] = 'Cuenta con clases completadas en MOVA';
                } elseif ($completed >= 1) {
                    $score += 5;
                    $reasons[] = 'Ya completó clases en MOVA';
                }

                $bioLen = mb_strlen((string) $profile->bio);
                if ($bioLen >= 200) {
                    $score += 10;
                    $reasons[] = 'Perfil docente detallado';
                } elseif ($bioLen > 0) {
                    $score += 3;
                    $reasons[] = 'Tiene perfil docente';
                }

                [$availBonus, $availReason] = $this->scoreAvailability($profile->availabilitySlots, (string) $diagnostic->urgency);
                if ($availBonus > 0) {
                    $score += $availBonus;
                    $reasons[] = $availReason;
                }

                $profile->_score = min($score, 100);
                $profile->_reasons = array_values(array_unique($reasons));

                return $profile;
            });

            // Desempate estable y explicable: a igual puntaje, más clases
            // completadas primero y, por último, el perfil más antiguo.
            $top = $scored
                ->sort(function (TeacherProfile $a, TeacherProfile $b) {
                    return [$b->_score, (int) $b->completed_classes_count, $a->id]
                        <=> [$a->_score, (int) $a->completed_classes_count, $b->id];
                })
                ->take(self::MAX_RECOMMENDATIONS)
                ->values();

            $recommendations = collect();
            foreach ($top as $rank => $profile) {
                $rec = DiagnosticRecommendation::create([
                    'student_diagnostic_id' => $diagnostic->id,
                    'class_offer_id' => null,
                    'teacher_profile_id' => $profile->id,
                    'rank' => $rank + 1,
                    'score' => $profile->_score,
                    'reasons' => $profile->_reasons,
                ]);
                $rec->setRelation('teacherProfile', $profile);
                $recommendations->push($rec);
            }

            $diagnostic->update(['status' => 'completed']);

            return $recommendations;
        });
    }

    /**
     * Profesores elegibles: verificados, de la materia, con biografía y una
     * tarifa EFECTIVA definida para esa materia y cuenta no suspendida. Misma
     * exigencia de verificación que ClassRequest::eligibleTeacherUsers().
     *
     * Tarifa efectiva = `teacher_subject.specific_rate` si el profesor fijó
     * una para la materia; si no, su `hourly_rate` base. Un profesor con tarifa
     * base 0 pero tarifa específica válida SÍ es elegible, y uno con tarifa
     * específica 0 no lo es aunque su base sea positiva.
     *
     * @return Collection<int, TeacherProfile>
     */
    private function candidateProfiles(StudentDiagnostic $diagnostic): Collection
    {
        $query = TeacherProfile::query()
            ->where('is_verified', true)
            ->whereNotNull('bio')
            ->where('bio', '!=', '')
            ->whereHas('user', fn ($q) => $q->whereNull('suspended_at'))
            ->whereHas('subjects', function ($q) use ($diagnostic) {
                if ($diagnostic->subject_id) {
                    $q->where('subjects.id', $diagnostic->subject_id);
                }

                $q->where(function ($rate) {
                    $rate->where('teacher_subject.specific_rate', '>', 0)
                        ->orWhere(fn ($base) => $base
                            ->whereNull('teacher_subject.specific_rate')
                            ->where('teacher_profiles.hourly_rate', '>', 0));
                });
            })
            ->with(['user', 'subjects', 'availabilitySlots']);

        return $query->get();
    }

    /**
     * Returns [bonus_points, reason_string] según las franjas semanales
     * declaradas vs. la urgencia, mirando SOLO horarios que aún no terminaron.
     *
     * Las franjas están en hora de Lima (Perú no tiene horario de verano, así
     * que no hay cambios de hora). No basta el nombre del día: un lunes de
     * 08:00–10:00 no cuenta como "esta semana" un miércoles, ni un martes de
     * 08:00–10:00 cuenta como "hoy" a las 11:00 del martes.
     *
     *   today_or_tomorrow → alguna franja futura hoy o mañana
     *   this_week         → alguna franja futura de hoy al domingo (semana
     *                       calendario lun–dom); si ya pasaron, neutral
     *   flexible          → declarar franjas suma un bono menor
     *
     * @param  Collection<int, TeacherAvailabilitySlot>  $slots
     */
    private function scoreAvailability(Collection $slots, string $urgency, ?Carbon $now = null): array
    {
        if ($slots->isEmpty()) {
            return [0, ''];
        }

        $now ??= Carbon::now('America/Lima');

        if ($urgency === 'today_or_tomorrow'
            && $this->hasUpcomingSlot($slots, $now, $now->copy()->addDay()->endOfDay())) {
            return [15, 'Tiene horarios disponibles hoy o mañana'];
        }

        if ($urgency === 'this_week'
            && $this->hasUpcomingSlot($slots, $now, $now->copy()->endOfWeek(Carbon::SUNDAY))) {
            return [15, 'Tiene horarios disponibles el resto de esta semana'];
        }

        if ($urgency === 'flexible') {
            return [5, 'Declara horarios disponibles'];
        }

        // Sin franjas futuras en la ventana → sin bono ni penalización.
        return [0, ''];
    }

    /**
     * ¿Alguna franja semanal tiene una ocurrencia que termine después de
     * $from y empiece antes de $until? (ambos en America/Lima)
     *
     * @param  Collection<int, TeacherAvailabilitySlot>  $slots
     */
    private function hasUpcomingSlot(Collection $slots, Carbon $from, Carbon $until): bool
    {
        for ($day = $from->copy()->startOfDay(); $day->lte($until); $day->addDay()) {
            foreach ($slots->where('day_of_week', $day->dayOfWeek) as $slot) {
                $start = $day->copy()->setTimeFromTimeString($slot->start_time);
                $end = $day->copy()->setTimeFromTimeString($slot->end_time);

                if ($end->gt($from) && $start->lt($until)) {
                    return true;
                }
            }
        }

        return false;
    }
}
