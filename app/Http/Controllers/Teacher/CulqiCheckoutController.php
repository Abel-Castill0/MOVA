<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\Culqi\CulqiClient;
use App\Payment\Culqi\CulqiConfig;
use App\Payment\Culqi\CulqiTokenInstrument;
use App\Payment\CulqiPaymentProvider;
use App\Services\CulqiPaymentReconciliationService;
use App\Support\CheckoutProvider;
use App\Support\CreditPackages;
use App\Payment\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/**
 * Frontera de aplicación del checkout de Culqi (Custom Checkout + Culqi3DS) con el núcleo
 * financiero. Se alcanza por las MISMAS rutas del checkout de Mercado Pago
 * (`teacher.credits.checkout.*`): CreditCheckoutController delega aquí cuando la recarga es
 * `payment_method=culqi`, de modo que el flujo de Mercado Pago no cambia.
 *
 * Este controlador NUNCA decide monto, créditos, moneda, referencia ni correo del pagador:
 * todo sale de la RechargeRequest en el servidor. Del navegador solo acepta `package_code` (al
 * crear la recarga, contra el catálogo), el `token` ya tokenizado por Culqi (nunca PAN/CVV), el
 * `device_fingerprint_id` de Culqi3DS y los `parameters3DS` que devuelve el banco. Cualquier
 * campo de importe/créditos/moneda/tarjeta en el request se rechaza con 422.
 *
 * El navegador solo muestra "aprobado" cuando `credits_credited` es true, y eso es SIEMPRE
 * `recharge.status === 'approved'` (el ledger), nunca el estado de Culqi ni el del frontend.
 */
class CulqiCheckoutController extends Controller
{
    /** Piso entre dos conciliaciones on-demand del mismo intento (el polling llama cada pocos segundos). */
    private const MIN_SECONDS_BETWEEN_RECONCILE_POLLS = 3;

    /** Piso entre dos búsquedas de un intento incierto: consumen un presupuesto finito. */
    private const MIN_SECONDS_BETWEEN_UNCERTAIN_SEARCH_POLLS = 15;

    /** Campos que el navegador NUNCA decide ni envía (monto/créditos/moneda/tarjeta cruda). */
    private const FORBIDDEN_FIELDS = [
        'amount', 'amount_minor', 'amount_pen', 'credits', 'currency', 'currency_code', 'price', 'package_code', 'status',
        'card_number', 'cardNumber', 'cvv', 'cvc', 'security_code', 'securityCode',
        'expiration_month', 'expirationMonth', 'expiration_year', 'expirationYear', 'expiration_date', 'expirationDate',
        'otp', 'number_phone',
    ];

