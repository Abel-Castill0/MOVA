<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Contracts\CardPaymentInstrument;
use App\Payment\MercadoPagoPaymentProvider;
use App\Services\MercadoPagoPaymentReconciliationService;
use App\Support\QaDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * SANDBOX REAL de Mercado Pago, de punta a punta por el backend de MOVA.
 *
 * Distinto de la suite de PHPUnit (HTTP simulado): este comando llama a la API
 * REAL de Mercado Pago con credenciales de PRUEBA, usando exactamente las
 * mismas clases que el checkout (MercadoPagoPaymentProvider,
 * MercadoPagoPaymentReconciliationService, PaymentWebhookService vía el
 * endpoint real) y comprueba el ledger de MOVA.
 *
 * Seguridad (todas fallan cerrado, antes de cualquier llamada de red):
 *   - solo APP_ENV local|testing y solo una BD QA (sqlite, o mysql_qa/mova_qa);
 *   - exige MERCADOPAGO_EXPECTED_LIVE_MODE=false explícito;
 *   - aborta si CUALQUIER respuesta de Mercado Pago dice live_mode=true;
 *   - los pagos usan tarjetas de PRUEBA publicadas por Mercado Pago y un payer
 *     sintético (test_payer@example.com); nunca dinero real, nunca correos a
 *     usuarios reales.
 *
 * Nunca imprime access token, public key, webhook secret ni tokens de tarjeta:
 * solo estados y conteos. Las credenciales se leen de config()/env() — se
 * cargan fuera del repositorio (ver scripts/sandbox-smoke.sh).
 *
 * Lo que NO prueba (y no debe presentarse como probado): que Mercado Pago
 * entregue webhooks reales a una URL pública (los pagos TEST no los disparan
 * solos — usar el simulador del panel), ni un desafío 3DS interactivo
 * (requiere navegador), ni producción.
 */
class MercadoPagoSandboxSmoke extends Command
{
    protected $signature = 'mercadopago:sandbox-smoke
        {--scenario=* : approved | rejected | pending (por defecto: approved y rejected)}
        {--skip-webhook : no ejercitar el endpoint del webhook firmado}';

    protected $description = 'QA: pago de PRUEBA real contra el sandbox de Mercado Pago a través del backend de MOVA y verificación del ledger (no usar en producción).';

    /** Tarjetas y titulares de PRUEBA publicados por Mercado Pago (Perú). */
    private const TEST_CARD = ['number' => '5031755734530604', 'cvv' => '123', 'month' => 11, 'year' => 2030, 'method' => 'master'];

    private const DEFAULT_PAYER_EMAIL = 'test_payer@example.com';

    private const SCENARIOS = [
        'approved' => ['holder' => 'APRO', 'expect_credit' => true],
        'rejected' => ['holder' => 'OTHE', 'expect_credit' => false],
        'pending' => ['holder' => 'CONT', 'expect_credit' => false],
    ];

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private bool $allOk = true;

