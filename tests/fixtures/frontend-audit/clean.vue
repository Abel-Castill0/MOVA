<template>
  <div>
    <p v-if="!items.length">No tienes elementos</p>
    <InputError :message="form.errors.name" />
    <button @click="submit" :disabled="form.processing">Guardar</button>
  </div>
</template>
<script setup>
function submit() { form.post(route('items.store')) }
</script>
