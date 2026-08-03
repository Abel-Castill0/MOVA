// Lunes 00:00:00 de la semana que contiene `date` (por defecto, ahora).
export function startOfWeek(date = new Date()) {
  const d = new Date(date)
  const day = d.getDay() // 0=domingo..6=sábado
  const diff = d.getDate() - day + (day === 0 ? -6 : 1)
  d.setDate(diff)
  d.setHours(0, 0, 0, 0)
  return d
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
