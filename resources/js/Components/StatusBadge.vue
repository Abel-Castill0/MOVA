<template>
  <span :class="['inline-block px-2 py-0.5 rounded-full text-xs font-semibold', colorClass]">{{ label }}</span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({ status: String })

const map = {
  scheduled: { label: 'Programada', color: 'bg-blue-100 text-blue-700' },
  in_progress: { label: 'En curso', color: 'bg-yellow-100 text-yellow-700' },
  completed: { label: 'Completada', color: 'bg-green-100 text-green-700' },
  cancelled: { label: 'Cancelada', color: 'bg-red-100 text-red-700' },
  open: { label: 'Abierta', color: 'bg-indigo-100 text-indigo-700' },
  accepted: { label: 'Aceptada', color: 'bg-green-100 text-green-700' },
  rejected: { label: 'Rechazada', color: 'bg-red-100 text-red-700' },
  pending_parent_approval: { label: 'Pend. aprobación', color: 'bg-orange-100 text-orange-700' },
  teacher_rejected: { label: 'Rechazada por profesor', color: 'bg-rose-100 text-rose-700' },
}

const colorClass = computed(() => map[props.status]?.color ?? 'bg-gray-100 text-gray-600')
const label = computed(() => map[props.status]?.label ?? props.status)
</script>
