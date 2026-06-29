<template>
  <div class="min-h-screen bg-slate-50">
    <!-- Top bar -->
    <header class="bg-white border-b border-gray-100 px-4 sm:px-8 py-3 flex items-center justify-between sticky top-0 z-10">
      <Link href="/" class="flex items-center gap-2">
        <div class="w-8 h-8 bg-gradient-to-br from-brand-600 to-brand-800 rounded-lg flex items-center justify-center shadow">
          <span class="text-white font-black text-sm">M</span>
        </div>
        <span class="font-black text-lg text-brand-900">MOVA</span>
      </Link>
      <div class="flex items-center gap-2">
        <Link :href="route('login')"
          class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-brand-700 transition-colors">
          Iniciar sesión
        </Link>
        <Link :href="route('register')"
          class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition-colors">
          Registrarse
        </Link>
      </div>
    </header>

    <!-- Flash messages -->
    <div v-if="flash.success || flash.error" class="px-4 sm:px-8 pt-4">
      <div v-if="flash.success" class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
        <span>✅</span> {{ flash.success }}
      </div>
      <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
        <span>❌</span> {{ flash.error }}
      </div>
    </div>

    <main class="px-4 sm:px-8 py-6 max-w-7xl mx-auto">
      <slot />
    </main>

    <footer class="border-t border-gray-100 px-4 sm:px-8 py-4 flex flex-wrap gap-4 justify-center text-xs text-gray-400">
      <Link :href="route('legal.terms')" class="hover:text-indigo-600 transition-colors">Términos y Condiciones</Link>
      <Link :href="route('legal.privacy')" class="hover:text-indigo-600 transition-colors">Política de Privacidad</Link>
      <a href="mailto:abelcastillotrabajo@gmail.com" class="hover:text-indigo-600 transition-colors">Soporte</a>
      <span class="text-gray-300">·</span>
      <span>MOVA Beta 2026</span>
    </footer>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

defineProps({ title: String })

const flash = computed(() => usePage().props.flash ?? {})
</script>
