// El padre escribe (o el diagnóstico genera) un texto libre con «Objetivo:», «Urgencia:» y notas entre corchetes. El
// profesor ve solo lo necesario para decidir: el objetivo académico y el mensaje, nunca más datos del menor.

/** Objetivo académico en una frase corta. */
export function requestGoal(request) {
  const text = request?.help_needed
  if (!text) return 'Reforzar los temas de la materia.'

  const match = text.match(/Objetivo:\s*([^\n\r]+)/i)
  if (match?.[1]) {
    const cleaned = match[1].split(/Urgencia:/i)[0].replace(/\[.*?\]/g, '').trim()
    if (cleaned) return cleaned.endsWith('.') ? cleaned : `${cleaned}.`
  }

  const clean = text.replace(/\[.*?\]/g, '').replace(/Urgencia:\s*[^\n\r]+/gi, '').replace(/Objetivo:\s*/gi, '').trim()
  if (!clean) return 'Reforzar los temas de la materia.'
  const first = clean.split('.')[0].trim() || clean.slice(0, 90)

  return first.endsWith('.') ? first : `${first}.`
}

/** Mensaje del padre sin las etiquetas internas; null si no escribió nada más que el objetivo. */
export function requestMessage(request) {
  const text = request?.help_needed
  if (!text) return null

  const lines = text.replace(/\[.*?\]/g, '').split('\n').map((l) => l.trim()).filter(Boolean)
  const free = lines.filter((l) => !/^(Objetivo|Urgencia):/i.test(l))
  if (free.length) return free.join(' ')

  return text.includes('Objetivo:') || text.includes('Urgencia:') ? null : text.trim() || null
}

/** Nombre de pila del alumno: antes de aceptar es lo único que llega (minimización de datos de menores). */
export function studentFirstName(request) {
  return request?.student?.first_name || 'Alumno'
}
