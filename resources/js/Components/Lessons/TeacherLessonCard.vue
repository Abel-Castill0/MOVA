<template>
  <div class="bg-white rounded-2xl border border-gray-100 hover:border-brand-200 transition-all hover:shadow-md overflow-hidden">
    <div :class="['h-1', statusStyle(lesson.status).stripe]"></div>

    <div class="p-5">
      <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-2 mb-2">
            <p class="font-bold text-slate-900">{{ lesson.class_request?.subject?.name ?? 'Clase' }}</p>
            <StatusBadge :status="lesson.status" />
          </div>
          <p class="text-sm text-slate-600">
            <span class="font-medium">Alumno:</span> {{ lesson.student?.first_name }} {{ lesson.student?.last_name }}
          </p>
          <p class="text-sm text-slate-500 mt-0.5 flex items-center gap-1">
            <Icon name="classes" :size="14" /> {{ fmtDate(lesson.start_time) }} · {{ lesson.duration_minutes }} min
          </p>
          <div v-if="lesson.status === 'cancelled' && lesson.cancel_reason"
            class="mt-2 text-xs text-red-600 bg-red-50 rounded px-2 py-1">
            Motivo de cancelación: {{ lesson.cancel_reason }}
          </div>
          <div v-if="lesson.rescheduled_at && lesson.original_start_time"
            class="mt-1 text-xs text-amber-700 bg-amber-50 rounded px-2 py-1">
            Reprogramada (original: {{ fmtDateShort(lesson.original_start_time) }})
          </div>
        </div>

        <div v-if="canJoinJitsi(lesson)" class="flex-shrink-0 bg-brand-50 border border-brand-100 rounded-xl p-4 min-w-0 sm:min-w-[220px] flex items-center">
          <button @click="$emit('join', lesson)"
            class="flex items-center gap-2 w-full px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow shadow-brand-600/25">
            <Icon name="join-room" :size="16" /> Ingresar a la Sala Virtual
          </button>
        </div>

        <div v-else-if="lesson.status !== 'scheduled'" class="flex-shrink-0 text-center px-4 py-3 bg-slate-50 rounded-xl">
          <p class="text-sm text-slate-400 capitalize">{{ statusStyle(lesson.status).label }}</p>
        </div>
      </div>

      <div v-if="lesson.status === 'paid' && !lesson.lesson_report" class="mt-3 bg-violet-50 border border-violet-100 rounded-xl p-3 flex items-start gap-2">
        <Icon name="payment-received" :size="16" class="text-violet-600 flex-shrink-0 mt-0.5" />
        <p class="text-xs font-medium text-violet-700">
          El padre/tutor confirmó el pago de esta clase. Ya puedes escribir el reporte pedagógico.
        </p>
      </div>

      <!-- C-1: el crédito de esta clase sigue reservado (ni consumido ni
           devuelto) hasta que el equipo MOVA la revise manualmente. -->
      <div v-if="lesson.status === 'needs_admin_review'" class="mt-3 bg-orange-50 border border-orange-100 rounded-xl p-3 flex items-start gap-2">
        <Icon name="under-review" :size="16" class="text-orange-600 flex-shrink-0 mt-0.5" />
        <p class="text-xs font-medium text-orange-700">
          Esta clase quedó sin confirmar y está en revisión por el equipo MOVA. Tu crédito sigue retenido mientras se resuelve.
        </p>
      </div>

      <div class="mt-3 pt-3 border-t border-gray-50 flex flex-wrap items-center justify-between gap-2">
        <template v-if="lesson.lesson_report">
          <Link :href="route('lesson-reports.show', lesson.id)"
            class="inline-flex items-center gap-1 text-xs text-green-600 hover:text-green-800 hover:bg-green-50 px-3 py-1.5 rounded-lg transition-colors font-medium">
            Ver reporte enviado <Icon name="check" :size="14" />
          </Link>
        </template>

        <template v-else-if="lesson.status === 'paid'">
          <Link :href="route('lesson-reports.create', lesson.id)"
            class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 text-white text-xs font-bold rounded-xl hover:bg-brand-700 transition-colors shadow-sm">
            <Icon name="my-reports" :size="14" /> Escribir reporte pedagógico
          </Link>
        </template>

        <template v-else-if="lesson.status === 'scheduled'">
          <span class="text-xs text-slate-400">Pendiente de confirmación de pago</span>
          <div class="flex gap-2">
            <button @click="$emit('reschedule', lesson)"
              class="text-xs text-amber-600 hover:text-amber-800 hover:bg-amber-50 px-3 py-1.5 rounded-lg transition-colors font-medium">
              Reprogramar
            </button>
            <button @click="$emit('cancel', lesson)"
              class="text-xs text-red-500 hover:text-red-700 hover:bg-red-50 px-3 py-1.5 rounded-lg transition-colors font-medium">
              Cancelar clase
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import StatusBadge from '@/Components/StatusBadge.vue'
import Icon from '@/Components/Icon.vue'
import { canJoinJitsi } from '@/utils/lessonJoin'
import { statusStyle } from '@/utils/statusColors'

defineProps({
  lesson: { type: Object, required: true },
})
defineEmits(['join', 'reschedule', 'cancel'])

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
  })
}

function fmtDateShort(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

</script>
