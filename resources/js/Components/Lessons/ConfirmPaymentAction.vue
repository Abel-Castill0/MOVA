<template>
  <div v-if="hasClassEnded(lesson)" class="text-right">
    <BaseButton v-if="!askingConfirm" variant="primary" size="sm" :disabled="payingId === lesson.id" @click="askingConfirm = true">
      <Icon name="check" :size="16" /> Ya pagué
    </BaseButton>

    <div v-else>
      <p class="text-xs font-medium text-ink mb-2">¿Confirmas que la clase fue pagada?</p>
      <div class="flex gap-2 justify-end">
        <BaseButton variant="secondary" size="sm" :disabled="payingId === lesson.id" @click="askingConfirm = false">
          Cancelar
        </BaseButton>
        <BaseButton variant="primary" size="sm" :loading="payingId === lesson.id" @click="$emit('pay', lesson)">
          Confirmar pago
        </BaseButton>
      </div>
      <p v-if="paymentErrorId === lesson.id" role="alert" class="text-xs text-danger-text mt-2 max-w-[14rem] ml-auto">
        {{ paymentError }}
      </p>
    </div>
  </div>
  <p v-else class="text-xs text-ink-subtle text-right max-w-[10rem]">
    Podrás confirmar el pago cuando la clase finalice.
  </p>
</template>

<script setup>
/**
 * Antes: "Ya pagué" disparaba el POST directo, sin ningún paso intermedio —
 * ni siquiera el modal que sí tienen cancel()/reschedule(). Encontrado
 * auditando el sistema completo de acciones sobre una clase: era la única
 * de las cuatro sin ninguna confirmación, pese a mover un estado financiero
 * real (scheduled → paid). En vez de un cuarto modal (ver nota de alcance en
 * docs/MOVA_DESIGN_AUDIT_FINAL.md — registro `product` de impeccable: "modal
 * as first thought" es de lo primero que hay que evitar), se resuelve con un
 * segundo paso INLINE dentro de la misma tarjeta — coherente con que la
 * acción ya vivía inline, sin la interrupción de un modal para algo que el
 * propio usuario describió como "confirmación breve, no un checkout".
 *
 * Usado por ParentLessonCard.vue y Dashboard/Parent.vue (dos veces) — antes
 * cada uno tenía su propio botón "Ya pagué" duplicado.
 */
import { ref } from 'vue'
import BaseButton from '@/Components/BaseButton.vue'
import Icon from '@/Components/Icon.vue'

defineProps({
  lesson: { type: Object, required: true },
  payingId: { type: [Number, String], default: null },
  paymentErrorId: { type: [Number, String], default: null },
  paymentError: { type: String, default: '' },
})
defineEmits(['pay'])

const askingConfirm = ref(false)

function hasClassEnded(l) {
  return Date.now() >= new Date(l.end_time).getTime()
}
</script>
