<template>
  <Transition
    enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 translate-y-4" enter-to-class="opacity-100 translate-y-0"
    leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 translate-y-4">
    <!-- Barra completa en móvil (el contenido suele requerir scroll para
         llegar al final, así que el padding-bottom reservado abajo evita el
         solape en el caso común). En sm+ pasa a tarjeta flotante compacta
         anclada a la esquina — un formulario centrado/izquierdo rara vez
         tiene su CTA en la esquina inferior derecha, así que reduce el
         solape real sin depender de reservar espacio en páginas que ya
         caben sin scroll (donde el padding-bottom no reposiciona nada:
         solo añade espacio al final, no empuja el contenido existente). -->
    <div v-if="visible" ref="bar"
      class="fixed inset-x-0 bottom-0 z-[60] px-4 pb-4 sm:inset-x-auto sm:right-6 sm:bottom-6 sm:left-auto sm:w-96 sm:px-0 sm:pb-0"
      role="dialog" aria-label="Aviso de cookies">
      <div class="mx-auto max-w-3xl rounded-2xl border border-gray-200 bg-white shadow-2xl shadow-black/10 p-5 sm:mx-0 sm:max-w-none">
        <p class="text-sm text-slate-600 leading-relaxed">
          Usamos cookies esenciales para que MOVA funcione y algunas opcionales para mejorar tu experiencia.
          Al continuar navegando aceptas nuestra
          <Link :href="route('legal.privacy')" class="text-brand-600 font-medium hover:underline">Política de Privacidad</Link>.
        </p>
        <button @click="accept" type="button"
          class="w-full sm:w-auto mt-4 px-5 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 active:bg-brand-800 transition-colors">
          Aceptar
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { Link } from '@inertiajs/vue3'

// Un solo valor persistido, sin timestamp ni versión: MOVA no distingue
// categorías de cookies opcionales todavía, así que "aceptado" es binario.
// Si el día de mañana se necesita volver a pedir consentimiento (cambio de
// política, nuevas categorías), basta con cambiar este key.
const STORAGE_KEY = 'mova_cookie_consent'

const visible = ref(false)
const bar = ref(null)

// El banner es `fixed bottom-0`: sin esto, tapa cualquier botón que viva
// cerca del final de una página corta (encontrado por el E2E de
// class-requests/create, pero es un bug real para cualquier usuario, no
// solo del test). Se reserva el espacio con padding-bottom en <body>,
// medido en vivo porque la altura del banner varía según el idioma/ancho
// de línea del texto — un valor fijo se desincroniza en cuanto cambie la
// copia.
function reserveSpace() {
  nextTick(() => {
    document.body.style.paddingBottom = bar.value ? `${bar.value.offsetHeight}px` : ''
  })
}

watch(visible, (isVisible) => {
  if (isVisible) reserveSpace()
  else document.body.style.paddingBottom = ''
})

onMounted(() => {
  if (localStorage.getItem(STORAGE_KEY) !== 'accepted') {
    visible.value = true
  }
  window.addEventListener('resize', reserveSpace)
})
onUnmounted(() => {
  window.removeEventListener('resize', reserveSpace)
  document.body.style.paddingBottom = ''
})

function accept() {
  localStorage.setItem(STORAGE_KEY, 'accepted')
  visible.value = false
}
</script>
