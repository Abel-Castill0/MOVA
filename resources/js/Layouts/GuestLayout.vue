<template>
  <Head :title="title" />
  <div class="min-h-screen bg-white lg:grid lg:grid-cols-2">
    <!-- Panel de marca: solo desktop. Mismo lenguaje del hero de Welcome.vue
         (gradiente brand-900→700, grid decorativo sutil) para que llegar al
         login se sienta como parte de MOVA, no como un formulario genérico
         de terceros. En mobile/tablet este panel desaparece entero — ahí la
         confianza la carga el logo + el propio formulario, no hace falta
         duplicar la superficie de marca. -->
    <div class="hidden lg:flex flex-col justify-between relative overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700 px-12 py-10">
      <div class="absolute inset-0 opacity-10" style="background-image: linear-gradient(rgba(255,255,255,.1) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.1) 1px,transparent 1px);background-size:60px 60px"></div>
      <Link href="/" class="relative flex items-center">
        <MovaLogo theme="blanco" class="h-9 w-auto" />
      </Link>
      <div class="relative max-w-sm">
        <p class="text-2xl font-black text-white leading-snug mb-3 text-balance">Clases particulares en vivo, con profesores verificados.</p>
        <p class="text-white/70 text-sm leading-relaxed text-pretty">Tú decides quién enseña a tu hijo. Cada profesor pasa por verificación antes de dictar su primera clase.</p>
      </div>
      <p class="relative text-white/40 text-xs">© {{ year }} MOVA</p>
    </div>

    <!-- Panel de formulario -->
    <div class="flex flex-col min-h-screen lg:min-h-0">
      <header class="lg:hidden flex items-center px-4 sm:px-8 py-4 border-b border-gray-100">
        <Link href="/" class="flex items-center">
          <MovaLogo class="h-8 w-auto" />
        </Link>
      </header>

      <!-- Flash messages -->
      <div v-if="flash.success || flash.error" class="px-4 sm:px-8 pt-6">
        <div v-if="flash.success" class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
          <span>✅</span> {{ flash.success }}
        </div>
        <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
          <span>❌</span> {{ flash.error }}
        </div>
      </div>

      <main class="flex-1 flex items-center justify-center px-4 sm:px-8 py-10">
        <div class="w-full" :class="maxWidth">
          <slot />
        </div>
      </main>

      <footer class="px-4 sm:px-8 py-6 flex flex-wrap gap-4 justify-center text-xs text-gray-400">
        <Link :href="route('legal.terms')" class="hover:text-brand-600 transition-colors">Términos y Condiciones</Link>
        <Link :href="route('legal.privacy')" class="hover:text-brand-600 transition-colors">Política de Privacidad</Link>
        <a href="mailto:m0v4class@gmail.com" class="hover:text-brand-600 transition-colors">Soporte</a>
        <span class="text-gray-300">·</span>
        <span>© {{ year }} MOVA. Todos los derechos reservados.</span>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'

defineProps({ title: String, maxWidth: { type: String, default: 'max-w-sm' } })

const flash = computed(() => usePage().props.flash ?? {})
const year = new Date().getFullYear()
</script>
