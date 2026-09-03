<?php

namespace App\Payment\Contracts;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\PaymentWebhookEvent;

/**
 * Abstracción sobre el proveedor de pagos (Culqi hoy; cualquier otro
 * mañana) para que RechargeApprovalService/PaymentWebhookService y el
 * resto de MOVA trabajen solo con conceptos propios (RechargeRequest,
 * PaymentOrder, CreditTransaction) y nunca con objetos específicos de un
 * proveedor externo. Cambiar de proveedor implica escribir una clase
 * nueva que implemente este contrato, no tocar el núcleo financiero.
 */
interface PaymentProviderContract
{
    /**
     * Crea/procesa UN intento de pago remoto para una RechargeRequest ya
     * existente (con package_code/credits/amount_pen ya congelados desde
     * config/credits.php) y devuelve el PaymentOrder local que lo
     * representa. El monto en amount_minor se congela aquí, en el momento
     * de creación — nunca se vuelve a leer el catálogo de paquetes.
     *
     * Renombrado desde `createOrder()` (ronda de pivot a Payments API): el
     * nombre anterior sugería un recurso "order" de Mercado Pago Orders
     * API, que este proyecto ya no usa — "payment attempt" es el concepto
     * provider-agnostic real (una RechargeRequest puede tener varios
     * intentos; ver PaymentOrder::$attempt_number).
     *
     * $instrument es opcional y provider-agnostic (TokenizedPaymentInstrument
     * — nunca un array sin tipar, nunca algo específico de un proveedor).
     * Money/créditos/moneda/referencia externa SIEMPRE los decide MOVA
     * server-side a partir de $recharge — el instrumento solo carga el
     * medio de pago ya tokenizado.
     */
    public function createPaymentAttempt(RechargeRequest $recharge, ?TokenizedPaymentInstrument $instrument = null): PaymentOrder;

    /**
     * Verifica la autenticidad de un webhook entrante (firma/secreto del
     * proveedor) contra el payload crudo y sus headers. Debe devolver null
     * si la firma no es válida — nunca debe confiar en el contenido del
     * payload antes de verificar quién lo envió realmente.
     */
    public function verifyWebhook(string $rawPayload, array $headers): ?PaymentWebhookEvent;
}
