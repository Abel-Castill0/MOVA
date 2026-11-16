/** Calendario de servicio en Perú, independiente de la zona horaria del dispositivo. */
export const PERU_TIMEZONE = 'America/Lima'

const formatter = new Intl.DateTimeFormat('en-GB', {
  timeZone: PERU_TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
})

export function peruCalendarParts(value) {
  const instant = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(instant.getTime())) return null
  const parts = Object.fromEntries(
    formatter.formatToParts(instant)
      .filter(p => ['year', 'month', 'day', 'hour', 'minute'].includes(p.type))
      .map(p => [p.type, Number(p.value)])
  )
  return parts
}

/** AAAA-MM-DD de la fecha civil peruana, comparable lexicográficamente. */
export function peruDayKey(value) {
  const p = peruCalendarParts(value)
  if (!p) return null
  return `${p.year}-${String(p.month).padStart(2, '0')}-${String(p.day).padStart(2, '0')}`
}

/** Clave de fecha virtual del calendario (ya es un día local, no un instante UTC). */
export function localCalendarDayKey(day) {
  if (!(day instanceof Date) || Number.isNaN(day.getTime())) return null
  return `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`
}

export function peruClockMinutes(value = new Date()) {
  const p = peruCalendarParts(value)
  return p ? p.hour * 60 + p.minute : null
}
