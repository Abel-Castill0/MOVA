// Antes duplicada palabra por palabra en ParentLessonCard.vue y
// TeacherLessonCard.vue; el calendario semanal la necesita también — con
// una tercera copia, se extrae (mismo criterio que hasScheduleOverlap()
// duplicado en el backend, C-2).
//
// F-06 — Espejo de la ventana AUTORITATIVA del backend
// (LessonController::join()). El backend es quien manda: esto solo evita
// mostrar un botón que fallaría con 403.
//
// Dos divergencias corregidas respecto a la versión anterior:
//
//   1. No había límite INFERIOR: `minutesToStart <= 15` también es cierto
//      para una clase que terminó hace tres días (minutos negativos), así
//      que el botón seguía apareciendo indefinidamente. Ahora se cierra a
//      los JOIN_GRACE_AFTER_MINUTES tras el fin.
//   2. 'pending_parent_confirmation' está permitido por el backend pero
//      el frontend lo bloqueaba, escondiendo un acceso legítimo.
//
// Si se cambian estos valores hay que cambiarlos también en config/jaas.php
// (join_window_before_minutes / join_grace_after_minutes). El backend no
// confía en este archivo: aquí solo se decide si se pinta el botón.
const JOIN_WINDOW_BEFORE_MINUTES = 15
const JOIN_GRACE_AFTER_MINUTES = 120

export function canJoinJitsi(lesson) {
  if (!lesson.has_jitsi_room) return false

  // 'paid': la clase ya ocurrió y el pago está confirmado; el acceso para
  // repasar o cerrar temas es comportamiento esperado del producto, igual
  // que en el backend.
  // Misma ventana absoluta que LessonController::join() para todos los estados (P1-02).
  if (!['scheduled', 'paid', 'pending_parent_confirmation'].includes(lesson.status)) return false

  const now = Date.now()
  const start = new Date(lesson.start_time).getTime()
  const end = lesson.end_time
    ? new Date(lesson.end_time).getTime()
    : start + (lesson.duration_minutes ?? 60) * 60000

  return now >= start - JOIN_WINDOW_BEFORE_MINUTES * 60000
    && now <= end + JOIN_GRACE_AFTER_MINUTES * 60000
}
