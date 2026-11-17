/**
 * Tiempo real de MOVA con Azure SignalR Service (cliente oficial @microsoft/signalr, modo Serverless).
 *
 * El navegador pide su token a /realtime/negotiate (autenticado por la sesión; el token es solo de ese usuario) y se
 * conecta directo al servicio. Las señales NO traen datos: solo qué áreas cambiaron (`requests`, `lessons`, `credits`,
 * `notifications`, `dashboard`). Cada página vuelve a pedir sus propios datos por HTTP, donde el servidor aplica sesión,
 * rol y alcance. Si algo falla, la interfaz sigue con su refresco normal (estado `fallback`).
 *
 * UNA conexión por pestaña: vive mientras la sesión sea de la misma persona, aunque se navegue entre páginas (cada página
 * usa su propio AppLayout, así que el layout NO es quien la cierra). Se cierra al salir de la sesión o al cambiar de
 * cuenta, detectado en cada navegación de Inertia. Un contador de generación impide que un arranque o parada antiguos
 * pisen a uno más nuevo.
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

export const ALL_TOPICS = ['requests', 'lessons', 'credits', 'notifications', 'dashboard']

/** 'off' (sin tiempo real) | 'connecting' | 'live' | 'reconnecting' | 'paused' (pestaña oculta) | 'fallback' (sin conexión: refresco normal) */
export const realtimeStatus = ref('off')

const FLUSH_MS = 350
const HIDDEN_PAUSE_MS = 10 * 60 * 1000
const RETRY_MS = [5000, 15000, 30000, 60000]

const listeners = new Map() // tema → Set<(topics) => void>
const seen = []
let pending = new Set()
let flushTimer = null
let connection = null
let connecting = false
let config = null
let ownerId = null
let active = false
let generation = 0
let retryTimer = null
let retryCount = 0
let hiddenTimer = null
let visibilityBound = false
let routerBound = false
let firstToken = null // el token de la primera negociación se reutiliza una vez: un solo POST por conexión

/** Suscribe una función a un tema. Devuelve la función para cancelar. */
export function onSignal(topic, handler) {
  if (!listeners.has(topic)) listeners.set(topic, new Set())
  listeners.get(topic).add(handler)

  return () => listeners.get(topic)?.delete(handler)
}

function flush() {
  flushTimer = null
  const topics = [...pending]
  pending = new Set()
  const called = new Set()
  for (const topic of topics) {
    for (const handler of listeners.get(topic) ?? []) {
      if (called.has(handler)) continue
      called.add(handler)
      try { handler(topics) } catch { /* un manejador defectuoso no corta a los demás */ }
    }
  }
}

function receive(signal) {
  if (!signal || typeof signal.id !== 'string' || !Array.isArray(signal.topics)) return
  if (seen.includes(signal.id)) return // señal repetida: no se vuelve a procesar
  seen.push(signal.id)
  if (seen.length > 100) seen.shift()
  signal.topics.filter((t) => ALL_TOPICS.includes(t)).forEach((t) => pending.add(t))
  if (!flushTimer) flushTimer = window.setTimeout(flush, FLUSH_MS)
}

function resync() {
  receive({ id: `resync-${Date.now()}-${Math.random().toString(36).slice(2)}`, topics: ALL_TOPICS })
}

async function negotiate() {
  const { data, status } = await window.axios.post(config.negotiateUrl, {}, { validateStatus: (s) => s === 200 || s === 204 })

  return status === 200 ? data : null
}

function scheduleRetry(gen) {
  if (!active || gen !== generation || retryTimer) return
  const delay = RETRY_MS[Math.min(retryCount, RETRY_MS.length - 1)]
  retryCount += 1
  retryTimer = window.setTimeout(() => {
    retryTimer = null
    connect(gen)
  }, delay)
}

