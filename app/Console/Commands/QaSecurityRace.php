<?php

namespace App\Console\Commands;

use App\Http\Controllers\ComplaintController;
use App\Models\Complaint;
use App\Models\User;
use App\Services\AdminMfaService;
use App\Support\QaDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * QA — carreras de seguridad con procesos reales (auditoría Codex P0-01/P1-01).
 * Hermano de mova:concurrency-probe: mismo guard (solo local/testing y, con
 * --connection, solo la base mova_qa). scripts/qa-security-race.sh lanza N
 * procesos que esperan una barrera común (--at) y cuenta los aceptados.
 *
 *   totp               mismo admin + mismo código TOTP     → aceptados <= 1
 *   recovery           mismo recovery code                  → aceptados == 1
 *   complaints-empty   N reclamaciones, año sin secuencia   → N creadas, códigos únicos
 *   complaints-seeded  ídem con el año ya poblado
 */
class QaSecurityRace extends Command
{
    protected $signature = 'mova:qa-security-race
        {operation : totp|recovery|complaints-empty|complaints-seeded}
        {--setup : crea el escenario y lo imprime como "scenario=<id> code=<code>"}
        {--cleanup : elimina los escenarios de este comando}
        {--scenario= : id del escenario}
        {--code= : código a presentar}
        {--at= : epoch (float) en que todos los procesos disparan a la vez}
        {--worker=0}
        {--connection= : solo se acepta si resuelve a mova_qa}';

    protected $description = 'QA: carrera real multi-proceso sobre MFA admin y Libro de Reclamaciones';

    private const EMAIL_PREFIX = 'qa-race-';

    public function handle(): int
    {
        QaDatabaseGuard::assertSafeEnvironment();

        if ($connection = $this->option('connection')) {
            config(['database.default' => $connection]);
            QaDatabaseGuard::assertDatabase($connection, 'mova_qa');
        }

        $operation = $this->argument('operation');

        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        if ($this->option('setup')) {
            return $this->setup($operation);
        }

        // Todo el bootstrap (autoload, conexión) ya ocurrió: esperar la barrera.
        if ($at = (float) $this->option('at')) {
            $wait = $at - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }

        try {
            $result = match ($operation) {
                'totp', 'recovery' => $this->attemptMfa(),
                'complaints-empty', 'complaints-seeded' => $this->attemptComplaint(),
            };
        } catch (Throwable $e) {
            $result = 'error:'.class_basename($e).':'.Str::limit($e->getMessage(), 120);
        }

        $this->line("worker={$this->option('worker')} result={$result}");

        return self::SUCCESS;
    }

    private function setup(string $operation): int
    {
        if (str_starts_with($operation, 'complaints')) {
            $year = now()->year;
            DB::table('complaints')->where('code', 'like', "MOVA-{$year}-%")->delete();
            DB::table('complaint_sequences')->where('year', $year)->delete();

            if ($operation === 'complaints-seeded') {
                foreach (range(1, 3) as $i) {
                    Complaint::file($this->complaintData("seed {$i}"));
                }
            }

            $this->line('scenario=0 code=-');

            return self::SUCCESS;
        }

        Role::findOrCreate('admin', 'web');
        $admin = User::create([
            'name' => 'QA Race Admin',
            'email' => self::EMAIL_PREFIX.Str::lower(Str::random(10)).'@mova.test',
            'password' => Str::random(40),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey(32);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $code = $operation === 'totp'
            ? $google2fa->getCurrentOtp($secret)
            : app(AdminMfaService::class)->regenerateRecoveryCodes($admin)[0];

        $this->line("scenario={$admin->id} code={$code}");

        return self::SUCCESS;
    }

    private function attemptMfa(): string
    {
        $user = User::findOrFail((int) $this->option('scenario'));

        return app(AdminMfaService::class)->verify($user, (string) $this->option('code')) ? 'accepted' : 'rejected';
    }

    /** Pasa por el controller real (validación + Complaint::file + notificación). */
    private function attemptComplaint(): string
    {
        Notification::fake();

        $request = Request::create('/libro-de-reclamaciones', 'POST', $this->complaintData('worker '.$this->option('worker')) + ['accepted' => '1']);
        $request->setLaravelSession(app('session')->driver('array'));
        app()->instance('request', $request);

        $response = app(ComplaintController::class)->store($request);

        if ($response->getStatusCode() !== 302) {
            return 'status='.$response->getStatusCode();
        }

        $code = Complaint::where('consumer_name', 'QA Race worker '.$this->option('worker'))->latest('id')->value('code');

        return 'accepted code='.$code;
    }

    private function complaintData(string $tag): array
    {
        return [
            'type' => 'reclamo', 'consumer_name' => "QA Race {$tag}", 'document_type' => 'DNI',
            'document_number' => '12345678', 'address' => 'Av. QA 123', 'email' => 'qa-race@mova.test',
            'good_type' => 'servicio', 'good_description' => 'QA', 'detail' => 'Carrera de concurrencia QA.',
            'consumer_request' => 'Ninguno, QA.', 'is_minor' => false,
        ];
    }

    private function cleanup(): int
    {
        User::where('email', 'like', self::EMAIL_PREFIX.'%@mova.test')->get()->each(function (User $u) {
            $u->syncRoles([]);
            $u->delete();
        });
        DB::table('complaints')->where('email', 'qa-race@mova.test')->delete();

        return self::SUCCESS;
    }
}
