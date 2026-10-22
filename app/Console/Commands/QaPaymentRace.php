<?php

namespace App\Console\Commands;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Contracts\CardPaymentInstrument;
use App\Payment\CulqiPaymentProvider;
use App\Services\CulqiPaymentReconciliationService;
use App\Support\QaDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * QA — carreras REALES multi-proceso sobre el flujo de pagos de Culqi (MySQL).
 * Hermano de mova:qa-security-race: SIEMPRE exige --connection=mysql_qa (base mova_qa), sin fallback.
 * scripts/qa-payment-race.sh crea el escenario, lanza N procesos que esperan una barrera común (--at) y
 * comprueba el estado final con --verify.
 *
 *   reconcile-paid    N procesos aplican la MISMA verdad "cargo pagado" (webhook/barrido/respuesta a la vez)
 *                     → exactamente 1 depósito, saldo = créditos, recarga aprobada
 *   reconcile-refund  recarga ya aprobada; N procesos aplican la MISMA devolución total
 *                     → exactamente 1 reversión, recarga revertida, saldo 0
 *   charge-attempt    N procesos (doble clic) piden cobrar el mismo intento contra un doble HTTP local
 *                     → exactamente 1 POST /v2/charges, 1 depósito
 *
 * Nada de esto toca Culqi real: los cargos son estructuras en memoria o las sirve el doble local de
 * scripts/qa/culqi-stub.php. Solo corre con APP_ENV local/testing.
 */
class QaPaymentRace extends Command
{
    protected $signature = 'mova:qa-payment-race
        {operation : reconcile-paid|reconcile-refund|charge-attempt}
        {--setup : crea el escenario y lo imprime como "scenario=<recharge_id>"}
        {--verify : imprime el estado final del escenario}
        {--cleanup : elimina los escenarios de este comando}
        {--scenario= : id de la recarga}
        {--at= : epoch (float) en que todos los procesos disparan a la vez}
        {--worker=0}
        {--stub-url= : URL base del doble HTTP (solo charge-attempt)}
        {--connection= : OBLIGATORIO, exactamente mysql_qa (y debe resolver a la base mova_qa)}';

    protected $description = 'QA: carrera real multi-proceso sobre acreditación/reversión de pagos Culqi';

    private const EMAIL_PREFIX = 'qa-pay-race-';

    private const QA_CONNECTION = 'mysql_qa';

    private const AMOUNT_MINOR = 1000;

    private const CREDITS = 5;

    public function handle(): int
    {
        QaDatabaseGuard::assertSafeEnvironment();

        if ($this->option('connection') !== self::QA_CONNECTION) {
            throw new RuntimeException('MOVA QA GUARD: mova:qa-payment-race exige --connection='.self::QA_CONNECTION.'. Abortado sin tocar datos.');
        }

        QaDatabaseGuard::assertDatabase(self::QA_CONNECTION, 'mova_qa');
        config(['database.default' => self::QA_CONNECTION]);

        $operation = $this->argument('operation');

        if ($this->option('cleanup')) {
            return $this->cleanup();
        }
        if ($this->option('setup')) {
            return $this->setup($operation);
        }
        if ($this->option('verify')) {
            return $this->verify();
        }

        if ($at = (float) $this->option('at')) {
            $wait = $at - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }

        try {
            $result = match ($operation) {
                'reconcile-paid' => $this->applyTruth($this->paidCharge($this->order())),
                'reconcile-refund' => $this->applyTruth($this->refundedCharge($this->order())),
                'charge-attempt' => $this->attemptCharge(),
            };
        } catch (Throwable $e) {
            $result = 'error:'.class_basename($e).':'.Str::limit(str_replace(["\n", "\r"], ' ', $e->getMessage()), 110);
        }

        $this->line("worker={$this->option('worker')} result={$result}");

        return self::SUCCESS;
    }

    private function setup(string $operation): int
    {
        Role::findOrCreate('teacher', 'web');
        $user = User::create([
            'name' => 'QA Pay Race',
            'email' => self::EMAIL_PREFIX.Str::lower(Str::random(10)).'@mova.test',
            'password' => Str::random(40),
            'phone' => '987654321',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);

        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => self::CREDITS,
            'amount_pen' => '10.00',
            'payment_method' => 'culqi',
            'operation_number' => null,
            'operation_number_normalized' => null,
            'status' => 'pending',
        ]);

        // charge-attempt crea su propia fila con 'prepared' (la reclama el proveedor); los otros dos parten de un
        // intento ya enviado, tal como lo deja el checkout tras el POST /v2/charges.
        if ($operation !== 'charge-attempt') {
            $order = PaymentOrder::create([
                'recharge_request_id' => $recharge->id,
                'attempt_number' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'provider' => 'culqi',
                'provider_order_id' => 'chr_test_RACE'.Str::upper(Str::random(12)),
                'submission_status' => 'submitted',
                'status' => 'pending',
                'amount_minor' => self::AMOUNT_MINOR,
                'currency' => 'PEN',
                'payment_channel' => 'card',
            ]);

            if ($operation === 'reconcile-refund') {
                // Estado previo realista: pagado y acreditado una vez, de forma secuencial.
                $this->applyTruth($this->paidCharge($order));
            }
        }

