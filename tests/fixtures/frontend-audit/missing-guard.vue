<template>
  <div>
    <p v-if="!items.length">No hay nada</p>
    <button @click="go">Enviar</button>
  </div>
</template>
<script setup>
function go() { router.post(route('thing.store')) }
</script>
