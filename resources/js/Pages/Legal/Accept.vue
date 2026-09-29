<template>
  <Head title="Actualizamos nuestros documentos – MOVA" />
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-gray-200 p-6 sm:p-8">
      <h1 class="text-xl font-black text-gray-900 mb-2">Actualizamos nuestros documentos legales</h1>
      <p class="text-sm text-gray-600 leading-relaxed">
        Para seguir usando MOVA necesitamos que revises y aceptes la versión vigente de:
      </p>

      <ul class="mt-4 space-y-2">
        <li v-for="doc in documents" :key="doc">
          <a :href="route(links[doc].route)" target="_blank" rel="noopener"
            class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3 text-sm font-semibold text-brand-700 hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-brand-500">
            <span>{{ links[doc].label }}</span>
            <span class="text-xs font-medium text-gray-500">versión {{ versions[doc] }} · se abre en otra pestaña</span>
          </a>
        </li>
      </ul>

      <form class="mt-6 space-y-4" @submit.prevent="submit">
        <div class="flex items-start gap-3">
          <input id="accepted" v-model="form.accepted" type="checkbox" required
            class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-brand-600 focus:ring-2 focus:ring-brand-500" />
          <label for="accepted" class="text-sm text-gray-700 leading-relaxed">
            He leído y acepto los Términos y Condiciones y la Política de Privacidad vigentes.
          </label>
        </div>
        <InputError :message="form.errors.accepted" />

        <button type="submit" :disabled="form.processing"
          class="w-full bg-brand-600 text-white py-2.5 rounded-xl text-sm font-bold hover:bg-brand-700 disabled:opacity-50 transition-colors">
          Aceptar y continuar
        </button>
      </form>

      <div class="mt-5 pt-4 border-t border-gray-100 text-center">
        <Link :href="route('logout')" method="post" as="button"
          class="text-sm text-gray-500 hover:text-gray-700 transition-colors">
          Cerrar sesión
        </Link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'

defineProps({
  documents: { type: Array, required: true },
  versions: { type: Object, required: true },
})

const links = {
  terms: { label: 'Términos y Condiciones', route: 'legal.terms' },
  privacy: { label: 'Política de Privacidad', route: 'legal.privacy' },
}

// Nunca preseleccionado: aceptar es un acto explícito.
const form = useForm({ accepted: false })

function submit() {
  form.post(route('legal.accept.store'))
}
</script>
