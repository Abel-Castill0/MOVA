<?php

namespace App\Services;

use App\Jobs\FinishAccountClosureCleanup;
use App\Models\AccountDeletionRequest;
use App\Models\ClassEvent;
use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\ClassCancelledNotification;
use App\Notifications\TeacherClosedAccountNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cierre y supresión automática de una cuenta (padre o profesor), sin aprobación de soporte.
 *
 * Una sola transacción (bloqueos en el orden global ClassRequest → Student → Lesson → User → TeacherProfile,
 * el mismo que usan agendar, contraofertar y cancelar) que: cierra la identidad, cancela las clases futuras
 * con su devolución de reserva, devuelve a búsqueda las solicitudes que puedan republicarse, trata el saldo
 * con asientos de ledger (nunca un UPDATE a cero), anonimiza los datos personales y libera correo y teléfono.
 * Lo externo (avisos, alertas, archivos de Cloudinary) ocurre después del commit y es reintentable.
 *
 * Se conservan, por obligación contractual/financiera o por derechos de terceros: ledger, recargas, órdenes
 * de pago, clases (también las terminadas), reportes del profesor, reclamaciones y constancias legales.
 */
class AccountLifecycleService
{
    /** Ventana de devolución de créditos no usados (docs/release/MOVA_V1_DECISIONS.md §3). */
    public const REFUND_WINDOW_DAYS = 7;

    private const OPEN_REQUEST_STATUSES = ['pending_parent_approval', 'open', 'counteroffered'];

    /** Call only inside a transaction, before locking TeacherProfile. */
    public static function lockOpenParticipants(array $ids): Collection
    {
        $users = User::whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($users->count() !== count(array_unique($ids)) || $users->contains(fn ($user) => $user->account_closed_at !== null)) {
            throw ValidationException::withMessages(['accept' => 'Una de las cuentas ya no está disponible para nuevas clases.']);
        }

        return $users;
    }

    public function authorize(User $user): void
    {
        abort_unless($user->hasAnyRole(['parent', 'teacher']) && ! $user->hasRole('admin'), 403);
    }

    /** Créditos: [disponibles proyectados tras devolver reservas futuras, reembolsables, perdibles]. */
    public function creditPlan(?TeacherProfile $teacher): array
    {
        if (! $teacher) {
            return [0, 0, 0];
        }
        $futureReserved = Lesson::where('teacher_profile_id', $teacher->id)->where('status', 'scheduled')->where('start_time', '>', now())->get()
            ->sum(fn (Lesson $lesson) => Lesson::creditCostForMinutes($lesson->duration_minutes));
        $available = max(0, $teacher->credits_available + (int) $futureReserved);
        // Créditos comprados dentro de la ventana de devolución siguen siendo reembolsables: no se pierden en silencio.
        $withinWindow = (int) RechargeRequest::where('teacher_profile_id', $teacher->id)->where('status', 'approved')
            ->where('approved_at', '>=', now()->subDays(self::REFUND_WINDOW_DAYS))->sum('credits');
        $refundable = min($available, $withinWindow);

        return [$available, $refundable, $available - $refundable];
    }

