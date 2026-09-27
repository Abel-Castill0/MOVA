<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ code: '' });
const submit = () => form.post(route('admin.mfa.verify'), { onFinish: () => form.reset() });
</script>

<template>
    <GuestLayout>
        <Head title="Verificación en dos pasos – MOVA" />

        <p class="mb-4 text-sm text-gray-600">
            Área de administración. Escribe el código de tu app autenticadora o uno de tus códigos de recuperación.
        </p>

        <form @submit.prevent="submit">
            <InputLabel for="code" value="Código" />
            <TextInput id="code" v-model="form.code" class="mt-1 block w-full"
                autocomplete="one-time-code" maxlength="32" required autofocus />
            <InputError class="mt-2" :message="form.errors.code" />

            <div class="mt-4 flex justify-end">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">Verificar</PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
