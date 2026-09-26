<template>
  <nav :class="['fixed top-0 inset-x-0 z-50 transition-all duration-300',
    isSolid ? 'bg-white/95 backdrop-blur-md shadow-sm border-b border-gray-100' : 'bg-transparent']">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="relative flex items-center justify-between h-16">
        <!-- Logo -->
        <Link href="/" class="flex items-center z-10 flex-shrink-0">
          <MovaLogo :theme="isSolid ? 'color' : 'blanco'" class="h-8 w-auto transition-opacity duration-300" />
        </Link>

        <!-- Desktop nav centralizado -->
        <div class="hidden md:flex items-center justify-center gap-7 absolute left-1/2 -translate-x-1/2">
          <a v-for="link in navLinks" :key="link.href" :href="link.href"
            @click="handleNavClick($event, link.href)"
            :class="['text-sm font-semibold transition-colors', isSolid ? 'text-slate-700 hover:text-brand-600' : 'text-white/90 hover:text-white']">
            {{ link.label }}
          </a>
        </div>

        <!-- Auth buttons / User dropdown -->
        <div class="flex items-center gap-3 z-10">
          <div class="hidden md:flex items-center gap-3">
            <template v-if="user">
              <!-- Menú desplegable de usuario -->
              <div class="relative" ref="userMenuRef">
                <button
                  type="button"
                  @click="userMenuOpen = !userMenuOpen"
                  class="user-trigger group flex items-center gap-2.5 py-1 px-1 rounded-xl transition-all duration-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 cursor-pointer select-none bg-transparent border-0"
                  :class="isSolid ? 'text-slate-700 hover:text-brand-600' : 'text-white hover:text-white/90'"
                  :aria-expanded="userMenuOpen"
                  aria-haspopup="true"
                >
                  <!-- Avatar con sutil escala en hover -->
                  <div class="relative flex-shrink-0">
                    <img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name"
                      class="w-7 h-7 rounded-full object-cover transition-transform duration-300 ease-out group-hover:scale-110 ring-2 ring-transparent group-hover:ring-brand-400/50" width="28" height="28" />
                    <div v-else class="w-7 h-7 bg-gradient-to-br from-brand-500 to-brand-700 rounded-full flex items-center justify-center text-white font-black text-xs transition-transform duration-300 ease-out group-hover:scale-110 shadow-sm group-hover:shadow-brand-500/30">
                      {{ user.name?.charAt(0)?.toUpperCase() }}
                    </div>
                  </div>

                  <!-- Info de usuario -->
                  <div class="min-w-0 text-left leading-tight hidden sm:block">
                    <p class="text-xs font-bold truncate max-w-[7.5rem] transition-colors duration-200"
                      :class="isSolid ? 'group-hover:text-brand-600' : 'group-hover:text-brand-200'">{{ user.name }}</p>
                    <p class="text-[10px] opacity-70 capitalize tracking-wide">{{ roleLabelText }}</p>
                  </div>

                  <!-- Flecha con giro spring motion graphic -->
                  <span class="chevron-arrow inline-flex items-center justify-center" :class="{ 'chevron-arrow-open': userMenuOpen }">
                    <svg
                      class="w-3.5 h-3.5 opacity-70 group-hover:opacity-100 transition-opacity"
                      fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                    </svg>
                  </span>
                </button>

                <!-- Dropdown con animación Motion Graphic -->
                <Transition name="motion-dropdown">
                  <div
                    v-if="userMenuOpen"
                    class="motion-dropdown-panel absolute right-0 top-full mt-2.5 w-52 bg-white/95 backdrop-blur-2xl rounded-2xl p-1.5 z-50 origin-top-right overflow-hidden shadow-[0_20px_50px_-12px_rgba(13,52,109,0.22),0_0_0_1px_rgba(226,232,240,0.85)]"
                  >
                    <!-- Opción 1: Dashboard -->
                    <Link
                      :href="route('dashboard')"
                      @click="userMenuOpen = false"
                      class="motion-item group flex items-center justify-between px-3 py-2.5 text-sm font-semibold text-slate-700 hover:text-brand-700 hover:bg-brand-50/90 rounded-xl transition-all duration-200"
                    >
                      <div class="flex items-center gap-2.5">
                        <span class="w-7 h-7 rounded-lg bg-brand-50 group-hover:bg-brand-100 flex items-center justify-center text-brand-600 transition-all duration-200 group-hover:scale-110">
                          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                          </svg>
                        </span>
                        <span>Ir a mi dashboard</span>
                      </div>
                      <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-brand-600 group-hover:translate-x-0.5 transition-all duration-200 opacity-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                      </svg>
                    </Link>

                    <div class="h-px bg-slate-100 my-1 mx-2"></div>

                    <!-- Opción 2: Cerrar sesión -->
                    <Link
                      :href="route('logout')"
                      method="post"
                      as="button"
                      @click="userMenuOpen = false"
                      class="motion-item group flex items-center justify-between px-3 py-2.5 text-sm font-semibold text-red-600 hover:text-red-700 hover:bg-red-50/90 rounded-xl transition-all duration-200 w-full text-left"
                    >
                      <div class="flex items-center gap-2.5">
                        <span class="w-7 h-7 rounded-lg bg-red-50 group-hover:bg-red-100 flex items-center justify-center text-red-500 transition-all duration-200 group-hover:scale-110">
                          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                          </svg>
                        </span>
                        <span>Cerrar sesión</span>
                      </div>
                      <svg class="w-3.5 h-3.5 text-red-400 group-hover:text-red-600 group-hover:translate-x-0.5 transition-all duration-200 opacity-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                      </svg>
                    </Link>
                  </div>
                </Transition>
              </div>
            </template>
            <template v-else>
              <Link :href="route('login')"
                :class="['text-sm font-medium transition-colors', isSolid ? 'text-slate-700 hover:text-brand-600' : 'text-white/90 hover:text-white']">
                Iniciar sesión
              </Link>
              <Link :href="route('register')"
                class="px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 transition-colors shadow-lg shadow-brand-600/25 whitespace-nowrap">
                Registrarse
              </Link>
            </template>
          </div>

          <!-- Mobile menu & auth actions (Mockup tal cual la imagen) -->
          <div class="md:hidden flex items-center gap-2">
            <template v-if="user">
              <Link :href="route('dashboard')"
                :class="['flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium border backdrop-blur-md transition-all active:scale-95',
                  isSolid ? 'bg-slate-100 text-slate-800 border-slate-200' : 'bg-white/10 hover:bg-white/20 text-white border-white/20 shadow-xs']">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                <span class="max-w-[75px] truncate">{{ user.name?.split(' ')[0] || 'Dashboard' }}</span>
              </Link>
            </template>
            <template v-else>
              <Link :href="route('login')"
                :class="['flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium border backdrop-blur-md transition-all active:scale-95',
                  isSolid ? 'bg-slate-100 text-slate-800 border-slate-200' : 'bg-white/10 hover:bg-white/20 text-white border-white/20 shadow-xs']">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                <span>Iniciar sesión</span>
              </Link>
            </template>

            <!-- Circular Hamburger button -->
            <button @click="mobileOpen = !mobileOpen"
              :class="['w-9 h-9 rounded-full flex items-center justify-center border backdrop-blur-md transition-all active:scale-95',
                isSolid ? 'bg-slate-100 text-slate-700 border-slate-200' : 'bg-white/10 hover:bg-white/20 text-white border-white/20 shadow-xs']"
              aria-label="Menú">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path v-if="!mobileOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"/>
                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
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
            <nav v-if="mobileOpen" class="flex-1 flex flex-col justify-center items-center text-center gap-2 -mt-12">
              <a v-for="link in navLinks" :key="link.href" :href="link.href"
                @click="handleMobileNavClick($event, link.href)"
                class="text-3xl font-black text-white/90 hover:text-white py-2 transition-colors">
                {{ link.label }}
              </a>
            </nav>
          </Transition>

          <div class="flex flex-col gap-3">
            <template v-if="user">
              <div class="flex items-center justify-center gap-3 px-1 mb-1">
                <img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name"
                  class="w-11 h-11 rounded-xl object-cover flex-shrink-0" width="44" height="44" />
                <div v-else class="w-11 h-11 bg-white/15 rounded-xl flex items-center justify-center text-white font-black flex-shrink-0">
                  {{ user.name?.charAt(0)?.toUpperCase() }}
                </div>
                <div class="min-w-0 text-left">
                  <p class="text-white font-semibold truncate">{{ user.name }}</p>
                  <p class="text-white/60 text-sm capitalize">{{ roleLabelText }}</p>
                </div>
              </div>
              <Link :href="route('dashboard')" @click="mobileOpen = false"
                class="text-center px-4 py-3.5 bg-white text-brand-800 font-bold rounded-xl shadow-lg">
                Ir a mi dashboard
              </Link>
              <Link :href="route('logout')" method="post" as="button" @click="mobileOpen = false"
                class="text-center px-4 py-3 text-red-300 hover:text-red-200 font-semibold border border-white/20 rounded-xl transition-colors">
                Cerrar sesión
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

