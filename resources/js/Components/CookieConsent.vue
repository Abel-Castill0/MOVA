<template>
  <Transition
    enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 translate-y-4" enter-to-class="opacity-100 translate-y-0"
    leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 translate-y-4">
    <div v-if="visible" class="fixed inset-x-0 bottom-0 z-[60] px-4 pb-4 sm:px-6 sm:pb-6" role="dialog" aria-label="Aviso de cookies">
      <div class="mx-auto max-w-3xl rounded-2xl border border-gray-200 bg-white shadow-2xl shadow-black/10 p-5 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
          <p class="text-sm text-slate-600 leading-relaxed">
            Usamos cookies esenciales para que MOVA funcione y algunas opcionales para mejorar tu experiencia.
            Al continuar navegando aceptas nuestra
            <Link :href="route('legal.privacy')" class="text-brand-600 font-medium hover:underline">Política de Privacidad</Link>.
          </p>
          <button @click="accept" type="button"
            class="shrink-0 px-5 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 active:bg-brand-800 transition-colors sm:ml-auto">
            Aceptar
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { Link } from '@inertiajs/vue3'

// Un solo valor persistido, sin timestamp ni versión: MOVA no distingue
// categorías de cookies opcionales todavía, así que "aceptado" es binario.
// Si el día de mañana se necesita volver a pedir consentimiento (cambio de
// política, nuevas categorías), basta con cambiar este key.
const STORAGE_KEY = 'mova_cookie_consent'

const visible = ref(false)

onMounted(() => {
  if (localStorage.getItem(STORAGE_KEY) !== 'accepted') {
    visible.value = true
  }
})

function accept() {
  localStorage.setItem(STORAGE_KEY, 'accepted')
  visible.value = false
}
</script>
