<template>
  <Modal :show="show" max-width="sm" :title-id="titleId" @close="$emit('close')">
    <div class="p-6">
      <div class="flex items-start gap-3">
        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-danger-bg flex items-center justify-center">
          <Icon name="cancel-lesson" :size="20" class="text-danger-text" />
        </div>
        <div class="min-w-0 pt-1.5">
          <h3 :id="titleId" class="font-bold text-ink">Cancelar clase</h3>
          <p class="text-sm text-ink-muted">{{ lesson ? fmtDate(lesson.start_time) : '' }}</p>
        </div>
      </div>

      <p class="text-sm text-ink-muted mt-4">
        Esta acción cancelará la clase programada. El padre y el profesor
        recibirán una notificación.
      </p>

      <div class="mt-4">
        <label :for="reasonId" class="block text-xs font-semibold text-ink mb-1">Motivo (opcional)</label>
        <textarea
          :id="reasonId"
          v-model="reason"
          rows="3"
          placeholder="Ej: Imprevisto de última hora"
          class="w-full border border-line-strong rounded-control px-3 py-2.5 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-focus-ring focus:border-transparent transition"
        />
      </div>

      <p v-if="error" role="alert" class="text-xs text-danger-text bg-danger-bg border border-danger-border rounded-control px-3 py-2 mt-3">
        {{ error }}
      </p>

      <div class="flex gap-2 mt-5">
        <BaseButton variant="secondary" class="flex-1" :disabled="processing" @click="$emit('close')">
          Volver
        </BaseButton>
        <BaseButton variant="danger" class="flex-1" :loading="processing" @click="confirm">
          Cancelar clase
        </BaseButton>
      </div>
    </div>
  </Modal>
</template>

<script setup>
import { ref, watch } from 'vue'
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

const titleId  = 'cancel-lesson-title'
const reasonId = 'cancel-lesson-reason'
const reason   = ref('')

// El motivo se limpia cada vez que la modal se abre — nunca arrastra el
// texto de un intento de cancelación anterior sobre otra lección.
watch(() => props.show, (show) => { if (show) reason.value = '' })

function confirm() {
  emit('confirm', reason.value.trim() || null)
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}
</script>
