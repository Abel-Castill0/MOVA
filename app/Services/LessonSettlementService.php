<?php

namespace App\Services;

use App\Models\ClassEvent;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\TeacherProfile;
use App\Notifications\LessonSettledNotification;
use App\Support\LessonNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Única capa de liquidación financiera de MOVA (C-1, Fase 3B §1).
 *
 * Antes de esta clase, "consumir una clase" tenía una implementación en
 * TeacherReviewController y estaba a punto de replicarse en el scheduler y en
 * dos rutas de admin más — cuatro implementaciones divergentes del mismo
 * concepto. Esta clase es el único lugar que mueve `credit_transactions`,
 * `teacher_profiles` y `classes.status/credits_settled_at` juntos.
 *
 * FUENTE DE VERDAD (Fase 3B §2): el ledger (`credit_transactions`) es la
 * autoridad. `credits_settled_at` es una caché derivada, NUNCA la garantía —
 * lo que realmente impide una doble liquidación es el índice UNIQUE sobre
 * `idempotency_key`. Por eso este servicio SIEMPRE intenta el INSERT y deja
 * que el UNIQUE falle si ya existe, en vez de confiar en un `credits_settled_at
 * IS NULL` leído antes del lock (que dos transacciones concurrentes podrían
 * leer igual).
 *
 * Deliberadamente NO se migran aquí cancel() ni AdminController::cancelLesson()
 * — funcionan, están probados, y no forman parte del bug de C-1. Migrarlos
 * queda como deuda técnica explícita (ver FOLLOW-UP), no como descuido.
 */
class LessonSettlementService
{
    /** Estados desde los que se puede consumir (liquidar cobrando el crédito). */
    public function __construct(private readonly OperationalAlertService $alerts)
    {
    }

    private const CONSUMABLE_STATES = ['paid', 'pending_parent_confirmation', 'needs_admin_review'];

    /** Estados desde los que se puede devolver (liquidar sin cobrar). */
    private const REFUNDABLE_STATES = ['scheduled', 'paid', 'pending_parent_confirmation', 'needs_admin_review'];

