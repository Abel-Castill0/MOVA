<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Contracts\CardPaymentInstrument;
use App\Payment\Culqi\CulqiClient;
use App\Payment\Culqi\CulqiConfig;
use App\Payment\CulqiPaymentProvider;
use App\Services\CulqiPaymentReconciliationService;
use App\Support\QaDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * QA: pago de PRUEBA real contra el entorno de integración de Culqi, a través
 * del backend de MOVA, y verificación de que el ledger acredita exactamente
 * una vez. Solo con llaves sk_test_/pk_test_, solo en una BD QA (sqlite o
 * mysql_qa), solo con tarjetas de prueba PUBLICADAS por Culqi (nunca datos
 * reales) y con el importe topado por CULQI_SANDBOX_MAX_AMOUNT_MINOR.
 */
class CulqiSandboxSmoke extends Command
{
    protected $signature = 'culqi:sandbox-smoke
        {--scenario=* : approved | insufficient_funds | stolen_card | refund (por defecto: approved y insufficient_funds)}
        {--skip-webhook : no ejercitar el endpoint del webhook}
        {--confirm-sandbox : obligatorio: confirma que entiendes que esto crea cargos de PRUEBA en Culqi}';

    protected $description = 'QA: pago de PRUEBA real contra Culqi (modo test) vía el backend de MOVA y verificación del ledger (nunca en producción).';

    /** Tarjetas de prueba publicadas en docs.culqi.com/es/documentacion/pagos-online/tarjetas-de-prueba */
    private const SCENARIOS = [
        'approved' => ['card' => '4111111111111111', 'month' => '09', 'year' => '2030', 'cvv' => '123', 'expect_credit' => true],
        'insufficient_funds' => ['card' => '4000040000000008', 'month' => '03', 'year' => '2030', 'cvv' => '295', 'expect_credit' => false],
        'stolen_card' => ['card' => '4000020000000000', 'month' => '10', 'year' => '2030', 'cvv' => '354', 'expect_credit' => false],
        // Cobro aprobado y luego devolución TOTAL (POST /v2/refunds): debe terminar revertido, sin saldo.
        'refund' => ['card' => '4111111111111111', 'month' => '09', 'year' => '2030', 'cvv' => '123', 'expect_credit' => true, 'refund' => true],
    ];

    private array $rows = [];

    private bool $allOk = true;