const props = defineProps({
  solid: { type: Boolean, default: false },
})

const scrolled = ref(false)
const mobileOpen = ref(false)
const userMenuOpen = ref(false)
const userMenuRef = ref(null)

const user = computed(() => usePage().props.auth?.user)
const roleLabelText = computed(() => roleLabel(user.value?.roles?.[0]))
const isSolid = computed(() => props.solid || scrolled.value)

function onDocumentClick(e) {
  if (userMenuRef.value && !userMenuRef.value.contains(e.target)) {
    userMenuOpen.value = false
  }
}

function onDocumentKeydown(e) {
  if (e.key === 'Escape') {
    userMenuOpen.value = false
  }
}

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

function handleNavClick(e, href) {
  if (href.startsWith('/#') && (window.location.pathname === '/' || window.location.pathname === '')) {
    const id = href.replace('/#', '')
    const el = document.getElementById(id)
    if (el) {
      e.preventDefault()
      const navOffset = id === 'materias' ? 70 : 68
      const elTop = el.getBoundingClientRect().top + window.pageYOffset
      window.scrollTo({
        top: Math.max(0, elTop - navOffset),
        behavior: 'smooth'
      })
      history.pushState(null, '', href)
    }
  }
}

function handleMobileNavClick(e, href) {
  mobileOpen.value = false
  handleNavClick(e, href)
}

