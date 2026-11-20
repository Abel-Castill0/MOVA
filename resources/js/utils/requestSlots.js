// Día + franja de una solicitud de clase (Mañana / Tarde / Noche), todo en hora de Perú (America/Lima, UTC-5 sin horario
// de verano). Las franjas y sus límites viven en config/class_requests.php y llegan como prop: aquí NO se repiten.
// Un día es una clave «YYYY-MM-DD» de Lima; nunca se usan getDate()/getHours() del navegador.
import { LIMA_TZ, limaDayKey } from './limaTime.js'

// Iconos (claves de utils/icons.js): sol naciente, sol y luna.
export const SLOT_ICONS = { morning: 'morning', afternoon: 'afternoon', evening: 'night' }

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']
const pad = (n) => String(n).padStart(2, '0')

export function parseDayKey(key) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(key ?? ''))
  if (!m) return null
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
  const probe = new Date(Date.UTC(y, mo - 1, d))

  return probe.getUTCFullYear() === y && probe.getUTCMonth() === mo - 1 && probe.getUTCDate() === d ? { y, m: mo - 1, d } : null
}

export function dayKey(y, m, d) {
  return `${y}-${pad(m + 1)}-${pad(d)}`
}

/** Suma `n` días a una clave (aritmética de calendario pura, sin zona). */
export function addDays(key, n) {
  const p = parseDayKey(key)
  if (!p) return ''
  const t = new Date(Date.UTC(p.y, p.m, p.d + n))

  return dayKey(t.getUTCFullYear(), t.getUTCMonth(), t.getUTCDate())
}

export function weekdayOf(key) {
  const p = parseDayKey(key)

  return p ? new Date(Date.UTC(p.y, p.m, p.d)).getUTCDay() : null
}

/** «viernes 20 de noviembre» */
export function dayLabel(key) {
  const p = parseDayKey(key)
  if (!p) return ''

  return `${WEEKDAYS[weekdayOf(key)]} ${p.d} de ${MONTHS[p.m]}`
}

export function monthLabel(y, m) {
  return `${MONTHS[m][0].toUpperCase()}${MONTHS[m].slice(1)} ${y}`
}

/** «por la tarde» */
export function slotPhrase(slot) {
  return { morning: 'por la mañana', afternoon: 'por la tarde', evening: 'por la noche' }[slot] ?? ''
}

/** «viernes 20 de noviembre, por la tarde» */
export function describePreference(date, slot) {
  const label = dayLabel(date)

  return label && slot ? `${label}, ${slotPhrase(slot)}` : ''
}

/** «12:00 – 18:00» */
export function slotRange(slotDef) {
  return slotDef ? `${pad(slotDef.start)}:00 – ${pad(slotDef.end)}:00` : ''
}

/** Instante en que termina la franja ese día (hora de Lima → UTC), o null. */
export function slotEnd(date, slotDef) {
  if (!parseDayKey(date) || !slotDef) return null
  const d = new Date(`${date}T${pad(slotDef.end)}:00:00-05:00`)

  return Number.isNaN(d.getTime()) ? null : d
}

/** ¿La franja de ese día ya terminó? Hoy, por la noche, «Mañana» deja de ofrecerse. */
export function slotHasEnded(date, slotDef, now = new Date()) {
  const end = slotEnd(date, slotDef)

  return end === null || now.getTime() >= end.getTime()
}

/** ¿Este instante cae dentro del día + franja? (Para avisar si una hora propuesta queda fuera.) */
export function slotContains(date, slotDef, instant) {
  const start = parseDayKey(date) && slotDef ? new Date(`${date}T${pad(slotDef.start)}:00:00-05:00`) : null
  const end = slotEnd(date, slotDef)
  const t = new Date(instant).getTime()

  return Boolean(start && end) && t >= start.getTime() && t < end.getTime()
}

/** Hoy en Lima según el reloj del navegador (solo para defaults de UI; el servidor valida con su propio «hoy»). */
export function todayKeyLima(now = new Date()) {
  return limaDayKey(now)
}

/** Semanas (lunes→domingo) de un mes: matriz de claves, con null en los huecos fuera del mes. */
export function monthGrid(y, m) {
  const first = weekdayOf(dayKey(y, m, 1))
  const lead = (first + 6) % 7
  const days = new Date(Date.UTC(y, m + 1, 0)).getUTCDate()
  const cells = [...Array(lead).fill(null), ...Array.from({ length: days }, (_, i) => dayKey(y, m, i + 1))]
  while (cells.length % 7) cells.push(null)

  return Array.from({ length: cells.length / 7 }, (_, w) => cells.slice(w * 7, w * 7 + 7))
}

export { LIMA_TZ }
