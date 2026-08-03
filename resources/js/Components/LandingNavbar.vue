<template>
  <nav :class="['fixed top-0 inset-x-0 z-50 transition-all duration-300',
    scrolled ? 'bg-white/95 backdrop-blur-md shadow-sm' : 'bg-transparent']">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <!-- Logo -->
        <!-- El navbar es transparente sobre el hero azul y se vuelve blanco al
             hacer scroll — el logo cambia de versión blanca a color en sincronía. -->
        <Link href="/" class="flex items-center">
          <MovaLogo :theme="scrolled ? 'color' : 'blanco'" class="h-8 w-auto transition-opacity duration-300" />
        </Link>

        <!-- Desktop nav -->
        <div class="hidden md:flex items-center gap-6">
          <a v-for="link in navLinks" :key="link.href" :href="link.href"
            :class="['text-sm font-medium transition-colors', scrolled ? 'text-slate-700 hover:text-brand-600' : 'text-white/90 hover:text-white']">
            {{ link.label }}
          </a>
        </div>

        <!-- Auth buttons -->
        <div class="flex items-center gap-3">
          <template v-if="user">
            <Link :href="route('dashboard')"
              class="px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition-colors">
              Mi dashboard
            </Link>
          </template>
          <template v-else>
            <Link :href="route('login')"
              :class="['text-sm font-medium transition-colors', scrolled ? 'text-slate-700 hover:text-brand-600' : 'text-white/90 hover:text-white']">
              Iniciar sesión
            </Link>
            <Link :href="route('register')"
              class="px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition-colors shadow-lg shadow-brand-600/25">
              Registrarse
            </Link>
          </template>

          <!-- Mobile menu button -->
          <button @click="mobileOpen = !mobileOpen" class="md:hidden p-2 rounded-lg"
            :class="scrolled ? 'text-slate-700' : 'text-white'">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-if="!mobileOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
              <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
      </div>

      <!-- Mobile menu -->
      <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
        <div v-if="mobileOpen" class="md:hidden bg-white border-t border-gray-100 py-3 space-y-1">
          <a v-for="link in navLinks" :key="link.href" :href="link.href"
            class="block px-4 py-2 text-sm font-medium text-slate-700 hover:bg-brand-50 hover:text-brand-600 rounded-lg mx-2">
            {{ link.label }}
          </a>
          <div class="flex gap-2 px-4 pt-2">
            <Link v-if="!user" :href="route('login')" class="flex-1 text-center px-3 py-2 text-sm font-medium text-slate-700 border border-gray-200 rounded-lg">Iniciar sesión</Link>
            <Link :href="user ? route('dashboard') : route('register')" class="flex-1 text-center px-3 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg">
              {{ user ? 'Dashboard' : 'Registrarse' }}
            </Link>
          </div>
        </div>
      </Transition>
    </div>
  </nav>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'

const scrolled = ref(false)
const mobileOpen = ref(false)
const user = usePage().props.auth?.user

const navLinks = [
  { href: '/', label: 'Inicio' },
  { href: '/#como-funciona', label: 'Cómo funciona' },
  { href: '/#materias', label: 'Materias' },
  { href: '/#profesores', label: 'Profesores' },
  { href: '/quienes-somos', label: 'Quiénes somos' },
]

function onScroll() { scrolled.value = window.scrollY > 20 }
onMounted(() => window.addEventListener('scroll', onScroll, { passive: true }))
onUnmounted(() => window.removeEventListener('scroll', onScroll))
</script>