    public function summary(User $user): array
    {
        $teacher = $user->teacherProfile;
        $students = $user->students()->withTrashed()->pluck('id');
        $lessons = Lesson::query()->where(function ($query) use ($students, $teacher) {
            $query->whereIn('student_id', $students);
            if ($teacher) {
                $query->orWhere('teacher_profile_id', $teacher->id);
            }
        });
        $requests = ClassRequest::query()->where(function ($query) use ($students, $teacher) {
            $query->whereIn('student_id', $students);
            if ($teacher) {
                $query->orWhere('teacher_profile_id', $teacher->id)
                    ->orWhere('counteroffer_teacher_profile_id', $teacher->id);
            }
        });
        $orders = PaymentOrder::query()->whereIn('recharge_request_id', $teacher?->rechargeRequests()->select('id') ?? []);
        [, $refundable, $forfeitable] = $this->creditPlan($teacher);

        return [
            'roles' => $user->getRoleNames()->values()->all(),
            'students' => $students->count(),
            'pending_classes' => (clone $lessons)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'future_classes' => (clone $lessons)->where('status', 'scheduled')->where('start_time', '>', now())->count(),
            'class_history' => $lessons->count(),
            'active_requests' => (clone $requests)->whereNotIn('status', ['cancelled', 'completed', 'expired', 'teacher_rejected', 'rejected', 'accepted'])->count(),
            'request_history' => $requests->count(),
            'credits_available' => $teacher?->credits_available ?? 0,
            'credits_reserved' => $teacher?->credits_reserved ?? 0,
            'credits_refundable' => $refundable,
            'credits_forfeitable' => $forfeitable,
            'pending_payments' => (clone $orders)->whereIn('status', ['created', 'pending'])->count(),
            'payment_reviews' => (clone $orders)->whereNotNull('review_reason')->count(),
            'recharges' => $teacher?->rechargeRequests()->count() ?? 0,
            'financial_records' => $teacher?->creditTransactions()->count() ?? 0,
            'professional_profile' => $teacher !== null,
            'teacher_verified' => $teacher?->is_verified ?? false,
            'reviews' => $teacher?->reviews()->count() ?? 0,
            'has_avatar' => filled($user->avatar_url),
        ];
    }

    /** Ids a bloquear, leídos sin bloqueo y vueltos a leer bajo bloqueo dentro de la transacción. */
    private function scope(int $userId): array
    {
        $teacherId = TeacherProfile::where('user_id', $userId)->value('id');
        $students = Student::withTrashed()->where('parent_user_id', $userId)->pluck('id');
        $lessons = Lesson::query()->where('status', 'scheduled')->where('start_time', '>', now())->where(function ($query) use ($students, $teacherId) {
            $query->whereIn('student_id', $students);
            if ($teacherId) {
                $query->orWhere('teacher_profile_id', $teacherId);
            }
        })->pluck('id');
        $requests = ClassRequest::query()->where(function ($query) use ($students, $teacherId) {
            $query->whereIn('student_id', $students);
            if ($teacherId) {
                $query->orWhere('teacher_profile_id', $teacherId)->orWhere('counteroffer_teacher_profile_id', $teacherId)
                    ->orWhereIn('id', Lesson::where('teacher_profile_id', $teacherId)->whereNotNull('class_request_id')->select('class_request_id'));
            }
        })->pluck('id');

        return ['teacher' => $teacherId, 'students' => $students, 'lessons' => $lessons, 'requests' => $requests];
    }

