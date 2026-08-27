// Fuente única del color/etiqueta por estado de clase — antes vivía
// hardcodeada dentro de StatusBadge.vue; el calendario semanal necesita el
// mismo mapeo para pintar los bloques de cada día, así que se extrae aquí
// en vez de duplicarlo una tercera vez.
export const STATUS_STYLES = {
  scheduled: { label: 'Programada', color: 'bg-blue-100 text-blue-700', ring: 'border-blue-200' },
  // violet, no indigo: el mapeo original usaba indigo (resto de Breeze) para
  // 'paid'/'open' — ninguna decisión de marca lo eligió, así que se
  // reemplaza por un tono realmente distinto del resto de la paleta
  // (blue=scheduled, green=completada, amber/orange=pendiente, red/rose=
  // rechazada) en vez de perpetuar un color que nunca fue una elección.
  paid: { label: 'Pagada', color: 'bg-violet-100 text-violet-700', ring: 'border-violet-200' },
  pending_parent_confirmation: { label: 'Esperando calificación', color: 'bg-amber-100 text-amber-700', ring: 'border-amber-200' },
  needs_admin_review: { label: 'En revisión', color: 'bg-orange-100 text-orange-700', ring: 'border-orange-200' },
  completed: { label: 'Completada', color: 'bg-green-100 text-green-700', ring: 'border-green-200' },
  cancelled: { label: 'Cancelada', color: 'bg-red-100 text-red-700', ring: 'border-red-200' },
  open: { label: 'Abierta', color: 'bg-cyan-100 text-cyan-700', ring: 'border-cyan-200' }, // mismo motivo que 'paid' arriba: era indigo sin razón de marca
  accepted: { label: 'Aceptada', color: 'bg-green-100 text-green-700', ring: 'border-green-200' },
  rejected: { label: 'Rechazada', color: 'bg-red-100 text-red-700', ring: 'border-red-200' },
  pending_parent_approval: { label: 'Pend. aprobación', color: 'bg-orange-100 text-orange-700', ring: 'border-orange-200' },
  teacher_rejected: { label: 'Rechazada por profesor', color: 'bg-rose-100 text-rose-700', ring: 'border-rose-200' },
}

const FALLBACK = { label: null, color: 'bg-gray-100 text-gray-600', ring: 'border-gray-200' }

export function statusStyle(status) {
  return STATUS_STYLES[status] ?? { ...FALLBACK, label: status }
}