    public function handle(MercadoPagoPaymentProvider $provider, MercadoPagoPaymentReconciliationService $reconciler): int
    {
        try {
            $this->assertSafeToRun();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Pre-flight: autenticación y conectividad con Mercado Pago (GET /v1/payment_methods)…');
        if ($provider->fetchPaymentMethods() === null) {
            $this->error('PRE-FLIGHT FALLÓ: Mercado Pago no aceptó el access token de prueba (o la API no responde). Revisa las credenciales de prueba; no se creó ningún pago.');

            return self::FAILURE;
        }

        $scenarios = $this->option('scenario') ?: ['approved', 'rejected'];
        foreach ($scenarios as $name) {
            if (! isset(self::SCENARIOS[$name])) {
                $this->error("Escenario desconocido [{$name}]. Usa: approved, rejected, pending.");

                return self::FAILURE;
            }
        }

        foreach ($scenarios as $name) {
            try {
                $this->runScenario($name, $provider, $reconciler);
            } catch (Throwable $e) {
                $this->allOk = false;
                $this->rows[] = [$name, 'ERROR', get_class($e), '-', '-', '-', '-'];
                $this->error("[{$name}] excepción: ".get_class($e).' — '.Str::limit($e->getMessage(), 160));
            }
        }

        $this->newLine();
        $this->table(
            ['escenario', 'estado MP/orden', 'conciliación', 'depósitos', 'créditos', 'webhook x1 / replay', 'veredicto'],
            $this->rows
        );

        $this->line('Alcance: sandbox real vía backend. NO prueba webhooks reales de Mercado Pago a una URL pública, ni 3DS interactivo, ni producción.');

        return $this->allOk ? self::SUCCESS : self::FAILURE;
    }

    private function assertSafeToRun(): void
    {
        QaDatabaseGuard::assertSafeEnvironment();

        $connection = (string) config('database.default');
        if ($connection === 'mysql_qa') {
            QaDatabaseGuard::assertDatabase('mysql_qa', 'mova_qa');
        } elseif ($connection !== 'sqlite') {
            throw new RuntimeException("MOVA QA GUARD: la conexión [{$connection}] no es una BD QA (sqlite o mysql_qa). Abortado.");
        }

        foreach (['access_token' => 'MERCADOPAGO_ACCESS_TOKEN', 'public_key' => 'MERCADOPAGO_PUBLIC_KEY'] as $key => $env) {
            if (! config("payments.mercadopago.{$key}")) {
                throw new RuntimeException("Falta {$env} (credencial de PRUEBA). Cárgala en el archivo de credenciales sandbox local — ver scripts/sandbox-smoke.sh.");
            }
        }

        // env('X') devuelve el booleano false para "false": normalizar antes de comparar.
        $rawLive = config('payments.mercadopago.expected_live_mode');
        $live = $rawLive === false ? 'false' : strtolower(trim((string) $rawLive));
        if ($live !== 'false') {
            throw new RuntimeException('MERCADOPAGO_EXPECTED_LIVE_MODE debe ser exactamente "false" para esta sonda. Abortado antes de cualquier llamada.');
        }
    }

    private function runScenario(string $name, MercadoPagoPaymentProvider $provider, MercadoPagoPaymentReconciliationService $reconciler): void
    {
        $spec = self::SCENARIOS[$name];

        $recharge = $this->makeRecharge();
        $teacher = $recharge->teacherProfile;

        $token = $this->tokenizeTestCard($spec['holder']);
        $instrument = new CardPaymentInstrument(
            token: $token,
            paymentMethodId: self::TEST_CARD['method'],
            installments: 1,
            identificationType: 'DNI',
            identificationNumber: '123456789',
        );
        unset($token);

        try {
            $provider->createPaymentAttempt($recharge, $instrument);
        } catch (RuntimeException $e) {
            // Igual que CreditCheckoutController::pay(): el estado definitivo
            // quedó persistido en PaymentOrder antes de lanzar. El mensaje es el
            // que ve el cliente (sin secretos ni payloads del proveedor).
            $this->warn("[{$name}] el proveedor devolvió: ".$e->getMessage());
        }

        $order = $recharge->fresh(['latestPaymentOrder'])->latestPaymentOrder;
        if (! $order instanceof PaymentOrder) {
            throw new RuntimeException('No se creó ningún PaymentOrder.');
        }

        $this->assertNotLiveMode($order);

        // Conciliación explícita (lo que hace refresh()/el webhook): dos veces,
        // para demostrar idempotencia del abono.
        $outcome1 = $order->provider_order_id ? $reconciler->reconcile($order->fresh(), null, $provider) : 'sin-id';
        $outcome2 = $order->provider_order_id ? $reconciler->reconcile($order->fresh(), null, $provider) : 'sin-id';

        $webhookNote = '-';
        if (! $this->option('skip-webhook') && $order->provider_order_id) {
            $webhookNote = $this->exerciseSignedWebhook((string) $order->fresh()->provider_order_id);
        }

        $order = $order->fresh();
        $deposits = CreditTransaction::where('teacher_profile_id', $teacher->id)->where('type', 'deposit')->count();
        $credits = (int) $teacher->fresh()->credits_available;
        $expectedCredits = $spec['expect_credit'] ? (int) $recharge->credits : 0;
        $expectedDeposits = $spec['expect_credit'] ? 1 : 0;

        $ok = $deposits === $expectedDeposits && $credits === $expectedCredits;
        // Pendiente: un estado final distinto de pending sería inesperado (CONT no resuelve solo).
        if ($name === 'pending' && ! in_array($order->status, ['pending', 'processing', 'review'], true)) {
            $ok = $ok && false;
        }
        $this->allOk = $this->allOk && $ok;

        $this->rows[] = [
            $name,
            (string) $order->status.' / '.(string) $order->submission_status,
            "{$outcome1} → {$outcome2}",
            "{$deposits} (esperado {$expectedDeposits})",
            "{$credits} (esperado {$expectedCredits})",
            $webhookNote,
            $ok ? 'OK' : 'FALLÓ',
        ];
    }

    private function makeRecharge(): RechargeRequest
    {
        $packages = (array) config('credits.packages');
        $code = (string) array_key_first($packages);
        $package = $packages[$code];

        // payer SINTÉTICO de la sonda. En POST /v1/payments el sandbox rechaza un correo
        // @testuser.com con 403 "Payer email forbidden" (4390, observado el 2026-10-05); un
        // correo arbitrario válido es lo que documenta Mercado Pago. MOVA usa el correo del
        // profesor como payer, así que solo este usuario sintético (BD temporal) lo lleva.
        // MERCADOPAGO_SANDBOX_PAYER_EMAIL permite otro valor; nunca un usuario real.
        $payerEmail = trim((string) env('MERCADOPAGO_SANDBOX_PAYER_EMAIL', '')) ?: self::DEFAULT_PAYER_EMAIL;

        return DB::transaction(function () use ($code, $package, $payerEmail) {
            // Una BD temporal por corrida: el escenario anterior libera el correo del payer.
            User::where('email', $payerEmail)->update(['email' => 'retired_'.Str::lower(Str::random(10)).'@example.invalid']);
            // MOVA no envía correo desde esta sonda (MAIL_MAILER=array).
            $user = User::create([
                'name' => 'Sandbox Smoke',
                'email' => $payerEmail,
                'password' => bcrypt(Str::random(32)),
                'email_verified_at' => now(),
            ]);
            Role::findOrCreate('teacher');
            $user->assignRole('teacher');

            $profile = TeacherProfile::create([
                'user_id' => $user->id,
                'hourly_rate' => 20,
                'is_verified' => true,
                'bio' => 'Perfil sintético de la sonda de sandbox.',
            ]);

            return RechargeRequest::create([
                'teacher_profile_id' => $profile->id,
                'package_code' => $code,
                'package_name' => $package['name'],
                'credits' => $package['credits'],
                'amount_pen' => $package['amount_pen'],
                'payment_method' => 'mercadopago',
                'operation_number' => null,
                'operation_number_normalized' => null,
                'status' => 'pending',
            ]);
        });
    }

    /** Tokeniza una tarjeta de PRUEBA con la public key de prueba (nunca se imprime el token). */
    private function tokenizeTestCard(string $holder): string
    {
        $response = Http::timeout(20)->acceptJson()->post(
            rtrim((string) config('payments.mercadopago.base_url'), '/').'/v1/card_tokens?public_key='.urlencode((string) config('payments.mercadopago.public_key')),
            [
                'card_number' => self::TEST_CARD['number'],
                'security_code' => self::TEST_CARD['cvv'],
                'expiration_month' => self::TEST_CARD['month'],
                'expiration_year' => self::TEST_CARD['year'],
                'cardholder' => [
                    'name' => $holder,
                    'identification' => ['type' => 'DNI', 'number' => '123456789'],
                ],
            ]
        );

        $id = $response->json('id');
        if (! $response->successful() || ! is_string($id) || $id === '') {
            throw new RuntimeException('No se pudo tokenizar la tarjeta de prueba (HTTP '.$response->status().'). Revisa que MERCADOPAGO_PUBLIC_KEY sea la de PRUEBA de la misma aplicación.');
        }

        return $id;
    }

    /**
     * Lee el pago real y aborta si Mercado Pago declara live_mode=true. El
     * resto de la lógica ya exige EXPECTED_LIVE_MODE=false, pero esto confirma
     * contra la propia respuesta de Mercado Pago, no contra nuestra config.
     */
    private function assertNotLiveMode(PaymentOrder $order): void
    {
        $paymentId = (string) ($order->fresh()->provider_order_id ?? '');
        if ($paymentId === '') {
            return; // el intento no llegó a crear un pago (p. ej. rechazo de tokenización)
        }

        $response = Http::timeout(20)->acceptJson()
            ->withToken((string) config('payments.mercadopago.access_token'))
            ->get(rtrim((string) config('payments.mercadopago.base_url'), '/').'/v1/payments/'.urlencode($paymentId));

        if ($response->json('live_mode') === true) {
            throw new RuntimeException('Mercado Pago respondió live_mode=true: ESTA SONDA NO DEBE CORRER CON CREDENCIALES REALES. Abortada.');
        }
    }

    /**
     * Envía al endpoint REAL de MOVA una notificación firmada con el algoritmo
     * documentado (id:{data.id};request-id:{x-request-id};ts:{ts};), dos veces.
     * Valida que MOVA acepte una firma bien formada y que el replay no duplique
     * nada. No demuestra que Mercado Pago entregue webhooks a una URL pública.
     */
    private function exerciseSignedWebhook(string $paymentId): string
    {
        $secret = (string) config('payments.mercadopago.webhook_secret');
        if ($secret === '' || ! config('payments.mercadopago.webhooks_enabled')) {
            return 'OMITIDO (sin MERCADOPAGO_WEBHOOK_SECRET o webhooks desactivados)';
        }

        $codes = [];
        foreach ([1, 2] as $_) {
            $ts = (string) (int) (microtime(true) * 1000);
            $requestId = (string) Str::uuid();
            $signature = 'ts='.$ts.',v1='.hash_hmac('sha256', 'id:'.strtolower($paymentId).';request-id:'.$requestId.';ts:'.$ts.';', $secret);

            $body = json_encode([
                'id' => random_int(100000000, 999999999),
                'type' => 'payment',
                'action' => 'payment.updated',
                'live_mode' => false,
                'api_version' => 'v1',
                'application_id' => config('payments.mercadopago.application_id'),
                'user_id' => config('payments.mercadopago.expected_collector_id'),
                'date_created' => now()->toIso8601String(),
                'data' => ['id' => $paymentId],
            ], JSON_UNESCAPED_SLASHES);

            $request = Request::create(
                '/api/webhooks/mercadopago?data.id='.urlencode($paymentId),
                'POST',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_SIGNATURE' => $signature,
                    'HTTP_X_REQUEST_ID' => $requestId,
                ],
                $body
            );

            $codes[] = app(HttpKernel::class)->handle($request)->getStatusCode();
        }

        return implode(' / ', $codes);
    }
}
