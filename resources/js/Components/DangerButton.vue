<script setup>
import BaseButton from '@/Components/BaseButton.vue'

// type="button" es nuevo respecto del <button> nativo original (que no
// declaraba type, o sea submit implícito) — verificado que sus 2
// call-sites (DeleteUserForm.vue) no viven dentro de ningún <form> y
// siempre usan @click explícito, así que el cambio no altera ningún
// comportamiento existente; es además la opción más segura hacia
// adelante para una acción destructiva.
defineProps({
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
})
</script>

<template>
  <BaseButton variant="danger" :type="type" :disabled="disabled" :loading="loading">
    <slot />
  </BaseButton>
</template>
