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
    <input
        class="border-gray-300 rounded-xl shadow-sm transition-colors duration-150 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/40 focus:ring-offset-0"
        v-model="model"
        ref="input"
    />
</template>
