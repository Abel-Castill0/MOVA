<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\Contracts\PaymentProviderContract;
use App\Payment\Contracts\YapePaymentInstrument;
use App\Payment\MercadoPagoPaymentProvider;
use App\Services\MercadoPagoPaymentReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/**
 * Frontera de aplicación entre el checkout automático (Yape vía Mercado
 * Pago Payments API) y el núcleo financiero ya existente
 * (PaymentProviderContract::createPaymentAttempt() / RechargeApprovalService
 * — ver docblocks de esas clases). Este controlador NUNCA decide monto,
 * créditos, moneda, external_reference ni payer.email: todo eso ya lo
 * deriva MercadoPagoPaymentProvider server-side a partir de la
 * RechargeRequest. Lo único que este controlador acepta del profesor es
 * `package_code` (al crear la recarga, contra el catálogo de
 * config('credits.packages')) y `token` (al pagar, ya tokenizado por
 * MercadoPago.js en el navegador — nunca teléfono/OTP crudos, ver
 * YapePaymentInstrument).
 *
 * Convive con el flujo manual existente (CreditController::storeRecharge —
 * transferencia/Yape con número de operación revisado por un admin) sin
 * tocarlo: ambos crean RechargeRequest, pero se distinguen por
 * `payment_method` ('mercadopago' aquí vs. 'yape'/'transfer'/'legacy' en
 * el manual) y solo este camino tiene PaymentOrder asociados.
 */
class CreditCheckoutController extends Controller
{
    /**
     * Ventana mínima entre dos reconcile() on-demand disparados por polling
     * del frontend para el MISMO intento — status() puede llamarse cada
     * pocos segundos mientras el profesor espera en pantalla; sin este
     * piso, cada tick de polling dispararía una llamada saliente a Mercado
     * Pago (GET /v1/payments) aunque nada haya cambiado desde el tick
     * anterior. reconcile() es GET, seguro de repetir seguido.
     */
    private const MIN_SECONDS_BETWEEN_RECONCILE_POLLS = 3;

    /**
     * Ventana mínima ESPECÍFICA para reconcileUncertainSubmission() — nunca
     * la misma que MIN_SECONDS_BETWEEN_RECONCILE_POLLS. A diferencia de
     * reconcile() (repetible sin límite), esta búsqueda consume un
     * presupuesto FINITO y pequeño (config('payments.mercadopago.recovery.
     * uncertain_search_max_attempts'), default 5) compartido con el barrido
     * en lote — encontrado en vivo durante esta misma ronda: sin esta
     * ventana propia, un polling cada 3s agota las 5 búsquedas en ~15
     * segundos y el intento cae a 'review' (revisión humana) aunque
     * Mercado Pago hubiera confirmado el pago segundos después. Espaciar
     * las búsquedas reales cada 15s hace que el presupuesto por defecto
     * alcance para ~75 segundos de polling activo, cerca del "hasta un
     * minuto" que ya promete la UI (ver Checkout.vue).
     */
    private const MIN_SECONDS_BETWEEN_UNCERTAIN_SEARCH_POLLS = 15;

