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

// Los campos datetime-local no llevan zona horaria; MOVA opera con hora de Perú.
export function toPeruDateTimeLocal(value) {
  const p = peruCalendarParts(value)
  if (!p) return ''
  const two = n => String(n).padStart(2, '0')
  return p.year + '-' + two(p.month) + '-' + two(p.day) + 'T' + two(p.hour) + ':' + two(p.minute)
}

// Hora civil peruana -> UTC; valida también fechas inexistentes y horas inválidas.
export function peruLocalDateTimeToIso(value) {
  if (typeof value !== 'string' || !/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/.test(value)) {
    throw new RangeError('Ingresa una fecha y hora válida de Perú.')
  }
  // Perú opera en UTC-5 sin horario de verano; no usar new Date(value), porque
  // interpretaría la zona horaria del navegador del profesor.
  const instant = new Date(value + ':00-05:00')
  if (Number.isNaN(instant.getTime()) || toPeruDateTimeLocal(instant) !== value) {
    throw new RangeError('La fecha o la hora seleccionada no existe.')
  }
  return instant.toISOString()
}
