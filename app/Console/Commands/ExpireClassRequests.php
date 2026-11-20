<?php

namespace App\Console\Commands;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\TeacherProfile;
use App\Notifications\ClassRequestExpiredNotification;
use App\Notifications\CounterofferLapsedNotification;
use App\Support\RequestSlots;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vencimiento de solicitudes por OPORTUNIDAD REAL, no por un plazo fijo desde su creación
 * (ClassRequest::responseDeadline): una clase puntual pedida para el viernes por la tarde sigue abierta hasta el viernes
 * a las 18:00 de Lima; un acompañamiento, 7 días; una solicitud antigua sin día, sus 24 h de siempre.
 *
 * Por estado:
 *   open                    vence al pasar su fecha límite                     → expired  (aviso al padre)
 *   pending_parent_approval solo con día + franja y la franja ya terminó       → expired  (el control parental no se apura
 *                                                                                           de otro modo: espera al padre)
 *   counteroffered          la propuesta vence en SU hora (counteroffer_time):
 *                             - si la solicitud aún tiene oportunidad         → open     (vuelve a recibir propuestas)
 *                             - si no                                         → expired
 *                           Una propuesta todavía aceptable nunca se toca, aunque la franja pedida ya haya pasado
 *                           (era una alternativa explícita que el padre aún puede aceptar).
 *   accepted / rejected / teacher_rejected / expired: jamás (clases, reservas y créditos quedan intactos).
 *
 * No toca dinero: ni una solicitud ni una propuesta reservan créditos (la reserva ocurre al crear la clase). Cada
 * transición se decide bajo `lockForUpdate` sobre la fila releída: si una aceptación concurrente ganó, aquí se ve
 * `accepted`/otro estado y no se hace nada ('raced'); si gana este comando, la aceptación encuentra el estado nuevo bajo
 * su propio lock y se rechaza. Idempotente: una segunda pasada no encuentra nada que cambiar.
 */
class ExpireClassRequests extends Command
{
    protected $signature = 'mova:expire-class-requests
        {--dry-run : Muestra qué cambiaría sin escribir nada}';

    protected $description = 'Vence solicitudes y propuestas cuya oportunidad real de atención ya pasó';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $candidates = $this->candidates($now);

        $this->info("Vencimiento por oportunidad real — candidatas: {$candidates->count()}");
        $tally = ['expired' => 0, 'reopened' => 0, 'raced' => 0, 'error' => 0];

        foreach ($candidates as $request) {
            $plan = $this->decide($request, $now);
            if ($plan === null) {
                continue;
            }
            if ($dryRun) {
                $this->line("  [dry-run] ClassRequest {$request->id} ({$request->status}, {$request->deadlinePolicy()}): {$plan}");

                continue;
            }
            $tally[$this->apply($request->id)]++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Vencidas: {$tally['expired']}. Propuestas vencidas que reabren la solicitud: {$tally['reopened']}. Ya resueltas por otra vía: {$tally['raced']}. Errores: {$tally['error']}.");

        return self::SUCCESS;
    }

    /**
     * Prefiltro barato en SQL (el veredicto final es responseDeadline() en PHP, la misma regla que ve la UI).
     * Día + franja: el día pedido ya es hoy o pasado en Lima. Sin día: superó el plazo más corto posible.
     */
    private function candidates($now)
    {
        $todayLima = RequestSlots::today($now);
        $legacyCutoff = $now->copy()->subHours(min((int) config('class_requests.expiry_hours', 24), 24 * (int) config('class_requests.mentorship_expiry_days', 7)));

        return ClassRequest::query()
            ->where(function ($q) use ($todayLima, $legacyCutoff, $now) {
                $q->where(function ($open) use ($todayLima, $legacyCutoff) {
                    $open->whereIn('status', ['open', 'pending_parent_approval'])->where(function ($w) use ($todayLima, $legacyCutoff) {
                        $w->where(fn ($d) => $d->whereNotNull('preferred_date')->where('preferred_date', '<=', $todayLima))
                            ->orWhere(fn ($n) => $n->whereNull('preferred_date')->where('status', 'open')->where('created_at', '<=', $legacyCutoff));
                    });
                })->orWhere(fn ($c) => $c->where('status', 'counteroffered')->where('counteroffer_time', '<=', $now));
            })
            ->orderBy('id')
            ->get();
    }

