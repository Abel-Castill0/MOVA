// CULQI CUSTOM CHECKOUT + CULQI3DS (carga perezosa) — documentación oficial:
//   https://docs.culqi.com/es/documentacion/checkout/checkout-custom
//   https://docs.culqi.com/es/documentacion/culqi-3ds/v1/configuracion
//   https://docs.culqi.com/es/documentacion/culqi-3ds/v1/uso-libreria
//
// Solo se usa la LLAVE PÚBLICA. La tarjeta se captura dentro del checkout alojado por Culqi y al
// navegador de MOVA solo llega un `token.id` (tkn_… / ype_…): MOVA nunca ve PAN, CVV ni fecha.
// Nunca se importa en app.js: lo carga solo la pantalla de checkout de Culqi.
//
// Checkout V4 NO se usa (Culqi anuncia su retiro): aquí solo `CulqiCheckout` (Custom) y `Culqi3DS` v1.

const CHECKOUT_SRC = 'https://js.culqi.com/checkout-js'
const THREEDS_SRC = 'https://3ds.culqi.com'

/** Tiempo máximo esperando el resultado del reto 3DS del banco (el servidor cierra el intento a los ~8 min). */
export const THREE_DS_TIMEOUT_MS = 8.5 * 60 * 1000

const scripts = new Map()

function loadScript(src, isReady, errorMessage) {
  if (typeof window !== 'undefined' && isReady()) return Promise.resolve()
  if (scripts.has(src)) return scripts.get(src)

  const promise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = src
    script.async = true
    script.onload = () => (isReady() ? resolve() : reject(new Error(errorMessage)))
    script.onerror = () => {
      // Permite reintentar en la próxima llamada en vez de memoizar el rechazo para siempre.
      scripts.delete(src)
      reject(new Error(errorMessage))
    }
    document.head.appendChild(script)
  })
  scripts.set(src, promise)

  return promise
}

export function loadCulqiCheckout() {
  return loadScript(CHECKOUT_SRC, () => typeof window.CulqiCheckout === 'function', 'No se pudo cargar el checkout de Culqi. Revisa tu conexión e intenta de nuevo.')
}

export function loadCulqi3DS() {
  return loadScript(THREEDS_SRC, () => typeof window.Culqi3DS === 'object' && window.Culqi3DS !== null, 'No se pudo cargar la verificación 3D Secure de Culqi. Revisa tu conexión e intenta de nuevo.')
}

export function preloadCulqi({ withThreeDs = true } = {}) {
  // Fire-and-forget: el error real se maneja al abrir el checkout.
  loadCulqiCheckout().catch(() => {})
  if (withThreeDs) loadCulqi3DS().catch(() => {})
}

/** Medios de la configuración de MOVA (card|yape|order) → medios del Custom Checkout de Culqi. */
export function culqiPaymentMethods(methods, { forOrder = false } = {}) {
  if (forOrder) {
    // Una orden (settings.order) habilita los medios en efectivo/billetera; sin orden solo hay tarjeta/Yape.
    return { tarjeta: false, yape: false, billetera: true, bancaMovil: true, agente: true, cuotealo: false }
  }

  return {
    tarjeta: methods.includes('card'),
    yape: methods.includes('yape'),
    billetera: false,
    bancaMovil: false,
    agente: false,
    cuotealo: false,
  }
}

let activeCheckout = null

/**
 * Abre el Custom Checkout. Resuelve UNA vez:
 *   { kind: 'token', token }  → hay un `token.id` para enviar al servidor (tarjeta o Yape)
 *   { kind: 'order' }         → Culqi generó el código/QR de la orden (`settings.order`)
 *   { kind: 'error', error }  → Culqi reportó un error (se le muestra un mensaje genérico, sin detalles)
 * Culqi no documenta un evento de cierre: si la persona cierra el modal sin pagar, la promesa
 * simplemente no se resuelve (la pantalla no queda bloqueada porque no se marca "ocupado" mientras está abierto).
 */
