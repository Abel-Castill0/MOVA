<?php

namespace App\Services;

use App\Events\ClassConfirmed;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ÚNICA verdad de agendamiento: convierte una ClassRequest en una Lesson
 * reservando créditos. Extraída tal cual de LessonController::store() para
 * que la aceptación normal del profesor y la aceptación de una contraoferta
 * por parte del padre ejecuten EXACTAMENTE la misma secuencia — nunca dos
 * copias que puedan divergir.
 *
 * Orden de locks (idéntico al original, no reordenar sin razón): primero la
 * ClassRequest (serializa aceptaciones concurrentes de la misma solicitud),
 * luego el Student (§13: serializa aceptaciones de solicitudes DISTINTAS
 * del mismo menor, que no comparten ningún otro lock), luego las clases en
 * conflicto (lockForUpdate en scheduleConflict) y al final el
 * TeacherProfile (créditos y cupo de mentoría).
 *
 * El llamador sigue siendo responsable de la AUTORIZACIÓN y del estado
 * esperado: el `$guard` corre bajo el lock de la ClassRequest, antes de
 * tocar nada más, y debe lanzar ValidationException si la solicitud ya no
 * califica (otra request ganó la carrera, la contraoferta cambió, etc.).
 * Cualquier excepción dentro de la transacción revierte TODO — no queda
 * nunca una solicitud 'accepted' sin su Lesson, ni una Lesson sin su
 * reserva de créditos.
 */
class LessonSchedulingService
{
    /**
     * @param  Closure(ClassRequest): void  $guard  Validación bajo lock del estado esperado.
     */
    public function schedule(
        int $classRequestId,
        int $teacherProfileId,
        string $startTime,
        int $durationMinutes,
        Closure $guard
    ): Lesson {
        $startTime = Carbon::parse($startTime)->utc()->toIso8601String();

        $lesson = DB::transaction(function () use ($classRequestId, $teacherProfileId, $startTime, $durationMinutes, $guard) {
            $classRequest = ClassRequest::with(['student', 'subject', 'classOffer'])
                ->whereKey($classRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            $guard($classRequest);

            // §13 — punto de serialización para el alumno (ver docblock).
            Student::whereKey($classRequest->student_id)->lockForUpdate()->first();

            $conflict = $this->conflict($teacherProfileId, $classRequest->student_id, $startTime, $durationMinutes, true);

            if ($conflict !== null) {
                throw ValidationException::withMessages([
                    'start_time' => $this->conflictMessage($conflict),
                ]);
            }

            $teacherProfile = TeacherProfile::whereKey($teacherProfileId)
                ->lockForUpdate()
                ->firstOrFail();

            // Bajo el lock del perfil: una revocación de verificación o una
            // suspensión confirmada después del chequeo de autorización (y
            // del guard) no puede terminar en una Lesson + reserva de
            // créditos para un profesor ya no habilitado.
            if (! $teacherProfile->is_verified
                || User::whereKey($teacherProfile->user_id)->value('suspended_at') !== null) {
                throw ValidationException::withMessages([
                    'accept' => 'Este profesor ya no está habilitado para agendar clases.',
                ]);
            }

            $creditsNeeded = Lesson::creditCostForMinutes($durationMinutes);

            if ($teacherProfile->credits_available < $creditsNeeded) {
                // Error de negocio, no de campo — misma clave `accept` que el
                // resto de errores no ligados a un input concreto.
                throw ValidationException::withMessages([
                    'accept' => 'Créditos insuficientes para esta duración. Por favor, recargue su saldo o elija una clase más corta.',
                ]);
            }

            if ($classRequest->is_mentorship && ! $teacherProfile->hasAvailableMentorshipSlots()) {
                throw ValidationException::withMessages([
                    'accept' => 'Tienes la agenda llena para acompañamiento continuo — no puedes aceptar esta solicitud por ahora.',
                ]);
            }

            // BUG-4: specific_rate de la oferta manda si existe (ya validado
            // contra maxAllowedRate() al guardarse la oferta).
            $rate = $classRequest->classOffer?->specific_rate ?? $teacherProfile->hourly_rate;

            $lesson = Lesson::create([
                'teacher_profile_id' => $teacherProfileId,
                'student_id' => $classRequest->student_id,
                'class_request_id' => $classRequest->id,
                'class_offer_id' => $classRequest->class_offer_id,
                'start_time' => $startTime,
                'duration_minutes' => $durationMinutes,
                'price_frozen_pen' => round($rate * $creditsNeeded, 2),
                'status' => 'scheduled',
            ]);

            // F-07: sala JaaS (JWT firmado por JaasService al unirse) — nunca
            // un link público de meet.jit.si ni una contraseña en claro.
            $lesson->update([
                'jitsi_room' => "mova-lesson-{$lesson->id}-".Str::random(32),
            ]);

            $profileUpdates = [
                'credits_available' => $teacherProfile->credits_available - $creditsNeeded,
                'credits_reserved' => $teacherProfile->credits_reserved + $creditsNeeded,
            ];

            if ($classRequest->is_mentorship) {
                $profileUpdates['mentorship_slots_taken'] = $teacherProfile->mentorship_slots_taken + 1;
            }

            $teacherProfile->update($profileUpdates);

            $teacherProfile->creditTransactions()->create([
                'idempotency_key' => "lesson:{$lesson->id}:reservation",
                'lesson_id' => $lesson->id,
                'type' => 'reservation',
                'amount' => $creditsNeeded,
                'description' => 'Reserva por aceptación de clase',
            ]);

            $classRequest->update(['status' => 'accepted']);

            return $lesson;
        });

        // Fuera de la transacción: solo se anuncia una clase que ya hizo
        // commit — nunca una que un rollback podría deshacer.
        event(new ClassConfirmed($lesson));

        return $lesson;
    }

    /**
     * Solapamiento con clases programadas del profesor O del alumno.
     *
     * @return null|'teacher'|'student'  Cuál de los dos está ocupado, para
     *                                   dar un mensaje que diga la verdad.
     */
    public function conflict(
        int $teacherProfileId,
        int $studentId,
        string $startTime,
        int $durationMinutes,
        bool $lock = false,
        ?int $excludeLessonId = null
    ): ?string {
        $requestedStart = Carbon::parse($startTime);
        $requestedEnd = $requestedStart->copy()->addMinutes($durationMinutes);

        $query = Lesson::where('status', 'scheduled')
            ->where('start_time', '<', $requestedEnd)
            ->where(function ($q) use ($teacherProfileId, $studentId) {
                $q->where('teacher_profile_id', $teacherProfileId)
                    ->orWhere('student_id', $studentId);
            })
            ->when($excludeLessonId, fn ($q) => $q->where('id', '!=', $excludeLessonId));

        if ($lock) {
            $query->lockForUpdate();
        }

        $overlapping = $query->get()->filter(
            fn (Lesson $lesson) => $lesson->end_time->gt($requestedStart)
        );

        if ($overlapping->isEmpty()) {
            return null;
        }

        // El conflicto del profesor manda en el mensaje: es quien está
        // eligiendo el horario y quien puede corregirlo en el acto.
        return $overlapping->contains(fn (Lesson $lesson) => $lesson->teacher_profile_id === $teacherProfileId)
            ? 'teacher'
            : 'student';
    }

    public function conflictMessage(string $conflict): string
    {
        return $conflict === 'teacher'
            ? 'Ya tienes una clase en ese horario.'
            : 'El alumno ya tiene otra clase agendada en ese horario con otro profesor.';
    }
}
