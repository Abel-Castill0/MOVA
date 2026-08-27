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
     * F-10 — Dos bugs corregidos aquí:
     *
     *   1. El destinatario se resolvía SOLO desde classOffer. Las solicitudes
     *      con código de referido y las abiertas (el flujo mayoritario) no
     *      tenían oferta, así que no se notificaba a nadie. Ahora se usa
     *      ClassRequest::eligibleTeacherUsers(), el mismo camino que
     *      SendClassRequestNotifications, para que no puedan divergir.
     *
     *   2. request_reminder_sent_at se escribía INCONDICIONALMENTE, incluso sin
     *      destinatario. Como el barrido filtra por whereNull, esas solicitudes
     *      quedaban excluidas para siempre y el aviso se perdía de forma
     *      definitiva.
     *
     * F-04: claim + despacho comparten transacción, igual que los recordatorios
     * de clase.
     */
    private function sendUnansweredRequestAlerts(): void
    {
        $requests = ClassRequest::where('status', 'open')
            ->whereNull('request_reminder_sent_at')
            ->where('created_at', '<=', now()->subHours(12))
            ->with(['classOffer.teacherProfile.user', 'teacherProfile.user', 'subject'])
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($requests as $classRequest) {
            // unique() por id: si un profesor apareciera dos veces al resolver
            // destinatarios, recibiría el mismo aviso repetido.
            $recipients = $classRequest->eligibleTeacherUsers()->unique('id');

            if ($recipients->isEmpty()) {
                // Se deja SIN marcar a propósito: sin profesor verificado que
                // pueda atenderla todavía, la solicitud debe seguir siendo
                // candidata en la próxima pasada (un profesor puede verificarse
                // más tarde). Marcarla aquí era el bug.
                $skipped++;

                continue;
            }

            try {
                $sent += (int) DB::transaction(function () use ($classRequest, $recipients) {
                    $claimed = ClassRequest::whereKey($classRequest->id)
                        ->whereNull('request_reminder_sent_at')
                        ->update(['request_reminder_sent_at' => now()]) === 1;

                    if (!$claimed) {
                        return false;
                    }

                    $recipients->each(fn ($user) => $user->notify(new UnansweredRequestNotification($classRequest)));

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
}
