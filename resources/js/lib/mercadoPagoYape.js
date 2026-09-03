// Tokenización Yape, específica de este método de pago. El loader del SDK
// (genérico, reutilizable también por un futuro Card Brick) vive en
// './mercadoPago' — ver ese archivo para el porqué de la separación.
import { loadMercadoPagoSdk } from './mercadoPago'

/**
 * Tokeniza un pago Yape en el navegador — teléfono/OTP nunca salen de esta
 * función hacia MOVA: van directo al SDK oficial de Mercado Pago, que
 * devuelve un token de un solo uso. Verificado contra la documentación
 * oficial de Mercado Pago (checkout-api/integration-configuration/yape):
 * `mp.yape({ otp, phoneNumber }).create()`.
 *
 * La documentación oficial no confirma si `create()` resuelve a un string
 * directo o a un objeto con `.id` — se maneja defensivamente ambas formas
 * en vez de asumir una sin verificar.
 *
 * @returns {Promise<string>} el token a enviar al backend.
 */
export async function createYapeToken(publicKey, { otp, phoneNumber }) {
  const MercadoPago = await loadMercadoPagoSdk()
  const mp = new MercadoPago(publicKey, { locale: 'es-PE' })

  let result
  try {
    const yape = mp.yape({ otp, phoneNumber })
    result = await yape.create()
  } catch (error) {
    throw new Error(
      error?.message || 'Mercado Pago rechazó el teléfono/código Yape ingresado. Verifica los datos e intenta de nuevo.'
    )
  }

  const token = typeof result === 'string' ? result : result?.id

  if (!token || typeof token !== 'string') {
    throw new Error('Mercado Pago no devolvió un token válido de Yape.')
  }

  return token
}