    /** Qué le toca a esta solicitud ahora (null = nada). Se vuelve a decidir bajo lock en apply(). */
    private function decide(ClassRequest $request, $now): ?string
    {
        return match ($request->status) {
            'open' => $request->opportunityHasPassed($now) ? 'expired' : null,
            'pending_parent_approval' => $request->hasPreferredDay() && $request->opportunityHasPassed($now) ? 'expired' : null,
            'counteroffered' => $request->counteroffer_time !== null && $request->counteroffer_time->lessThanOrEqualTo($now)
                ? ($request->opportunityHasPassed($now) ? 'expired' : 'reopened')
                : null,
            default => null,
        };
    }

    private function apply(int $id): string
    {
        try {
            $result = DB::transaction(function () use ($id) {
                $request = ClassRequest::with(['student.parent', 'subject'])->whereKey($id)->lockForUpdate()->first();
                $now = now();
                $plan = $request ? $this->decide($request, $now) : null;
                if ($plan === null) {
                    return null;
                }

                $lapsedTeacherId = $request->status === 'counteroffered' ? $request->counteroffer_teacher_profile_id : null;
                $reason = match (true) {
                    $request->status === 'counteroffered' => 'proposal_lapsed',
                    $request->status === 'pending_parent_approval' => 'approval_missing',
                    $request->deadlinePolicy() === 'slot' => 'slot_passed',
                    default => 'no_answer',
                };

                if ($plan === 'reopened') {
                    $request->update([
                        'status' => 'open', 'counteroffer_time' => null, 'counteroffer_duration_minutes' => null, 'counteroffer_teacher_profile_id' => null,
                    ]);
                    ClassEvent::log('counteroffer_lapsed', null, null, $request->id, 'La propuesta de horario pasó sin respuesta; la solicitud vuelve a estar abierta.');
                } else {
                    $request->update(['status' => 'expired'] + ($request->status === 'counteroffered'
                        ? ['counteroffer_time' => null, 'counteroffer_duration_minutes' => null, 'counteroffer_teacher_profile_id' => null]
                        : []));
                    ClassEvent::log('request_expired', null, null, $request->id, match ($reason) {
                        'slot_passed' => 'Terminó el día y la franja pedidos sin una clase confirmada',
                        'approval_missing' => 'Terminó el día y la franja pedidos sin la aprobación del padre',
                        'proposal_lapsed' => 'La propuesta pasó sin respuesta y ya no quedaba oportunidad',
                        default => 'Sin respuesta de ningún profesor dentro del plazo',
                    });
                }

                return [$plan, $request, $reason, $lapsedTeacherId];
            });

            if ($result === null) {
                return 'raced';
            }
            [$plan, $request, $reason, $lapsedTeacherId] = $result;

            // Avisos SOLO tras el commit. Al padre siempre (pierde algo o debe saber que sigue esperando); al profesor solo
            // si era SU propuesta la que venció. Nunca a todos los profesores elegibles: sería ruido.
            // Cada aviso por separado: un fallo al avisar a uno no impide avisar al otro ni revierte el cambio de estado.
            $notify = function (callable $send) use ($request) {
                try {
                    $send();
                } catch (\Throwable $e) {
                    Log::warning('CLASS_REQUEST_EXPIRY_NOTICE_FAILED', ['class_request_id' => $request->id, 'error' => $e->getMessage()]);
                    report($e);
                }
            };
            $parent = $request->student?->parent;
            $notify(fn () => $parent?->notify($plan === 'reopened'
                ? new CounterofferLapsedNotification($request, true, 'parent')
                : new ClassRequestExpiredNotification($request, $reason)));
            if ($lapsedTeacherId) {
                $notify(fn () => TeacherProfile::find($lapsedTeacherId)?->user?->notify(new CounterofferLapsedNotification($request, $plan === 'reopened', 'teacher')));
            }

            $this->line("  ClassRequest {$request->id}: {$plan} ({$reason}).");

            return $plan;
        } catch (\Throwable $e) {
            Log::error('CLASS_REQUEST_EXPIRY_FAILED', ['class_request_id' => $id, 'error' => $e->getMessage()]);
            report($e);
            $this->error("  ClassRequest {$id}: no se pudo procesar.");

            return 'error';
        }
    }
}
