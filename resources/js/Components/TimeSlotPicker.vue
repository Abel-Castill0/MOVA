<template>
  <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
    <button v-for="slot in slots" :key="slot.value" type="button"
      @click="toggle(slot.value)"
      :class="['px-3 py-2 rounded-lg border text-sm font-medium transition-colors text-left',
        selected.includes(slot.value)
          ? 'bg-indigo-600 border-indigo-600 text-white'
          : 'bg-white border-gray-200 text-gray-700 hover:border-indigo-300']">
      {{ slot.label }}
    </button>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({ modelValue: Array })
const emit = defineEmits(['update:modelValue'])

const slots = [
  { value: 'morning_weekday', label: '🌅 Mañana (L-V)' },
  { value: 'afternoon_weekday', label: '☀️ Tarde (L-V)' },
  { value: 'evening_weekday', label: '🌆 Noche (L-V)' },
  { value: 'morning_weekend', label: '🌅 Mañana (S-D)' },
  { value: 'afternoon_weekend', label: '☀️ Tarde (S-D)' },
  { value: 'flexible', label: '🔄 Flexible' },
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
