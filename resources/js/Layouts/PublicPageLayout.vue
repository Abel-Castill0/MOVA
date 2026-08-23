<!--
  Para páginas públicas de CONTENIDO (marketplace, perfil de profesor) vistas
  por un invitado — no un formulario de auth. GuestLayout.vue está diseñado
  para login/registro: panel de marca a la izquierda + `max-w-sm` a la
  derecha, ambos como mitades de una pantalla dividida. Reutilizarlo aquí
  (como se hacía antes) encajonaba el marketplace/perfil entero dentro de
  esos 384px, con el panel de marca ocupando la otra mitad de la pantalla —
  bien para un formulario corto, roto para un grid de tarjetas o un perfil.

  Tampoco se reutiliza LandingNavbar.vue: ese navbar es `fixed` y transparente
  a propósito (flota sobre el hero azul de Welcome.vue y se vuelve blanco al
  hacer scroll) — probado en navegador real sobre este fondo claro: logo y
  menú se renderizan en blanco sobre blanco, invisibles hasta pasar 20px de
  scroll. Header simple, no fixed, mismo lenguaje visual que AppLayout.
  LandingFooter.vue tampoco se reutiliza completo (logo, quick links de
  marketing, "Únete a MOVA" — copy de landing, no de una página de
  contenido), pero sí se trajo la firma del desarrollador que le faltaba a
  este footer.

  Auth-aware a propósito: Marketplace/Index.vue y Teachers/Show.vue usan
  SIEMPRE este layout, ya sea invitado o logueado — antes cambiaban a
  AppLayout (sidebar completo) al iniciar sesión, lo que hacía que la MISMA
  página se sintiera como dos productos distintos según el rol. El único
  bit que sí depende de auth es este header: invitado ve Iniciar
  sesión/Registrarse, logueado ve su avatar+nombre+rol y "Mi dashboard" —
  igual que LandingNavbar.vue, sin el problema de contraste de ese
  componente (ver nota de arriba).
-->
<template>
  <Head :title="title" />
  <div class="min-h-screen bg-slate-50 flex flex-col">
    <header class="bg-white border-b border-gray-100 px-4 sm:px-6 lg:px-8 py-3.5 flex items-center justify-between">
      <Link href="/" class="flex items-center">
        <MovaLogo class="h-8 w-auto" />
      </Link>
      <div class="flex items-center gap-3">
        <template v-if="user">
          <div class="flex items-center gap-2">
            <img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name"
              class="w-8 h-8 rounded-lg object-cover flex-shrink-0" width="32" height="32" />
            <div v-else class="w-8 h-8 bg-gradient-to-br from-brand-500 to-brand-700 rounded-lg flex items-center justify-center text-white font-black text-xs flex-shrink-0">
              {{ user.name?.charAt(0)?.toUpperCase() }}
            </div>
            <div class="min-w-0 leading-tight hidden sm:block">
              <p class="text-sm font-semibold text-slate-900 truncate max-w-[8rem]">{{ user.name }}</p>
              <p class="text-xs text-slate-400">{{ roleLabelText }}</p>
            </div>
          </div>
          <Link :href="route('dashboard')" class="px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 transition-colors shadow-sm shadow-brand-600/20 whitespace-nowrap">
            Mi dashboard
          </Link>
        </template>
        <template v-else>
          <Link :href="route('login')" class="text-sm font-medium text-slate-600 hover:text-brand-600 transition-colors">
            Iniciar sesión
          </Link>
          <Link :href="route('register')" class="px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 transition-colors shadow-sm shadow-brand-600/20">
            Registrarse
          </Link>
        </template>
      </div>
    </header>

    <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6 max-w-6xl w-full mx-auto">
      <slot />
    </main>

    <footer class="px-4 sm:px-8 py-6 flex flex-wrap gap-4 justify-center text-xs text-gray-400 border-t border-gray-100 bg-white">
      <Link :href="route('legal.terms')" class="hover:text-brand-600 transition-colors">Términos y Condiciones</Link>
      <Link :href="route('legal.privacy')" class="hover:text-brand-600 transition-colors">Política de Privacidad</Link>
      <a href="mailto:m0v4class@gmail.com" class="hover:text-brand-600 transition-colors">Soporte</a>
      <span class="text-gray-300">·</span>
      <span>© {{ year }} MOVA. Todos los derechos reservados.</span>
      <span class="text-gray-300">·</span>
      <!-- Mismo enlace que LandingFooter.vue — la firma del desarrollador
           faltaba aquí, la única inconsistencia real entre este footer y el
           de la landing (el resto — logo, quick links, contacto — es copy
           de marketing que no pertenece a una página de contenido). -->
      <a href="https://portafolio-henna-mu.vercel.app/" target="_blank" rel="noopener noreferrer" class="hover:text-brand-600 transition-colors">
        Desarrollado con <span class="text-red-400">❤</span> por Abel Castillo
      </a>
    </footer>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'
import { roleLabel } from '@/utils/roleLabels'

defineProps({ title: String })
const year = new Date().getFullYear()
const user = computed(() => usePage().props.auth?.user)
const roleLabelText = computed(() => roleLabel(user.value?.roles?.[0]))
</script>
