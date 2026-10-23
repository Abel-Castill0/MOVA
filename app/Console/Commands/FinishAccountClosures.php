<?php

namespace App\Console\Commands;

use App\Jobs\FinishAccountClosureCleanup;
use App\Models\AccountDeletionRequest;
use App\Models\TeacherProfile;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Red de seguridad del cierre automático de cuentas, sin intervención humana:
 *  - reintenta la limpieza secundaria (archivos externos) que no llegó a completarse;
 *  - avisa de saldo que apareció DESPUÉS del cierre (p. ej. un webhook de pago tardío): ese dinero no se
 *    pierde ni se usa en silencio; queda como incidencia excepcional para su tratamiento (devolución).
 */
class FinishAccountClosures extends Command
{
    protected $signature = 'mova:account-closure:finish';

    protected $description = 'Reintenta la limpieza pendiente de cuentas cerradas y avisa de saldos posteriores al cierre.';

    private const MAX_ATTEMPTS = 10;

    public function handle(OperationalAlertService $alerts): int
    {
        $retried = 0;
        AccountDeletionRequest::whereIn('cleanup_status', ['pending', 'failed'])
            ->where('cleanup_attempts', '<', self::MAX_ATTEMPTS)->orderBy('id')->limit(50)->get()
            ->each(function (AccountDeletionRequest $request) use (&$retried) {
                try {
                    FinishAccountClosureCleanup::dispatchSync($request->id);
                } catch (Throwable) {
                    // Queda `failed` y se reintenta en la próxima ejecución; el detalle se reporta en el job.
                }
                $retried++;
            });

        $late = 0;
        TeacherProfile::whereHas('user', fn ($q) => $q->whereNotNull('account_erased_at'))->where('credits_available', '>', 0)
            ->get()->each(function (TeacherProfile $profile) use ($alerts, &$late) {
                $alerts->raise("account-closure:{$profile->user_id}:balance-after-closure", 'account_closure',
                    'Saldo en una cuenta ya cerrada',
                    'Una cuenta eliminada tiene créditos disponibles (reembolsables dentro del plazo o acreditados tras el cierre). No son utilizables; requiere tratamiento de devolución.',
                    ['user_id' => $profile->user_id, 'credits' => $profile->credits_available]);
                $late++;
            });

        $this->info("Limpiezas reintentadas: {$retried} · cuentas cerradas con saldo: {$late}");

        return self::SUCCESS;
    }
}
