<script setup>
import BaseButton from '@/Components/BaseButton.vue'

// type="button" ya era el default de este componente antes del refactor —
// se conserva igual (sus 4 call-sites son todos "Cancelar" con @click,
// nunca dependen de submit).
defineProps({
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
})
</script>

<template>
  <BaseButton variant="secondary" :type="type" :disabled="disabled" :loading="loading">
    <slot />
  </BaseButton>
</template>
