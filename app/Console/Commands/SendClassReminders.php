<?php

namespace App\Console\Commands;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Notifications\ClassReminderNotification;
use App\Notifications\PendingReportReminderNotification;
use App\Notifications\UnansweredRequestNotification;
use App\Support\LessonNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendClassReminders extends Command
{
    protected $signature = 'classmate:send-reminders';

    protected $description = 'Send class reminders (24h, 2h, 10m) and pending report alerts';

    public function handle(): void
    {
        $this->send24hReminders();
        $this->send2hReminders();
        $this->send10mReminders();
        $this->sendPendingReportAlerts();
        $this->sendUnansweredRequestAlerts();
    }

    /**
     * F-04 — Reclama la lección y despacha el aviso de forma ATÓMICA.
     *
     * ── Por qué hace falta un claim ──────────────────────────────────────
     * El patrón original (SELECT -> notify() -> update()) dejaba una ventana de
     * carrera: este comando corre cada minuto y sus barridos pueden superar los
     * 60 s con volumen real, así que dos instancias leían el mismo conjunto con
     * el marcador todavía en NULL y ambas notificaban.
     *
     * ── Por qué el claim solo NO bastaba ─────────────────────────────────
     * La primera corrección hacía UPDATE y luego notificaba fuera de cualquier
     * transacción, aceptando explícitamente "preferimos perder un recordatorio
     * a enviarlo dos veces". Esa concesión era innecesaria: si el proceso moría
     * entre el UPDATE y el despacho, el marcador quedaba consumido y el aviso
     * se perdía PARA SIEMPRE, porque el barrido filtra por whereNull. Cambiar
     * un duplicado por una pérdida permanente no es buen intercambio.
     *
     * ── Por qué esto es seguro y no viola "nada externo en transacción" ──
     * Las 21 clases de App\Notifications implementan ShouldQueue (verificado),
     * así que notify() NO hace ninguna llamada externa: solo inserta filas en
     * la tabla `jobs`. Con QUEUE_CONNECTION=database esas filas viven en la
     * MISMA base de datos, de modo que envolver claim + despacho en una
     * transacción los vuelve atómicos sin retener ningún lock durante una
     * llamada de red:
     *
     *     claim OK + jobs insertados -> COMMIT
     *     excepción / caída          -> ROLLBACK (marcador liberado; se
     *                                             reintenta en la próxima pasada)
     *
     * ── SEMÁNTICA EXACTA — no confundir los tres niveles ────────────────
     *
     *   1. Claim + encolado (esta transacción):  EXACTLY-ONCE.
     *      El marcador y el job se persisten juntos o no se persiste ninguno.
     *      Es lo único que este mecanismo puede garantizar.
     *
     *   2. Procesamiento del job por el worker:  AT-LEAST-ONCE.
     *      Si el worker muere DESPUÉS del COMMIT y antes de marcar el job como
     *      terminado, la cola lo reintenta (retry_after=90 s, mayor que
     *      cualquier Http::timeout del código). Es correcto y deseado: sin
     *      reintento, un worker caído perdería el aviso.
     *
     *   3. Entrega externa (Meta / correo):  AT-LEAST-ONCE, dependiente del
     *      proveedor. El caso real: Meta ACEPTA el mensaje, el worker muere
     *      antes de registrar el éxito, la cola reintenta y el usuario recibe
     *      el aviso DOS VECES. Esta transacción no puede evitarlo — ninguna
     *      puede, porque el efecto ya salió del sistema.
     *
     *   Es decir: NO existe "exactly-once delivery" aquí, y afirmarlo sería
     *   falso. Lo que sí se eliminó es el modo de fallo grave —marcador
     *   consumido sin nada encolado, aviso perdido para siempre— a cambio de
     *   un duplicado posible y poco frecuente. Ese intercambio sí es correcto.
     *
     *   Mitigación del duplicado a nivel de WhatsApp: whatsapp_messages guarda
     *   un client_reference por envío y MetaCloudApiProvider registra el
     *   provider_message_id con UNIQUE(provider, provider_message_id), así que
     *   un reintento es detectable a posteriori aunque no prevenible.
     *
     * ── DEPENDENCIA EXPLÍCITA ────────────────────────────────────────────
     * Esta atomicidad EXIGE que la cola comparta la conexión de base de datos.
     * Si QUEUE_CONNECTION pasara a redis/sqs, el INSERT del job saldría de la
     * transacción y volvería la ventana de pérdida. `mova:health-check` vigila
     * esa configuración y lo reporta como advertencia.
     *
     * @param  array<string, mixed>  $claimCondition  columna => valor esperado ANTES de reclamar
     * @param  array<string, mixed>  $claimValue      columna => valor a escribir al reclamar
     * @param  callable  $dispatch  despacha las notificaciones (solo encoladas)
     */
    private function claimAndDispatch(
        Lesson $lesson,
        array $claimCondition,
        array $claimValue,
        callable $dispatch
    ): bool {
        try {
            return DB::transaction(function () use ($lesson, $claimCondition, $claimValue, $dispatch) {
                $query = Lesson::whereKey($lesson->id);

                foreach ($claimCondition as $column => $expected) {
                    $query = $expected === null
                        ? $query->whereNull($column)
                        : $query->where($column, $expected);
                }

                // Recomprobado dentro del propio UPDATE: entre el SELECT del
                // barrido y este momento, la clase pudo cancelarse o
                // reprogramarse. El motor decide quién gana la carrera.
                if ($query->where('status', 'scheduled')->update($claimValue) !== 1) {
                    return false;
                }

                $dispatch();

                return true;
            });
        } catch (\Throwable $e) {
            // El rollback ya liberó el marcador: la lección vuelve a ser
            // candidata en la próxima pasada. Se registra para que un fallo
            // sistemático de despacho sea visible y no un silencio.
            Log::error('REMINDER_DISPATCH_FAILED', [
                'lesson_id' => $lesson->id,
                'claim' => array_key_first($claimValue),
                'error' => $e->getMessage(),
            ]);
            report($e);

            return false;
        }
    }

    private function send24hReminders(): void
    {
        $lessons = Lesson::where('status', 'scheduled')
            ->whereNull('reminder_24h_sent_at')
            ->whereBetween('start_time', [now()->addHours(23), now()->addHours(25)])
            ->with(['teacherProfile.user', 'student.parent'])
            ->get();

        $sent = 0;

        foreach ($lessons as $lesson) {
            $sent += (int) $this->claimAndDispatch(
                $lesson,
                ['reminder_24h_sent_at' => null],
                ['reminder_24h_sent_at' => now()],
                fn () => LessonNotifier::notifyBoth($lesson, new ClassReminderNotification($lesson, '24h')),
            );
        }

        $this->info("24h reminders: {$sent}");
    }

    private function send2hReminders(): void
    {
        $lessons = Lesson::where('status', 'scheduled')
            ->whereNull('reminder_2h_sent_at')
            ->whereBetween('start_time', [now()->addMinutes(90), now()->addMinutes(150)])
            ->with(['teacherProfile.user', 'student.parent'])
            ->get();

        $sent = 0;

        foreach ($lessons as $lesson) {
            $sent += (int) $this->claimAndDispatch(
                $lesson,
                ['reminder_2h_sent_at' => null],
                ['reminder_2h_sent_at' => now()],
                fn () => LessonNotifier::notifyBoth($lesson, new ClassReminderNotification($lesson, '2h')),
            );
        }

        $this->info("2h reminders: {$sent}");
    }

    private function send10mReminders(): void
    {
        $lessons = Lesson::where('status', 'scheduled')
            ->where('reminder_sent', false)
            ->whereBetween('start_time', [now(), now()->addMinutes(10)])
            ->with(['teacherProfile.user', 'student.parent'])
            ->get();

        $sent = 0;

        foreach ($lessons as $lesson) {
            $sent += (int) $this->claimAndDispatch(
                $lesson,
                ['reminder_sent' => false],
                ['reminder_sent' => true],
                fn () => LessonNotifier::notifyBoth($lesson, new ClassReminderNotification($lesson, '10m')),
            );
        }

        $this->info("10m reminders: {$sent}");
    }

    /**
     * C-1 (A-1, Fase 3B §11): 'status=completed' + sin reporte era imposible
     * antes de C-1 (completed solo llegaba tras crear el reporte) — este aviso
     * nunca se disparó. El estado real de "debe un reporte" es 'paid' dentro de
     * la ventana de gracia; fuera de ella el scheduler ya liquidó la clase sin
     * reporte, y avisar llegaría tarde. Misma condición que DashboardController
     * — ver Lesson::scopeAwaitingReportWithinGrace() para no triplicarla.
     *
     * F-04: este barrido ya usaba transacción + lockForUpdate + recomprobación,
     * pero notificaba DESPUÉS de cerrar la transacción, con la misma ventana de
     * pérdida que los otros cuatro. Ahora el despacho ocurre dentro.
     */
    private function sendPendingReportAlerts(): void
    {
        $lessons = Lesson::awaitingReportWithinGrace()
            ->whereNull('report_reminder_sent_at')
            ->with(['teacherProfile.user'])
            ->get();

        $sent = 0;

        foreach ($lessons as $lesson) {
            $teacher = $lesson->teacherProfile?->user;

            if (!$teacher) {
                continue;
            }

            try {
                $sent += (int) DB::transaction(function () use ($lesson, $teacher) {
                    $locked = Lesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();

                    // Recheck del estado bajo lock, no solo del marcador: entre
                    // el SELECT y este lock, un admin pudo forzar el cierre
                    // (force-complete/refund) o el padre pudo reseñarla.
                    if ($locked->report_reminder_sent_at !== null || $locked->status !== 'paid') {
                        return false;
                    }

                    $locked->update(['report_reminder_sent_at' => now()]);
                    $teacher->notify(new PendingReportReminderNotification($lesson));

                    return true;
                });
            } catch (\Throwable $e) {
                Log::error('REPORT_REMINDER_DISPATCH_FAILED', [
                    'lesson_id' => $lesson->id,
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        $this->info("Pending report alerts: {$sent}");
    }

    /**
     * Recordatorios a profesores de solicitudes SIN propuesta (`open`), según su oportunidad real:
     *
     *   1. «Sigue sin propuesta» (`request_reminder_sent_at`): pasadas `unanswered_after_hours` desde la publicación,
     *      solo si el último aviso todavía no toca (una clase pedida para dentro de pocas horas no recibe dos avisos
     *      seguidos: se queda con el último).
     *   2. «Último aviso» (`final_reminder_sent_at`): cuando faltan `final_before_hours` para la franja pedida (o para el
     *      vencimiento de un acompañamiento), solo si aún queda al menos `final_min_lead_minutes` y han pasado
     *      `min_gap_hours` desde la publicación y desde el aviso anterior. Las solicitudes antiguas sin día no lo reciben.
     *
     * Nunca se avisa si ya no hay oportunidad (fecha límite pasada) ni de solicitudes con propuesta pendiente, aprobación
     * del padre pendiente o cerradas. Cada aviso se reclama con un UPDATE condicional en la misma transacción que el
     * despacho (F-04): dos pasadas simultáneas no duplican. Sin destinatario elegible no se marca (F-10). El canal
     * (correo/WhatsApp) y su consentimiento los decide la notificación, como siempre.
     */
    private function sendUnansweredRequestAlerts(): void
    {
        $cfg = config('class_requests.reminders', []);
        $after = (int) ($cfg['unanswered_after_hours'] ?? 12);
        $finalBefore = (int) ($cfg['final_before_hours'] ?? 24);
        $minLead = (int) ($cfg['final_min_lead_minutes'] ?? 60);
        $gap = (int) ($cfg['min_gap_hours'] ?? 3);
        $now = now();

        $requests = ClassRequest::where('status', 'open')
            ->where(fn ($q) => $q->whereNull('request_reminder_sent_at')->orWhereNull('final_reminder_sent_at'))
            ->where('created_at', '<=', $now->copy()->subHours(min($after, $gap)))
            ->with(['classOffer.teacherProfile.user', 'teacherProfile.user', 'subject', 'student.parent'])
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($requests as $classRequest) {
            $kind = $this->reminderKind($classRequest, $now, $after, $finalBefore, $minLead, $gap);
            if ($kind === null) {
                continue;
            }
            $column = $kind === 'final' ? 'final_reminder_sent_at' : 'request_reminder_sent_at';

            // unique() por id: si un profesor apareciera dos veces al resolver destinatarios, recibiría el aviso repetido.
            $recipients = $classRequest->eligibleTeacherUsers()->unique('id');
            if ($recipients->isEmpty()) {
                // Sin marcar a propósito: un profesor puede verificarse más tarde (F-10).
                $skipped++;

                continue;
            }

            try {
                $sent += (int) DB::transaction(function () use ($classRequest, $recipients, $column, $kind) {
                    $claimed = ClassRequest::whereKey($classRequest->id)
                        ->where('status', 'open')
                        ->whereNull($column)
                        ->update($kind === 'final' && $classRequest->request_reminder_sent_at === null
                            // El último aviso sustituye al de «sigue sin propuesta» si este aún no salió: nunca dos seguidos.
                            ? ['final_reminder_sent_at' => now(), 'request_reminder_sent_at' => now()]
                            : [$column => now()]) === 1;

                    if (! $claimed) {
                        return false;
                    }

                    $recipients->each(fn ($user) => $user->notify(new UnansweredRequestNotification($classRequest, $kind)));

                    return true;
                });
            } catch (\Throwable $e) {
                Log::error('UNANSWERED_REMINDER_DISPATCH_FAILED', [
                    'class_request_id' => $classRequest->id,
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        $this->info("Unanswered request alerts: {$sent} enviada(s), {$skipped} sin destinatario (no marcadas).");
    }

    /** 'unanswered' | 'final' | null según la oportunidad real de la solicitud. */
    private function reminderKind(ClassRequest $r, $now, int $after, int $finalBefore, int $minLead, int $gap): ?string
    {
        $deadline = $r->responseDeadline();
        if ($deadline === null || $now->greaterThanOrEqualTo($deadline) || $r->student?->parent?->account_closed_at !== null) {
            return null;
        }
        $basis = $r->deadlineBasis();
        $policy = $r->deadlinePolicy();

        // Momento del último aviso: 24 h antes de la franja (clase puntual) o del vencimiento (acompañamiento).
        $finalAt = match ($policy) {
            'slot' => $r->slotStart()?->copy()->subHours($finalBefore),
            'mentorship' => $deadline->copy()->subHours($finalBefore),
            default => null,
        };

        if ($finalAt !== null && $r->final_reminder_sent_at === null && $now->greaterThanOrEqualTo($finalAt)) {
            $enoughLead = $now->copy()->addMinutes($minLead)->lessThanOrEqualTo($deadline);
            $lastNotice = $r->request_reminder_sent_at && $r->request_reminder_sent_at->greaterThan($basis) ? $r->request_reminder_sent_at : $basis;
            $spaced = $lastNotice->copy()->addHours($gap)->lessThanOrEqualTo($now);

            return $enoughLead && $spaced ? 'final' : null;
        }

        if ($r->request_reminder_sent_at === null && $basis->copy()->addHours($after)->lessThanOrEqualTo($now)
            && ($finalAt === null || $now->lessThan($finalAt))) {
            return 'unanswered';
        }

        return null;
    }
}
