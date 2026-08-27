<template>
  <!-- antes esto era window.confirm() y se eliminó; el guard usa processing -->
  <div>
    <p v-if="!items.length">Aún no hay nada</p>
    <button @click="go" :disabled="processing">Enviar</button>
  </div>
</template>
<script setup>
/* Comentario de bloque: menciona confirm() y loading a propósito. */
const processing = ref(false)
function go() { router.post(route('thing.store'), {}, { onError: () => {} }) }
</script>
