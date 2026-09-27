<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminMfaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Break-glass (P0-C): un admin que perdió su app y sus recovery codes solo
 * puede recuperarse con acceso de shell al runtime. Tras el reset, el admin
 * vuelve a enrolarse en su próximo acceso.
 */
class ResetAdminMfa extends Command
{
    protected $signature = 'mova:admin-mfa-reset {email} {--force : No pedir confirmación}';

    protected $description = 'Resetea el MFA de un administrador (break-glass, requiere shell).';

    public function handle(AdminMfaService $mfa): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user || ! $user->hasRole('admin')) {
            $this->error('No existe un administrador con ese email.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("¿Resetear el MFA de {$user->email}?")) {
            return self::FAILURE;
        }

        $mfa->reset($user);
        Log::warning('admin_mfa.reset_via_cli', ['user_id' => $user->id]);
        $this->info('MFA reseteado. El admin deberá enrolarse de nuevo.');

        return self::SUCCESS;
    }
}
