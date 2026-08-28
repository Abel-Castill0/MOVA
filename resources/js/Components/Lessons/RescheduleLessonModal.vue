<template>
  <Modal :show="show" max-width="sm" :title-id="titleId" @close="$emit('close')">
    <div class="p-6">
      <h3 :id="titleId" class="font-bold text-ink mb-4">Reprogramar clase</h3>

      <!-- Horario actual → nuevo horario, siempre visible una vez elegida
           una fecha — reduce el error de "¿a qué hora quedó realmente?". -->
      <div class="rounded-control border border-line-strong bg-canvas px-3 py-2.5 mb-4 text-sm">
        <div class="flex items-center gap-2 min-w-0">
          <span class="text-ink-muted flex-shrink-0">Actual</span>
          <span class="font-medium text-ink truncate">{{ lesson ? fmtDate(lesson.start_time) : '' }}</span>
        </div>
        <div v-if="newPreview" class="flex items-center gap-2 min-w-0 mt-1 pt-1 border-t border-line">
          <Icon name="arrow-right" :size="14" class="text-ink-subtle flex-shrink-0 rotate-90" />
          <span class="font-semibold text-ink truncate">{{ newPreview }}</span>
        </div>
      </div>

      <div class="space-y-3">
        <div>
          <label :for="dateId" class="block text-xs font-semibold text-ink mb-1">Nueva fecha y hora</label>
          <input
            :id="dateId"
            v-model="form.start_time"
            type="datetime-local"
            class="w-full border border-line-strong rounded-control px-3 py-2.5 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-focus-ring focus:border-transparent transition"
          />
          <!-- La duración no es editable aquí: cambiarla exige recalcular
               price_frozen_pen y los créditos ya reservados, un flujo
               económico que no existe todavía (LessonController::
               reschedule — C-2 v1 solo mueve la fecha/hora). -->
        </div>
        <div>
          <label :for="reasonId" class="block text-xs font-semibold text-ink mb-1">Motivo (opcional)</label>
          <input
            :id="reasonId"
            v-model="form.reason"
            type="text"
            maxlength="500"
            placeholder="Ej: Por disponibilidad del alumno"
            class="w-full border border-line-strong rounded-control px-3 py-2.5 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-focus-ring focus:border-transparent transition"
          />
        </div>
        <p v-if="error" role="alert" class="text-xs text-danger-text bg-danger-bg border border-danger-border rounded-control px-3 py-2">
          {{ error }}
        </p>
      </div>

      <div class="flex gap-2 mt-5">
        <BaseButton variant="secondary" class="flex-1" :disabled="processing" @click="$emit('close')">
          Volver
        </BaseButton>
        <BaseButton variant="primary" class="flex-1" :loading="processing" @click="submit">
          Reprogramar
        </BaseButton>
      </div>
    </div>
  </Modal>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import Modal from '@/Components/Modal.vue'
import BaseButton from '@/Components/BaseButton.vue'
import Icon from '@/Components/Icon.vue'

const props = defineProps({
  show: { type: Boolean, default: false },
  lesson: { type: Object, default: null },
  error: { type: String, default: '' },
  processing: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'confirm'])

const titleId  = 'reschedule-lesson-title'
const dateId   = 'reschedule-lesson-date'
const reasonId = 'reschedule-lesson-reason'
const form = reactive({ start_time: '', reason: '' })

// Se repuebla desde la lección real cada vez que la modal se abre — nunca
// arrastra el formulario de un intento de reprogramación anterior.
watch(() => props.show, (show) => {
  if (show && props.lesson) {
    form.start_time = toDatetimeLocal(props.lesson.start_time)
    form.reason = ''
  }
})

const newPreview = computed(() => (form.start_time ? fmtDate(form.start_time) : ''))

function submit() {
  emit('confirm', {
    start_time: form.start_time ? new Date(form.start_time).toISOString() : '',
    reason: form.reason,
  })
}

function toDatetimeLocal(d) {
  const dt = new Date(d)
  dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset())
  return dt.toISOString().slice(0, 16)
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}
</script>
