<template>
  <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 sm:gap-2.5">
    <button v-for="slot in slots" :key="slot.value" type="button"
      @click="toggle(slot.value)"
      :aria-pressed="selected.includes(slot.value)"
      :class="['flex items-center gap-2.5 px-3.5 py-3 sm:py-3.5 rounded-2xl border-2 text-xs sm:text-sm font-bold transition-all duration-200 text-left active:scale-[0.98]',
        selected.includes(slot.value)
          ? 'bg-brand-50/90 border-brand-600 text-brand-900 shadow-sm ring-2 ring-brand-500/10'
          : 'bg-white border-slate-200/90 text-slate-700 hover:border-slate-300 hover:bg-slate-50/60 shadow-2xs']">
      <Icon :name="slot.icon" :size="18" class="flex-shrink-0" :class="selected.includes(slot.value) ? 'text-brand-600' : 'text-slate-400'" />
      <span class="truncate">{{ slot.label }}</span>
    </button>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import Icon from '@/Components/Icon.vue'
import { TIME_SLOTS } from '@/utils/timeSlots'

const props = defineProps({
  modelValue: Array,
  dark: { type: Boolean, default: false }
})
const emit = defineEmits(['update:modelValue'])

const slots = TIME_SLOTS

const selected = ref(props.modelValue ?? [])

watch(() => props.modelValue, v => { selected.value = v ?? [] })

function toggle(val) {
  const idx = selected.value.indexOf(val)
  if (idx >= 0) selected.value.splice(idx, 1)
  else selected.value.push(val)
  emit('update:modelValue', [...selected.value])
}
</script>
