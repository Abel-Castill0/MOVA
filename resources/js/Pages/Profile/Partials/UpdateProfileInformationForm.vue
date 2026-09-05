<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
    // H-12: el telefono faltaba en este formulario. Sin el, un numero mal
    // escrito en el registro (donde es opcional) no se podia corregir en
    // ninguna parte del producto.
    phone: user.phone ?? '',
});

// Cambiar de numero invalida la verificacion anterior: el estado "verificado"
// describe un NUMERO, no al usuario. Se avisa ANTES de guardar en vez de
// sorprender al usuario con el aviso ya perdido.
const phoneWillNeedReverification = computed(
    () => user.phone_verified && (form.phone ?? '').trim() !== (user.phone ?? '').trim(),
);
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Información del perfil</h2>

            <p class="mt-1 text-sm text-gray-600">
                Actualiza tu nombre, tu correo electrónico y tu número de celular.
            </p>
        </header>

        <form @submit.prevent="form.patch(route('profile.update'))" class="mt-6 space-y-6">
            <div>
                <InputLabel for="name" value="Nombre" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Correo electrónico" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="phone" value="Celular" />

                <TextInput
                    id="phone"
                    type="tel"
                    class="mt-1 block w-full"
                    v-model="form.phone"
                    autocomplete="tel"
                    placeholder="987654321"
                />

                <p class="mt-1 text-xs text-gray-500">
                    Celular peruano de 9 dígitos, o internacional con prefijo (+51987654321).
                    Se usa para verificar tu cuenta por WhatsApp.
                </p>

                <p
                    v-if="phoneWillNeedReverification"
                    class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800"
                >
                    Al cambiar tu número tendrás que verificarlo de nuevo por WhatsApp, y dejarás de
                    recibir avisos por ese canal hasta que lo hagas.
                </p>

                <InputError class="mt-2" :message="form.errors.phone" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="text-sm mt-2 text-gray-800">
                    Tu correo electrónico no está verificado.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        Haz clic aquí para reenviar el correo de verificación.
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 font-medium text-sm text-green-600"
                >
                    Se ha enviado un nuevo enlace de verificación a tu correo electrónico.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">Guardado.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
