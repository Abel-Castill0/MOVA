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
     * Crea la orden de pago remota para una RechargeRequest ya existente
     * (con package_code/credits/amount_pen ya congelados desde
     * config/credits.php) y devuelve el PaymentOrder local que la
     * representa. El monto en amount_minor se congela aquí, en el momento
     * de creación — nunca se vuelve a leer el catálogo de paquetes.
     */
    public function createOrder(RechargeRequest $recharge): PaymentOrder;

    /**
     * Verifica la autenticidad de un webhook entrante (firma/secreto del
     * proveedor) contra el payload crudo y sus headers. Debe devolver null
     * si la firma no es válida — nunca debe confiar en el contenido del
     * payload antes de verificar quién lo envió realmente.
     */
    public function verifyWebhook(string $rawPayload, array $headers): ?PaymentWebhookEvent;
}
