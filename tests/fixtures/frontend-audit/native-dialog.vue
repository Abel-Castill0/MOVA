<template>
  <div>
    <p v-if="!items.length">Sin datos</p>
    <button @click="go" :disabled="processing">Borrar</button>
  </div>
</template>
<script setup>
const processing = ref(false)
function go() { if (confirm('¿Seguro?')) router.delete(route('thing.destroy'), { onError: () => {} }) }
</script>
