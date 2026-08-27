<template>
  <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
    <button v-for="slot in slots" :key="slot.value" type="button"
      @click="toggle(slot.value)"
      :aria-pressed="selected.includes(slot.value)"
      :class="['flex items-center gap-2 px-3 py-2 rounded-lg border text-sm font-medium transition-colors text-left',
        selected.includes(slot.value)
          ? 'bg-brand-600 border-brand-600 text-white'
          : 'bg-white border-gray-200 text-gray-700 hover:border-brand-300']">
      <Icon :name="slot.icon" :size="16" class="flex-shrink-0" />
      {{ slot.label }}
    </button>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import Icon from '@/Components/Icon.vue'

const props = defineProps({ modelValue: Array })
const emit = defineEmits(['update:modelValue'])

const slots = [
  { value: 'morning_weekday', label: 'Mañana (L-V)', icon: 'morning' },
  { value: 'afternoon_weekday', label: 'Tarde (L-V)', icon: 'afternoon' },
  { value: 'evening_weekday', label: 'Noche (L-V)', icon: 'evening' },
  { value: 'morning_weekend', label: 'Mañana (S-D)', icon: 'morning' },
  { value: 'afternoon_weekend', label: 'Tarde (S-D)', icon: 'afternoon' },
  { value: 'flexible', label: 'Flexible', icon: 'flexible' },
]

const selected = ref(props.modelValue ?? [])

watch(() => props.modelValue, v => { selected.value = v ?? [] })

function toggle(val) {
  const idx = selected.value.indexOf(val)
  if (idx >= 0) selected.value.splice(idx, 1)
  else selected.value.push(val)
  emit('update:modelValue', [...selected.value])
}
</script>
