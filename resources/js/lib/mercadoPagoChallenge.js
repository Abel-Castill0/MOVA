// 3DS CHALLENGE (MOVA Card Payment Brick 3DS) — dibuja el iframe del banco
// para un pago Payments API en status_detail='pending_challenge' y avisa
// cuando el Challenge terminó. Verificado contra documentación oficial
// (búsqueda MCP "three_d_secure_mode 3DS Payments API" / "pending_challenge
// Card Payment Brick", sección "Integrate 3DS" de Payments API — NO la
// variante de Orders API, que MOVA no usa):
//   - El Challenge se muestra dentro de un <iframe> que recibe un <form
//     method="post"> con un único campo oculto `creq`, apuntando a
//     `external_resource_url`.
//   - Al terminar, la ventana del Challenge hace
//     `window.postMessage({status:'COMPLETE'}, ...)` — la doc oficial NO
//     documenta un targetOrigin, así que el listener de abajo no puede
//     validar `event.origin` contra un valor conocido; por eso NUNCA se lee
//     nada del mensaje salvo `status==='COMPLETE'` como una señal de "el
//     profesor ya interactuó, vuelve a preguntarle a tu backend" — jamás
//     como confirmación de pago (ver docblock de Checkout.vue: la verdad
//     financiera solo sale de refresh()/status()).
//   - POSTMESSAGE HARDENING (ronda de hardening final): sin un origin
//     conocido que validar, la única defensa disponible contra un mensaje de
//     OTRA pestaña/iframe/extensión es exigir que `event.source` sea
//     EXACTAMENTE el `contentWindow` del iframe del Challenge que MOVA acaba
//     de crear — nunca una whitelist de dominio de banco (el ACS puede ser
//     cualquier emisor). Aun así, el resultado de un mensaje válido sigue
//     siendo SOLO una señal de "vuelve a preguntar" — ver watchChallengeCompletion().
//
// Mismo criterio que mercadoPagoCard.js: una función por responsabilidad,
// nada de estado de módulo compartido entre renderizados.

const IFRAME_ID = 'mova-3ds-challenge-iframe'

/**
 * Inserta el iframe + form auto-enviado dentro de `container` (un elemento
 * ya en el DOM). Idempotente respecto al propio nodo: limpia cualquier
 * intento previo (`container.innerHTML = ''`) antes de insertar el nuevo,
 * así que puede llamarse de nuevo sin acumular iframes si el componente se
 * re-renderiza con el mismo Challenge.
 *
 * @param {HTMLElement} container
 * @param {{externalResourceUrl:string, creq:string}} challenge
 * @returns {HTMLIFrameElement} el propio iframe recién creado — pásalo a
 *   watchChallengeCompletion() para que pueda validar event.source.
 */
export function renderChallenge(container, { externalResourceUrl, creq }) {
  // Nunca se asigna contenido a innerHTML — solo se VACÍA (string literal
  // ''), que no es una superficie de XSS. Todo lo que sigue se construye
  // con createElement()/propiedades tipadas (iframe.title, hiddenField.value,
  // etc.), nunca concatenando externalResourceUrl/creq en un string de HTML.
  container.innerHTML = ''

  const iframe = document.createElement('iframe')
  iframe.id = IFRAME_ID
  iframe.name = IFRAME_ID
  iframe.title = 'Verificación adicional de tu banco'
  // Alto responsivo — mismo criterio que el CSS recomendado por la
  // documentación oficial (min-height + width 100%), sin depender de un
  // <style> global.
  iframe.style.width = '100%'
  iframe.style.minHeight = '420px'
  iframe.style.border = '0'
  iframe.style.borderRadius = '0.75rem'

  const form = document.createElement('form')
  form.method = 'post'
  form.action = externalResourceUrl
  form.target = IFRAME_ID

  const hiddenField = document.createElement('input')
  hiddenField.type = 'hidden'
  hiddenField.name = 'creq'
  hiddenField.value = creq
  form.appendChild(hiddenField)

  // El <form> vive FUERA del iframe (apunta a él vía target) — igual
  // estructura que el snippet oficial: se agrega al DOM junto al iframe y
  // se envía una sola vez para arrancar el Challenge dentro de ese frame.
  container.appendChild(iframe)
  container.appendChild(form)
  form.submit()

  return iframe
}

export function clearChallenge(container) {
  if (container) container.innerHTML = ''
}

/**
 * Suscribe un listener a la señal `postMessage({status:'COMPLETE'})` que
 * el Challenge dispara al terminar. Devuelve la función de limpieza —
 * llamarla siempre al desmontar/reemplazar el Challenge (mismo criterio de
 * lifecycle que el Card Payment Brick).
 *
 * POSTMESSAGE HARDENING (ronda de hardening final): un mensaje solo se
 * acepta si (a) `event.data` es un objeto, (b) `event.data.status ===
 * 'COMPLETE'`, Y (c) `event.source` es EXACTAMENTE el `contentWindow` del
 * iframe del Challenge activo — nunca una whitelist de dominio de banco
 * (ver docblock del archivo). `iframeEl` puede volverse inválido (removido
 * del DOM) sin que este handler se haya limpiado todavía; `contentWindow`
 * es entonces `null` y la comparación simplemente no matchea nunca — fail
 * closed, no una excepción.
 *
 * @param {HTMLIFrameElement} iframeEl  el iframe devuelto por renderChallenge()
 * @param {() => void} onComplete
 * @returns {() => void}
 */
export function watchChallengeCompletion(iframeEl, onComplete) {
  const handler = (event) => {
    if (typeof event?.data !== 'object' || event.data === null) return
    if (event.data.status !== 'COMPLETE') return
    if (event.source !== iframeEl?.contentWindow) return

    onComplete()
  }

  window.addEventListener('message', handler)

  return () => window.removeEventListener('message', handler)
}
