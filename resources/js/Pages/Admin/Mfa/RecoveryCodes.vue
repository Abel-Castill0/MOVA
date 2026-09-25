<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({ codes: { type: Array, default: () => [] } });

const form = useForm({});
const regenerate = () => form.post(route('admin.mfa.recovery-codes.regenerate'));
</script>

<template>
    <GuestLayout>
        <Head title="Códigos de recuperación – MOVA" />

        <h1 class="text-lg font-semibold text-gray-900">Códigos de recuperación</h1>

        <template v-if="codes.length">
            <p class="mt-2 text-sm text-gray-600">
                Guárdalos en un lugar seguro. Cada código sirve una sola vez si pierdes tu app autenticadora.
                <strong>No se volverán a mostrar.</strong>
            </p>
            <ul class="mt-4 grid grid-cols-2 gap-2 font-mono text-sm text-gray-900 select-all">
                <li v-for="c in codes" :key="c">{{ c }}</li>
            </ul>
        </template>
        <p v-else class="mt-2 text-sm text-gray-600">
            Por seguridad los códigos solo se muestran al generarlos. Puedes generar un juego nuevo (invalida los anteriores).
        </p>

        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <SecondaryButton type="button" :disabled="form.processing" @click="regenerate">Generar nuevos códigos</SecondaryButton>
            <Link :href="route('dashboard')"><PrimaryButton type="button">Continuar</PrimaryButton></Link>
        </div>
    </GuestLayout>
</template>