export async function openCulqiCheckout({ publicKey, amountMinor, currency = 'PEN', email, methods, order = null, title = 'MOVA — Recarga de créditos' }) {
  await loadCulqiCheckout()

  return new Promise((resolve) => {
    const config = {
      settings: { title, currency, amount: amountMinor, ...(order ? { order } : {}) },
      client: { email },
      options: {
        lang: 'es',
        installments: false,
        modal: true,
        paymentMethods: culqiPaymentMethods(methods, { forOrder: Boolean(order) }),
      },
      appearance: { theme: 'default', buttonCardPayText: 'Pagar' },
    }

    const culqi = new window.CulqiCheckout(publicKey, config)
    activeCheckout = culqi
    let settled = false

    culqi.culqi = () => {
      if (settled) return
      if (culqi.token) {
        settled = true
        const token = culqi.token.id
        culqi.close()
        resolve({ kind: 'token', token })
      } else if (culqi.order) {
        // El modal se deja ABIERTO: ahí Culqi muestra el QR/CIP que la persona necesita para pagar.
        settled = true
        resolve({ kind: 'order' })
      } else if (culqi.error) {
        settled = true
        resolve({ kind: 'error', error: culqi.error })
      }
    }

    culqi.open()
  })
}

export function closeCulqiCheckout() {
  try {
    activeCheckout?.close?.()
  } catch {
    // el modal ya no existe: nada que cerrar
  }
  activeCheckout = null
}

/** device_finger_print_id de Culqi3DS (UUID). null si la librería no lo pudo generar: el cargo seguirá sin 3DS posible. */
export async function generateDeviceFingerprint(publicKey) {
  try {
    await loadCulqi3DS()
    window.Culqi3DS.publicKey = publicKey

    return (await window.Culqi3DS.generateDevice()) || null
  } catch {
    return null
  }
}

/**
 * Ejecuta el reto 3DS del banco para un token. Resuelve:
 *   { ok: true, parameters3DS }                 → enviar al servidor (segundo POST /charges)
 *   { ok: false, reason: 'cancelled' | 'failed' } → cerrar el intento (no existe cargo)
 * La librería se reconfigura y se resetea por cada cargo, como exige la documentación.
 */
export async function runThreeDs({ publicKey, token, amountMinor, email, returnUrl }) {
  await loadCulqi3DS()
  const lib = window.Culqi3DS

  return new Promise((resolve) => {
    let finished = false
    let timer = null

    const finish = (result) => {
      if (finished) return
      finished = true
      window.removeEventListener('message', onMessage, false)
      if (timer) window.clearTimeout(timer)
      try {
        lib.reset()
      } catch {
        // sin sesión que resetear
      }
      resolve(result)
    }

    function onMessage(event) {
      // La documentación exige validar el origen; además se ignora cualquier mensaje que no sea un objeto de Culqi3DS.
      if (event.origin !== window.location.origin) return
      const response = event.data
      if (!response || typeof response !== 'object') return

      if (response.parameters3DS) {
        finish({ ok: true, parameters3DS: response.parameters3DS })
      } else if (response.error) {
        finish({ ok: false, reason: 'failed' })
      }
    }

    window.addEventListener('message', onMessage, false)
    timer = window.setTimeout(() => finish({ ok: false, reason: 'failed' }), THREE_DS_TIMEOUT_MS)

    lib.publicKey = publicKey
    lib.settings = { charge: { totalAmount: amountMinor, returnUrl }, card: { email } }
    lib.options = {
      showModal: true,
      showLoading: true,
      showIcon: true,
      closeModalAction: () => finish({ ok: false, reason: 'cancelled' }),
      style: { btnColor: '#1F5AA6', btnTextColor: '#FFFFFF' },
    }

    Promise.resolve(lib.initAuthentication(token)).catch(() => finish({ ok: false, reason: 'failed' }))
  })
}
