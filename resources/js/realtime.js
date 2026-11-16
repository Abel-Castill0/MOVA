/**
 * Tiempo real de MOVA con Azure SignalR Service (cliente oficial @microsoft/signalr, modo Serverless).
 *
 * El navegador pide su token a /realtime/negotiate (autenticado por la sesión; el token es solo de ese usuario) y se
 * conecta directo al servicio. Las señales NO traen datos: solo qué áreas cambiaron (`requests`, `lessons`, `credits`,
 * `notifications`, `dashboard`). Cada página vuelve a pedir sus propios datos por HTTP, donde el servidor aplica sesión,
 * rol y alcance. Si algo falla, la interfaz sigue con su refresco normal (estado `fallback`).
 *
 * El módulo se carga bajo demanda desde AppLayout; las páginas de invitado no descargan nada de esto.
 */
import { ref } from 'vue'

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
let config = null
let ownerId = null
let stopped = true
let retryTimer = null
let retryCount = 0
let hiddenTimer = null
let visibilityBound = false

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

function scheduleRetry() {
  if (stopped || retryTimer) return
  const delay = RETRY_MS[Math.min(retryCount, RETRY_MS.length - 1)]
  retryCount += 1
  retryTimer = window.setTimeout(() => {
    retryTimer = null
    connect()
  }, delay)
}

async function connect() {
  if (stopped || connection) return
  realtimeStatus.value = 'connecting'
  try {
    const first = await negotiate()
    if (!first) {
      realtimeStatus.value = 'off' // el servidor no tiene tiempo real configurado: estado normal, no un error

      return
    }
    const signalR = await import('@microsoft/signalr')
    if (stopped) return

    const built = new signalR.HubConnectionBuilder()
      .withUrl(first.url, { accessTokenFactory: async () => (await negotiate())?.accessToken ?? '' })
      .withAutomaticReconnect({ nextRetryDelayInMilliseconds: (ctx) => Math.min(30000, 1000 * 2 ** ctx.previousRetryCount) + Math.random() * 500 })
      .configureLogging(signalR.LogLevel.None)
      .build()

    built.on('signal', receive)
    built.onreconnecting(() => { realtimeStatus.value = 'reconnecting' })
    built.onreconnected(() => {
      realtimeStatus.value = 'live'
      resync() // lo ocurrido mientras no había conexión se recupera pidiendo los datos al servidor
    })
    built.onclose(() => {
      connection = null
      if (stopped) return
      realtimeStatus.value = 'fallback'
      scheduleRetry()
    })

    await built.start()
    if (stopped) {
      await built.stop()

      return
    }
    connection = built
    retryCount = 0
    realtimeStatus.value = 'live'
    resync()
  } catch {
    connection = null
    realtimeStatus.value = 'fallback'
    scheduleRetry()
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
  if (stopped) return
  if (document.hidden) {
    // Una pestaña olvidada no debe ocupar una conexión del servicio: se pausa y se retoma al volver.
    hiddenTimer = window.setTimeout(async () => {
      await disconnect()
      if (!stopped) realtimeStatus.value = 'paused'
    }, HIDDEN_PAUSE_MS)
  } else {
    window.clearTimeout(hiddenTimer)
    if (realtimeStatus.value === 'paused' || (realtimeStatus.value === 'fallback' && !connection)) {
      retryCount = 0
      connect()
    }
  }
}

/**
 * Arranca (o reinicia) el tiempo real para ESTE usuario. Si había una conexión de otra persona en la misma pestaña, se
 * cierra antes: nunca se reutiliza una conexión entre cuentas.
 */
export async function startRealtime(cfg, userId) {
  if (!cfg?.enabled || !cfg.negotiateUrl || !userId) {
    realtimeStatus.value = 'off'

    return
  }
  if (!stopped && ownerId === userId && (connection || retryTimer)) return
  await stopRealtime()
  config = cfg
  ownerId = userId
  stopped = false
  if (!visibilityBound) {
    document.addEventListener('visibilitychange', onVisibility)
    visibilityBound = true
  }
  connect()
}

/** Cierra la conexión, la reconexión y los temporizadores (al salir de la sesión o desmontar el layout). */
export async function stopRealtime() {
  stopped = true
  ownerId = null
  window.clearTimeout(hiddenTimer)
  window.clearTimeout(flushTimer)
  flushTimer = null
  pending = new Set()
  if (visibilityBound) {
    document.removeEventListener('visibilitychange', onVisibility)
    visibilityBound = false
  }
  await disconnect()
  seen.length = 0
  realtimeStatus.value = 'off'
}