    /**
     * Consume los créditos reservados de una clase: la marca como completada
     * y descuenta credits_reserved.
     *
     * $actorId = NULL ⟺ liquidación automática del sistema (scheduler).
     * $actorId = id de usuario ⟺ liquidación disparada por su acción (reseña,
     * o un admin en force-complete).
     *
     * $notify = true dispara LessonSettledNotification a profesor y padre —
     * SOLO tiene sentido en el camino automático (mova:settle-lessons): la
     * reseña y el force-complete de admin son acciones humanas que ya saben
     * lo que hicieron y tienen sus propias notificaciones (Decisión de
     * negocio #3, Fase 3B §17). Nunca se dispara en el camino idempotente
     * (lección ya liquidada) — $settledNow solo se marca true en el camino
     * real de liquidación, después de escribirla.
     *
     * $notify=true exige $actorId=null (verificado en código, no solo en
     * comentario — hallazgo de la pasada de code-review de esta decisión):
     * el texto de LessonSettledNotification ("se cerró automáticamente")
     * sería falso e induciría a error si un futuro caller lo combinara con
     * una acción humana con actor real. Falla ruidoso en vez de permitirlo.
     */
    public function consume(Lesson $lesson, ?int $actorId = null, ?string $reason = null, string $eventType = 'class_settled', bool $notify = false): Lesson
    {
        if ($notify && $actorId !== null) {
            throw new \InvalidArgumentException(
                'LessonSettlementService::consume(notify: true) es exclusivo del camino automático del sistema — '
                .'exige actorId=null. Un actor humano ya sabe lo que hizo y no debe recibir '
                .'"se cerró automáticamente".'
            );
        }

        $settledNow = false;

        $lesson = DB::transaction(function () use ($lesson, $actorId, $reason, $eventType, &$settledNow) {
            $lesson = Lesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();

            // Idempotencia por resultado: si ya está liquidada (cualquier
            // llamador concurrente ganó la carrera), no es un error — se
            // devuelve la lección tal como quedó, sin volver a mover nada.
            if ($lesson->credits_settled_at !== null) {
                return $lesson;
            }

            if (! in_array($lesson->status, self::CONSUMABLE_STATES, true)) {
                throw new \RuntimeException(
                    "No se puede consumir Lesson {$lesson->id}: status='{$lesson->status}' no es liquidable "
                    .'(se esperaba paid, pending_parent_confirmation o needs_admin_review).'
                );
            }

            // BUG-3 (docs/MOVA_AUDIT_PHASE0.md, sección Q) — el reporte
            // pedagógico es "el diferenciador" del producto (MOVA_MASTER_
            // CONTEXT.md §1). Antes, el scheduler auto-liquidaba una clase
            // 'paid'/'pending_parent_confirmation' sin exigirlo, dejando que
            // un profesor perezoso se saliera gratis de esa obligación, y
            // LessonSettledNotification le prometía al padre "puedes
            // calificar cuando quieras" cuando el backend luego lo rechazaba
            // con 403 (sin reporte no hay calificación posible).
            //
            // Este guard es SOLO para el camino automático (actorId=null):
            // un admin humano en force-complete (actorId real) sigue
            // pudiendo completar sin reporte a propósito — es una decisión
            // informada de un humano que ya sabe lo que está aprobando, no
            // el sistema decidiendo solo. needs_admin_review ya pasó por
            // este guard antes (fue escalada precisamente por esto), así
            // que no se re-evalúa aquí.
            if ($actorId === null && in_array($lesson->status, ['paid', 'pending_parent_confirmation'], true)
                && ! $lesson->lessonReport()->exists()) {
                throw new \RuntimeException(
                    "No se puede auto-liquidar Lesson {$lesson->id}: sin reporte pedagógico del profesor. "
                    .'Debe escalarse a needs_admin_review para que un admin decida, no completarse automáticamente.'
                );
            }

            $teacherProfile = TeacherProfile::whereKey($lesson->teacher_profile_id)->lockForUpdate()->firstOrFail();
            $amount = $lesson->reservedCreditAmount();

            // Mismo guard que cancelLesson()/cancel() ya hacían antes de esta
            // capa (y que aquí faltaba): credits_reserved no tiene CHECK a
            // nivel de BD. Si alguna vez está desincronizado del ledger —
            // justo lo que mova:reconcile-ledger existe para detectar—, sin
            // este guard consume() lo empujaría a negativo en silencio en vez
            // de fallar ruidosamente, violando la regla de "nunca fabricar,
            // siempre abortar" del resto del proyecto.
            if ($teacherProfile->credits_reserved < $amount) {
                throw new \RuntimeException(
                    "No se puede consumir Lesson {$lesson->id}: TeacherProfile {$teacherProfile->id} tiene "
                    ."credits_reserved={$teacherProfile->credits_reserved}, se necesitan {$amount}. "
                    .'Desincronización ledger↔saldo — requiere investigación manual, no se fabrica el faltante.'
                );
            }

            // La garantía real de "no consumir dos veces" es este UNIQUE, no el
            // chequeo de credits_settled_at de arriba (que protege contra el caso
            // normal, pero dos transacciones podrían leerlo NULL a la vez).
            try {
                CreditTransaction::create([
                    'teacher_profile_id' => $teacherProfile->id,
                    'lesson_id' => $lesson->id,
                    'idempotency_key' => "lesson:{$lesson->id}:consumption",
                    'type' => 'consumption',
                    'amount' => $amount,
                    'description' => 'Consumo por clase completada',
                ]);
            } catch (UniqueConstraintViolationException) {
                return $lesson->fresh();
            }

            $teacherProfile->update([
                'credits_reserved' => $teacherProfile->credits_reserved - $amount,
                'completed_classes_count' => $teacherProfile->completed_classes_count + 1,
                'is_experienced' => ($teacherProfile->completed_classes_count + 1) >= 5,
            ]);

            // avgRating() consulta visibleReviews() en vivo — si ya hay reseña
            // (caso: la reseña llegó primero y disparó este mismo consume()), el
            // recálculo ya la incluye. Si no hay reseña aún, sigue siendo correcto
            // recalcular ahora con completed_classes_count actualizado.
            $teacherProfile->refresh();
            $teacherProfile->update(['hourly_rate' => $teacherProfile->maxAllowedRate()]);

            // NUNCA update(['credits_settled_at' => ...]): esa columna está
            // deliberadamente FUERA de $fillable (Lesson.php) para que nadie
            // la escriba por fuera de este servicio — pero eso significa que
            // update() con mass assignment la descarta en silencio, incluso
            // aquí, el único caller autorizado. Asignación directa + save()
            // no pasa por $fillable, así que sí se escribe.
            $lesson->status = 'completed';
            $lesson->credits_settled_at = now();
            $lesson->save();

            ClassEvent::log($eventType, $actorId, $lesson->id, $lesson->class_request_id, $reason);

            $settledNow = true;

            return $lesson->fresh();
        });

        // La clase llegó a un estado TERMINAL, así que la incidencia
        // "varada esperando decisión del admin" (si la había) se acabó.
        // Se llama incondicionalmente: es un UPDATE indexado que afecta a 0
        // filas en el caso normal, y preguntarlo antes exigiría arrastrar el
        // estado previo fuera de la transacción sin ganar nada.
        $this->alerts->resolve("lesson:{$lesson->id}:needs_admin_review");

        // Notificar SIEMPRE fuera de la transacción (Fase 3B §13 — una cola
        // con after_commit=false podría procesar la notificación antes del
        // commit real si se despachara dentro).
        if ($notify && $settledNow) {
            LessonNotifier::notifyBoth($lesson, new LessonSettledNotification($lesson));
        }

        return $lesson;
    }

