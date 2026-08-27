// Fuente única de las franjas horarias de disponibilidad — antes vivía
// duplicada, palabra por palabra, en TimeSlotPicker.vue (el widget que el
// padre usa para elegir su disponibilidad) y en ClassRequests/TeacherIndex.vue
// (donde el profesor ve qué eligió el padre). Encontrado auditando
// duplicación cruzada entre páginas, no solo dentro de un componente.
//
// `icon` es la clave semántica de resources/js/utils/icons.js (no el
// componente ya resuelto) — cada consumidor la pasa tal cual a <Icon :name>.
export const TIME_SLOTS = [
  { value: 'morning_weekday', label: 'Mañana (L-V)', icon: 'morning' },
  { value: 'afternoon_weekday', label: 'Tarde (L-V)', icon: 'afternoon' },
  { value: 'evening_weekday', label: 'Noche (L-V)', icon: 'evening' },
  { value: 'morning_weekend', label: 'Mañana (S-D)', icon: 'morning' },
  { value: 'afternoon_weekend', label: 'Tarde (S-D)', icon: 'afternoon' },
  { value: 'flexible', label: 'Flexible', icon: 'flexible' },
]

const BY_VALUE = Object.fromEntries(TIME_SLOTS.map((slot) => [slot.value, slot]))

export function timeSlotLabel(value) {
  return BY_VALUE[value]?.label ?? value.replace(/_/g, ' ')
}

export function timeSlotIconName(value) {
  return BY_VALUE[value]?.icon ?? 'flexible'
}
