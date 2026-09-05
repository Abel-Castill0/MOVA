<script setup>
import { computed, ref } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});
const sendError = ref('');

const submit = () => {
    form.post(route('verification.send'), {
        onError: (errors) => {
            sendError.value = errors.email ?? 'No se pudo reenviar el enlace. Inténtalo de nuevo.';
        },
    });
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout>
        <Head title="Verificar correo electrónico – MOVA" />

        <div class="mb-4 text-sm text-gray-600">
            ¡Gracias por registrarte! Antes de continuar, por favor verifica tu correo electrónico haciendo clic en el enlace que te enviamos. Si no lo recibiste, podemos enviarte uno nuevo.
        </div>

        <div class="mb-4 font-medium text-sm text-green-600" v-if="verificationLinkSent">
            Se ha enviado un nuevo enlace de verificación al correo que proporcionaste al registrarte.
        </div>

        <InputError v-if="sendError" class="mb-4" :message="sendError" />

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Reenviar enlace de verificación
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-500"
                    >Cerrar sesión</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
