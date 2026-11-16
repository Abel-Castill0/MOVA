import { peruCalendarParts, peruDayKey, localCalendarDayKey } from './peruDate.js'

// Lunes de la semana civil PERUANA que contiene el instante indicado.
// Es una fecha virtual (a medianoche del dispositivo) para dibujar columnas;
// NO convertirla a UTC para filtrar lecciones.
export function startOfWeek(date = new Date()) {
  const p = peruCalendarParts(date)
  if (!p) return new Date(NaN)
  const monday = new Date(p.year, p.month - 1, p.day, 12)
  const day = monday.getDay() // 0 domingo ... 6 sábado
  monday.setDate(monday.getDate() - ((day + 6) % 7))
  monday.setHours(0, 0, 0, 0)
  return monday
}

// La pertenencia se calcula con días civiles de Lima (no la TZ del equipo).
// Se preserva el orden del controlador para no alterar el historial.
export function splitByWeek(items, dateField = 'start_time', referenceDate = new Date()) {
  const weekStartKey = localCalendarDayKey(startOfWeek(referenceDate))
  const thisWeek = []
  const past = []
  for (const item of items) {
    const dateKey = peruDayKey(item[dateField])
    if (dateKey !== null && dateKey >= weekStartKey) thisWeek.push(item)
    else past.push(item)
  }
  return { thisWeek, past }
}
