<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Créditos de cortesía decididos por el propietario (sin pago). Mismo patrón que el bono de bienvenida
 * (PhoneVerificationController::grantTeacherWelcomeBonus): transacción, bloqueo User → TeacherProfile, depósito en el ledger
 * append-only con clave de idempotencia única. NO crea una recarga ni simula un pago. Solo cuentas de profesor activas.
 *
 *   php artisan mova:grant-credits correo@ejemplo.com 10 --reference=cortesia-20261105-abel [--dry-run]
 */
class GrantCourtesyCredits extends Command
{
    private const MAX_CREDITS = 100;

    protected $signature = 'mova:grant-credits {email : correo de la cuenta de profesor}
        {credits : créditos a acreditar (1-100)}
        {--reference= : clave única de la cortesía (letras minúsculas, números y guiones); repetirla no acredita dos veces}
        {--dry-run : muestra la cuenta y el saldo sin cambiar nada}';

    protected $description = 'Acredita créditos de cortesía a un profesor (ledger idempotente, sin pago).';

    public function handle(): int
    {
        $credits = filter_var($this->argument('credits'), FILTER_VALIDATE_INT);
        $reference = (string) $this->option('reference');
        if ($credits === false || $credits < 1 || $credits > self::MAX_CREDITS) {
            $this->error('Los créditos deben ser un entero entre 1 y '.self::MAX_CREDITS.'.');

            return self::FAILURE;
        }
        if (! preg_match('/^[a-z0-9][a-z0-9-]{5,59}$/', $reference)) {
            $this->error('Falta --reference (6-60 caracteres: minúsculas, números y guiones), p. ej. cortesia-20261105-nombre.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim((string) $this->argument('email')))])->first();
        $problem = $this->ineligible($user);
        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }
        $profile = $user->teacherProfile;
        $key = "grant:{$reference}";

        $this->line("Cuenta #{$user->id} (profesor) · perfil #{$profile->id} · saldo disponible {$profile->credits_available} · reservado {$profile->credits_reserved}");
        if ($this->option('dry-run')) {
            $this->line("[--dry-run] Se acreditarían {$credits} créditos con la clave {$key}. Nada cambió.");

            return self::SUCCESS;
        }

        $result = DB::transaction(function () use ($user, $profile, $credits, $key) {
            // Mismo orden de bloqueo que el cierre de cuenta y el bono de bienvenida: User antes que TeacherProfile.
            $owner = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $owner || $owner->account_closed_at !== null || $owner->suspended_at !== null) {
                return ['status' => 'ineligible'];
            }
            $teacher = TeacherProfile::whereKey($profile->id)->lockForUpdate()->first();
            // La clave es única en todo el ledger: se busca globalmente para fallar limpio si es de otro profesor o de otra cantidad.
            $existing = CreditTransaction::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return ((int) $existing->teacher_profile_id === (int) $teacher->id && (int) $existing->amount === $credits)
                    ? ['status' => 'duplicate', 'balance' => $teacher->credits_available]
                    : ['status' => 'conflict'];
            }

            $teacher->update(['credits_available' => $teacher->credits_available + $credits]);
            $teacher->creditTransactions()->create([
                'idempotency_key' => $key,
                'type' => 'deposit',
                'amount' => $credits,
                'description' => 'Cortesía MOVA', // lo ve el profesor: sin notas internas (la referencia queda en la clave)
            ]);

            return ['status' => 'granted', 'balance' => $teacher->fresh()->credits_available];
        });

        if ($result['status'] === 'ineligible') {
            $this->error('La cuenta se cerró o suspendió durante la operación: no se acreditó nada.');

            return self::FAILURE;
        }
        if ($result['status'] === 'conflict') {
            $this->error("La referencia {$key} ya se usó para otra cuenta u otra cantidad: no se acreditó nada. Usa otra referencia.");

            return self::FAILURE;
        }
        if ($result['status'] === 'duplicate') {
            $this->warn("La cortesía {$key} ya estaba aplicada: no se acreditó de nuevo. Saldo disponible: {$result['balance']}.");

            return self::SUCCESS;
        }

        Log::info('[Créditos] Cortesía acreditada', ['user_id' => $user->id, 'teacher_profile_id' => $profile->id, 'credits' => $credits, 'key' => $key]);
        $this->info("Acreditados {$credits} créditos de cortesía. Saldo disponible: {$result['balance']}.");

        return self::SUCCESS;
    }

    private function ineligible(?User $user): ?string
    {
        return match (true) {
            $user === null => 'No existe una cuenta con ese correo.',
            $user->account_closed_at !== null => 'La cuenta está cerrada: no se acredita.',
            $user->suspended_at !== null => 'La cuenta está suspendida: no se acredita.',
            ! $user->hasRole('teacher') || $user->teacherProfile === null => 'La cuenta no es de profesor: los créditos solo existen para profesores.',
            default => null,
        };
    }
}
