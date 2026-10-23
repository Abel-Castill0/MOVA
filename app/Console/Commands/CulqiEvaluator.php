<?php

namespace App\Console\Commands;

use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Cuenta de EVALUACIÓN para la revisión comercial de Culqi: un profesor sin privilegios que puede recorrer el flujo de
 * compra de créditos y nada más. Sin contraseña conocida (como `mova:create-admin`): el titular la fija con «Olvidé mi
 * contraseña» en un correo que controla y la entrega a Culqi por un canal privado — la contraseña nunca pasa por Git, logs,
 * documentación ni por este comando.
 *
 *   php artisan mova:culqi-evaluator create revision@correo-del-titular.pe
 *   php artisan mova:culqi-evaluator revoke  revision@correo-del-titular.pe
 *   php artisan mova:culqi-evaluator status  revision@correo-del-titular.pe
 *
 * Permisos: solo el rol `teacher`, perfil SIN verificar (no aparece en el marketplace ni cuenta como profesor disponible),
 * sin materias, sin saldo, sin acceso a datos de otros usuarios ni al panel de administración. Revocar: si ya compró
 * (hay recargas/ledger) se SUSPENDE y se le cierra la sesión (los registros financieros no se borran); si no, se elimina.
 */
class CulqiEvaluator extends Command
{
    protected $signature = 'mova:culqi-evaluator {action : create|revoke|status} {email : Correo del evaluador (un buzón que controla el titular)}';

    protected $description = 'Crea, consulta o revoca la cuenta de evaluación de Culqi (profesor sin privilegios, sin contraseña conocida).';

    public const NAME = 'Evaluador Culqi (revisión comercial)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo no es válido.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'create' => $this->create($email),
            'revoke' => $this->revoke($email),
            'status' => $this->status($email),
            default => $this->refuse('La acción debe ser create, revoke o status.'),
        };
    }

    private function create(string $email): int
    {
        if (app()->environment('production') && preg_match('/@(mova\.test|example\.(com|org|net)|invalid)$|\.test$/i', $email)) {
            return $this->refuse('Ese correo parece de prueba: usa un buzón real que controles para recibir el enlace de contraseña.');
        }

        $existing = User::where('email', $email)->first();
        if ($existing && $existing->name !== self::NAME) {
            return $this->refuse('Ya existe una cuenta con ese correo que NO es de evaluación: no se modifica. Usa otro correo.');
        }
        if ($existing) {
            $this->info('La cuenta de evaluación ya existe.');

            return $this->status($email);
        }

        Role::findOrCreate('teacher', 'web');

        DB::transaction(function () use ($email) {
            $user = User::create([
                'name' => self::NAME,
                'email' => $email,
                'password' => Hash::make(Str::random(64)), // desconocida: se fija por «Olvidé mi contraseña»
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole('teacher');

            // Sin verificar: no figura en el marketplace ni en el catálogo; sin saldo ni materias.
            TeacherProfile::create(['user_id' => $user->id, 'is_verified' => false, 'credits_available' => 0, 'credits_reserved' => 0]);
        });

        $this->info("Cuenta de evaluación creada: {$email} (rol teacher, sin verificar, sin saldo).");
        $this->line('Siguiente paso: abre /forgot-password, pide el enlace para ese correo y fija la contraseña tú; entrégala a Culqi por un canal privado.');
        $this->line('Al terminar la revisión: php artisan mova:culqi-evaluator revoke '.$email);

        return self::SUCCESS;
    }

    private function revoke(string $email): int
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->info('No existe una cuenta con ese correo: nada que revocar.');

            return self::SUCCESS;
        }
        if ($user->name !== self::NAME) {
            return $this->refuse('Esa cuenta NO es de evaluación: no se toca.');
        }

        $profile = TeacherProfile::where('user_id', $user->id)->first();
        $hasFinancialHistory = $profile && RechargeRequest::where('teacher_profile_id', $profile->id)->exists();

        if ($hasFinancialHistory) {
            // El ledger y las recargas son append-only: no se borran. Se suspende, se invalida la contraseña y se cierran sesiones.
            $user->forceFill(['suspended_at' => now(), 'password' => Hash::make(Str::random(64)), 'remember_token' => null])->save();
            $this->dropSessions($user);
            $this->info("Cuenta SUSPENDIDA y credenciales invalidadas (tiene recargas registradas: se conservan): {$email}");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $profile) {
            $this->dropSessions($user);
            $profile?->delete();
            $user->syncRoles([]);
            $user->delete();
        });
        $this->info("Cuenta de evaluación ELIMINADA: {$email}");

        return self::SUCCESS;
    }

    private function status(string $email): int
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->line('No existe una cuenta con ese correo.');

            return self::SUCCESS;
        }

        $profile = TeacherProfile::where('user_id', $user->id)->first();
        $this->table(['Campo', 'Valor'], [
            ['Cuenta de evaluación', $user->name === self::NAME ? 'sí' : 'NO (otra cuenta)'],
            ['Roles', $user->getRoleNames()->implode(', ')],
            ['Correo verificado', $user->email_verified_at ? 'sí' : 'no'],
            ['Suspendida', $user->suspended_at ? 'sí' : 'no'],
            ['Profesor verificado', $profile?->is_verified ? 'sí' : 'no'],
            ['Saldo de créditos', $profile?->credits_available ?? 0],
            ['Recargas registradas', $profile ? RechargeRequest::where('teacher_profile_id', $profile->id)->count() : 0],
        ]);

        return self::SUCCESS;
    }

    private function dropSessions(User $user): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
