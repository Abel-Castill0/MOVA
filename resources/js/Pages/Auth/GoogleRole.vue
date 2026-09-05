<template>
  <GuestLayout max-width="max-w-xl" :role="form.role">
    <Head title="Elige tu rol – MOVA" />

    <div class="mb-6">
      <h1 class="text-2xl font-black text-slate-900">Casi listo</h1>
      <p class="mt-1 text-sm text-slate-500">
        Verificamos tu cuenta de Google. Solo falta saber cómo usarás MOVA.
      </p>
    </div>

    <div class="mb-6 flex items-center gap-3 rounded-2xl border border-gray-200 bg-slate-50 p-4">
      <Icon name="check" :size="20" class="flex-shrink-0 text-green-600" />
      <div class="min-w-0">
        <span class="block truncate font-semibold text-slate-900">{{ name }}</span>
        <span class="block truncate text-xs text-slate-500">{{ email }}</span>
      </div>
    </div>

    <form class="space-y-5" @submit.prevent="submit">
      <div>
        <h2 class="text-xl font-black text-slate-900">¿Cómo usarás MOVA?</h2>
        <p class="text-sm text-slate-500">Esto define tu panel y no se puede cambiar solo después.</p>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <button type="button" :class="roleClass(form.role === 'parent')" @click="form.role = 'parent'">
          <Icon name="role-parent" :size="24" />
          <span class="block font-bold">Soy padre</span>
          <span class="block text-xs text-slate-500">Busco apoyo para mi hijo.</span>
        </button>
        <button type="button" :class="roleClass(form.role === 'teacher')" @click="form.role = 'teacher'">
          <Icon name="role-teacher" :size="24" />
          <span class="block font-bold">Soy profesor</span>
          <span class="block text-xs text-slate-500">Quiero enseñar en MOVA.</span>
        </button>
      </div>
      <InputError :message="form.errors.role" />

      <label class="flex items-start gap-2 text-sm text-slate-600">
        <input
          v-model="form.accepted_terms"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
        />
        <span>
          Acepto los
          <a :href="route('legal.terms')" target="_blank" class="text-brand-600 hover:underline">Términos y Condiciones</a>
          y la
          <a :href="route('legal.privacy')" target="_blank" class="text-brand-600 hover:underline">Política de Privacidad</a>.
        </span>
      </label>
      <InputError :message="form.errors.accepted_terms" />

      <button
        type="submit"
        :disabled="form.processing || !form.role || !form.accepted_terms"
        class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-brand-700 disabled:opacity-50"
      >
        {{ form.processing ? 'Creando tu cuenta…' : 'Crear mi cuenta' }}
      </button>

      <p class="text-center text-xs text-slate-400">
        ¿No eres tú?
        <Link :href="route('login')" class="text-brand-600 hover:underline">Volver al inicio de sesión</Link>.
      </p>
    </form>
  </GuestLayout>
</template>

<script setup>
/**
 * R-19 — Elección de rol tras autenticarse con Google.
 *
 * Antes, cualquier cuenta creada por Google quedaba como `parent` sin
 * preguntar: un profesor que entrara por aquí terminaba con el panel
 * equivocado y sin perfil docente.
 *
 * En este punto la cuenta TODAVÍA NO EXISTE en la base de datos. `email` y
 * `name` vienen de la sesión del servidor (identidad ya verificada por
 * Google) y se muestran solo para que la persona confirme que es ella; el
 * backend nunca los lee de vuelta desde este formulario.
 */
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Icon from '@/Components/Icon.vue'
import InputError from '@/Components/InputError.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

defineProps({
  email: { type: String, required: true },
  name: { type: String, required: true },
})

const form = useForm({
  role: '',
  accepted_terms: false,
})

// Mismo tratamiento visual que el selector de rol del registro normal, para
// que las dos vías de alta se sientan como el mismo producto.
function roleClass(active) {
  return [
    'rounded-2xl border-2 p-4 text-left transition-all hover:border-brand-400',
    active ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-gray-200 text-slate-700',
  ]
}

function submit() {
  form.post(route('auth.google.role.store'))
}
</script>