    public function handle(CulqiClient $client, CulqiPaymentProvider $provider, CulqiPaymentReconciliationService $reconciler): int
    {
        try {
            $this->assertSafeToRun();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Pre-flight: autenticación y conectividad con Culqi (GET /v2/charges?limit=1, solo lectura)…');
        if (! $client->listCharges(['limit' => 1])->successful()) {
            $this->error('PRE-FLIGHT FALLÓ: Culqi no aceptó la llave secreta de prueba (o la API no responde). No se creó ningún cargo.');

            return self::FAILURE;
        }

        $scenarios = $this->option('scenario') ?: ['approved', 'insufficient_funds'];
        foreach ($scenarios as $name) {
            if (! isset(self::SCENARIOS[$name])) {
                $this->error("Escenario desconocido [{$name}]. Usa: ".implode(', ', array_keys(self::SCENARIOS)).'.');

                return self::FAILURE;
            }
        }

        foreach ($scenarios as $name) {
            try {
                $this->runScenario($name, $client, $provider, $reconciler);
            } catch (Throwable $e) {
                $this->allOk = false;
                $this->rows[] = [$name, 'ERROR', class_basename($e), '-', '-', '-', 'FALLÓ'];
                $this->error("[{$name}] excepción: ".class_basename($e).' — '.Str::limit($e->getMessage(), 200));
            }
        }

        $this->newLine();
        $this->table(['escenario', 'orden', 'conciliación x2', 'depósitos', 'créditos', 'webhook x2', 'veredicto'], $this->rows);
        $this->line('Alcance: entorno de integración (test) de Culqi vía backend. NO prueba producción, 3DS interactivo ni webhooks reales desde CulqiPanel hacia una URL pública.');

        return $this->allOk ? self::SUCCESS : self::FAILURE;
    }

    private function assertSafeToRun(): void
    {
        if (! $this->option('confirm-sandbox')) {
            throw new RuntimeException('Falta --confirm-sandbox. Esta sonda crea cargos de PRUEBA reales en el entorno de integración de Culqi.');
        }

        QaDatabaseGuard::assertSafeEnvironment();

        $connection = (string) config('database.default');
        if ($connection === 'mysql_qa') {
            QaDatabaseGuard::assertDatabase('mysql_qa', 'mova_qa');
        } elseif ($connection !== 'sqlite') {
            throw new RuntimeException("MOVA QA GUARD: la conexión [{$connection}] no es una BD QA (sqlite o mysql_qa). Abortado.");
        }

        $problems = CulqiConfig::problems();
        if ($problems !== []) {
            throw new RuntimeException('Configuración de Culqi incoherente: '.implode(' ', $problems));
        }

        if (! CulqiConfig::isTestMode()
            || CulqiConfig::keyMode(CulqiConfig::secretKey(), 'sk') !== 'test'
            || CulqiConfig::keyMode(CulqiConfig::publicKey(), 'pk') !== 'test') {
            throw new RuntimeException('Esta sonda exige CULQI_ENV=test y llaves pk_test_/sk_test_. Abortado antes de cualquier llamada.');
        }
    }

    private function runScenario(string $name, CulqiClient $client, CulqiPaymentProvider $provider, CulqiPaymentReconciliationService $reconciler): void
    {
        $spec = self::SCENARIOS[$name];
        $recharge = $this->makeRecharge();
        $teacher = $recharge->teacherProfile;
        $email = $teacher->user->email;

        $tokenResult = $client->createToken([
            'card_number' => $spec['card'],
            'cvv' => $spec['cvv'],
            'expiration_month' => $spec['month'],
            'expiration_year' => $spec['year'],
            'email' => $email,
        ]);
        $token = $tokenResult->body['id'] ?? null;
        if (! $tokenResult->successful() || ! is_string($token)) {
            throw new RuntimeException('No se pudo tokenizar la tarjeta de prueba (HTTP '.$tokenResult->status.', '.($tokenResult->errorCode() ?? $tokenResult->transportError ?? 'sin código').'). La API de tokens directa requiere habilitación del comercio; usa Culqi.js/Checkout para producción.');
        }

        try {
            $provider->createPaymentAttempt($recharge, new CardPaymentInstrument(token: $token, paymentMethodId: 'visa'));
        } catch (RuntimeException $e) {
            $this->warn("[{$name}] el proveedor devolvió: ".Str::limit($e->getMessage(), 160));
        }
        unset($token);

        $order = $recharge->fresh(['latestPaymentOrder'])->latestPaymentOrder;
        if (! $order instanceof PaymentOrder) {
            throw new RuntimeException('No se creó ningún PaymentOrder.');
        }

        $outcome1 = $order->provider_order_id ? $reconciler->reconcile($order->fresh()) : 'sin-id';
        $outcome2 = $order->provider_order_id ? $reconciler->reconcile($order->fresh()) : 'sin-id';

        $webhookNote = '-';
        if (! $this->option('skip-webhook') && $order->provider_order_id) {
            $webhookNote = $this->exerciseWebhook($order->fresh());
        }

        $refundNote = '';
        if (($spec['refund'] ?? false) && $order->fresh()->status === 'paid') {
            $refund = $client->createRefund([
                'amount' => $order->amount_minor,
                'charge_id' => (string) $order->provider_order_id,
                'reason' => 'solicitud_comprador',
            ]);
            $refundNote = $refund->successful() ? 'devolución creada' : 'devolución FALLÓ (HTTP '.$refund->status.')';
            $outcome2 = $reconciler->reconcile($order->fresh());
            $outcome2 .= ' ('.$refundNote.')';
        }

        $order = $order->fresh();
        $deposits = CreditTransaction::where('teacher_profile_id', $teacher->id)->where('type', 'deposit')->count();
        $credits = (int) $teacher->fresh()->credits_available;
        $reversed = (bool) ($spec['refund'] ?? false);
        $expectedCredits = $spec['expect_credit'] && ! $reversed ? (int) $recharge->credits : 0;
        $expectedDeposits = $spec['expect_credit'] ? 1 : 0;

        $ok = $deposits === $expectedDeposits && $credits === $expectedCredits
            && ($spec['expect_credit'] ? $order->status === 'paid' : $order->status === 'failed')
            && (! $reversed || $recharge->fresh()->status === 'reversed');
        $this->allOk = $this->allOk && $ok;

        $this->rows[] = [
            $name,
            "{$order->status} / ".($order->submission_status ?? '-'),
            "{$outcome1} → {$outcome2}",
            "{$deposits} (esperado {$expectedDeposits})",
            "{$credits} (esperado {$expectedCredits})",
            $webhookNote,
            $ok ? 'OK' : 'FALLÓ',
        ];
    }

    private function makeRecharge(): RechargeRequest
    {
        $amountPen = '5.00';
        $maxMinor = (int) config('payments.culqi.sandbox_max_amount_minor', 1000);
        if ((int) round(((float) $amountPen) * 100) > $maxMinor) {
            throw new RuntimeException("El importe de la sonda supera CULQI_SANDBOX_MAX_AMOUNT_MINOR ({$maxMinor}).");
        }

        // Correo sintético único por ejecución: nunca se toca (ni renombra) a un usuario existente.
        $email = (string) config('payments.culqi.sandbox_payer_email_local', 'sandbox-smoke').'+'.Str::lower(Str::random(10)).'@example.com';

        return DB::transaction(function () use ($email, $amountPen) {
            $user = User::create([
                'name' => 'Sandbox Smoke Culqi',
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                'email_verified_at' => now(),
            ]);
            Role::findOrCreate('teacher');
            $user->assignRole('teacher');

            $profile = TeacherProfile::create([
                'user_id' => $user->id,
                'hourly_rate' => 20,
                'is_verified' => true,
                'bio' => 'Perfil sintético de la sonda de sandbox de Culqi.',
            ]);

            return RechargeRequest::create([
                'teacher_profile_id' => $profile->id,
                'package_code' => 'sandbox_smoke',
                'package_name' => 'Sonda sandbox Culqi',
                'credits' => 1,
                'amount_pen' => $amountPen,
                'payment_method' => 'culqi',
                'operation_number' => null,
                'operation_number_normalized' => null,
                'status' => 'pending',
            ]);
        });
    }

    /** Envía el mismo evento dos veces al endpoint real: debe procesarse una sola vez. */
    private function exerciseWebhook(PaymentOrder $order): string
    {
        $secret = (string) config('payments.culqi.webhook_secret');
        if ($secret === '') {
            return 'OMITIDO (sin CULQI_WEBHOOK_SECRET)';
        }

        config(['payments.culqi.webhooks_enabled' => true, 'queue.default' => 'sync']);

        $eventId = 'evt_test_smoke_'.Str::lower(Str::random(12));
        $body = json_encode([
            'object' => 'event',
            'id' => $eventId,
            'type' => 'charge.creation.succeeded',
            'creation_date' => time(),
            'data' => json_encode(['id' => $order->provider_order_id, 'object' => 'charge']),
        ], JSON_UNESCAPED_SLASHES);

        $codes = [];
        foreach ([1, 2] as $_) {
            $request = Request::create('/api/webhooks/culqi/'.$secret, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
            $codes[] = app(HttpKernel::class)->handle($request)->getStatusCode();
        }

        $rows = PaymentWebhook::where('provider', 'culqi')->where('event_id', $eventId)->count();

        return implode(' / ', $codes)." ({$rows} fila)";
    }
}
