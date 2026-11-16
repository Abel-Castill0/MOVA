<script setup>
/**
 * Estado discreto del tiempo real. Solo informa cuando hay algo que decir: en vivo, reconectando o sin conexión en vivo
 * (en ese caso la pantalla se sigue actualizando sola cada pocos segundos). Sin tiempo real configurado no muestra nada.
 */
import { computed } from 'vue'
import { realtimeStatus } from '@/realtime.js'

const view = computed(() => ({
  live: { text: 'En vivo', dot: 'bg-emerald-500' },
  connecting: { text: 'Conectando…', dot: 'bg-amber-400' },
  reconnecting: { text: 'Reconectando…', dot: 'bg-amber-400' },
  fallback: { text: 'Se actualiza cada 30 s', dot: 'bg-slate-400' },
}[realtimeStatus.value] ?? null))
</script>

<template>
  <span v-if="view" class="inline-flex items-center gap-1.5 text-xs font-medium text-ink-muted" role="status" data-testid="live-indicator">
    <span :class="['h-2 w-2 rounded-full', view.dot]" aria-hidden="true" />
    {{ view.text }}
  </span>
</template>