async function connect(gen) {
  if (!active || gen !== generation || connection || connecting) return
  connecting = true
  realtimeStatus.value = 'connecting'
  let built = null
  try {
    const first = await negotiate()
    if (gen !== generation) return
    if (!first) {
      realtimeStatus.value = 'off' // el servidor no tiene tiempo real configurado: estado normal, no un error

      return
    }
    firstToken = first.accessToken
    const signalR = await import('@microsoft/signalr')
    if (gen !== generation) return

    built = new signalR.HubConnectionBuilder()
      .withUrl(first.url, {
        accessTokenFactory: async () => {
          if (firstToken) {
            const token = firstToken
            firstToken = null

            return token
          }

          return (await negotiate())?.accessToken ?? ''
        },
      })
      .withAutomaticReconnect({ nextRetryDelayInMilliseconds: (ctx) => Math.min(30000, 1000 * 2 ** ctx.previousRetryCount) + Math.random() * 500 })
      .configureLogging(signalR.LogLevel.None)
      .build()

    built.on('signal', (signal) => { if (gen === generation) receive(signal) })
    built.onreconnecting(() => { if (gen === generation) realtimeStatus.value = 'reconnecting' })
    built.onreconnected(() => {
      if (gen !== generation) return
      realtimeStatus.value = 'live'
      resync() // lo ocurrido mientras no había conexión se recupera pidiendo los datos al servidor
    })
    built.onclose(() => {
      if (gen !== generation) return // una conexión vieja que se cierra no toca el estado de la nueva
      connection = null
      realtimeStatus.value = 'fallback'
      scheduleRetry(gen)
    })

    await built.start()
    if (gen !== generation) {
      await built.stop().catch(() => {})

      return
    }
    connection = built
    retryCount = 0
    realtimeStatus.value = 'live'
    resync()
  } catch {
    if (built) await built.stop().catch(() => {})
    if (gen === generation) {
      connection = null
      realtimeStatus.value = 'fallback'
      scheduleRetry(gen)
    }
  } finally {
    connecting = false
  }
}

async function disconnect() {
  window.clearTimeout(retryTimer)
  retryTimer = null
  const current = connection
  connection = null
  if (current) {
    try { await current.stop() } catch { /* ya cerrada */ }
  }
}

function onVisibility() {
  if (!active) return
  const gen = generation
  if (document.hidden) {
    // Una pestaña olvidada no debe ocupar una conexión del servicio: se pausa y se retoma al volver.
    hiddenTimer = window.setTimeout(async () => {
      if (gen !== generation) return
      await disconnect()
      if (active && gen === generation) realtimeStatus.value = 'paused'
    }, HIDDEN_PAUSE_MS)
  } else {
    window.clearTimeout(hiddenTimer)
    if (realtimeStatus.value === 'paused' || (realtimeStatus.value === 'fallback' && !connection)) {
      retryCount = 0
      connect(gen)
    }
  }
}

/** Cada navegación de Inertia revisa de quién es la sesión: si salió o cambió, se cierra y (si hay otra cuenta) se reabre. */
function bindRouter() {
  if (routerBound) return
  routerBound = true
  router.on('navigate', (event) => {
    const props = event.detail?.page?.props ?? {}
    const uid = props.auth?.user?.id ?? null
    if (!uid) {
      stopRealtime()
    } else if (uid !== ownerId) {
      startRealtime(props.realtime, uid)
    }
  })
}

/**
 * Arranca el tiempo real para ESTE usuario. Es idempotente: si ya está activo para la misma persona no hace nada. Si había
 * una conexión de otra persona en la misma pestaña, se cierra antes: nunca se reutiliza una conexión entre cuentas.
 */
export async function startRealtime(cfg, userId) {
  bindRouter()
  if (!cfg?.enabled || !cfg.negotiateUrl || !userId) {
    await stopRealtime()

    return
  }
  if (active && ownerId === userId) {
    config = cfg

    return
  }
  await stopRealtime()
  config = cfg
  ownerId = userId
  active = true
  const gen = ++generation
  if (!visibilityBound) {
    document.addEventListener('visibilitychange', onVisibility)
    visibilityBound = true
  }
  connect(gen)
}

/** Cierra la conexión, la reconexión y los temporizadores (al salir de la sesión o al cambiar de cuenta). */
export async function stopRealtime() {
  generation += 1 // invalida de golpe todo lo que esté en curso
  active = false
  ownerId = null
  firstToken = null
  realtimeStatus.value = 'off'
  window.clearTimeout(hiddenTimer)
  window.clearTimeout(flushTimer)
  flushTimer = null
  pending = new Set()
  seen.length = 0
  if (visibilityBound) {
    document.removeEventListener('visibilitychange', onVisibility)
    visibilityBound = false
  }
  await disconnect()
}
