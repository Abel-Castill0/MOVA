<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({ qrSvg: String, secret: String });

const form = useForm({ code: '' });
const submit = () => form.post(route('admin.mfa.confirm'), { onFinish: () => form.reset() });
</script>

<template>
    <GuestLayout>
        <Head title="Configurar verificación en dos pasos – MOVA" />

        <h1 class="text-lg font-semibold text-gray-900">Verificación en dos pasos</h1>
        <p class="mt-2 text-sm text-gray-600">
            Las cuentas de administrador requieren una app autenticadora (Google Authenticator, 1Password, Authy…).
            Escanea el código QR y escribe el código de 6 dígitos para activarla.
        </p>

        <div class="mt-4 flex justify-center" aria-hidden="true" v-html="qrSvg" />
        <p class="mt-3 text-xs text-gray-600">
            ¿No puedes escanear? Clave manual:
            <code class="break-all select-all font-mono text-gray-900">{{ secret }}</code>
        </p>

        <form class="mt-4" @submit.prevent="submit">
            <InputLabel for="code" value="Código de 6 dígitos" />
            <TextInput id="code" v-model="form.code" class="mt-1 block w-full" inputmode="numeric"
                autocomplete="one-time-code" maxlength="6" required autofocus />
            <InputError class="mt-2" :message="form.errors.code" />

            <div class="mt-4 flex justify-end">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">Activar</PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
