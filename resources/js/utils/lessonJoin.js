// Antes duplicada palabra por palabra en ParentLessonCard.vue y
// TeacherLessonCard.vue; el calendario semanal la necesita también — con
// una tercera copia, se extrae (mismo criterio que hasScheduleOverlap()
// duplicado en el backend, C-2).
//
// Espejo de la autorización real del backend
// (LessonController::join():166): 'paid' siempre puede entrar (ya se
// confirmó el pago), 'scheduled' solo dentro de los 15 minutos previos al
// inicio. needs_admin_review queda excluido por construcción (whitelist,
// no negación) — una clase en disputa no debe abrir sala.
export function canJoinJitsi(lesson) {
  if (!lesson.has_jitsi_room) return false
  if (lesson.status === 'paid') return true
  if (lesson.status !== 'scheduled') return false
  const minutesToStart = (new Date(lesson.start_time).getTime() - Date.now()) / 60000
  return minutesToStart <= 15
}
