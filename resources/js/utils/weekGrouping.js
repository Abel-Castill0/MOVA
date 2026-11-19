import { limaStartOfWeek } from '@/utils/limaTime'

// Lunes 00:00 (hora de Lima) de la semana que contiene `date` (por defecto, ahora), como instante. No depende de la zona
// del navegador: una familia con el reloj en otra zona ve las mismas semanas que WhatsApp y el correo.
export function startOfWeek(date = new Date()) {
  return limaStartOfWeek(date)
}

// Separa una lista por `dateField`: "esta semana o después" vs "pasadas"
// (antes del lunes de la semana actual). No hay un tercer balde para
// clases más allá de esta semana — caen en "esta semana" también, porque
// para el usuario lo relevante es distinguir "todavía pendiente" de
// "historial", no la semana calendario exacta.
//
// Preserva el orden de `items` tal cual llega — los controllers de Lessons
// (start_time desc) y de Dashboard (start_time asc) usan órdenes opuestos a
// propósito (historial reciente arriba vs. próxima clase arriba), así que
// esta función no reordena; cada caller decide si necesita invertir algún
// balde para su propio contexto.
export function splitByWeek(items, dateField = 'start_time') {
  const weekStart = startOfWeek()
  const thisWeek = []
  const past = []
  for (const item of items) {
    if (new Date(item[dateField]) >= weekStart) thisWeek.push(item)
    else past.push(item)
  }
  return { thisWeek, past }
}
