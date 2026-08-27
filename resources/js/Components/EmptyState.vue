<script setup>
/**
 * Mismo rol que el patrón que ya existe suelto en varias páginas (ej.
 * Marketplace/Index.vue: "Aún no hay profesores verificados"), centralizado
 * para que todas las páginas usen la misma estructura/spacing/tokens en vez
 * de reinventar el bloque cada vez. El slot #icon queda opcional y libre de
 * contenido (texto, emoji existente, o un <Icon> de Lucide una vez migrado
 * en la Fase 3) — este componente no fuerza esa dependencia todavía.
 */
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: null },
})
</script>

<template>
  <div class="bg-surface rounded-elevated border border-line py-16 px-6 text-center">
    <div v-if="$slots.icon" class="mb-4 flex justify-center" aria-hidden="true">
      <slot name="icon" />
    </div>
    <p class="text-lg font-bold text-ink mb-1">{{ title }}</p>
    <p v-if="description" class="text-sm text-ink-muted">{{ description }}</p>
    <div v-if="$slots.action" class="mt-5">
      <slot name="action" />
    </div>
  </div>
</template>
