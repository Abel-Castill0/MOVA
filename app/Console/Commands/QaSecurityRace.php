<?php

namespace App\Console\Commands;

use App\Http\Controllers\ComplaintController;
use App\Models\Complaint;
use App\Models\User;
use App\Services\AdminMfaService;
use App\Support\QaDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * QA — carreras de seguridad con procesos reales (auditoría Codex P0-01/P1-01).
 * Hermano de mova:concurrency-probe, pero SIEMPRE exige --connection=mysql_qa
 * resuelta a la base mova_qa (sin fallback). scripts/qa-security-race.sh lanza N
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
        {--cleanup : elimina solamente --scenario=<id>}
        {--verify : verifica las hojas y el contador de un escenario de reclamaciones}
        {--scenario= : id del admin QA, o c-<año QA>-<token> para reclamaciones}
        {--code= : código a presentar}
        {--at= : epoch (float) en que todos los procesos disparan a la vez}
        {--worker=0}
        {--connection= : OBLIGATORIO, exactamente mysql_qa (y debe resolver a la base mova_qa)}';

    protected $description = 'QA: carrera real multi-proceso sobre MFA admin y Libro de Reclamaciones';

    private const EMAIL_PREFIX = 'qa-race-';

    private const QA_CONNECTION = 'mysql_qa';

    // Años futuros dentro del límite de TIMESTAMP de MySQL (2038). Usar
    // año 5000 rompería created_at/updated_at de Complaint::file; cada año
    // debe estar libre y ser posterior al real antes de reservarlo.
    private const COMPLAINT_YEAR_MIN = 2030;

    private const COMPLAINT_YEAR_MAX = 2037;

    private const OPERATIONS = ['totp', 'recovery', 'complaints-empty', 'complaints-seeded'];

    public function handle(): int
    {
        // Fail closed ANTES de cualquier rama (setup/cleanup/worker): este
        // comando borra y crea filas, así que solo corre con la conexión QA
        // explícita y verificada. Sin --connection, o con otra conexión, no
        // hay fallback a la conexión por defecto.
        QaDatabaseGuard::assertSafeEnvironment();

        if ($this->option('connection') !== self::QA_CONNECTION) {
            throw new RuntimeException(
                'MOVA QA GUARD: mova:qa-security-race exige --connection='.self::QA_CONNECTION.'. Abortado sin tocar datos.'
            );
        }

        QaDatabaseGuard::assertLocalQaConnection(self::QA_CONNECTION);
        QaDatabaseGuard::assertDatabase(self::QA_CONNECTION, 'mova_qa');
        config(['database.default' => self::QA_CONNECTION]);

        $operation = $this->argument('operation');
        if (! in_array($operation, self::OPERATIONS, true)) {
            $this->error('Operación de carrera de seguridad no admitida.');

            return self::FAILURE;
        }

        if ($this->option('cleanup')) {
            return $this->cleanup($operation);
        }

        if ($this->option('setup')) {
            return $this->setup($operation);
        }

        if ($this->option('verify')) {
            return $this->verify($operation);
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
            // Una excepción no es un perdedor legítimo de una carrera.
            // Los mensajes SQL/MFA pueden contener datos que no deben salir
            // en la salida del runner ni en los logs de CI.
            $result = 'error:'.class_basename($e);
        }

        $this->line("worker={$this->option('worker')} result={$result}");

        return str_starts_with($result, 'error:') ? self::FAILURE : self::SUCCESS;
    }

    private function setup(string $operation): int
    {
        if (str_starts_with($operation, 'complaints')) {
            return $this->setupComplaints($operation);
        }

        return DB::transaction(function () use ($operation) {
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
        });
    }

    private function setupComplaints(string $operation): int
    {
        // La reserva de año es atómica: no depende de SELECT previo ni de
        // borrar tablas de un escenario concurrente. Un año vacío tiene
        // last_number=0, pero no se emiten hojas hasta que corran workers.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $year = random_int(self::COMPLAINT_YEAR_MIN, self::COMPLAINT_YEAR_MAX);
            if ($year <= now()->year) {
                continue;
            }
            $scenario = 'c-'.$year.'-'.bin2hex(random_bytes(8));
            $reserved = DB::transaction(function () use ($year, $scenario, $operation) {
                // Un año con hojas previas pero secuencia ausente tampoco
                // puede pertenecer a este escenario recién creado.
                if (DB::table('complaints')->where('code', 'like', "MOVA-{$year}-%")->exists()) {
                    return false;
                }
                if (DB::table('complaint_sequences')->insertOrIgnore([
                    'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]) !== 1) {
                    return false;
                }

                $this->withComplaintYear($year, function () use ($scenario, $operation) {
                    if ($operation === 'complaints-seeded') {
                        foreach (range(1, 3) as $i) {
                            Complaint::file($this->complaintData($scenario, "seed {$i}"));
                        }
                    }
                });

                return true;
            });

            if ($reserved) {
                $this->line("scenario={$scenario} code=-");

                return self::SUCCESS;
            }
        }

        $this->error('No hay años futuros QA libres y compatibles con TIMESTAMP para reservar una carrera.');

        return self::FAILURE;
    }

    private function attemptMfa(): string
    {
        $user = User::findOrFail((int) $this->option('scenario'));
        if (! str_starts_with($user->email, self::EMAIL_PREFIX) || ! str_ends_with($user->email, '@mova.test')) {
            throw new RuntimeException('Escenario MFA ajeno al arnés QA.');
        }

        return app(AdminMfaService::class)->verify($user, (string) $this->option('code')) ? 'accepted' : 'rejected';
    }

    /** Pasa por el controller real (validación + Complaint::file + notificación). */
    private function attemptComplaint(): string
    {
        [$scenario, $year] = $this->complaintScenario();
        Notification::fake();

        return $this->withComplaintYear($year, function () use ($scenario) {
            $tag = 'worker '.$this->option('worker');
            $request = Request::create('/libro-de-reclamaciones', 'POST', $this->complaintData($scenario, $tag) + ['accepted' => '1']);
            $request->setLaravelSession(app('session')->driver('array'));
            app()->instance('request', $request);

            $response = app(ComplaintController::class)->store($request);

            if ($response->getStatusCode() !== 302) {
                return 'status='.$response->getStatusCode();
            }

            $code = Complaint::where('consumer_name', "QA Race {$scenario} {$tag}")->latest('id')->value('code');

            return $code ? 'accepted code='.$code : 'error:MissingComplaint';
        });
    }

    private function complaintData(string $scenario, string $tag): array
    {
        return [
            'type' => 'reclamo', 'consumer_name' => "QA Race {$scenario} {$tag}", 'document_type' => 'DNI',
            'document_number' => '12345678', 'address' => 'Av. QA 123', 'email' => $this->complaintEmail($scenario),
            'good_type' => 'servicio', 'good_description' => 'QA', 'detail' => 'Carrera de concurrencia QA.',
            'consumer_request' => 'Ninguno, QA.', 'is_minor' => false,
        ];
    }

    private function cleanup(string $operation): int
    {
        if (str_starts_with($operation, 'complaints')) {
            [$scenario, $year] = $this->complaintScenario();

            return DB::transaction(function () use ($scenario, $year) {
                $sequence = DB::table('complaint_sequences')->where('year', $year)->lockForUpdate()->first();
                if (! $sequence) {
                    $this->error('No existe el contador QA de este escenario.');

                    return self::FAILURE;
                }
                $prefix = "MOVA-{$year}-%";
                $all = DB::table('complaints')->where('code', 'like', $prefix)->count();
                $owned = DB::table('complaints')->where('code', 'like', $prefix)->where('email', $this->complaintEmail($scenario))->count();
                if ($owned !== $all) {
                    $this->error('El año QA contiene hojas ajenas: no se permite borrar el contador.');

                    return self::FAILURE;
                }

                DB::table('complaints')->where('code', 'like', $prefix)->where('email', $this->complaintEmail($scenario))->delete();
                DB::table('complaint_sequences')->where('year', $year)->delete();

                return self::SUCCESS;
            });
        }

        $id = (int) $this->option('scenario');
        $user = $id > 0 ? User::find($id) : null;
        if (! $user || ! str_starts_with($user->email, self::EMAIL_PREFIX) || ! str_ends_with($user->email, '@mova.test')) {
            $this->error('Cleanup MFA exige un --scenario=<id> de admin QA existente.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user) {
            $user->syncRoles([]);
            $user->delete();
        });

        return self::SUCCESS;
    }

    private function verify(string $operation): int
    {
        if (! str_starts_with($operation, 'complaints')) {
            $this->error('Verify solo está disponible para reclamaciones.');

            return self::FAILURE;
        }

        [$scenario, $year] = $this->complaintScenario();
        $codes = DB::table('complaints')->where('code', 'like', "MOVA-{$year}-%")
            ->where('email', $this->complaintEmail($scenario))->pluck('code');
        $all = DB::table('complaints')->where('code', 'like', "MOVA-{$year}-%")->count();
        $last = DB::table('complaint_sequences')->where('year', $year)->value('last_number');
        $this->line(sprintf('verify rows=%d total_year=%d last_number=%d year=%d', $codes->count(), $all, $last ?? -1, $year));

        return $last !== null && $all === $codes->count() ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{string, int} */
    private function complaintScenario(): array
    {
        $scenario = (string) $this->option('scenario');
        if (! preg_match('/^c-(203[0-7])-([a-f0-9]{16})$/D', $scenario, $parts)
            || (int) $parts[1] <= now()->year) {
            throw new RuntimeException('Escenario QA inválido: se exige c-<año QA>-<token>.');
        }

        return [$scenario, (int) $parts[1]];
    }

    private function complaintEmail(string $scenario): string
    {
        return "qa-security-race-{$scenario}@mova.test";
    }

    private function withComplaintYear(int $year, callable $callback): mixed
    {
        // Aislamiento temporal por proceso: no alterar el reloj global del
        // test ni el año real después del controlador/seed.
        $previous = Carbon::getTestNow();
        Carbon::setTestNow(now()->setYear($year));
        try {
            return $callback();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
