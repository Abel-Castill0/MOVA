<script setup>
import { computed } from 'vue';

const emit = defineEmits(['update:checked']);

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        required: true,
    },
    value: {
        default: null,
    },
});

const proxyChecked = computed({
    get() {
        return props.checked;
    },

    set(val) {
        emit('update:checked', val);
    },
});
</script>

<template>
    <!-- text-brand-600/focus:ring-focus-ring reemplaza el indigo heredado de
         Breeze — mismo azul de marca que el resto del sistema, no un color
         ajeno a la identidad de MOVA. -->
    <input
        type="checkbox"
        :value="value"
        v-model="proxyChecked"
        class="rounded-chip border-line-strong text-brand-600 shadow-elevation-1 focus:ring-2 focus:ring-focus-ring"
    />
</template>
