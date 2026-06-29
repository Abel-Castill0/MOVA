<?php

namespace App\Services;

use App\Models\ClassOffer;
use App\Models\DiagnosticRecommendation;
use App\Models\Lesson;
use App\Models\StudentDiagnostic;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DiagnosticRecommendationService
{
    /**
     * Compute scored recommendations for a diagnostic and persist them.
     * Returns the top-5 recommendations ordered by score.
     *
     * Scoring (0–100):
     *   35 — base (subject match, always true when offer passes filter)
     *   15 — grade level match between subject.level and student.grade_level
     *   15 — prior lesson history between student and this teacher
     *   15 — detailed bio (≥ 200 chars)
     *    5 — any bio present (< 200 chars but > 0)
     *   10 — hourly rate > 0 (tarifa definida)
     *    5 — offer has a title longer than 10 chars
     *   10 — availability matches urgency (today_or_tomorrow / this_week)
     *        NULL availability → neutral (no bonus, no penalty)
     */
    public function compute(StudentDiagnostic $diagnostic): Collection
    {
        // Delete stale recommendations if re-computing
        $diagnostic->recommendations()->delete();

        $studentId = $diagnostic->student_id;
        $gradeLevel = $diagnostic->level ?? $diagnostic->student->grade_level ?? null;

        // Build candidate offers: must match subject, be active, belong to verified teacher with non-empty bio
        $query = ClassOffer::where('is_active', true)
            ->whereHas('teacherProfile', fn ($q) =>
                $q->where('is_verified', true)
                  ->whereNotNull('bio')
                  ->where('bio', '!=', '')
                  ->where('hourly_rate', '>', 0)
            )
            ->with(['teacherProfile.user', 'subject']);

        if ($diagnostic->subject_id) {
            $query->where('subject_id', $diagnostic->subject_id);
        }

        $offers = $query->get();

        if ($offers->isEmpty()) {
            $diagnostic->update(['status' => 'completed']);
            return collect();
        }

        // Pre-load prior lesson history for this student
        $priorTeacherIds = Lesson::whereHas('classRequest', fn ($q) =>
            $q->where('student_id', $studentId)
        )->pluck('teacher_profile_id')->unique()->toArray();

        $scored = $offers->map(function (ClassOffer $offer) use ($gradeLevel, $priorTeacherIds, $diagnostic) {
            $score   = 35; // base: subject match + filters passed
            $reasons = [];

            // Subject name reason
            $subjectName = $offer->subject?->name ?? 'la materia seleccionada';
            $reasons[] = "Enseña {$subjectName}";
            $reasons[] = 'Profesor verificado por MOVA';

            // Grade level match
            $offerLevel = $offer->subject?->level ?? null;
            if ($gradeLevel && $offerLevel && ($offerLevel === $gradeLevel || $offerLevel === 'todos')) {
                $score += 15;
                $reasons[] = 'Atiende el nivel educativo de tu hijo';
            }

            // Prior history
            if (in_array($offer->teacher_profile_id, $priorTeacherIds)) {
                $score += 15;
                $reasons[] = 'Ya tiene experiencia con tu hijo';
            }

            // Bio quality
            $bioLen = strlen($offer->teacherProfile->bio ?? '');
            if ($bioLen >= 200) {
                $score += 15;
                $reasons[] = 'Perfil docente detallado';
            } elseif ($bioLen > 0) {
                $score += 5;
                $reasons[] = 'Tiene perfil docente';
            }

            // Hourly rate defined
            if ($offer->teacherProfile->hourly_rate > 0) {
                $score += 10;
                $reasons[] = 'Tarifa por hora definida';
            }

            // Offer has descriptive title
            if (strlen($offer->title) > 10) {
                $score += 5;
                $reasons[] = 'Oferta con descripción clara';
            }

            // Availability signal (light — NULL is neutral, not penalized)
            $schedule = $offer->availability_schedule;
            if ($schedule && isset($schedule['days'])) {
                [$availBonus, $availReason] = $this->scoreAvailability($schedule, $diagnostic->urgency);
                if ($availBonus > 0) {
                    $score += $availBonus;
                    $reasons[] = $availReason;
                }
            }

            $offer->_score   = min($score, 100);
            $offer->_reasons = array_values(array_unique($reasons));

            return $offer;
        });

        // Sort once to determine baseline scores before AI boost
        $sorted = $scored->sortByDesc('_score')->values();

        // AI keyword boost: max 5 pts, only when keywords available and confidence >= 60
        $aiKeywords  = $diagnostic->ai_keywords ?? [];
        $aiConfidence = $diagnostic->ai_confidence ?? 0;
        $applyAiBoost = !empty($aiKeywords) && $aiConfidence >= 60;

        if ($applyAiBoost && $sorted->isNotEmpty()) {
            $topScore = $sorted->first()->_score;

            $sorted = $sorted->map(function ($offer) use ($aiKeywords, $topScore) {
                // Only boost if within 15 pts of the top candidate
                if (($topScore - $offer->_score) > 15) {
                    return $offer;
                }
                $searchText = mb_strtolower($offer->title . ' ' . ($offer->description ?? ''));
                $matched = 0;
                foreach ($aiKeywords as $kw) {
                    if (mb_strlen($kw) > 2 && str_contains($searchText, mb_strtolower($kw))) {
                        $matched++;
                    }
                }
                if ($matched > 0) {
                    $offer->_score = min($offer->_score + min($matched * 2, 5), 100);
                }
                return $offer;
            })->sortByDesc('_score')->values();
        }

        $top = $sorted->take(5)->values();

        $recommendations = collect();
        foreach ($top as $rank => $offer) {
            $rec = DiagnosticRecommendation::create([
                'student_diagnostic_id' => $diagnostic->id,
                'class_offer_id'        => $offer->id,
                'teacher_profile_id'    => $offer->teacher_profile_id,
                'rank'                  => $rank + 1,
                'score'                 => $offer->_score,
                'reasons'               => $offer->_reasons,
            ]);
            $rec->setRelation('classOffer', $offer);
            $recommendations->push($rec);
        }

        $diagnostic->update(['status' => 'completed']);

        return $recommendations;
    }

    /**
     * Returns [bonus_points, reason_string] based on availability_schedule vs urgency.
     * Days are evaluated in America/Lima timezone to match the target market.
     */
    private function scoreAvailability(array $schedule, string $urgency): array
    {
        $dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $now = Carbon::now('America/Lima');

        $hasSlot = fn (string $day): bool =>
            !empty($schedule['days'][$day] ?? []);

        if ($urgency === 'today_or_tomorrow') {
            $today    = $dayNames[$now->dayOfWeek];
            $tomorrow = $dayNames[$now->copy()->addDay()->dayOfWeek];
            if ($hasSlot($today) || $hasSlot($tomorrow)) {
                return [10, 'Tiene horarios disponibles hoy o mañana'];
            }
        }

        if ($urgency === 'this_week') {
            foreach ($dayNames as $day) {
                if ($hasSlot($day)) {
                    return [10, 'Tiene horarios disponibles esta semana'];
                }
            }
        }

        // flexible urgency or no matching slots → no bonus but no penalty
        return [0, ''];
    }
}