function onScroll() { scrolled.value = window.scrollY > 20 }
onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true })
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
})
onUnmounted(() => {
  window.removeEventListener('scroll', onScroll)
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
})
</script>

<style scoped>
/* ── Motion Graphic Micro-Interactions ─────────────────────────────────────── */

/* Rotación elástica de la flecha con spring curve */
.chevron-arrow {
  transition: transform 0.38s cubic-bezier(0.34, 1.56, 0.64, 1);
  will-change: transform;
}
.chevron-arrow-open {
  transform: rotate(180deg) translateY(1.5px);
}

/* Transición física con spring y desenfoque dinámico */
.motion-dropdown-enter-active {
  animation: motionDropIn 0.32s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}
.motion-dropdown-leave-active {
  animation: motionDropOut 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes motionDropIn {
  0% {
    opacity: 0;
    transform: scale(0.86) translateY(-10px);
    filter: blur(4px);
  }
  75% {
    opacity: 1;
    transform: scale(1.02) translateY(1px);
    filter: blur(0px);
  }
  100% {
    opacity: 1;
    transform: scale(1) translateY(0);
    filter: blur(0px);
  }
}

@keyframes motionDropOut {
  0% {
    opacity: 1;
    transform: scale(1) translateY(0);
    filter: blur(0px);
  }
  100% {
    opacity: 0;
    transform: scale(0.9) translateY(-8px);
    filter: blur(2px);
  }
}

/* Entrada escalonada de items */
.motion-dropdown-enter-active .motion-item:nth-of-type(1) {
  animation: itemSlideIn 0.28s cubic-bezier(0.16, 1, 0.3, 1) 0.05s both;
}
.motion-dropdown-enter-active .motion-item:nth-of-type(2) {
  animation: itemSlideIn 0.28s cubic-bezier(0.16, 1, 0.3, 1) 0.1s both;
}

@keyframes itemSlideIn {
  0% {
    opacity: 0;
    transform: translateX(8px);
  }
  100% {
    opacity: 1;
    transform: translateX(0);
  }
}
</style>
