import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Lógica compartida de Cancelar/Reprogramar una clase — antes duplicada
 * letra por letra entre Lessons/ParentIndex.vue y Lessons/TeacherIndex.vue
 * (mismo `router.post`, mismo guard F-12, mismo mapeo de error, mismo
 * markup de modal). Es el mismo problema de fondo que "Response Contract
 * Integrity" (docs/MOVA_SYSTEM_KNOWLEDGE.md): dos copias del mismo contrato
 * de negocio divergen con el tiempo si nadie las mira juntas — el propio
 * bug que esto reemplaza (cancel() sin ningún onError) solo se corrigió en
 * un archivo cada vez porque nunca hubo una fuente única que arreglar.
 *
 * Los componentes CancelLessonModal.vue/RescheduleLessonModal.vue son
 * puramente de presentación (dueños de su propio estado de formulario);
 * este composable es dueño de la petición de red, el guard de doble-submit
 * y el mapeo de error real del backend — la misma separación que ya usa
 * useJitsiMeet().
 */
export function useLessonActions() {
  const cancelTarget = ref(null)
  const cancelError  = ref('')
  const cancelling   = ref(false)

  function openCancel(lesson) {
    cancelTarget.value = lesson
    cancelError.value  = ''
  }

  function closeCancel() {
    cancelTarget.value = null
    cancelError.value  = ''
  }

  function submitCancel(reason) {
    if (cancelling.value || !cancelTarget.value) return
    cancelling.value = true
    router.post(
      route('lessons.cancel', cancelTarget.value.id),
      { reason: reason || null },
      {
        onSuccess: () => closeCancel(),
        onError: (e) => { cancelError.value = e.cancel || 'No se pudo cancelar la clase. Intenta nuevamente.' },
        onFinish: () => { cancelling.value = false },
      }
    )
  }

  const rescheduleTarget = ref(null)
  const rescheduleError  = ref('')
  const rescheduling     = ref(false)

  function openReschedule(lesson) {
    rescheduleTarget.value = lesson
    rescheduleError.value  = ''
  }

  function closeReschedule() {
    rescheduleTarget.value = null
    rescheduleError.value  = ''
  }

  function submitReschedule({ start_time, reason }) {
    if (rescheduling.value || !rescheduleTarget.value) return
    if (!start_time) {
      rescheduleError.value = 'Selecciona una fecha y hora.'
      return
    }
    rescheduling.value = true
    router.post(
      route('lessons.reschedule', rescheduleTarget.value.id),
      { start_time, reason: reason || null },
      {
        onSuccess: () => closeReschedule(),
        // El backend devuelve mensajes reales bajo start_time/reschedule/
        // duration_minutes según el caso (estado inválido, intento de
        // cambiar duración, etc.) — nunca colapsar a un genérico si alguno
        // de los tres viene presente.
        onError: (e) => { rescheduleError.value = e.start_time || e.reschedule || e.duration_minutes || 'Error al reprogramar.' },
        onFinish: () => { rescheduling.value = false },
      }
    )
  }

  return {
    cancelTarget, cancelError, cancelling, openCancel, closeCancel, submitCancel,
    rescheduleTarget, rescheduleError, rescheduling, openReschedule, closeReschedule, submitReschedule,
  }
}
