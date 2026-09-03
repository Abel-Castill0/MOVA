// CARD PAYMENT BRICK (MOVA Card Payment Brick) — monta/desmonta el Card
// Payment Brick de MercadoPago.js v2 y expone únicamente lo que
// Checkout.vue necesita para pilotearlo. Reutiliza el loader singleton de
// './mercadoPago' (mismo <script> del SDK que ya carga Yape) — nunca lo
// inyecta una segunda vez. Misma separación de responsabilidades que
// mercadoPagoYape.js: tokenización específica del medio de pago aquí,
// loader genérico allá.
//
// Verificado contra documentación oficial (búsqueda MCP "Card Payment
// Brick" + "Card Payment Brick recibir pago backend token issuer_id" +
// ficha técnica github.com/mercadopago/sdk-js/docs/bricks/card-payment.md):
//   - Setup: `const bricksBuilder = mp.bricks();` seguido de
//     `bricksBuilder.create('cardPayment', containerId, settings)`.
//   - `settings.initialization.amount` es OBLIGATORIO (monto total a
//     mostrar en el Brick — nunca lo que decide cuánto se cobra realmente;
//     eso lo vuelve a derivar MOVA server-side desde RechargeRequest, ver
//     CreditCheckoutController::pay()).
//   - `onSubmit(formData)` entrega el payload YA listo para Payments API:
//     `{ token, issuer_id, payment_method_id, transaction_amount,
//        payment_method_option_id, processing_mode, installments,
//        payer: { email, identification: { type, number } } }`.
//     transaction_amount/payer.email de formData se IGNORAN a propósito en
//     Checkout.vue — nunca se reenvían al backend.
//   - Lifecycle: "cada vez que el usuario sale de la pantalla donde se
//     muestra algún Brick, es necesario destruir la instancia actual con
//     `controller.unmount()`. Al ingresar nuevamente se debe generar una
//     nueva instancia" — nunca se reutiliza un controller ya desmontado.
import { loadMercadoPagoSdk } from './mercadoPago'

/**
 * Crea una instancia NUEVA de Card Payment Brick dentro de `containerId`
 * (debe existir ya en el DOM — Vue debe haber renderizado el `<div>` antes
 * de llamar esto). Cada llamada construye un `MercadoPago` y un
 * `bricksBuilder` propios — igual criterio que createYapeToken(): más
 * simple y sin estado de módulo compartido que pueda quedar obsoleto.
 *
 * @param {string} publicKey
 * @param {string} containerId
 * @param {{amount:number, onSubmit:(formData:object)=>Promise<void>, onReady?:()=>void, onError?:(error:unknown)=>void}} options
 * @returns {Promise<object>} el controller del Brick — pásalo a unmountCardPaymentBrick() al salir/cambiar de método.
 */
export async function mountCardPaymentBrick(publicKey, containerId, { amount, onSubmit, onReady, onError }) {
  const MercadoPago = await loadMercadoPagoSdk()
  const mp = new MercadoPago(publicKey, { locale: 'es-PE' })
  const bricksBuilder = mp.bricks()

  return bricksBuilder.create('cardPayment', containerId, {
    initialization: { amount },
    callbacks: {
      onReady: onReady || (() => {}),
      // El propio Brick espera que onSubmit devuelva una Promise: la
      // resuelve/rechaza para decidir su propia animación de éxito/error —
      // nunca se envuelve/absorbe aquí, se reenvía tal cual la que entrega
      // el llamador (Checkout.vue, con el POST real al backend).
      onSubmit,
      onError: onError || (() => {}),
    },
  })
}

/**
 * Destruye una instancia de Card Payment Brick — best-effort: un fallo al
 * desmontar nunca debe bloquear que el profesor cambie de método de pago o
 * salga de la pantalla.
 */
export async function unmountCardPaymentBrick(controller) {
  if (!controller || typeof controller.unmount !== 'function') return

  try {
    await controller.unmount()
  } catch {
    // best-effort — ver docblock de la función.
  }
}
