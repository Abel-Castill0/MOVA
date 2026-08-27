<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * Máquina de estados de `classes.status`:
 *
 *   scheduled -> paid -> pending_parent_confirmation -> completed
 *        \                                                 ^
 *         \-> cancelled                                    |
 *          \-> needs_admin_review ------(admin)------------/
 *
 * F-11 — ADVERTENCIA SOBRE EL SIGNIFICADO DE 'paid':
 *
 *   'paid' NO significa "MOVA verificó un pago". Significa: «el padre declaró
 *   haber pagado al profesor por fuera de la plataforma». MOVA no intermedia
 *   ese dinero (el padre paga al profesor por Yape/Plin directamente) y no
 *   tiene ninguna evidencia de que el pago ocurriera. Es un registro
 *   DECLARATIVO y unilateral, no una transacción procesada ni conciliada.
 *
 *   No debe leerse nunca como PAYMENT_VERIFIED. Esa confusión importa porque
 *   'paid' sí gobierna consecuencias reales:
 *     - LessonReportController: habilita al profesor a subir el reporte.
 *     - SettleLessons: arranca el reloj de la gracia de C-1, tras la cual el
 *       crédito del profesor se consume automáticamente.
 *     - DashboardController / SendClassReminders: métricas y avisos.
 *     - lessonJoin.js / LessonController::join(): acceso a la sala.
 *
 *   Consecuencia asumida: un padre puede declarar 'paid' sin haber pagado, y
 *   eso terminará consumiendo el crédito del profesor. Es una asimetría
 *   ACEPTADA —el crédito representa el coste de usar la plataforma, no el pago
 *   del padre— pero debe ser una decisión consciente, no un efecto secundario.
 *
 *   El pago del PROFESOR A MOVA (recargas, y en el futuro Culqi) es un flujo
 *   completamente distinto: vive en RechargeRequest / PaymentOrder y ese sí
 *   se verifica. No mezclar ambos.
 *
 *   Separar `lesson_status` de `payment_confirmation_status` sería el modelo
 *   más limpio, pero 'paid' aparece en 30+ ubicaciones entre PHP, Vue,
 *   migraciones y tests: es una migración de riesgo alto que requiere decisión
 *   de producto, no una corrección. Ver docs/MOVA_FULL_AUDIT.md F-11.
 */
