<?php

namespace App\Services;

use App\Models\AccountDeletionRequest;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\PaymentOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** No financial mutations and no irreversible erasure without a retention decision. */
class AccountLifecycleService
{
    /** Call only inside a transaction, before locking TeacherProfile. */
    public static function lockOpenParticipants(array $ids): \Illuminate\Support\Collection
    {
        $users = User::whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($users->count() !== count(array_unique($ids)) || $users->contains(fn ($user) => $user->account_closed_at !== null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['accept' => 'Una de las cuentas ya no está disponible para nuevas clases.']);
        }
        return $users;
    }

    public function authorize(User $user): void
    {
        abort_unless($user->hasAnyRole(['parent', 'teacher']) && ! $user->hasRole('admin'), 403);
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

        return [
            'roles' => $user->getRoleNames()->values()->all(),
            'students' => $students->count(),
            'pending_classes' => (clone $lessons)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'class_history' => $lessons->count(),
            'active_requests' => (clone $requests)->whereNotIn('status', ['cancelled', 'completed', 'expired', 'teacher_rejected', 'rejected', 'accepted'])->count(),
            'request_history' => $requests->count(),
            'credits_available' => $teacher?->credits_available ?? 0,
            'credits_reserved' => $teacher?->credits_reserved ?? 0,
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

    public function requestClosure(User $user, string $identityMethod, ?\Closure $validateIdentity = null): AccountDeletionRequest
    {
        return DB::transaction(function () use ($user, $identityMethod, $validateIdentity) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user);
            $validateIdentity?->__invoke($user);
            if ($existing = AccountDeletionRequest::where('user_id', $user->id)->first()) {
                return $existing;
            }
            // Serialize profile/financial dependency observation; nothing is
            // cancelled, refunded, consumed or inferred from this snapshot.
            $teacher = $user->teacherProfile()->lockForUpdate()->first();
            $user->setRelation('teacherProfile', $teacher);
            $request = AccountDeletionRequest::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $user->id,
                'identity_method' => $identityMethod,
                'snapshot' => $this->summary($user),
                'events' => [['action' => 'requested', 'at' => now()->toIso8601String(), 'actor_id' => $user->id]],
            ]);
            $user->forceFill([
                'account_closed_at' => now(),
                'remember_token' => null,
                'whatsapp_opt_out_at' => $user->whatsapp_opt_out_at ?? now(),
                'phone_verification_code_hash' => null,
                'phone_verification_expires_at' => null,
            ])->save();
            if ($teacher) {
                $teacher->update(['is_verified' => false]);
                $teacher->classOffers()->update(['is_active' => false]);
            }
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            return $request;
        }, 3);
    }

    public function respond(AccountDeletionRequest $deletion, User $actor, string $response): void
    {
        abort_unless($actor->hasRole('admin') && ! $actor->account_closed_at, 403);
        DB::transaction(function () use ($deletion, $actor, $response) {
            $deletion = AccountDeletionRequest::whereKey($deletion->id)->lockForUpdate()->firstOrFail();
            if ($deletion->response === $response) {
                return;
            }
            $events = $deletion->events;
            $events[] = ['action' => 'response_recorded', 'actor_id' => $actor->id, 'at' => now()->toIso8601String(), 'response' => $response];
            $deletion->update(['status' => 'responded', 'response' => $response, 'responded_at' => now(), 'events' => $events]);
        });
    }
}