    /**
     * Devuelve los créditos reservados de una clase: la marca como cancelada
     * y mueve el importe de reserved a available.
     *
     * A propósito SIN parámetro $notify (a diferencia de consume()): hoy
     * ningún llamador dispara un refund automático sin actor — el scheduler
     * solo auto-CONSUME tras la gracia, nunca auto-reembolsa (needs_admin_review
     * no tiene efecto financiero por diseño). Agregar $notify aquí sería
     * superficie sin usar, y LessonSettledNotification tiene texto específico
     * de "clase completada / puedes calificar" que sería incorrecto para un
     * reembolso (la clase no ocurrió). Si en el futuro se agrega un camino de
     * auto-refund, esto necesita su propia notificación con texto de
     * cancelación, no reutilizar esta.
     */
    public function refund(Lesson $lesson, ?int $actorId = null, ?string $reason = null, string $eventType = 'class_cancelled'): Lesson
    {
        $result = DB::transaction(function () use ($lesson, $actorId, $reason, $eventType) {
            // Eager load classRequest: se lee más abajo (is_mentorship) para
            // decidir si liberar el cupo de mentoría. Sin esto, el lazy load
            // ocurriría con el lock de teacherProfile ya tomado, alargando
            // sin necesidad la ventana de bloqueo de la fila financiera — el
            // mismo motivo por el que AdminController::cancelLesson() ya
            // eager-carga esta misma relación.
            $lesson = Lesson::with('classRequest')->whereKey($lesson->id)->lockForUpdate()->firstOrFail();

            if ($lesson->credits_settled_at !== null) {
                return $lesson;
            }

            if (! in_array($lesson->status, self::REFUNDABLE_STATES, true)) {
                throw new \RuntimeException(
                    "No se puede devolver Lesson {$lesson->id}: status='{$lesson->status}' no es reembolsable "
                    .'(se esperaba scheduled, paid, pending_parent_confirmation o needs_admin_review).'
                );
            }

            $teacherProfile = TeacherProfile::whereKey($lesson->teacher_profile_id)->lockForUpdate()->firstOrFail();
            $amount = $lesson->reservedCreditAmount();

            // Ver el comentario equivalente en consume(): mismo guard contra
            // empujar credits_reserved a negativo por una desincronización.
            if ($teacherProfile->credits_reserved < $amount) {
                throw new \RuntimeException(
                    "No se puede devolver Lesson {$lesson->id}: TeacherProfile {$teacherProfile->id} tiene "
                    ."credits_reserved={$teacherProfile->credits_reserved}, se necesitan {$amount}. "
                    .'Desincronización ledger↔saldo — requiere investigación manual, no se fabrica el faltante.'
                );
            }

            try {
                CreditTransaction::create([
                    'teacher_profile_id' => $teacherProfile->id,
                    'lesson_id' => $lesson->id,
                    'idempotency_key' => "lesson:{$lesson->id}:release",
                    'type' => 'refund',
                    'amount' => $amount,
                    'description' => 'Devolución por clase cancelada',
                ]);
            } catch (UniqueConstraintViolationException) {
                return $lesson->fresh();
            }

            $profileUpdates = [
                'credits_available' => $teacherProfile->credits_available + $amount,
                'credits_reserved' => $teacherProfile->credits_reserved - $amount,
            ];

            // Ambos cancel() existentes (LessonController y AdminController)
            // solo devuelven desde 'scheduled' y ya liberan el cupo de
            // mentoría ahí. Este servicio, en cambio, también reembolsa desde
            // 'paid'/'pending_parent_confirmation'/'needs_admin_review' —
            // terreno que esos dos NUNCA pisaron. Sin esta línea, un
            // force-refund de una clase de mentoría más allá de 'scheduled'
            // dejaría el cupo tomado para siempre.
            if ($lesson->classRequest?->is_mentorship) {
                $profileUpdates['mentorship_slots_taken'] = max(0, $teacherProfile->mentorship_slots_taken - 1);
            }

            $teacherProfile->update($profileUpdates);

            // Ver el comentario equivalente en consume(): update() con
            // mass assignment descartaría credits_settled_at en silencio.
            $lesson->status = 'cancelled';
            $lesson->credits_settled_at = now();
            $lesson->save();

            ClassEvent::log($eventType, $actorId, $lesson->id, $lesson->class_request_id, $reason);

            return $lesson->fresh();
        });

        // Mismo criterio que en consume(): estado terminal, incidencia cerrada.
        $this->alerts->resolve("lesson:{$lesson->id}:needs_admin_review");

        return $result;
    }

}
