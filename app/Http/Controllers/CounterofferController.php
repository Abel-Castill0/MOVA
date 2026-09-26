<?php

namespace App\Http\Controllers;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\TeacherProfile;
use App\Notifications\CounterofferProposedNotification;
use App\Notifications\CounterofferRejectedNotification;
use App\Services\LessonSchedulingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Contraoferta de horario, 100% dentro de MOVA:
 *
 *   profesor elegible  →  POST .../counteroffer          open → counteroffered
 *   padre dueño        →  POST .../counteroffer/accept   counteroffered → accepted (+ Lesson)
 *   padre dueño        →  POST .../counteroffer/reject   counteroffered → open
 *
 * NO existe ningún endpoint público que cambie este estado: una versión
 * anterior aceptaba "SI/NO" en un webhook sin autenticación ni firma y, al
 * aceptar, marcaba la solicitud como 'accepted' con un link de meet.jit.si,
 * saltándose la Lesson, los créditos, la reserva y la agenda. Aquí aceptar
 * pasa por LessonSchedulingService::schedule(), la MISMA primitiva que la
 * aceptación normal del profesor (LessonController::store).
 */
class CounterofferController extends Controller
{
    /** Profesor: propone hora exacta + duración para una solicitud abierta. */
    public function store(Request $request, ClassRequest $classRequest, LessonSchedulingService $scheduling)
    {
        $this->authorize('counteroffer', $classRequest);

        $data = $request->validate([
            'counteroffer_time' => ['required', 'date', 'after:now'],
            'duration_minutes'  => ['required', 'integer', 'min:30', 'max:240'],
        ], [
            'counteroffer_time.required' => 'La hora propuesta es obligatoria.',
            'counteroffer_time.after'    => 'La hora propuesta debe ser en el futuro.',
            'duration_minutes.min'       => 'La duración mínima es de 30 minutos.',
            'duration_minutes.max'       => 'La duración máxima es de 240 minutos.',
        ]);

        $startTime = Carbon::parse($data['counteroffer_time'])->utc();
        $duration = (int) $data['duration_minutes'];
        $profile = $request->user()->teacherProfile;

        // Fail-fast, NO autoritativo: evita proponer un horario que el
        // profesor ya tiene ocupado o que no podría pagar. La verdad se
        // vuelve a comprobar bajo lock cuando el padre acepta.
        $conflict = $scheduling->conflict($profile->id, $classRequest->student_id, $startTime->toIso8601String(), $duration);
        if ($conflict !== null) {
            throw ValidationException::withMessages(['counteroffer_time' => $scheduling->conflictMessage($conflict)]);
        }
        if ($profile->credits_available < Lesson::creditCostForMinutes($duration)) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'No tienes créditos suficientes para una clase de esta duración.',
            ]);
        }

        $updated = DB::transaction(function () use ($classRequest, $startTime, $duration, $profile, $request) {
            $locked = ClassRequest::whereKey($classRequest->id)->lockForUpdate()->firstOrFail();

            // Bajo lock: dos profesores proponiendo a la vez — solo uno gana,
            // el otro ve la solicitud ya 'counteroffered'.
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages([
                    'counteroffer_time' => 'Esta solicitud ya no está disponible para una contraoferta.',
                ]);
            }

            // La elegibilidad se re-evalúa sobre datos frescos y la fila
            // bloqueada: si un admin suspendió o des-verificó al profesor
            // mientras esta petición viajaba, no se bloquea la solicitud con
            // una propuesta que luego nadie podría aceptar.
            $teacher = $request->user()->fresh();
            if (! $teacher
                || $teacher->suspended_at !== null
                || ! Gate::forUser($teacher)->allows('counteroffer', $locked)) {
                throw ValidationException::withMessages([
                    'counteroffer_time' => 'Ya no puedes proponer un horario para esta solicitud.',
                ]);
            }

            $locked->update([
                'status'                          => 'counteroffered',
                'counteroffer_time'               => $startTime,
                'counteroffer_duration_minutes'   => $duration,
                'counteroffer_teacher_profile_id' => $profile->id,
            ]);

            ClassEvent::log('counteroffer_proposed', $request->user()->id, null, $locked->id, null, [
                'duration_minutes' => $duration,
            ]);

            return $locked;
        });

        // Tras el commit: nunca avisar de un estado que un rollback podría
        // deshacer. Un fallo al notificar no revierte la propuesta ya guardada.
        $updated->loadMissing('student.parent');
        $updated->student?->parent?->notify(new CounterofferProposedNotification($updated));

        return back()->with('success', 'Propuesta enviada. El padre podrá aceptarla o rechazarla desde MOVA.');
    }

    /** Padre dueño: acepta la contraoferta → crea la Lesson con la primitiva única. */
    public function accept(Request $request, ClassRequest $classRequest, LessonSchedulingService $scheduling)
    {
        $this->authorize('respondToCounteroffer', $classRequest);
        $seenRef = $this->seenRef($request);

        // Fail-fast; la verdad se re-evalúa bajo lock en el guard.
        $this->assertStillCounteroffered($classRequest, requireFuture: true);
        $this->assertSameProposal($classRequest, $seenRef);

        $scheduling->schedule(
            $classRequest->id,
            (int) $classRequest->counteroffer_teacher_profile_id,
            $classRequest->counteroffer_time->toIso8601String(),
            (int) $classRequest->counteroffer_duration_minutes,
            function (ClassRequest $locked) use ($request, $seenRef) {
                // Ownership bajo lock (student_id es inmutable, pero la
                // verificación se repite sobre la fila bloqueada).
                abort_unless($request->user()->can('respondToCounteroffer', $locked), 403);

                // La propuesta aceptada debe ser EXACTAMENTE la que el padre
                // vio en SU pantalla (huella enviada desde la página): si el
                // profesor, la hora o la duración cambiaron desde entonces —
                // incluso por un rechazo + nueva propuesta en otra pestaña —
                // no se agenda nada.
                $this->assertStillCounteroffered($locked, requireFuture: true);
                $this->assertSameProposal($locked, $seenRef);

                // El profesor que propuso debe SEGUIR siendo elegible y no
                // estar suspendido — la misma policy que la aceptación normal,
                // evaluada para él, no para el padre.
                $teacherUser = $locked->counterofferTeacherProfile?->user;
                if (! $teacherUser
                    || $teacherUser->suspended_at !== null
                    || ! Gate::forUser($teacherUser)->allows('accept', $locked)) {
                    throw ValidationException::withMessages([
                        'counteroffer' => 'El profesor que hizo esta propuesta ya no está disponible. Rechaza la propuesta para que tu solicitud vuelva a estar abierta.',
                    ]);
                }
            }
        );

        return redirect()->route('parent.lessons')->with('success', '¡Clase programada! Ya aparece en "Mis clases".');
    }

    /** Padre dueño: rechaza la contraoferta → la solicitud vuelve a 'open'. */
    public function reject(Request $request, ClassRequest $classRequest)
    {
        $this->authorize('respondToCounteroffer', $classRequest);
        $seenRef = $this->seenRef($request);

        $rejected = DB::transaction(function () use ($classRequest, $request, $seenRef) {
            $locked = ClassRequest::with('subject')->whereKey($classRequest->id)->lockForUpdate()->firstOrFail();

            abort_unless($request->user()->can('respondToCounteroffer', $locked), 403);
            $this->assertStillCounteroffered($locked);
            $this->assertSameProposal($locked, $seenRef);

            $teacherProfileId = $locked->counteroffer_teacher_profile_id;

            // Sin ninguna mutación de créditos: una propuesta nunca reservó
            // nada. Se limpian TODOS los campos de la contraoferta.
            $locked->update([
                'status'                          => 'open',
                'counteroffer_time'               => null,
                'counteroffer_duration_minutes'   => null,
                'counteroffer_teacher_profile_id' => null,
            ]);

            ClassEvent::log('counteroffer_rejected', $request->user()->id, null, $locked->id);

            return ['teacher_profile_id' => $teacherProfileId, 'request' => $locked];
        });

        $teacherUser = TeacherProfile::find($rejected['teacher_profile_id'])?->user;
        $teacherUser?->notify(new CounterofferRejectedNotification(
            $rejected['request']->id,
            $rejected['request']->subject?->name ?? 'la clase'
        ));

        return back()->with('success', 'Propuesta rechazada. Tu solicitud vuelve a estar abierta para los profesores.');
    }

    /** Huella de la propuesta que el padre tenía en pantalla (ClassRequest::counterofferRef). */
    private function seenRef(Request $request): string
    {
        return $request->validate([
            'counteroffer_ref' => ['required', 'string', 'size:64'],
        ], [
            'counteroffer_ref.*' => 'La propuesta ya no está disponible. Recarga la página.',
        ])['counteroffer_ref'];
    }

    private function assertSameProposal(ClassRequest $classRequest, string $seenRef): void
    {
        $current = $classRequest->counterofferRef();

        if ($current === null || ! hash_equals($current, $seenRef)) {
            throw ValidationException::withMessages([
                'counteroffer' => 'La propuesta cambió mientras la revisabas. Recarga la página para ver la vigente.',
            ]);
        }
    }

    private function assertStillCounteroffered(ClassRequest $classRequest, bool $requireFuture = false): void
    {
        if ($classRequest->status !== 'counteroffered'
            || $classRequest->counteroffer_time === null
            || $classRequest->counteroffer_duration_minutes === null
            || $classRequest->counteroffer_teacher_profile_id === null) {
            throw ValidationException::withMessages([
                'counteroffer' => 'Esta propuesta ya no está disponible.',
            ]);
        }

        // Solo aceptar exige un horario futuro — rechazar una propuesta ya
        // vencida tiene que seguir siendo posible para reabrir la solicitud.
        if ($requireFuture && $classRequest->counteroffer_time->isPast()) {
            throw ValidationException::withMessages([
                'counteroffer' => 'El horario propuesto ya pasó. Rechaza la propuesta para que tu solicitud vuelva a estar abierta.',
            ]);
        }
    }
}
