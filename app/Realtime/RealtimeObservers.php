<?php

namespace App\Realtime;

use App\Models\ClassRequest;
use App\Models\ClassRequestTeacherDecline;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;

/**
 * Engancha las señales en tiempo real a los MODELOS, no a cada controlador: cualquier camino que cambie una solicitud,
 * una clase, un movimiento de créditos o una recarga (profesor, padre, administrador, scheduler) avisa igual, y solo
 * cuando el cambio es real (`wasChanged`) y tras el commit (ver RealtimeSignal).
 */
class RealtimeObservers
{
    public static function register(): void
    {
        // `created` y `updated` por separado: `wasRecentlyCreated` sigue en true en la instancia que acaba de crearse y haría
        // avisar por cualquier guardado posterior que no cambie nada relevante.
        $requestSignal = function (ClassRequest $request) {
            RealtimeSignal::to(function () use ($request) {
                $request->loadMissing('student');
                $ids = [$request->student?->parent_user_id, $request->counterofferTeacherProfile?->user_id];
                // Una solicitud que espera la aprobación del padre no existe todavía para ningún profesor.
                if ($request->status !== 'pending_parent_approval') {
                    $ids = [...$ids, ...$request->eligibleTeacherUsers()->pluck('id')->all()];
                }

                return $ids;
            }, ['requests', 'dashboard']);
        };
        ClassRequest::created($requestSignal);
        ClassRequest::updated(function (ClassRequest $request) use ($requestSignal) {
            if ($request->wasChanged(['status', 'teacher_profile_id', 'class_offer_id', 'counteroffer_teacher_profile_id', 'counteroffer_time'])) {
                $requestSignal($request);
            }
        });

        // Un profesor descarta una solicitud pública: la solicitud no cambia, pero a quien la pidió (y al propio profesor)
        // le cambia lo que ve (entrega, contadores).
        ClassRequestTeacherDecline::created(function (ClassRequestTeacherDecline $decline) {
            RealtimeSignal::to(function () use ($decline) {
                $request = ClassRequest::with('student')->find($decline->class_request_id);

                return [$request?->student?->parent_user_id, \App\Models\TeacherProfile::find($decline->teacher_profile_id)?->user_id];
            }, ['requests', 'dashboard']);
        });

        $lessonSignal = function (Lesson $lesson) {
            RealtimeSignal::to(function () use ($lesson) {
                $lesson->loadMissing(['student', 'teacherProfile']);

                return [$lesson->student?->parent_user_id, $lesson->teacherProfile?->user_id];
            }, ['lessons', 'requests', 'credits', 'dashboard']);
        };
        Lesson::created($lessonSignal);
        Lesson::updated(function (Lesson $lesson) use ($lessonSignal) {
            if ($lesson->wasChanged(['status', 'start_time'])) {
                $lessonSignal($lesson);
            }
        });

        // Todo movimiento de créditos pasa por el ledger append-only: es la señal fiable de que saldo/reservas cambiaron.
        CreditTransaction::created(function (CreditTransaction $tx) {
            RealtimeSignal::to(fn () => [$tx->teacherProfile?->user_id], ['credits', 'dashboard']);
        });

        $rechargeSignal = fn (RechargeRequest $recharge) => RealtimeSignal::to(fn () => [$recharge->teacherProfile?->user_id], ['credits']);
        RechargeRequest::created($rechargeSignal);
        RechargeRequest::updated(function (RechargeRequest $recharge) use ($rechargeSignal) {
            if ($recharge->wasChanged('status')) {
                $rechargeSignal($recharge);
            }
        });

        // La campana y el contador de avisos: se avisa cuando una notificación queda guardada para esa persona.
        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            if ($event->channel === 'database' && isset($event->notifiable->id)) {
                RealtimeSignal::to([$event->notifiable->id], ['notifications']);
            }
        });
    }
}
