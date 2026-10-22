<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\Culqi\CulqiConfig;
use App\Payment\Culqi\CulqiTokenInstrument;
use App\Payment\CulqiPaymentProvider;
use App\Services\CulqiPaymentReconciliationService;
use App\Support\CheckoutProvider;
use App\Support\CreditPackages;
use App\Payment\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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

        $this->run($recharge, fn (CulqiPaymentProvider $provider) => $provider->createOrderAttempt($recharge));

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
        if ($order !== null && CheckoutProvider::culqiEnabled(auth()->user())) {
            $this->reconcileOnDemand($recharge, $order);
        }

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

    private function respond(RechargeRequest $recharge)
    {
        $recharge = $recharge->fresh(['latestPaymentOrder', 'teacherProfile.user']);

        return response()->json($this->safeStatus($recharge, $recharge->latestPaymentOrder))
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
            return $this->payload('pending', 'Tu orden de pago está creada. Págala con el código o QR del checkout; tus créditos se acreditan automáticamente cuando Culqi confirme el pago.', false, [
                'type' => 'order',
                'order_id' => $order->provider_order_id,
                'amount_minor' => $order->amount_minor,
                'expires_at' => $order->expires_at?->toIso8601String(),
            ]);
        }

        // 'paid' local sin recarga aprobada todavía NO es aprobado (el ledger manda).
        return $this->payload('pending', $uncertain, false);
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
