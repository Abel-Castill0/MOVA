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
          <!-- Solo desktop: en móvil estos mismos enlaces ya viven en el menú
               colapsable de abajo. Antes no tenían hidden md:flex, así que se
               dibujaban también en móvil apretados junto al logo y el botón
               de hamburguesa. -->
          <div class="hidden md:flex items-center gap-3">
            <template v-if="user">
              <!-- Avatar+nombre+rol antes solo vivían en el sidebar de
                   AppLayout — invisibles aquí, en la landing, que un usuario
                   logueado también puede visitar (link "Inicio" del navbar,
                   o el logo). Sin esto no había forma de confirmar con qué
                   cuenta se estaba, ni un camino de vuelta salvo adivinar
                   que "Mi dashboard" hacía algo. -->
              <div class="flex items-center gap-2" :class="scrolled ? 'text-slate-700' : 'text-white'">
                <img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name"
                  class="w-8 h-8 rounded-lg object-cover flex-shrink-0" width="32" height="32" />
                <div v-else class="w-8 h-8 bg-gradient-to-br from-brand-500 to-brand-700 rounded-lg flex items-center justify-center text-white font-black text-xs flex-shrink-0">
                  {{ user.name?.charAt(0)?.toUpperCase() }}
                </div>
                <div class="min-w-0 leading-tight">
                  <p class="text-sm font-semibold truncate max-w-[8rem]">{{ user.name }}</p>
                  <p class="text-xs opacity-70 capitalize">{{ roleLabelText }}</p>
                </div>
              </div>
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
          </div>

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

    </div>
  </nav>

  <!-- Menú móvil full-screen: mismo gradiente de marca que el hero de
       Welcome.vue, para que abrir el menú se sienta como parte de MOVA y no
       como un dropdown genérico. Vive fuera de <nav> (que tiene su propio
       stacking context por el backdrop-blur del scroll) para que z-50
       quede por encima de todo sin depender de la jerarquía de <nav>. -->
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100"
      leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="mobileOpen" class="md:hidden fixed inset-0 z-50 bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700">
        <div class="absolute inset-0 opacity-10" style="background-image: linear-gradient(rgba(255,255,255,.1) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.1) 1px,transparent 1px);background-size:60px 60px"></div>

        <div class="relative h-full flex flex-col px-6 pt-5 pb-8">
          <div class="flex items-center justify-between">
            <Link href="/" class="flex items-center" @click="mobileOpen = false">
              <MovaLogo theme="blanco" class="h-8 w-auto" />
            </Link>
            <button @click="mobileOpen = false" aria-label="Cerrar menú" class="p-2 -mr-2 rounded-lg text-white/80 hover:text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <Transition
            enter-active-class="transition duration-300 ease-out delay-75" enter-from-class="opacity-0 translate-y-2" enter-to-class="opacity-100 translate-y-0">
            <nav v-if="mobileOpen" class="flex-1 flex flex-col justify-center gap-1 -mt-12">
              <a v-for="link in navLinks" :key="link.href" :href="link.href" @click="mobileOpen = false"
                class="text-3xl font-black text-white/90 hover:text-white py-3 transition-colors">
                {{ link.label }}
              </a>
            </nav>
          </Transition>

          <div class="flex flex-col gap-3">
            <template v-if="user">
              <div class="flex items-center gap-3 px-1 mb-1">
                <img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name"
                  class="w-11 h-11 rounded-xl object-cover flex-shrink-0" width="44" height="44" />
                <div v-else class="w-11 h-11 bg-white/15 rounded-xl flex items-center justify-center text-white font-black flex-shrink-0">
                  {{ user.name?.charAt(0)?.toUpperCase() }}
                </div>
                <div class="min-w-0">
                  <p class="text-white font-semibold truncate">{{ user.name }}</p>
                  <p class="text-white/60 text-sm capitalize">{{ roleLabelText }}</p>
                </div>
              </div>
              <Link :href="route('dashboard')" @click="mobileOpen = false"
                class="text-center px-4 py-3.5 bg-white text-brand-800 font-bold rounded-xl">
                Mi dashboard
              </Link>
            </template>
            <template v-else>
              <Link :href="route('register')" @click="mobileOpen = false"
                class="text-center px-4 py-3.5 bg-white text-brand-800 font-bold rounded-xl">
                Registrarse
              </Link>
              <Link :href="route('login')" @click="mobileOpen = false"
                class="text-center px-4 py-3.5 text-white font-semibold border border-white/30 rounded-xl">
                Iniciar sesión
              </Link>
            </template>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import MovaLogo from '@/Components/MovaLogo.vue'
import { roleLabel } from '@/utils/roleLabels'

const scrolled = ref(false)
const mobileOpen = ref(false)
const user = usePage().props.auth?.user
const roleLabelText = computed(() => roleLabel(user?.roles?.[0]))

// El menú full-screen tapa toda la pantalla; sin esto el contenido detrás
// sigue haciendo scroll con el dedo y se nota el "doble scroll".
watch(mobileOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
})
onUnmounted(() => { document.body.style.overflow = '' })

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
