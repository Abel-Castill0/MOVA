<script setup>
import BaseButton from '@/Components/BaseButton.vue'

/**
 * Capa fina sobre BaseButton — mismo nombre de archivo/import que ya usan
 * sus 8 call-sites reales (Auth/*, Profile/*, Teacher/Credits/Index),
 * misma API (slot por defecto, :class, :disabled, ningún prop nuevo
 * obligatorio). type="submit" por defecto porque las 8 usan
 * `<form @submit.prevent="...">` con este botón dentro sin type explícito
 * — dependen del type=submit nativo del <button> para disparar el submit;
 * verificado archivo por archivo antes de fijar este default.
 */
defineProps({
  type: { type: String, default: 'submit' },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
})
</script>

<template>
  <BaseButton variant="primary" :type="type" :disabled="disabled" :loading="loading">
    <slot />
  </BaseButton>
</template>
