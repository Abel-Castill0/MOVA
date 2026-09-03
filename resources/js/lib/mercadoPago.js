// MERCADOPAGO.JS LOADER (MOVA Yape Checkout Pre-Card Hardening) — carga
// perezosa y ÚNICA del SDK MercadoPago.js v2, reutilizable por cualquier
// método de pago (Yape hoy, Card Brick después). Antes vivía duplicado
// dentro de mercadoPagoYape.js; se extrajo aquí para que un segundo método
// de pago no tenga que copiar/pegar el mismo loader ni arriesgarse a
// inyectar el <script> del SDK dos veces.
//
// Nunca se importa globalmente en app.js — solo desde la pantalla de
// checkout que realmente lo necesita, y el propio <script> del SDK solo se
// inyecta la primera vez que loadMercadoPagoSdk()/preloadMercadoPagoSdk()
// se invocan.
let sdkPromise = null

export function loadMercadoPagoSdk() {
  if (typeof window !== 'undefined' && window.MercadoPago) {
    return Promise.resolve(window.MercadoPago)
  }

  if (sdkPromise) return sdkPromise

  sdkPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = 'https://sdk.mercadopago.com/js/v2'
    script.async = true
    script.onload = () => {
      if (window.MercadoPago) {
        resolve(window.MercadoPago)
      } else {
        reject(new Error('El SDK de Mercado Pago cargó pero no expuso window.MercadoPago.'))
      }
    }
    script.onerror = () => {
      // Permite reintentar en la próxima llamada en vez de dejar la promesa
      // rechazada memoizada para siempre (un fallo de red no debería
      // condenar el resto de la sesión del profesor).
      sdkPromise = null
      reject(new Error('No se pudo cargar el SDK de Mercado Pago. Revisa tu conexión e intenta de nuevo.'))
    }
    document.head.appendChild(script)
  })

  return sdkPromise
}

export function preloadMercadoPagoSdk() {
  // Fire-and-forget: precarga sin bloquear el render de la página ni
  // propagar el error al montar (el error real se maneja al pagar/tokenizar).
  loadMercadoPagoSdk().catch(() => {})
}
