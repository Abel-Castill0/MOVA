// Horas de clase siempre en hora de Perú (America/Lima, UTC-5 sin horario de verano), igual que WhatsApp, el correo y la
// campana (App\Support\LimaClock). El servidor guarda UTC; el navegador del usuario NO decide la zona.
export const LIMA_TZ = 'America/Lima'
export const LIMA_LABEL = 'hora de Perú'

const LIMA_OFFSET = '-05:00'

function asDate(value) {
  const date = value instanceof Date ? value : new Date(value)

  return Number.isNaN(date.getTime()) ? null : date
}

/** «mié, 25 nov 2026, 20:00» */
export function fmtLimaDate(value) {
  const date = asDate(value)

  return date
    ? date.toLocaleDateString('es-PE', { timeZone: LIMA_TZ, weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    : ''
}

/** «25 nov, 20:00» */
export function fmtLimaDateShort(value) {
  const date = asDate(value)

  return date ? date.toLocaleDateString('es-PE', { timeZone: LIMA_TZ, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
}

/** «20:00» */
export function fmtLimaTime(value) {
  const date = asDate(value)

  return date ? date.toLocaleTimeString('es-PE', { timeZone: LIMA_TZ, hour: '2-digit', minute: '2-digit' }) : ''
}

/** Instante UTC → valor de un <input type="datetime-local"> expresado en hora de Lima («2026-11-25T20:00»). */
export function toLimaDatetimeLocal(value) {
  const date = asDate(value)
  if (!date) return ''
  const shifted = new Date(date.getTime() - 5 * 3600 * 1000)

  return shifted.toISOString().slice(0, 16)
}

/** Valor de un <input type="datetime-local"> (hora de Lima) → ISO UTC para el servidor. '' si no es válido. */
export function limaLocalToIso(local) {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(String(local ?? ''))) return ''
  const date = new Date(`${local}:00${LIMA_OFFSET}`)

  return Number.isNaN(date.getTime()) ? '' : date.toISOString()
}

// ── Calendario: aritmética de días/semanas en Lima, sin depender de la zona del navegador ──
// Lima es UTC-5 todo el año (sin horario de verano), así que desplazar el instante 5 h y leer los campos UTC da la
// fecha/hora de pared de Lima de forma exacta. Nunca se usan getHours()/getDate()/setDate() del navegador.
const LIMA_OFFSET_MS = 5 * 3600 * 1000
const DAY_MS = 86400000

/** Campos de pared de Lima de un instante: {year, month(0-11), day, hour, minute, dow(0=dom..6=sáb)}. null si es inválido. */
export function limaParts(value) {
  const date = asDate(value)
  if (!date) return null
  const s = new Date(date.getTime() - LIMA_OFFSET_MS)

  return { year: s.getUTCFullYear(), month: s.getUTCMonth(), day: s.getUTCDate(), hour: s.getUTCHours(), minute: s.getUTCMinutes(), dow: s.getUTCDay() }
}

/** «2026-11-16»: clave del día calendario de Lima. */
export function limaDayKey(value) {
  const p = limaParts(value)

  return p ? `${p.year}-${String(p.month + 1).padStart(2, '0')}-${String(p.day).padStart(2, '0')}` : ''
}

/** Instante (UTC) en que empieza el lunes 00:00 de Lima de la semana que contiene `value` (por defecto, ahora). */
export function limaStartOfWeek(value = new Date()) {
  const p = limaParts(value)
  if (!p) return null
  const sinceMonday = p.dow === 0 ? 6 : p.dow - 1

  return new Date(Date.UTC(p.year, p.month, p.day - sinceMonday) + LIMA_OFFSET_MS)
}

/** Suma `days` días de calendario a un instante (Lima no tiene horario de verano: 24 h exactas). */
export function addLimaDays(value, days) {
  return new Date(asDate(value).getTime() + days * DAY_MS)
}

/** Minutos transcurridos desde las 00:00 de Lima del día del instante. */
export function limaMinutesOfDay(value) {
  const p = limaParts(value)

  return p ? p.hour * 60 + p.minute : 0
}

/** «04:30» — hora de Lima en 24 h, idéntica a la que escriben WhatsApp y el correo (LimaClock). */
export function fmtLimaClock(value) {
  const p = limaParts(value)

  return p ? `${String(p.hour).padStart(2, '0')}:${String(p.minute).padStart(2, '0')}` : ''
}
