<template>
  <span v-if="user.account_erased_at" class="inline-flex items-center text-xs bg-gray-200 text-gray-700 px-2 py-0.5 rounded-full font-semibold whitespace-nowrap">Eliminada</span>
  <span v-else-if="user.account_closed_at" class="inline-flex items-center text-xs bg-gray-200 text-gray-700 px-2 py-0.5 rounded-full font-semibold whitespace-nowrap">Cerrada</span>
  <span v-else-if="user.suspended_at" class="inline-flex items-center text-xs bg-red-100 text-red-800 px-2 py-0.5 rounded-full font-semibold whitespace-nowrap">Suspendido</span>
  <span v-else class="inline-flex items-center text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded-full font-semibold whitespace-nowrap">Activo</span>
</template>

<script setup>
defineProps({ user: { type: Object, required: true } })
</script>
