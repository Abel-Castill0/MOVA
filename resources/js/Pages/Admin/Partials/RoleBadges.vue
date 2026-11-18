<template>
  <span v-for="r in roles" :key="r.name"
    class="inline-block text-xs bg-brand-100 text-brand-700 px-2 py-0.5 rounded-full mr-1">{{ label(r.name) }}</span>
  <span v-if="!roles?.length" class="text-xs text-gray-500">Sin rol</span>
</template>

<script setup>
defineProps({ roles: { type: Array, default: () => [] } })

const LABELS = { parent: 'Padre', teacher: 'Profesor', admin: 'Administrador' }
const label = (name) => LABELS[name] ?? name
</script>