        $this->line("scenario={$recharge->id}");

        return self::SUCCESS;
    }

    private function verify(): int
    {
        $recharge = RechargeRequest::findOrFail((int) $this->option('scenario'));
        $profile = TeacherProfile::findOrFail($recharge->teacher_profile_id);

        $deposits = DB::table('credit_transactions')->where('idempotency_key', "recharge:{$recharge->id}:deposit")->count();
        $reversals = DB::table('credit_transactions')->where('idempotency_key', "recharge:{$recharge->id}:reversal")->count();
        $rows = DB::table('credit_transactions')->where('teacher_profile_id', $profile->id)->count();
        $orders = PaymentOrder::where('recharge_request_id', $recharge->id)->get();

        $this->line(sprintf(
            'verify deposits=%d reversals=%d ledger_rows=%d credits_available=%s recharge=%s orders=%d order_status=%s',
            $deposits, $reversals, $rows, $profile->fresh()->credits_available, $recharge->fresh()->status,
            $orders->count(), $orders->pluck('status')->implode(','),
        ));

        return self::SUCCESS;
    }

    private function order(): PaymentOrder
    {
        return PaymentOrder::where('recharge_request_id', (int) $this->option('scenario'))->orderBy('id')->firstOrFail();
    }

    private function applyTruth(array $charge): string
    {
        $order = PaymentOrder::where('provider_order_id', $charge['id'])->firstOrFail();

        return app(CulqiPaymentReconciliationService::class)->applyTruth($order, null, $charge);
    }

    /** Cargo exitoso tal como lo devuelve el sandbox real (paid:false + venta_exitosa/AUT0000). */
    private function paidCharge(PaymentOrder $order): array
    {
        return [
            'object' => 'charge', 'id' => $order->provider_order_id, 'amount' => self::AMOUNT_MINOR, 'amount_refunded' => 0,
            'current_amount' => self::AMOUNT_MINOR, 'currency_code' => 'PEN', 'email' => 'qa-race@mova.test',
            'capture' => true, 'paid' => false, 'dispute' => false, 'duplicated' => false,
            'outcome' => ['type' => 'venta_exitosa', 'code' => 'AUT0000'],
            'metadata' => [
                'payment_order_id' => (string) $order->id, 'recharge_request_id' => (string) $order->recharge_request_id,
                'attempt' => (string) $order->attempt_number, 'idempotency_key' => (string) $order->idempotency_key,
            ],
        ];
    }

    private function refundedCharge(PaymentOrder $order): array
    {
        return array_replace($this->paidCharge($order), ['amount_refunded' => self::AMOUNT_MINOR, 'current_amount' => 0]);
    }

    private function attemptCharge(): string
    {
        $stub = (string) $this->option('stub-url');
        if ($stub === '') {
            throw new RuntimeException('charge-attempt requiere --stub-url (doble HTTP local).');
        }
        // Metadata y correo QA nunca deben salir de la máquina: solo se admite el doble en loopback.
        if (! in_array(parse_url($stub, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('MOVA QA GUARD: --stub-url debe apuntar a 127.0.0.1 o localhost.');
        }

        config([
            'payments.culqi.env' => 'test',
            'payments.culqi.public_key' => 'pk_test_QARaceKey000001',
            'payments.culqi.private_key' => 'sk_test_QARaceKey000001',
            'payments.culqi.base_url' => $stub,
            'payments.culqi.secure_url' => $stub,
            'payments.culqi.encrypt_payloads' => false,
        ]);

        $recharge = RechargeRequest::findOrFail((int) $this->option('scenario'));

        try {
            $order = (new CulqiPaymentProvider())->createPaymentAttempt($recharge, new CardPaymentInstrument('tkn_test_QARaceToken0001', 'visa'));
        } catch (RuntimeException $e) {
            return 'rejected:'.Str::limit(str_replace(["\n", "\r"], ' ', $e->getMessage()), 90);
        }

        return 'accepted status='.$order->status;
    }

    /**
     * OJO: borra filas de credit_transactions (el ledger es append-only en producción). Es aceptable SOLO porque este
     * comando corre únicamente contra la base QA `mova_qa` y limita el borrado a los usuarios `qa-pay-race-*@mova.test`;
     * no copiar este patrón fuera de un arnés QA.
     */
    private function cleanup(): int
    {
        $users = User::where('email', 'like', self::EMAIL_PREFIX.'%@mova.test')->get();
        foreach ($users as $user) {
            $profileIds = TeacherProfile::where('user_id', $user->id)->pluck('id');
            $rechargeIds = RechargeRequest::whereIn('teacher_profile_id', $profileIds)->pluck('id');
            DB::table('payment_orders')->whereIn('recharge_request_id', $rechargeIds)->delete();
            DB::table('credit_transactions')->whereIn('teacher_profile_id', $profileIds)->delete();
            DB::table('recharge_requests')->whereIn('id', $rechargeIds)->delete();
            DB::table('teacher_profiles')->whereIn('id', $profileIds)->delete();
            $user->syncRoles([]);
            $user->delete();
        }

        return self::SUCCESS;
    }
}