    public function store(Request $request)
    {
        $teacherProfile = $request->user()->teacherProfile;
        abort_unless($teacherProfile, 403, 'No tienes perfil de profesor.');
        abort_unless(CheckoutProvider::culqiEnabled($request->user()), 503, 'El pago automático no está disponible por ahora.');

        $packages = CreditPackages::forCheckout($request->user());
        $data = $request->validate(['package_code' => ['required', 'string', Rule::in(array_keys($packages))]]);
        $package = $packages[$data['package_code']];

        // operation_number queda NULL a propósito (igual que Mercado Pago): la identidad del pago vive en PaymentOrder.
        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $teacherProfile->id,
            'package_code' => $data['package_code'],
            'package_name' => $package['name'],
            'credits' => $package['credits'],
            'amount_pen' => $package['amount_pen'],
            'payment_method' => CulqiPaymentProvider::PROVIDER,
            'operation_number' => null,
            'operation_number_normalized' => null,
            'status' => 'pending',
        ]);

        return redirect()->route('teacher.credits.checkout.show', $recharge);
    }

    public function show(RechargeRequest $recharge)
    {
        $this->authorize('view', $recharge);
        abort_unless($recharge->payment_method === CulqiPaymentProvider::PROVIDER, 404);

        $recharge->load('teacherProfile.user', 'latestPaymentOrder');
        $enabled = CheckoutProvider::culqiEnabled(auth()->user());

        return Inertia::render('Teacher/Credits/CheckoutCulqi', [
            'recharge' => [
                'id' => $recharge->id,
                'package_name' => $recharge->package_name,
                'credits' => $recharge->credits,
                'amount_pen' => $recharge->amount_pen,
                'amount_minor' => Money::solesToMinor((string) $recharge->amount_pen),
            ],
            'teacherProfile' => ['credits_available' => $recharge->teacherProfile->credits_available],
            'currency' => 'S/',
            'checkoutEnabled' => $enabled,
            // SOLO la llave pública llega al navegador (la secreta nunca sale del servidor).
            'culqi' => $enabled ? [
                'publicKey' => CulqiConfig::publicKey(),
                'mode' => CulqiConfig::declaredEnv(),
                'methods' => CheckoutProvider::culqiMethods(),
                'email' => $recharge->teacherProfile->user->email,
            ] : null,
            'initialStatus' => $this->safeStatus($recharge, $recharge->latestPaymentOrder),
        ]);
    }

    /** Recibe el token (tarjeta o Yape) ya tokenizado por Culqi y crea el cargo; nunca lee importes del request. */
    public function pay(Request $request, RechargeRequest $recharge)
    {
        $this->guard($request, $recharge);

        $data = $request->validate($this->forbidden() + [
            'token' => ['required', 'string', 'max:64', 'regex:/^(tkn|ype)_'.CulqiConfig::declaredEnv().'_[A-Za-z0-9]{8,}$/'],
            'device_fingerprint_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9a-fA-F-]{16,64}$/'],
        ]);

        $channel = str_starts_with($data['token'], 'ype_') ? 'yape' : 'card';
        abort_unless(in_array($channel, CheckoutProvider::culqiMethods(), true), 422, 'Ese medio de pago no está disponible por ahora.');

        $this->run($recharge, fn (CulqiPaymentProvider $provider) => $provider->createPaymentAttempt(
            $recharge,
            new CulqiTokenInstrument($data['token'], $data['device_fingerprint_id'] ?? null)
        ));

        return $this->respond($recharge);
    }

    /** Segundo paso de 3DS: el banco autenticó al titular en el navegador; se repite el cargo con los parámetros 3DS. */
    public function threeDs(Request $request, RechargeRequest $recharge)
    {
        $this->guard($request, $recharge);

        $data = $request->validate($this->forbidden() + [
            'token' => ['required', 'string', 'max:64', 'regex:/^tkn_'.CulqiConfig::declaredEnv().'_[A-Za-z0-9]{8,}$/'],
            'device_fingerprint_id' => ['required', 'string', 'max:64', 'regex:/^[0-9a-fA-F-]{16,64}$/'],
            'parameters3DS' => ['required', 'array'],
            'parameters3DS.eci' => ['required', 'string', 'regex:/^\d{2}$/'],
            'parameters3DS.xid' => ['required', 'string', 'max:128'],
            'parameters3DS.cavv' => ['required', 'string', 'max:128'],
            'parameters3DS.protocolVersion' => ['required', 'string', 'regex:/^\d\.\d\.\d$/'],
            'parameters3DS.directoryServerTransactionId' => ['nullable', 'string', 'max:64'],
        ]);

        $this->run($recharge, fn (CulqiPaymentProvider $provider) => $provider->completeThreeDs(
            $recharge,
            new CulqiTokenInstrument($data['token'], $data['device_fingerprint_id'], $data['parameters3DS'])
        ));

        return $this->respond($recharge);
    }

    /** El titular canceló o falló el reto 3DS: no hay cargo; se cierra el intento para poder reintentar. */
    public function cancelThreeDs(Request $request, RechargeRequest $recharge)
    {
        $this->guard($request, $recharge);

        $data = $request->validate($this->forbidden() + ['reason' => ['required', Rule::in(['cancelled', 'failed'])]]);

        $this->run($recharge, fn (CulqiPaymentProvider $provider) => $provider->abandonThreeDs($recharge, '3ds_'.$data['reason']));

        return $this->respond($recharge);
    }

    /** Crea la orden de pago (PagoEfectivo/billeteras/…): NO acredita; el pago llega por webhook/conciliación. */
    public function order(Request $request, RechargeRequest $recharge)
    {
        $this->guard($request, $recharge);
        $request->validate($this->forbidden());
        abort_unless(in_array('order', CheckoutProvider::culqiMethods(), true), 422, 'Las órdenes de pago no están disponibles por ahora.');

        // Culqi exige un celular del pagador para crear la orden: se avisa ANTES de crear ningún intento.
        $phone = preg_replace('/\D+/', '', (string) $recharge->loadMissing('teacherProfile.user')->teacherProfile?->user?->phone) ?? '';
        abort_unless(strlen($phone) >= 9, 422, 'Registra tu celular en tu perfil para pagar en efectivo o con billetera: Culqi lo exige para generar la orden.');

        $teacherLock = Cache::lock('culqi-qr:teacher:'.$recharge->teacher_profile_id, 30);
        abort_unless($teacherLock->get(), 409, 'Ya estamos generando una orden de pago para tu cuenta. Espera unos segundos.');
        try {
            $other = $this->otherLiveOrder($recharge);
            if ($other !== null) {
                return $this->activeOrderConflict($other);
            }

            $this->run($recharge, fn (CulqiPaymentProvider $provider) => $provider->createOrderAttempt($recharge));
        } finally {
            $teacherLock->release();
        }

        $this->remoteOrderDetails = true;

        return $this->respond($recharge);
    }

    /** Lectura PURA: jamás reconcilia ni muta estado financiero. */
    public function status(RechargeRequest $recharge)
    {
        $this->authorize('view', $recharge);
        abort_unless($recharge->payment_method === CulqiPaymentProvider::PROVIDER, 404);

        return $this->respond($recharge);
    }

    /** Conciliación EXPLÍCITA (POST) del intento más reciente; es lo que llama el polling del frontend. */
    public function refresh(RechargeRequest $recharge)
    {
        $this->authorize('view', $recharge);
        abort_unless($recharge->payment_method === CulqiPaymentProvider::PROVIDER, 404);

        $order = $recharge->latestPaymentOrder;
        // Reconciliar lo ya creado no depende de la allowlist ni de que Culqi siga siendo el proveedor de compras nuevas.
        if ($order !== null && CheckoutProvider::culqiLegacyProcessing()) {
            $this->reconcileOnDemand($recharge, $order);
        }

        $this->remoteOrderDetails = true;

        return $this->respond($recharge);
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function guard(Request $request, RechargeRequest $recharge): void
    {
        $this->authorize('pay', $recharge);
        abort_unless($recharge->payment_method === CulqiPaymentProvider::PROVIDER, 404);
        abort_unless(CheckoutProvider::culqiEnabled($request->user()), 503, 'El pago automático no está disponible por ahora.');
    }

    /** @return array<string, string> reglas `prohibited` para cada campo que el navegador no debe decidir */
    private function forbidden(): array
    {
        return array_fill_keys(self::FORBIDDEN_FIELDS, 'prohibited');
    }

    /** Ejecuta una operación del proveedor; un RuntimeException (incierto/rechazo/estado) no rompe la pantalla. */
    private function run(RechargeRequest $recharge, callable $operation): void
    {
        try {
            $operation(app(CulqiPaymentProvider::class));
        } catch (QueryException $e) {
            // Un fallo de base de datos no es «la operación no avanzó»: su mensaje lleva SQL y valores.
            throw $e;
        } catch (RuntimeException $e) {
            Log::info('[CulqiCheckout] La operación no avanzó en este ciclo.', [
                'recharge_request_id' => $recharge->id,
                'motivo' => $e->getMessage(),
            ]);
        }
    }

    /** Solo los endpoints POST (order, refresh) leen la orden en Culqi para mostrar su QR/CIP; los GET no hacen red. */
    private bool $remoteOrderDetails = false;

    private function respond(RechargeRequest $recharge, ?string $notice = null)
    {
        $recharge = $recharge->fresh(['latestPaymentOrder', 'teacherProfile.user']);

        return response()->json($this->safeStatus($recharge, $recharge->latestPaymentOrder) + ['notice' => $notice])
            ->header('Cache-Control', 'no-store, private');
    }

    private function reconcileOnDemand(RechargeRequest $recharge, PaymentOrder $order): void
    {
        if ($order->status !== 'pending') {
            return;
        }

        $reconciler = app(CulqiPaymentReconciliationService::class);

        try {
            if ($order->submission_status === 'awaiting_3ds') {
                // Sin cargo en Culqi: vencida la ventana, el intento se cierra y el usuario puede reintentar.
                if ($order->three_ds_expires_at !== null && $order->three_ds_expires_at->isPast()) {
                    app(CulqiPaymentProvider::class)->abandonThreeDs($recharge, '3ds_expired');
                }

                return;
            }

            if ($order->provider_order_id === null && in_array($order->submission_status, ['submitting', 'uncertain'], true)) {
                if ($order->updated_at !== null && $order->updated_at->gt(now()->subSeconds(self::MIN_SECONDS_BETWEEN_UNCERTAIN_SEARCH_POLLS))) {
                    return;
                }

                $reconciler->reconcileUncertainSubmission($order);

                return;
            }

            if ($order->provider_order_id !== null) {
                if ($order->last_verified_at !== null && $order->last_verified_at->gt(now()->subSeconds(self::MIN_SECONDS_BETWEEN_RECONCILE_POLLS))) {
                    return;
                }

                $reconciler->reconcile($order);
            }
        } catch (RuntimeException $e) {
            // fail closed silencioso: el polling sigue mostrando el último estado seguro conocido.
            Log::warning('[CulqiCheckout] Reconciliación on-demand no pudo completarse.', [
                'recharge_request_id' => $recharge->id,
                'payment_order_id' => $order->id,
                'motivo' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Vocabulario canónico del checkout (idle | pending | uncertain | review | approved | failed), igual que
     * el de Mercado Pago, para que el frontend reutilice el polling. `credits_credited` es SIEMPRE
     * `recharge.status === 'approved'`: la única señal autoritativa es el ledger.
     *
     * @return array{status:string,message:string,credits_credited:bool,action_required:?array<string,mixed>}
     */
    private function safeStatus(RechargeRequest $recharge, ?PaymentOrder $order): array
    {
        $uncertain = 'Estamos verificando tu pago. No vuelvas a pagar mientras termina la verificación.';

        if ($recharge->status === 'approved') {
            return $this->payload('approved', '¡Pago aprobado! Tus créditos ya están disponibles.', true);
        }

        if ($recharge->status === 'reversed') {
            return $this->payload('failed', 'Este pago fue devuelto. Si lo necesitas, inicia una recarga nueva.', false);
        }

        if ($order === null) {
            return $this->payload('idle', 'Todavía no se ha iniciado ningún intento de pago para esta recarga.', false);
        }

        if (in_array($order->submission_status, ['submitting', 'uncertain'], true)) {
            return $this->payload('uncertain', $uncertain, false);
        }

        if ($order->review_reason !== null) {
            return $this->payload('review', $uncertain, false);
        }

        if ($order->submission_status === 'awaiting_3ds') {
            if ($order->three_ds_expires_at === null || $order->three_ds_expires_at->isPast()) {
                return $this->payload('failed', 'La verificación de tu banco venció y no se te cobró nada. Puedes intentarlo de nuevo.', false);
            }

            $user = $recharge->teacherProfile?->user;

            return $this->payload('pending', 'Tu banco pide una verificación adicional (3D Secure). Complétala sin cerrar esta pantalla.', false, [
                'type' => '3ds',
                'amount_minor' => $order->amount_minor,
                'email' => $user?->email,
                'expires_at' => $order->three_ds_expires_at->toIso8601String(),
            ]);
        }

        if ($order->status === 'failed') {
            return $this->payload('failed', $this->failureMessage($order), false);
        }

        if (in_array($order->status, ['expired', 'cancelled'], true)) {
            return $this->payload('failed', 'La orden de pago venció sin recibir el pago. Puedes iniciar otra.', false);
        }

        if ($order->status === 'pending' && $order->payment_channel === CulqiPaymentProvider::CHANNEL_ORDER && $order->provider_order_id !== null) {
            // Una orden que el checkout de Culqi YA confirmó (tiene CIP/QR) no puede volver a abrirse en el checkout: Culqi
            // responde CCKT-400 («problemas al intentar validar tu compra»). En ese caso MOVA muestra el QR y el CIP vigentes,
            // leídos de la API (solo lectura), y el profesor sigue con ESA orden: nunca se crea otra mientras esté pendiente.
            $details = $this->orderPaymentDetails($order);

            return $this->payload('pending', $details['confirmed'] === true
                ? 'Tu orden de pago está lista. Paga con el QR o el código CIP de abajo; tus créditos se acreditan automáticamente cuando Culqi confirme el pago.'
                : 'Tu orden de pago está creada. Págala con el código o QR del checkout; tus créditos se acreditan automáticamente cuando Culqi confirme el pago.', false, [
                'type' => 'order',
                'order_id' => $order->provider_order_id,
                'amount_minor' => $order->amount_minor,
                'expires_at' => $order->expires_at?->toIso8601String(),
            ] + $details);
        }

        // 'paid' local sin recarga aprobada todavía NO es aprobado (el ledger manda).
        return $this->payload('pending', $uncertain, false);
    }

    /**
     * Datos de pago de una orden pendiente, leídos de Culqi (GET, sin efectos) y cacheados brevemente para no consultar
     * en cada sondeo. `confirmed`: true si Culqi ya generó el CIP (el checkout ya no la acepta), false si aún no, null si no se
     * pudo saber (la pantalla no reabre el checkout a ciegas). El QR solo se acepta del dominio oficial de PagoEfectivo.
     *
     * @return array{confirmed: ?bool, cip: ?string, qr_url: ?string}
     */
    private function orderPaymentDetails(PaymentOrder $order): array
    {
        $unknown = ['confirmed' => null, 'cip' => null, 'qr_url' => null];
        if (CulqiConfig::secretKey() === null) {
            return $unknown;
        }

        $key = 'culqi-order-details:'.$order->provider_order_id;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if (! $this->remoteOrderDetails) {
            return $unknown; // GET: lectura pura, sin llamar a Culqi (el sondeo POST lo completa en segundos)
        }
        $details = (function () use ($order, $unknown) {
            try {
                $result = app(CulqiClient::class)->getOrder((string) $order->provider_order_id);
            } catch (\Throwable $e) {
                Log::info('[CulqiCheckout] No se pudo leer la orden para mostrar su QR/CIP.', ['payment_order_id' => $order->id, 'error' => class_basename($e)]);

                return $unknown;
            }
            $body = $result->body ?? [];
            if (! $result->successful() || ($body['id'] ?? null) !== $order->provider_order_id) {
                return $unknown;
            }
            $cip = preg_match('/^\d{6,20}$/', (string) ($body['payment_code'] ?? '')) ? (string) $body['payment_code'] : null;
            $qr = (string) ($body['qr'] ?? '');
            $host = strtolower((string) parse_url($qr, PHP_URL_HOST));
            // Sin «\», «@» ni usuario: PHP y el navegador podrían interpretar un host distinto (QR de phishing).
            $qrOk = str_starts_with($qr, 'https://') && filter_var($qr, FILTER_VALIDATE_URL) !== false
                && ! str_contains($qr, '\\') && ! str_contains($qr, '@') && parse_url($qr, PHP_URL_USER) === null
                && ($host === 'pagoefectivolatam.com' || str_ends_with($host, '.pagoefectivolatam.com'));

            return ['confirmed' => $cip !== null, 'cip' => $cip, 'qr_url' => $qrOk ? $qr : null];
        })();
        // Solo se cachea la orden confirmada (no vuelve atrás). Sin confirmar o desconocida puede cambiar en segundos (el
        // profesor elige «Billeteras móviles»): se vuelve a leer, para no ofrecer reabrir el checkout con una orden ya confirmada.
        if ($details['confirmed'] === true) {
            Cache::put($key, $details, 120);
        }

        return $details;
    }

    /**
     * «Generar nuevo QR». Primero concilia la orden actual contra Culqi:
     *   - pagada → se muestra el resultado (nunca se crea otra);
     *   - incierta / en revisión → «estamos verificando», sin nuevo intento;
     *   - pendiente y vigente → NO se crea otra: borrar una orden en Culqi no invalida su CIP/QR (eliminación lógica), así
     *     que ambas serían pagables. Se explica y se ofrecen el CIP y la hora de vencimiento;
     *   - vencida / cancelada / fallida → se crea una orden nueva por la vía normal (bloqueo de fila e idempotencia).
     */
    public function newQr(Request $request, RechargeRequest $recharge)
    {
        $this->guard($request, $recharge);
        $request->validate($this->forbidden());
        abort_unless(in_array('order', CheckoutProvider::culqiMethods(), true), 422, 'Las órdenes de pago no están disponibles por ahora.');

        $lock = Cache::lock('culqi-qr:teacher:'.$recharge->teacher_profile_id, 30);
        if (! $lock->get()) {
            return $this->respond($recharge, 'Ya estamos generando tu QR. Espera unos segundos.');
        }

        try {
            $order = $recharge->latestPaymentOrder;
            if ($order !== null && CheckoutProvider::culqiLegacyProcessing()) {
                $this->reconcileOnDemand($recharge, $order);
                $order = $order->fresh();
            }
            $recharge = $recharge->fresh();

            if ($recharge->status !== 'pending' || $order === null || $order->status === 'paid' || $order->review_reason !== null
                || in_array($order->submission_status, ['submitting', 'uncertain'], true)) {
                $this->remoteOrderDetails = true;

                return $this->respond($recharge);
            }

            if ($order->payment_channel === CulqiPaymentProvider::CHANNEL_ORDER
                && ($order->status === 'pending' || CulqiPaymentProvider::orderMayStillBePaid($order))) {
                $this->remoteOrderDetails = true;
                if ($order->expires_at !== null && $order->expires_at->isPast()) {
                    return $this->respond($recharge, 'Tu QR ya debería haber vencido, pero Culqi aún no lo confirma. Vuelve a intentarlo en unos minutos: no generamos otro hasta tener esa confirmación, para no cobrarte dos veces.');
                }
                $until = $order->expires_at?->timezone('America/Lima')->format('d/m H:i');

                return $this->respond($recharge, 'Tu QR actual sigue vigente'.($until ? " hasta las {$until}" : '').'. Por seguridad no generamos otro mientras siga vigente: el anterior seguiría pudiéndose pagar y se te cobraría dos veces. Si tu app no lo lee, prueba con el código CIP en tu banca móvil (servicio PagoEfectivo) o espera a que venza.');
            }

            if (! in_array($order->status, ['expired', 'cancelled', 'failed'], true)) {
                return $this->respond($recharge);
            }

            $other = $this->otherLiveOrder($recharge);
            if ($other !== null) {
                return $this->respond($recharge, 'Tienes otra orden con QR vigente (recarga #'.$other->recharge_request_id.'). Págala o espera a que venza antes de generar otra.');
            }

            $phone = preg_replace('/\D+/', '', (string) $recharge->loadMissing('teacherProfile.user')->teacherProfile?->user?->phone) ?? '';
            abort_unless(strlen($phone) >= 9, 422, 'Registra tu celular en tu perfil para pagar con billetera: Culqi lo exige para generar la orden.');

            $this->run($recharge, fn (CulqiPaymentProvider $provider) => $provider->createOrderAttempt($recharge));
            $this->remoteOrderDetails = true;

            return $this->respond($recharge);
        } finally {
            $lock->release();
        }
    }

    /**
     * 409 con los datos de la orden QR que sigue viva (del MISMO profesor: otherLiveOrder ya filtra por él), para que la
     * pantalla ofrezca «Ir a mi orden de pago activa». La vigencia es la que Culqi devolvió al crearla (expiration_date).
     */
    private function activeOrderConflict(PaymentOrder $other)
    {
        $until = $other->expires_at?->timezone('America/Lima');

        return response()->json([
            'message' => 'Ya tienes otra orden de pago con QR vigente'.($until ? ' hasta el '.$until->format('d/m \a \l\a\s H:i') : '').'. Págala ahí o espera a que venza antes de generar otra, para no pagar dos veces.',
            'active_order' => [
                'recharge_id' => $other->recharge_request_id,
                'expires_at' => $other->expires_at?->toIso8601String(),
                'url' => route('teacher.credits.checkout.show', $other->recharge_request_id),
            ],
        ], 409)->header('Cache-Control', 'no-store, private');
    }

    /** Otra orden QR/CIP del mismo profesor, pendiente y sin vencer, en una recarga distinta (evita pagar dos órdenes). */
    private function otherLiveOrder(RechargeRequest $recharge): ?PaymentOrder
    {
        // «pending» sigue vivo hasta que Culqi lo marque vencido (no por el reloj de MOVA); «cancelled» (borrado lógico en
        // Culqi) sigue pagable hasta su vencimiento + 15 min.
        return PaymentOrder::query()
            ->where('provider', CulqiPaymentProvider::PROVIDER)
            ->where('payment_channel', CulqiPaymentProvider::CHANNEL_ORDER)
            ->where('recharge_request_id', '!=', $recharge->id)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($c) => $c->where('status', 'cancelled')
                ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()->subMinutes(15)))))
            ->whereHas('rechargeRequest', fn ($q) => $q->where('teacher_profile_id', $recharge->teacher_profile_id))
            ->first();
    }

    /** El checkout de Culqi devolvió un error (p. ej. CCKT-400): se registra solo el código, sin datos personales. */
    public function clientError(Request $request, RechargeRequest $recharge)
    {
        $this->authorize('view', $recharge);
        abort_unless($recharge->payment_method === CulqiPaymentProvider::PROVIDER, 404);
        $data = $request->validate(['code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_.-]*$/'], 'flow' => ['required', Rule::in(['token', 'order'])]]);

        if ($id = $recharge->latestPaymentOrder?->provider_order_id) {
            Cache::forget('culqi-order-details:'.$id); // el siguiente sondeo lee el estado real (p. ej. ya confirmada)
        }
        Log::warning('[CulqiCheckout] El checkout de Culqi devolvió un error.', [
            'recharge_request_id' => $recharge->id,
            'payment_order_id' => $recharge->latestPaymentOrder?->id,
            'flow' => $data['flow'],
            'code' => $data['code'] ?? null,
        ]);

        return response()->noContent();
    }

    private function failureMessage(PaymentOrder $order): string
    {
        return match ($order->provider_status_detail) {
            'requires_3ds' => 'Tu banco exige una verificación adicional que no pudo iniciarse. Prueba con otra tarjeta o inténtalo de nuevo.',
            '3ds_cancelled' => 'Cancelaste la verificación de tu banco. No se te cobró nada; puedes intentarlo de nuevo.',
            '3ds_failed' => 'La verificación de tu banco no se completó. No se te cobró nada; puedes intentarlo de nuevo.',
            '3ds_expired' => 'La verificación de tu banco venció. No se te cobró nada; puedes intentarlo de nuevo.',
            default => 'El pago no pudo completarse. Puedes intentarlo de nuevo.',
        };
    }

    /** @param array<string, mixed>|null $actionRequired */
    private function payload(string $status, string $message, bool $creditsCredited, ?array $actionRequired = null): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'credits_credited' => $creditsCredited,
            'action_required' => $actionRequired,
        ];
    }
}
