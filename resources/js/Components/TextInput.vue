<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <!-- Sin w-full/min-h aquí a propósito: varios call-sites reales (ej.
         Register.vue el input de "materia" con class="block flex-1", o
         DeleteUserForm.vue con "w-3/4") controlan su propio ancho — un
         w-full en el componente competiría con esas clases con la misma
         especificidad y el resultado dependería del orden de generación de
         Tailwind, no del layout real de la página. Cada página sigue
         decidiendo su propio ancho, como ya hacía antes de este refactor. -->
    <input
        class="bg-surface text-ink placeholder:text-ink-subtle border border-line-strong rounded-control shadow-elevation-1 transition-colors duration-micro focus:border-focus-ring focus:ring-2 focus:ring-focus-ring/40 focus:ring-offset-0"
        v-model="model"
        ref="input"
    />
</template>
