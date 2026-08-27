// Fuente única del color/etiqueta por estado de clase — antes vivía
// hardcodeada dentro de StatusBadge.vue; el calendario semanal necesita el
// mismo mapeo para pintar los bloques de cada día, así que se extrae aquí
// en vez de duplicarlo una tercera vez.
//
// `color`/`ring` = tinte claro para el badge de píldora (StatusBadge.vue).
// `stripe` = barra superior sólida de las tarjetas de clase (TeacherLessonCard/
// ParentLessonCard). `dot` = punto pequeño del timeline (Dashboard/Parent.vue).
// Los tres son el MISMO matiz por estado en tres pesos de Tailwind distintos
// (100/700 · 500 · 400) — antes cada consumidor repetía su propio mapeo de
// color por estado (y en el caso de "scheduled" ni siquiera coincidían entre
// sí: la tarjeta usaba `brand-500`, el badge usaba `blue`). Un solo lugar
// que cambiar si un estado necesita otro color, y ninguna posibilidad de que
// dos componentes queden desincronizados.
export const STATUS_STYLES = {
  scheduled: { label: 'Programada', color: 'bg-blue-100 text-blue-700', ring: 'border-blue-200', stripe: 'bg-blue-500', dot: 'bg-blue-400' },
  // violet, no indigo: el mapeo original usaba indigo (resto de Breeze) para
  // 'paid'/'open' — ninguna decisión de marca lo eligió, así que se
  // reemplaza por un tono realmente distinto del resto de la paleta
  // (blue=scheduled, green=completada, amber/orange=pendiente, red/rose=
  // rechazada) en vez de perpetuar un color que nunca fue una elección.
  paid: { label: 'Pagada', color: 'bg-violet-100 text-violet-700', ring: 'border-violet-200', stripe: 'bg-violet-500', dot: 'bg-violet-400' },
  pending_parent_confirmation: { label: 'Esperando calificación', color: 'bg-amber-100 text-amber-700', ring: 'border-amber-200', stripe: 'bg-amber-500', dot: 'bg-amber-400' },
  needs_admin_review: { label: 'En revisión', color: 'bg-orange-100 text-orange-700', ring: 'border-orange-200', stripe: 'bg-orange-500', dot: 'bg-orange-400' },
  completed: { label: 'Completada', color: 'bg-green-100 text-green-700', ring: 'border-green-200', stripe: 'bg-green-500', dot: 'bg-green-400' },
  cancelled: { label: 'Cancelada', color: 'bg-red-100 text-red-700', ring: 'border-red-200', stripe: 'bg-red-500', dot: 'bg-red-400' },
  open: { label: 'Abierta', color: 'bg-cyan-100 text-cyan-700', ring: 'border-cyan-200', stripe: 'bg-cyan-500', dot: 'bg-cyan-400' }, // mismo motivo que 'paid' arriba: era indigo sin razón de marca
  accepted: { label: 'Aceptada', color: 'bg-green-100 text-green-700', ring: 'border-green-200', stripe: 'bg-green-500', dot: 'bg-green-400' },
  rejected: { label: 'Rechazada', color: 'bg-red-100 text-red-700', ring: 'border-red-200', stripe: 'bg-red-500', dot: 'bg-red-400' },
  pending_parent_approval: { label: 'Pend. aprobación', color: 'bg-orange-100 text-orange-700', ring: 'border-orange-200', stripe: 'bg-orange-500', dot: 'bg-orange-400' },
  teacher_rejected: { label: 'Rechazada por profesor', color: 'bg-rose-100 text-rose-700', ring: 'border-rose-200', stripe: 'bg-rose-500', dot: 'bg-rose-400' },
}

const FALLBACK = { label: null, color: 'bg-gray-100 text-gray-600', ring: 'border-gray-200', stripe: 'bg-gray-300', dot: 'bg-slate-300' }

export function statusStyle(status) {
  return STATUS_STYLES[status] ?? { ...FALLBACK, label: status }
}