class Lesson extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'teacher_profile_id', 'student_id', 'class_request_id', 'class_offer_id',
        'start_time', 'duration_minutes', 'price_frozen_pen',
        'jitsi_room', 'status', 'reminder_sent',
        'reminder_24h_sent_at', 'reminder_2h_sent_at', 'report_reminder_sent_at',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
        'original_start_time', 'rescheduled_at', 'rescheduled_by', 'reschedule_reason',
    ];

    // credits_settled_at NO está en $fillable a propósito: es un marcador
    // financiero derivado del ledger. $fillable ya tiene 21 campos y varios
    // controladores hacen update() con arrays construidos desde el request;
    // dejarlo asignable en masa permitiría modificarlo sin pasar por la capa
    // de settlement. Solo esa capa debe escribirlo.
    protected $casts = [
        // Sin cast, MySQL (PDO) devuelve DECIMAL como string en el formato
        // que traiga la columna, mientras que SQLite (tests) lo devuelve con
        // tipado dinámico — mismo código, tipo distinto según el driver.
        // 'decimal:2' normaliza a un string de 2 decimales siempre, sea cual
        // sea el motor. Nunca 'float': precisión monetaria.
        'price_frozen_pen'        => 'decimal:2',
        'credits_settled_at'      => 'datetime',
        'start_time'              => 'datetime',
        'original_start_time'     => 'datetime',
        'reminder_sent'           => 'boolean',
        'reminder_24h_sent_at'    => 'datetime',
        'reminder_2h_sent_at'     => 'datetime',
        'report_reminder_sent_at' => 'datetime',
        'cancelled_at'            => 'datetime',
        'rescheduled_at'          => 'datetime',
    ];

    // La sala de Jitsi funciona como un token de acceso: quien la conoce puede
    // unirse (y, si llega primero, fijar o saltarse el password). Nunca deben
    // salir en un listado — solo LessonController::join() los expone, tras
    // pasar por LessonPolicy::view() y validar el estado de la clase.
    protected $hidden = ['jitsi_room'];

    protected $appends = ['has_jitsi_room', 'end_time', 'credit_cost'];

    public function getHasJitsiRoomAttribute(): bool
    {
        return $this->jitsi_room !== null;
    }

    // Cobro por hora (o fracción): 1 crédito por cada hora iniciada, mínimo 1.
    // Ej: 30 min = 1 crédito, 60 min = 1 crédito, 90 min = 2 créditos.
    public static function creditCostForMinutes(int $durationMinutes): int
    {
        $hours = (int) ceil($durationMinutes / config('credits.credit_minutes', 60));

        return max(1, $hours) * (int) config('credits.cost_per_hour', 1);
    }

    public function getCreditCostAttribute(): int
    {
        return static::creditCostForMinutes($this->duration_minutes);
    }

    // Créditos realmente reservados/consumidos según el ledger, en vez de
    // recalcular desde duration_minutes — así un refund/consumption siempre
    // devuelve exactamente lo que se reservó, incluso si la clase fue
    // reprogramada con otra duración después de aceptarse.
    // C-1: endurecido — el fallback anterior a credit_cost cuando no existía
    // reserva FABRICABA un importe de la nada si el ledger no la respaldaba
    // (riesgo #4 detectado en el diseño de C-1, Fase 3B). En cualquier estado
    // válido de la máquina de estados (scheduled/paid/pending_parent_confirmation/
    // needs_admin_review/completed/cancelled ya cerrados) debe existir EXACTAMENTE
    // una reserva por diseño de store(); si no la hay, es una anomalía financiera
    // real y debe fallar ruidosamente, no seguir con una cifra inventada.
    public function reservedCreditAmount(): int
    {
        $reservations = CreditTransaction::where('lesson_id', $this->id)
            ->where('type', 'reservation')
            ->pluck('amount');

        if ($reservations->count() !== 1) {
            throw new \RuntimeException(
                "Lesson {$this->id}: se esperaba exactamente 1 asiento 'reservation' en el ledger, "
                ."hay {$reservations->count()}. No se puede determinar el importe a liquidar sin "
                .'arriesgar una cifra fabricada — requiere investigación manual.'
            );
        }

        return (int) $reservations->first();
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /**
     * F-18 (regresión corregida en Fase 4) — `withTrashed()` es OBLIGATORIO.
     *
     * Al añadir SoftDeletes a Student, esta relación empezó a devolver NULL en
     * cuanto el padre daba de baja al alumno. Verificado empíricamente: las
     * clases históricas perdían su alumno, y —más grave— `?->parent?->notify()`
     * dejaba de encontrar destinatario, así que el padre dejaba de recibir
     * avisos de cancelación y devolución SIN ningún error visible.
     *
     * Un registro histórico debe seguir resolviendo su alumno aunque este ya
     * no aparezca en la lista activa del padre. Los datos personales del menor
     * ya se sustituyen al darlo de baja (Student::anonymize()), así que esto no
     * reexpone información.
     */
    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function classRequest()
    {
        return $this->belongsTo(ClassRequest::class);
    }

    public function classOffer()
    {
        return $this->belongsTo(ClassOffer::class);
    }

    public function lessonReport()
    {
        return $this->hasOne(LessonReport::class);
    }

    public function teacherReview()
    {
        return $this->hasOne(TeacherReview::class);
    }

    public function getEndTimeAttribute()
    {
        return $this->start_time->copy()->addMinutes($this->duration_minutes);
    }

    /**
     * Filtra clases cuya hora de fin real (start_time + duration_minutes) es
     * anterior a $moment.
     *
     * end_time es un accesor calculado, NO una columna: no se puede usar en un
     * where(). Esta expresión SQL vive aquí y solo aquí — duplicarla por
     * comandos, controladores y tests generaría exactamente la misma deuda que
     * encontramos en C-2, donde store() y reschedule() tenían dos
     * implementaciones divergentes del mismo chequeo de solapamiento.
     *
     * MySQL y SQLite no comparten sintaxis de aritmética de fechas, así que se
     * ramifica por driver: producción usa MySQL, la suite de tests usa SQLite.
     */
    public function scopeEndedBefore(Builder $query, $moment): Builder
    {
        $moment = Carbon::parse($moment)->toDateTimeString();

        if ($query->getConnection()->getDriverName() === 'sqlite') {
            return $query->whereRaw(
                "datetime(start_time, '+' || duration_minutes || ' minutes') < ?",
                [$moment]
            );
        }

        return $query->whereRaw(
            'DATE_ADD(start_time, INTERVAL duration_minutes MINUTE) < ?',
            [$moment]
        );
    }

    /**
     * Complemento de endedBefore(): filtra clases cuya hora de fin real es
     * POSTERIOR o igual a $moment. Existe para expresar "todavía dentro de la
     * ventana de gracia" sin negar endedBefore() desde fuera (whereNot sobre
     * un whereRaw no compone limpio con el resto del query builder).
     */
    public function scopeEndedAfter(Builder $query, $moment): Builder
    {
        $moment = Carbon::parse($moment)->toDateTimeString();

        if ($query->getConnection()->getDriverName() === 'sqlite') {
            return $query->whereRaw(
                "datetime(start_time, '+' || duration_minutes || ' minutes') >= ?",
                [$moment]
            );
        }

        return $query->whereRaw(
            'DATE_ADD(start_time, INTERVAL duration_minutes MINUTE) >= ?',
            [$moment]
        );
    }

    /**
     * C-1 corrige un bug (A-1, Fase 3B §11): antes de C-1, 'completed' solo se
     * alcanzaba DESPUÉS de crear el reporte, así que "completed sin reporte"
     * era imposible por construcción — tres sitios distintos consultaban una
     * condición que nunca podía ser cierta (DashboardController x2,
     * SendClassReminders). El estado correcto para "profesor le debe un
     * reporte al padre" es 'paid', no 'completed'.
     *
     * Ventana con ambos extremos, no solo un mínimo:
     *   - endedBefore(ahora - report_reminder_delay_hours): ya pasó tiempo
     *     razonable desde que terminó, no tiene sentido avisar a los 2 minutos.
     *   - endedAfter(ahora - settlement_grace_days): SIGUE dentro de la
     *     gracia. Sin este límite superior, después de la gracia el scheduler
     *     ya liquidó la clase (pasa a 'completed') y esta condición volvería a
     *     ser insatisfacible del lado incorrecto — cero contadores muertos,
     *     pero por la razón opuesta.
     *
     * Un solo lugar para esta condición, reutilizado por los 3 sitios que la
     * necesitan — repetirla generaría la misma deuda que hasScheduleOverlap()
     * duplicado dejó en C-2.
     */
    public function scopeAwaitingReportWithinGrace(Builder $query): Builder
    {
        $graceDays = (int) config('credits.settlement_grace_days', 7);
        $reminderDelayHours = (int) config('credits.report_reminder_delay_hours', 2);

        return $query->where('status', 'paid')
            ->whereDoesntHave('lessonReport')
            ->endedBefore(now()->subHours($reminderDelayHours))
            ->endedAfter(now()->subDays($graceDays));
    }
}