    /**
     * Cierra y suprime la cuenta. Idempotente: una solicitud ya completada se devuelve sin repetir nada, y dos
     * peticiones simultáneas se serializan por el bloqueo del usuario.
     */
    public function closeAccount(User $user, string $identityMethod, ?\Closure $validateIdentity = null, bool|int $acceptCreditLoss = false): AccountDeletionRequest
    {
        // Una cuenta ya procesada pierde sus roles: la repetición devuelve la misma constancia (idempotente).
        if ($done = AccountDeletionRequest::where('user_id', $user->id)->where('status', 'completed')->first()) {
            return $done;
        }
        $this->authorize($user);
        $plan = ['lessons' => [], 'notices' => [], 'republished' => []];

        // Lectura inicial SIN bloqueo y fuera de la transacción: así no se fija una instantánea (REPEATABLE READ)
        // anterior a lo que otro proceso confirme mientras esperamos sus locks.
        $scope = $this->scope($user->id);

        $request = DB::transaction(function () use ($user, $identityMethod, $validateIdentity, $acceptCreditLoss, &$plan, $scope) {
            $plan = ['lessons' => [], 'notices' => [], 'republished' => [], 'unresolved' => []];
            ClassRequest::whereIn('id', $scope['requests'])->orderBy('id')->lockForUpdate()->get(['id']);
            Student::withTrashed()->whereIn('id', $scope['students'])->orderBy('id')->lockForUpdate()->get(['id']);
            Lesson::whereIn('id', $scope['lessons'])->orderBy('id')->lockForUpdate()->get(['id']);
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            // Perfil bloqueado justo después del usuario (orden global) y ANTES de cualquier lectura no bloqueante:
            // así la instantánea de la transacción ya incluye lo que confirmaron los procesos que esperábamos
            // (una recarga acreditada, una clase recién agendada).
            $teacher = $scope['teacher'] ? TeacherProfile::whereKey($scope['teacher'])->lockForUpdate()->first() : null;

            $existing = AccountDeletionRequest::where('user_id', $locked->id)->lockForUpdate()->first();
            if ($existing && $existing->status === 'completed') {
                return $existing;
            }
            $this->authorize($locked);
            $validateIdentity?->__invoke($locked);
            // Cierre automático no disponible con una restricción o una deuda abiertas: evita usarlo para evadirlas.
            if ($locked->suspended_at !== null || ($teacher && $teacher->credits_available < 0)) {
                throw ValidationException::withMessages(['closure' => 'Tu cuenta tiene una restricción o un saldo pendiente. Escríbenos a soporte con tu correo y lo resolvemos contigo.']);
            }

            $locked->setRelation('teacherProfile', $teacher);
            $summary = $this->summary($locked);
            [, , $forfeitable] = $this->creditPlan($teacher);
            if ($forfeitable > 0 && ! $acceptCreditLoss) {
                throw ValidationException::withMessages(['accept_credit_loss' => 'Debes aceptar la pérdida del saldo no utilizado para continuar.']);
            }

            $events = $existing?->events ?? [];
            $events[] = ['action' => $existing ? 'reconfirmed' : 'requested', 'at' => now()->toIso8601String(), 'actor_id' => $locked->id];
            $request = $existing ?? AccountDeletionRequest::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $locked->id,
                'identity_method' => $identityMethod,
                'snapshot' => $summary,
                'events' => $events,
            ]);
            $originalEmail = $locked->email;
            $hadAvatar = filled($locked->avatar_url);

            // Cierre de identidad primero: cualquier alta concurrente ve la cuenta cerrada al obtener el lock.
            $locked->forceFill(['account_closed_at' => $locked->account_closed_at ?? now()])->save();

            $outcome = $this->cancelFutureClasses($locked, $scope, $plan);
            $outcome += $this->handleRequests($locked, $scope, $teacher, $plan);
            $outcome += $this->settleCredits($locked, $teacher, $acceptCreditLoss);
            $outcome['students_anonymized'] = $this->erasePersonalData($locked, $scope, $teacher, $originalEmail, $locked->phone);
            $outcome['had_avatar'] = $hadAvatar;

            $events[] = ['action' => 'erased', 'at' => now()->toIso8601String(), 'actor_id' => null];
            $request->forceFill([
                'status' => 'completed',
                'erased_at' => now(),
                'events' => $events,
                'outcome' => $outcome,
                'cleanup_status' => $hadAvatar ? 'pending' : 'not_required',
            ])->save();

            return $request;
        }, 5);

        if ($request->wasRecentlyCreated || $plan['lessons'] || $plan['notices'] || $request->cleanup_status === 'pending') {
            $this->afterCommit($request, $user->id, $plan);
        }

        return $request;
    }

    private function cancelFutureClasses(User $user, array $scope, array &$plan): array
    {
        $isTeacher = $scope['teacher'] !== null;
        $reason = $isTeacher ? 'El profesor cerró su cuenta en MOVA.' : 'La familia cerró su cuenta en MOVA.';
        $settlement = app(LessonSettlementService::class);
        $unresolved = [];

        // Segunda pasada: clases agendadas entre la lectura inicial y el cierre de identidad.
        for ($pass = 0; $pass < 2; $pass++) {
            // Solo clases que aún no empezaron: una clase ya dada o en curso sigue el flujo normal de confirmación/liquidación.
            $lessons = Lesson::where('status', 'scheduled')->where('start_time', '>', now())->whereNotIn('id', $unresolved ?: [0])
                ->where(function ($query) use ($scope) {
                    $query->whereIn('student_id', $scope['students']);
                    if ($scope['teacher']) {
                        $query->orWhere('teacher_profile_id', $scope['teacher']);
                    }
                })->orderBy('id')->lockForUpdate()->get();
            if ($lessons->isEmpty()) {
                break;
            }
            foreach ($lessons as $lesson) {
                try {
                    $settlement->refund($lesson, $user->id, $reason, 'class_cancelled');
                } catch (\RuntimeException) {
                    // Anomalía de ledger: no se fabrica el saldo; queda como incidencia excepcional.
                    $unresolved[] = $lesson->id;
                    continue;
                }
                Lesson::whereKey($lesson->id)->update(['cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason]);
                $plan['lessons'][] = $lesson->id;
            }
        }
        $plan['unresolved'] = $unresolved;

        return ['classes_cancelled' => count($plan['lessons']), 'unresolved_lessons' => $unresolved];
    }

    private function handleRequests(User $user, array $scope, ?TeacherProfile $teacher, array &$plan): array
    {
        $closed = $expired = $republished = $needsNew = 0;
        $offerIds = $teacher ? ClassOffer::where('teacher_profile_id', $teacher->id)->pluck('id')->all() : [];
        $cancelled = collect($plan['lessons']);
        $cancelledRequestIds = $cancelled->isEmpty() ? collect() : Lesson::whereIn('id', $cancelled)->pluck('class_request_id');

        $requests = ClassRequest::with('student')->whereIn('id', $this->scope($user->id)['requests'])->orderBy('id')->get();
        foreach ($requests as $request) {
            $ownStudent = $scope['students']->contains($request->student_id);
            $hadCancelledLesson = $cancelledRequestIds->contains($request->id);
            $active = in_array($request->status, self::OPEN_REQUEST_STATUSES, true) || ($request->status === 'accepted' && $hadCancelledLesson);
            if (! $active) {
                continue;
            }
            if ($ownStudent) {
                // Nunca se republica la demanda de un padre que ya no usa MOVA.
                $request->forceFill(['status' => 'expired'])->save();
                ClassEvent::log('request_closed_by_account_closure', $user->id, null, $request->id, 'La familia cerró su cuenta.');
                $closed++;
                continue;
            }
            $directed = ($request->teacher_profile_id !== null && $request->teacher_profile_id === $teacher?->id)
                || ($request->class_offer_id !== null && in_array($request->class_offer_id, $offerIds, true));
            $parentOpen = ! $request->student?->parent?->account_closed_at;
            if (! $parentOpen) {
                $request->forceFill(['status' => 'expired'])->save();
                $expired++;
                continue;
            }
            if (! $directed && $request->status === 'pending_parent_approval') {
                continue; // sigue esperando al padre; el control parental no se salta
            }
            if ($directed) {
                // Cambiaría una condición aceptada por la familia (profesor/oferta): el padre confirma una nueva.
                $request->forceFill(['status' => 'expired'])->save();
                ClassEvent::log('request_needs_new_confirmation', $user->id, null, $request->id, 'El profesor cerró su cuenta.');
                $plan['notices'][] = [$request->id, false];
                $needsNew++;
                continue;
            }
            // Misma materia, duración y preferencias; vuelve a la búsqueda y a la bandeja de profesores elegibles.
            $request->forceFill([
                'status' => 'open', 'republished_at' => now(), 'request_reminder_sent_at' => null,
                'counteroffer_time' => null, 'counteroffer_duration_minutes' => null, 'counteroffer_teacher_profile_id' => null,
            ])->save();
            ClassEvent::log('request_republished', $user->id, null, $request->id, 'El profesor cerró su cuenta.');
            $plan['notices'][] = [$request->id, true];
            $republished++;
        }

        return ['requests_closed' => $closed + $expired, 'requests_republished' => $republished, 'requests_need_new_confirmation' => $needsNew];
    }

    private function settleCredits(User $user, ?TeacherProfile $teacher, bool|int $acceptCreditLoss): array
    {
        if (! $teacher) {
            return ['credits_forfeited' => 0, 'credits_refundable' => 0];
        }
        $profile = TeacherProfile::whereKey($teacher->id)->lockForUpdate()->firstOrFail();
        $available = max(0, $profile->credits_available);
        $window = (int) RechargeRequest::where('teacher_profile_id', $profile->id)->where('status', 'approved')
            ->where('approved_at', '>=', now()->subDays(self::REFUND_WINDOW_DAYS))->sum('credits');
        $refundable = min($available, $window);
        $forfeit = $available - $refundable;
        $key = "account-closure:{$user->id}:forfeiture";
        if ($forfeit > 0 && (! $acceptCreditLoss || (is_int($acceptCreditLoss) && $forfeit > $acceptCreditLoss))) {
            // Valor real bajo bloqueo (el aviso previo pudo quedar desfasado): sin aceptación expresa no se pierde nada.
            throw ValidationException::withMessages(['accept_credit_loss' => 'Debes aceptar la pérdida del saldo no utilizado para continuar (el importe cambió; revisa el nuevo valor).']);
        }
        if ($forfeit > 0 && ! CreditTransaction::where('idempotency_key', $key)->exists()) {
            CreditTransaction::create([
                'teacher_profile_id' => $profile->id,
                'idempotency_key' => $key,
                'type' => 'forfeiture',
                'amount' => -$forfeit,
                'description' => 'Saldo no utilizado perdido al eliminar la cuenta (aceptado por el titular)',
            ]);
            $profile->update(['credits_available' => $profile->credits_available - $forfeit]);
        } else {
            $forfeit = 0;
        }

        return ['credits_forfeited' => $forfeit, 'credits_refundable' => $refundable, 'credits_after' => $profile->fresh()->credits_available];
    }

    private function erasePersonalData(User $user, array $scope, ?TeacherProfile $teacher, string $originalEmail, ?string $originalPhone = null): int
    {
        // Credenciales y accesos (el correo original solo se usa aquí, antes de liberarlo).
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $originalEmail)->delete();
        DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
        DB::table('ai_usage_logs')->where('user_id', $user->id)->delete();

        // Profesor: perfil público fuera de directorios y datos de cobro/presentación suprimidos.
        if ($teacher) {
            DB::table('teacher_availability_slots')->where('teacher_profile_id', $teacher->id)->delete();
            DB::table('teacher_subject')->where('teacher_profile_id', $teacher->id)->delete();
            ClassOffer::where('teacher_profile_id', $teacher->id)->update(['is_active' => false, 'description' => null, 'availability_schedule' => null]);
            TeacherProfile::whereKey($teacher->id)->update([
                'bio' => null, 'yape_number' => null, 'plin_number' => null, 'is_verified' => false,
                'rejected_at' => null, 'rejection_reason' => null,
            ]);
        }

        // Alumnos: datos del menor anonimizados (las clases y reportes siguen resolviendo la fila) y ocultos.
        $students = Student::withTrashed()->whereIn('id', $scope['students'])->get();
        foreach ($students as $student) {
            $student->forceFill(['first_name' => 'Estudiante', 'last_name' => "eliminado {$student->id}", 'birth_date' => null, 'school' => null])->save();
            if (! $student->trashed()) {
                $student->delete();
            }
        }
        if ($scope['students']->isNotEmpty()) {
            ClassRequest::whereIn('student_id', $scope['students'])->update(['help_needed' => '[Eliminado por el titular]', 'preferred_times' => null]);
            DB::table('student_diagnostics')->where('parent_user_id', $user->id)->update([
                'difficulty_text' => '[Eliminado por el titular]', 'school_feedback' => null, 'ai_keywords' => null, 'ai_detected_level' => null,
                'ai_summary' => null, 'ai_suggested_goal' => null, 'ai_risk_flags' => null,
            ]);
        }
        DB::table('student_data_consents')->where('parent_user_id', $user->id)->update(['ip' => null, 'user_agent' => null]);
        DB::table('legal_acceptances')->where('user_id', $user->id)->update(['ip' => null, 'user_agent' => null]);
        foreach (array_filter([$originalPhone, User::normalizePhone($originalPhone)]) as $phone) {
            DB::table('whatsapp_messages')->where('to', $phone)->update(['to' => '[eliminado]']);
        }

        // Identidad: la fila se conserva solo como referencia de integridad (FKs de ledger y clases) sin datos personales.
        $user->syncRoles([]);
        $user->forceFill([
            'name' => 'Cuenta eliminada', 'email' => "deleted-{$user->id}@mova.invalid", 'email_verified_at' => null,
            'phone' => null, 'phone_verified_at' => null, 'phone_verified_normalized' => null, 'phone_verification_code_hash' => null,
            'phone_verification_expires_at' => null, 'whatsapp_opt_in_at' => null, 'whatsapp_opt_out_at' => $user->whatsapp_opt_out_at ?? now(),
            'avatar_url' => null, 'remember_token' => null, 'parental_control' => false, 'password' => Str::random(64),
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
            'account_erased_at' => now(),
        ])->save();

        return $students->count();
    }

    private function afterCommit(AccountDeletionRequest $request, int $userId, array $plan): void
    {
        foreach ($plan['lessons'] as $lessonId) {
            try {
                $lesson = Lesson::with(['student.parent', 'teacherProfile.user'])->find($lessonId);
                $other = $lesson?->teacherProfile?->user_id === $userId ? $lesson->student?->parent : $lesson?->teacherProfile?->user;
                $other?->notify(new ClassCancelledNotification($lesson));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        foreach ($plan['notices'] as [$requestId, $republished]) {
            try {
                $classRequest = ClassRequest::with(['student.parent', 'subject'])->find($requestId);
                $classRequest?->student?->parent?->notify(new TeacherClosedAccountNotification($classRequest, $republished));
                if ($republished && $classRequest) {
                    app(ClassRequestNotifier::class)->notifyEligibleTeachers($classRequest);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $outcome = $request->outcome ?? [];
        try {
            $alerts = app(OperationalAlertService::class);
            if (($outcome['credits_refundable'] ?? 0) > 0) {
                $alerts->raise("account-closure:{$userId}:refundable-credits", 'account_closure', 'Cuenta cerrada con créditos reembolsables',
                    'Un profesor cerró su cuenta con créditos comprados dentro de la ventana de devolución. Revisa su derecho de reembolso (no se perdieron).',
                    ['user_id' => $userId, 'credits' => $outcome['credits_refundable']]);
            }
            if (! empty($outcome['unresolved_lessons'])) {
                $alerts->raise("account-closure:{$userId}:unresolved-lessons", 'account_closure', 'Clases sin cancelar al cerrar una cuenta',
                    'No se pudo cancelar automáticamente alguna clase por una anomalía de ledger; requiere revisión.',
                    ['user_id' => $userId, 'lessons' => $outcome['unresolved_lessons']]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
        if ($request->cleanup_status === 'pending') {
            try {
                FinishAccountClosureCleanup::dispatch($request->id);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** Solo para solicitudes heredadas del flujo anterior que siguen sin completarse. */
    public function respond(AccountDeletionRequest $deletion, User $actor, string $response): void
    {
        abort_unless($actor->hasRole('admin') && ! $actor->account_closed_at, 403);
        DB::transaction(function () use ($deletion, $actor, $response) {
            $deletion = AccountDeletionRequest::whereKey($deletion->id)->lockForUpdate()->firstOrFail();
            if ($deletion->status === 'completed' || $deletion->response === $response) {
                return;
            }
            $events = $deletion->events;
            $events[] = ['action' => 'response_recorded', 'actor_id' => $actor->id, 'at' => now()->toIso8601String(), 'response' => $response];
            $deletion->update(['status' => 'responded', 'response' => $response, 'responded_at' => now(), 'events' => $events]);
        });
    }
}