    /**
     * Crea la RechargeRequest para UN intento de checkout automático.
     * Siempre una fila nueva (nunca reutiliza una anterior): un checkout
     * abandonado deja una RechargeRequest 'pending' sin PaymentOrder pagado
     * — inofensivo, igual que ya ocurre hoy con el flujo manual (un
     * profesor puede enviar varias solicitudes pendientes).
     */
    public function store(Request $request)
    {
        $teacherProfile = $request->user()->teacherProfile;
        abort_unless($teacherProfile, 403, 'No tienes perfil de profesor.');
        abort_unless(
            $this->mercadoPagoCheckoutEnabled(),
            503,
            'El pago automático con Yape no está disponible por ahora.'
        );

        $packages = config('credits.packages');

        $data = $request->validate([
            'package_code' => ['required', 'string', Rule::in(array_keys($packages))],
        ]);

        $package = $packages[$data['package_code']];

        // operation_number/operation_number_normalized quedan NULL a
        // propósito (ver migración
        // 2026_09_02_000001_make_recharge_operation_number_nullable.php):
        // este checkout automático no tiene ningún "número de operación"
        // que el profesor escriba — inventar un placeholder (UUID) para
        // satisfacer una restricción heredada del flujo manual solo
        // ensuciaba el dato con algo sin significado de negocio. La
        // identidad real del pago vive en PaymentOrder.provider_order_id /
        // externalReference(), nunca aquí.
        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $teacherProfile->id,
            'package_code' => $data['package_code'],
            'package_name' => $package['name'],
            'credits' => $package['credits'],
            'amount_pen' => $package['amount_pen'],
            'payment_method' => 'mercadopago',
            'operation_number' => null,
            'operation_number_normalized' => null,
            'status' => 'pending',
        ]);

        return redirect()->route('teacher.credits.checkout.show', $recharge);
    }

    /**
     * Pantalla de checkout — idempotente: recargar la página o volver a
     * abrirla nunca crea un intento nuevo, solo refleja el estado actual
     * (incluido "ya se pagó" o "el último intento falló, puedes
     * reintentar").
     */
    public function show(RechargeRequest $recharge)
    {
        $this->authorize('view', $recharge);
        abort_unless($recharge->payment_method === 'mercadopago', 404);

        $recharge->load('teacherProfile', 'latestPaymentOrder');

        return Inertia::render('Teacher/Credits/Checkout', [
            'recharge' => [
                'id' => $recharge->id,
                'package_name' => $recharge->package_name,
                'credits' => $recharge->credits,
                'amount_pen' => $recharge->amount_pen,
            ],
            'teacherProfile' => [
                'credits_available' => $recharge->teacherProfile->credits_available,
            ],
            'currency' => 'S/',
            'checkoutEnabled' => $this->mercadoPagoCheckoutEnabled(),
            'mercadoPagoPublicKey' => $this->mercadoPagoCheckoutEnabled()
                ? config('payments.mercadopago.public_key')
                : null,
            'initialStatus' => $this->safeStatus($recharge, $recharge->latestPaymentOrder),
        ]);
    }

    /**
     * Recibe el token Yape ya generado en el navegador (mp.yape().create())
     * y lo entrega a PaymentProviderContract::createPaymentAttempt(). Nunca
     * lee monto/créditos/moneda/referencia/payer del request — el único
     * campo aceptado es el token, y YapePaymentInstrument ni siquiera tiene
     * espacio para nada más (ver docblock de esa clase).
     */
    public function pay(Request $request, RechargeRequest $recharge)
    {
        $this->authorize('pay', $recharge);
        abort_unless($recharge->payment_method === 'mercadopago', 404);
        abort_unless(
            $this->mercadoPagoCheckoutEnabled(),
            503,
            'El pago automático con Yape no está disponible por ahora.'
        );

        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        try {
            app(PaymentProviderContract::class)->createPaymentAttempt(
                $recharge,
                new YapePaymentInstrument($data['token'])
            );
        } catch (RuntimeException $e) {
            // El estado definitivo de ESTE intento ya quedó persistido en
            // PaymentOrder (submission_status/status/review_reason) dentro
            // del provider ANTES de lanzar — nunca se interpreta el mensaje
            // de la excepción aquí, solo se relee lo ya guardado (ver
            // docblock de MercadoPagoPaymentProvider::createPaymentAttempt()).
            Log::info('[CreditCheckout] Intento de pago no confirmó de forma definitiva en el ciclo síncrono.', [
                'recharge_request_id' => $recharge->id,
                'motivo' => $e->getMessage(),
            ]);
        }

        $recharge = $recharge->fresh(['latestPaymentOrder']);

        return response()->json($this->safeStatus($recharge, $recharge->latestPaymentOrder));
    }

    /**
     * Estado CANÓNICO del intento más reciente, para el polling acotado del
     * checkout (idle/tokenizing/submitting/verifying/pending/approved/
     * failed/uncertain-review — sección 7/8 del encargo). Nunca expone
     * Access Token, payload crudo del proveedor, ni datos financieros
     * internos que la UI no necesita.
     *
     * STATUS SEMANTICS (MOVA Yape Final Pre-Card Gate): lectura PURA —
     * jamás dispara reconciliación ni puede mutar balance/estado
     * financiero (ver test_get_status_never_reconciles_or_mutates_state).
     * Antes este método reconciliaba on-demand contra Mercado Pago —
     * violaba la semántica HTTP de un GET (idempotente/sin efectos
     * secundarios), documentado como `GET STATUS SIDE EFFECT = TEMPORARY
     * PRE-WEBHOOK DEBT` en docs/MOVA_DESIGN_AUDIT_FINAL.md. Esa
     * reconciliación ahora vive exclusivamente en refresh() (POST,
     * explícito) — este método solo lee lo que ya está persistido.
     */
    public function status(RechargeRequest $recharge)
    {
        $this->authorize('view', $recharge);
        abort_unless($recharge->payment_method === 'mercadopago', 404);

        return response()->json($this->safeStatus($recharge, $recharge->latestPaymentOrder));
    }

    /**
     * Reconciliación EXPLÍCITA (POST) del intento más reciente — reemplaza
     * la reconciliación on-demand que antes vivía dentro de status() (GET).
     * El polling del checkout (Checkout.vue) llama a este endpoint, no a
     * status(), mientras espera una confirmación; devuelve exactamente el
     * mismo payload que status() para no romper el contrato del frontend.
     *
     * Dispara reconciliación server-to-server ON-DEMAND (reutilizando
     * MercadoPagoPaymentReconciliationService, el mismo camino que el
     * webhook/recovery — nunca un segundo camino de crédito) cuando el
     * intento sigue sin resolverse: es la única vía de confirmación casi
     * en tiempo real mientras MERCADOPAGO_WEBHOOKS_ENABLED=false y el
     * barrido de recovery programado (`mercadopago:reconcile`) todavía no
     * está agendado en el scheduler. Idempotente: reconcile()/credit() ya
     * son seguros de repetir (lockForUpdate()+idempotency_key) incluso ante
     * dos refresh "simultáneos" — ver
     * test_repeated_refresh_never_duplicates_credits.
     */
    public function refresh(RechargeRequest $recharge)
    {
        $this->authorize('view', $recharge);
        abort_unless($recharge->payment_method === 'mercadopago', 404);

        $order = $recharge->latestPaymentOrder;

        if ($order !== null && $this->mercadoPagoCheckoutEnabled()) {
            $this->reconcileOnDemand($recharge, $order);
            $order = $order->fresh();
            $recharge = $recharge->fresh();
        }

        return response()->json($this->safeStatus($recharge, $order));
    }

    /**
     * Decide cuál de las dos reconciliaciones (si alguna) toca en ESTE
     * poll, cada una con su propio piso de tiempo — nunca comparten el
     * mismo debounce (ver MIN_SECONDS_BETWEEN_UNCERTAIN_SEARCH_POLLS).
     */
    private function reconcileOnDemand(RechargeRequest $recharge, PaymentOrder $order): void
    {
        if ($order->status !== 'pending') {
            return; // ya terminal (paid/failed/expired/cancelled) — nada que reconciliar.
        }

        $provider = app(MercadoPagoPaymentProvider::class);
        $reconciler = app(MercadoPagoPaymentReconciliationService::class);

        try {
            if (in_array($order->submission_status, ['submitting', 'uncertain'], true) && $order->provider_order_id === null) {
                if ($order->updated_at !== null
                    && $order->updated_at->gt(now()->subSeconds(self::MIN_SECONDS_BETWEEN_UNCERTAIN_SEARCH_POLLS))) {
                    return;
                }

                $reconciler->reconcileUncertainSubmission($order, $provider);

                return;
            }

            if ($order->provider_order_id !== null) {
                if ($order->last_verified_at !== null
                    && $order->last_verified_at->gt(now()->subSeconds(self::MIN_SECONDS_BETWEEN_RECONCILE_POLLS))) {
                    return;
                }

                $reconciler->reconcile($order, null, $provider);
            }
        } catch (RuntimeException $e) {
            // fail closed silencioso: el polling debe seguir devolviendo el
            // último estado seguro conocido, nunca romper la pantalla del
            // profesor por un hipo transitorio de la API de Mercado Pago.
            Log::warning('[CreditCheckout] Reconciliación on-demand (polling) no pudo completarse.', [
                'recharge_request_id' => $recharge->id,
                'payment_order_id' => $order->id,
                'motivo' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Traduce (RechargeRequest, PaymentOrder|null) al vocabulario de 8
     * estados de la sección 7 del encargo. `credits_credited` es SIEMPRE
     * `$recharge->status === 'approved'` — el único lugar que de verdad
     * abona créditos es RechargeApprovalService::credit() (ver su
     * docblock), así que reflejar ese campo es la única señal
     * verdaderamente autoritativa, nunca el status local de PaymentOrder
     * por sí solo.
     *
     * @return array{status:string,message:string,credits_credited:bool,action_required:?string}
     */
    private function safeStatus(RechargeRequest $recharge, ?PaymentOrder $order): array
    {
        $uncertainMessage = 'Estamos verificando tu pago. No vuelvas a pagar mientras termina la verificación.';

        if ($recharge->status === 'approved') {
            return $this->statusPayload('approved', '¡Pago aprobado! Tus créditos ya están disponibles.', true);
        }

        if ($order === null) {
            return $this->statusPayload('idle', 'Todavía no se ha iniciado ningún intento de pago para esta recarga.', false);
        }

        if (in_array($order->submission_status, ['submitting', 'uncertain'], true)) {
            return $this->statusPayload('uncertain', $uncertainMessage, false);
        }

        if ($order->review_reason !== null) {
            return $this->statusPayload('review', $uncertainMessage, false);
        }

        if ($order->status === 'failed') {
            return $this->statusPayload('failed', 'El pago no pudo completarse. Puedes intentarlo de nuevo.', false);
        }

        if (in_array($order->status, ['expired', 'cancelled'], true)) {
            return $this->statusPayload('failed', 'El intento de pago venció. Puedes intentarlo de nuevo.', false);
        }

        if ($order->status === 'paid') {
            // 'paid' local sin recharge.status='approved' todavía no debería
            // persistir (applyPaid() acredita en la misma operación que
            // marca 'paid') — tratado igual que 'pending' por seguridad,
            // nunca como 'approved' sin la confirmación del ledger.
            return $this->statusPayload('pending', $uncertainMessage, false);
        }

        return $this->statusPayload('pending', $uncertainMessage, false);
    }

    private function statusPayload(string $status, string $message, bool $creditsCredited): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'credits_credited' => $creditsCredited,
            'action_required' => null,
        ];
    }

    /**
     * El checkout automático tiene su PROPIO interruptor — independiente de
     * `credits.recharges.enabled` (flujo manual). Fail-closed: solo
     * habilitado cuando pagos están prendidos, el proveedor resuelto es
     * realmente 'mercadopago' (nunca 'fake'/'culqi'), y hay una Public Key
     * que exponer al navegador.
     */
    private function mercadoPagoCheckoutEnabled(): bool
    {
        return (bool) config('payments.enabled')
            && config('payments.provider') === 'mercadopago'
            && filled(config('payments.mercadopago.public_key'));
    }
}
