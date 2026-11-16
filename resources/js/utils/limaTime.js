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
