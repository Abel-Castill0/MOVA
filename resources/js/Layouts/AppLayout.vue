<template>
  <Head :title="title" />
  <!-- SE MANTIENE `bg-slate-50` FIJO, A PROPÓSITO.
       Se probó migrarlo a `bg-canvas` (idéntico en claro, oscuro en dark) para
       arreglar Operations, y la auditoría de contraste A/B demostró que
       REGRESIONA otras páginas: los títulos del resto de pantallas usan texto
       oscuro fijo (gray-900 / slate-900) directamente sobre este lienzo, así
       que oscurecerlo los deja en un ratio de 1.05 —invisibles— en
       /admin/lessons, /admin/recharges, /admin/requests, /admin/users,
       /class-requests y los tres dashboards.
       Mientras esas páginas no usen tokens de tinta, este fondo tiene que
       seguir siendo claro. Operations pinta su propia superficie. -->
  <div class="min-h-screen relative overflow-x-clip bg-[#EBF2FA]"
    style="background: radial-gradient(circle at 12% 12%, rgba(191, 219, 254, 0.55) 0%, transparent 45%), radial-gradient(circle at 88% 18%, rgba(186, 230, 253, 0.5) 0%, transparent 50%), radial-gradient(circle at 50% 95%, rgba(224, 231, 255, 0.45) 0%, transparent 55%), #EBF2FA;">

    <!-- Fondo de Luz Ambiental y Malla Hexagonal para todas las secciones -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none select-none z-0">
      <div class="absolute -top-24 -left-24 w-[34rem] h-[34rem] bg-blue-300/15 rounded-full blur-[100px]"></div>
      <div class="absolute top-1/4 -right-20 w-[36rem] h-[36rem] bg-sky-300/15 rounded-full blur-[110px]"></div>
      <div class="absolute -bottom-16 left-1/3 w-[32rem] h-[32rem] bg-indigo-200/15 rounded-full blur-[100px]"></div>
      <div class="absolute -left-48 top-8 w-[38rem] h-[38rem] rounded-full border border-brand-200/30"></div>
      <div class="absolute -right-48 -top-8 w-[42rem] h-[42rem] rounded-full border border-brand-200/35"></div>
      <svg class="absolute inset-0 w-full h-full opacity-60" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <pattern id="hex-grid-seamless" width="56" height="96" patternUnits="userSpaceOnUse">
            <path
              d="M28 0 L56 16 L56 48 L28 64 L0 48 L0 16 Z M28 64 L56 80 L56 112 L28 128 L0 112 L0 80 Z"
              fill="none"
              stroke="rgba(31, 90, 166, 0.05)"
              stroke-width="1"
            />
          </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#hex-grid-seamless)" />
      </svg>
    </div>

    <!-- Mobile overlay -->
    <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-200" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="sidebarOpen" class="fixed inset-0 bg-black/40 z-40 lg:hidden" @click="sidebarOpen = false" />
    </Transition>

    <!-- Sidebar (Diseño curvo rounded-r-3xl y frosted glass en todas las secciones) -->
    <aside :class="[
        'fixed inset-y-0 left-0 z-50 flex flex-col transition-all duration-300 ease-in-out',
        'bg-white/85 lg:bg-white/95 backdrop-blur-xl border-r border-slate-200/50 rounded-r-3xl shadow-2xl lg:shadow-none',
        isSidebarCompressed ? 'w-64 lg:w-20' : 'w-64',
        sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
      ]">

      <!-- Logo -->
      <div :class="['px-5 py-4 flex items-center border-b border-slate-100/80 transition-all duration-300', isSidebarCompressed ? 'justify-between lg:justify-center lg:px-2' : 'justify-between']">
        <Link href="/" class="flex items-center">
          <!-- Mobile (drawer completo w-64): siempre imagotipo ('Mova') -->
          <span class="lg:hidden flex items-center">
            <MovaLogo variant="imagotipo" class="h-8 w-auto" />
          </span>
          <!-- Desktop: alterna entre isotipo (w-20 comprimido) e imagotipo (w-64 expandido) -->
          <span class="hidden lg:flex items-center">
            <MovaLogo :variant="isSidebarCompressed ? 'isotipo' : 'imagotipo'" class="h-8 w-auto transition-all duration-300" />
          </span>
        </Link>
        <!-- Botón comprimir en el header (visible cuando está expandido en desktop) -->
        <button
          v-if="!isSidebarCompressed"
          @click="toggleSidebarCompression"
          type="button"
          title="Comprimir menú lateral"
          aria-label="Comprimir menú lateral"
          class="hidden lg:flex items-center justify-center w-8 h-8 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 active:scale-95 transition-all cursor-pointer"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
          </svg>
        </button>
        <button @click="sidebarOpen = false" aria-label="Cerrar menú"
          class="lg:hidden flex items-center justify-center w-11 h-11 -mr-2 rounded-xl text-slate-400 hover:text-slate-600 active:bg-slate-100 transition-colors">
          <Icon name="close" :size="20" />
        </button>
      </div>

      <!-- Nav con bordes redondeados y píldora active rounded-r-full -->
      <nav class="flex-1 px-3 py-4 space-y-1.5 overflow-y-auto" aria-label="Navegación principal">
        <Link v-for="item in navItems" :key="item.href"
          :href="item.href"
          @click="sidebarOpen = false"
          :aria-current="isActive(item.href) ? 'page' : undefined"
          :title="isSidebarCompressed ? item.label : undefined"
          :class="[
            'flex items-center text-sm font-semibold transition-all duration-150',
            isSidebarCompressed ? 'gap-3 lg:justify-center lg:px-2 py-2.5 rounded-xl' : 'gap-3',
            isActive(item.href)
              ? (isSidebarCompressed
                  ? ['-ml-3 pl-6 pr-4 lg:ml-0 lg:px-0 lg:w-11 lg:h-11 lg:mx-auto lg:rounded-2xl font-bold shadow-sm', isTeacher ? 'bg-orange-500 text-white shadow-orange-500/25' : 'bg-brand-600 text-white shadow-brand-600/25']
                  : ['-ml-3 pl-6 pr-4 py-2.5 rounded-l-none rounded-r-full font-bold shadow-sm', isTeacher ? 'bg-orange-500 text-white shadow-orange-500/25' : 'bg-brand-600 text-white shadow-brand-600/25'])
              : 'px-3 py-2.5 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900'
          ]">
          <Icon :name="item.icon" :size="18" class="flex-shrink-0" />
          <span :class="['truncate', isSidebarCompressed ? 'lg:hidden' : '']">{{ item.label }}</span>
          <span v-if="item.badge" :class="['ml-auto min-w-[20px] h-5 px-1 bg-white/25 rounded-full text-[11px] font-bold flex items-center justify-center', isSidebarCompressed ? 'lg:hidden' : '']">
            {{ item.badge }}
          </span>
        </Link>
      </nav>

      <!-- User footer con tarjeta rounded-2xl -->
      <div class="px-3 pb-4 pt-3 space-y-1.5 border-t border-slate-100/80">
        <div :class="['flex items-center rounded-2xl bg-slate-100/70 border border-slate-200/50 transition-all duration-300', isSidebarCompressed ? 'gap-3 px-3 py-2.5 lg:p-1.5 lg:justify-center' : 'gap-3 px-3 py-2.5']">
          <img v-if="user?.avatar_url" :src="user.avatar_url" :alt="user?.name"
            class="w-9 h-9 rounded-xl object-cover flex-shrink-0 ring-2 ring-white shadow-xs" width="36" height="36" />
          <div v-else :class="['w-9 h-9 rounded-xl flex items-center justify-center text-white font-black text-sm flex-shrink-0 shadow-xs', avatarClass]">
            {{ user?.name?.charAt(0)?.toUpperCase() }}
          </div>
          <div :class="['min-w-0 flex-1', isSidebarCompressed ? 'lg:hidden' : '']">
            <p class="text-sm font-bold text-slate-900 truncate leading-tight">{{ user?.name }}</p>
            <p class="text-xs text-slate-400 truncate mt-0.5">{{ roleLabel(user?.roles?.[0]) }}</p>
          </div>
          <NotificationBell :class="['flex-shrink-0', isSidebarCompressed ? 'lg:hidden' : '']" />
        </div>

        <!-- Botón para comprimir / expandir en el mismo sidebar (Desktop) -->
        <button
          type="button"
          @click="toggleSidebarCompression"
          :title="isSidebarCompressed ? 'Expandir menú lateral' : 'Comprimir menú lateral'"
          :aria-label="isSidebarCompressed ? 'Expandir menú lateral' : 'Comprimir menú lateral'"
          :class="[
            'hidden lg:flex items-center text-xs font-semibold rounded-xl text-slate-500 hover:text-brand-700 hover:bg-brand-50 active:bg-brand-100 transition-all duration-150 cursor-pointer',
            isSidebarCompressed ? 'w-full justify-center py-2.5' : 'w-full px-3 py-2 gap-2.5'
          ]"
        >
          <svg
            :class="['w-4 h-4 shrink-0 transition-transform duration-300', isSidebarCompressed ? 'rotate-180 text-brand-600' : 'text-slate-400']"
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
          </svg>
          <span :class="isSidebarCompressed ? 'lg:hidden' : 'truncate'">
            {{ isSidebarCompressed ? 'Expandir' : 'Comprimir menú' }}
          </span>
        </button>

        <Link :href="route('logout')" method="post" as="button"
          :title="isSidebarCompressed ? 'Cerrar sesión' : undefined"
          :class="['w-full flex items-center min-h-[40px] text-left text-sm text-slate-400 hover:text-red-600 rounded-xl hover:bg-red-50 active:bg-red-100 transition-colors font-medium', isSidebarCompressed ? 'gap-2.5 px-3 py-2 lg:justify-center lg:px-0' : 'gap-2.5 px-3 py-2']">
          <Icon name="logout" :size="16" class="flex-shrink-0" />
          <span :class="isSidebarCompressed ? 'lg:hidden' : ''">Cerrar sesión</span>
        </Link>
      </div>
    </aside>

    <!-- Main content -->
    <div :class="['flex flex-col min-h-screen relative z-10 transition-all duration-300 ease-in-out', isSidebarCompressed ? 'lg:ml-20' : 'lg:ml-64', compact ? 'lg:h-screen lg:max-h-screen' : '']">

      <!-- Top bar -->
      <header :class="headerClasses">
        <!-- Hamburger (mobile only) -->
        <button @click="sidebarOpen = true" aria-label="Abrir menú"
          class="lg:hidden flex items-center justify-center w-11 h-11 -ml-2 rounded-2xl text-slate-500 hover:bg-slate-100 active:bg-slate-200 transition-colors flex-shrink-0">
          <Icon name="menu" :size="20" />
        </button>

        <!-- Toggle para expandir menú (desktop cuando está comprimido) -->
        <button
          v-if="isSidebarCompressed"
          @click="toggleSidebarCompression"
          type="button"
          aria-label="Expandir menú lateral"
          title="Expandir menú lateral"
          class="hidden lg:flex items-center justify-center w-8 h-8 rounded-xl text-slate-400 hover:text-brand-600 hover:bg-slate-100 active:scale-95 transition-all cursor-pointer flex-shrink-0 -ml-1"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
          </svg>
        </button>

        <h1 class="text-base font-bold text-slate-900 flex-1 truncate">{{ title }}</h1>

        <div class="lg:hidden flex items-center gap-2 flex-shrink-0 bg-slate-100/70 border border-slate-200/50 py-1 px-2.5 rounded-2xl">
          <img v-if="user?.avatar_url" :src="user.avatar_url" :alt="user?.name"
            class="w-7 h-7 rounded-xl object-cover ring-1 ring-white" width="28" height="28" />
          <div v-else class="w-7 h-7 bg-gradient-to-br from-brand-500 to-brand-700 rounded-xl flex items-center justify-center text-white font-black text-xs flex-shrink-0 shadow-2xs">
            {{ user?.name?.charAt(0)?.toUpperCase() }}
          </div>
          <div class="min-w-0 leading-tight">
            <p class="text-xs font-bold text-slate-800 max-w-[5rem] truncate">{{ user?.name }}</p>
            <p class="text-[10px] text-slate-400 truncate">{{ roleLabel(user?.roles?.[0]) }}</p>
          </div>
        </div>

        <div class="lg:hidden">
          <NotificationBell placement="down-right" />
        </div>
      </header>

      <!-- Flash messages -->
      <div v-if="flash.success || flash.error" class="px-4 sm:px-6 lg:px-8 pt-4">
        <div v-if="flash.success" class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
          <Icon name="flash-success" :size="18" label="Éxito" class="flex-shrink-0" /> {{ flash.success }}
        </div>
        <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
          <Icon name="flash-error" :size="18" label="Error" class="flex-shrink-0" /> {{ flash.error }}
        </div>
      </div>

      <!-- Toast de notificación en tiempo real -->
      <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 -translate-y-2" leave-active-class="transition duration-200" leave-to-class="opacity-0">
        <div v-if="realtimeToast" class="px-4 sm:px-6 lg:px-8 pt-4">
          <div class="bg-brand-50 border border-brand-200 text-brand-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
            <Icon name="notification" :size="18" class="flex-shrink-0" /> {{ realtimeToast }}
          </div>
        </div>
      </Transition>

      <main
        ref="mainRef"
        @scroll.passive="checkScroll"
        :class="['flex-1 flex flex-col relative z-10 min-h-0', compact ? 'px-4 sm:px-6 lg:px-8 py-2 sm:py-2.5 overflow-y-auto' : (seamless ? 'px-3 sm:px-6 lg:px-8 py-2 sm:py-6' : 'px-4 sm:px-6 lg:px-8 py-4 sm:py-6')]"
      >
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import NotificationBell from '@/Components/NotificationBell.vue'
import MovaLogo from '@/Components/MovaLogo.vue'
import Icon from '@/Components/Icon.vue'
import { roleLabel } from '@/utils/roleLabels'

const props = defineProps({
  title: String,
  seamless: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
  sidebarCompressed: { type: Boolean, default: false }
})

const userCompressed = ref(false)

// Cargar preferencia guardada en el navegador
if (typeof window !== 'undefined') {
  try {
    userCompressed.value = localStorage.getItem('mova_sidebar_compressed') === 'true'
  } catch (e) {}
}

const isSidebarCompressed = computed(() => {
  return props.sidebarCompressed || userCompressed.value
})

function toggleSidebarCompression() {
  userCompressed.value = !userCompressed.value
  if (typeof window !== 'undefined') {
    try {
      localStorage.setItem('mova_sidebar_compressed', userCompressed.value ? 'true' : 'false')
    } catch (e) {}
  }
}

const page        = usePage()
const user        = computed(() => page.props.auth?.user)
const flash       = computed(() => page.props.flash ?? {})
const sidebarOpen = ref(false)
const realtimeToast = ref('')
let toastTimeout

const mainRef = ref(null)
const isScrolled = ref(false)

const isRequestsSection = computed(() => {
  const t = (props.title || '').trim().toLowerCase()
  return t === 'solicitudes' ||
         t === 'mis solicitudes' ||
         page.url.startsWith('/teacher/requests') ||
         page.url.startsWith('/class-requests')
})

const headerClasses = computed(() => {
  return [
    'w-full sticky top-0 z-30 flex items-center gap-3 transition-all duration-300 ease-in-out',
    isRequestsSection.value
      ? (isSidebarCompressed.value ? 'lg:-ml-20 lg:w-[calc(100%+5rem)] lg:pl-28' : 'lg:-ml-64 lg:w-[calc(100%+16rem)] lg:pl-72')
      : '',
    props.compact ? 'px-4 sm:px-6 lg:pr-8 h-[60px]' : 'px-4 sm:px-6 lg:pr-8 py-3.5 min-h-[64px]',
    // En mobile siempre muestra la barra con fondo frosted y borde inferior (añade shadow al scrollear)
    // En desktop es transparente en reposo superior y adquiere barra frosted al scrollear
    isScrolled.value
      ? (isRequestsSection.value
          ? 'bg-[#EBF2FA]/95 backdrop-blur-md border-b border-blue-200/50 shadow-xs lg:bg-[#BDD4F2]/95 lg:border-[#9EC3EB]'
          : 'bg-[#EBF2FA]/95 backdrop-blur-md border-b border-blue-200/50 shadow-xs lg:bg-[#EBF2FA]/95 lg:backdrop-blur-md lg:border-b lg:border-blue-200/50 lg:shadow-xs')
      : 'bg-[#EBF2FA]/95 backdrop-blur-md border-b border-blue-200/50 lg:bg-transparent lg:border-transparent lg:shadow-none'
  ]
})

function checkScroll(e) {
  const mainSt = mainRef.value?.scrollTop || 0
  const winSt = typeof window !== 'undefined' ? (window.scrollY || document.documentElement?.scrollTop || 0) : 0
  const targetSt = (e?.target && e.target !== window && e.target !== document && typeof e.target.scrollTop === 'number') ? e.target.scrollTop : 0
  const st = Math.max(mainSt, winSt, targetSt)
  isScrolled.value = st > 4
}

function showRealtimeToast(message) {
  if (!message) return
  clearTimeout(toastTimeout)
  realtimeToast.value = message
  toastTimeout = setTimeout(() => { realtimeToast.value = '' }, 6000)
}

let channel
let removeNavigateListener

onMounted(async () => {
  window.addEventListener('scroll', checkScroll, { passive: true, capture: true })
  if (mainRef.value) {
    mainRef.value.addEventListener('scroll', checkScroll, { passive: true })
  }
  removeNavigateListener = router.on('navigate', () => {
    isScrolled.value = false
    setTimeout(checkScroll, 50)
  })

  const userId = user.value?.id
  if (!userId) return

  const { initEcho } = await import('@/echo.js')
  const echo = initEcho()

  channel = echo.private(`App.Models.User.${userId}`)
  channel.notification((notification) => {
    router.reload({ only: ['lessons', 'notifications', 'auth'], preserveScroll: true })
    window.dispatchEvent(new CustomEvent('mova:notification', { detail: notification }))
    showRealtimeToast(notification.message)
  })
})

onUnmounted(() => {
  window.removeEventListener('scroll', checkScroll, { capture: true })
  if (mainRef.value) {
    mainRef.value.removeEventListener('scroll', checkScroll)
  }
  if (removeNavigateListener) {
    removeNavigateListener()
  }
  clearTimeout(toastTimeout)
  const userId = user.value?.id
  if (userId && window.Echo) {
    window.Echo.leave(`App.Models.User.${userId}`)
  }
})

const isTeacher = computed(() => (user.value?.roles ?? []).includes('teacher'))

// Sidebar active item color: naranja para profesores, azul MOVA para el resto
const activeNavClass = computed(() =>
  isTeacher.value
    ? 'bg-orange-500 text-white shadow-sm shadow-orange-500/30'
    : 'bg-brand-600 text-white shadow-sm shadow-brand-600/30'
)

// Avatar color por rol
const avatarClass = computed(() =>
  isTeacher.value
    ? 'bg-gradient-to-br from-orange-400 to-orange-600'
    : 'bg-gradient-to-br from-brand-500 to-brand-700'
)

function isActive(href) {
  if (href === '/marketplace' && (page.url.startsWith('/marketplace') || page.url.startsWith('/teachers'))) {
    return true
  }
  return page.url.startsWith(href) && href !== '/'
}

const navItems = computed(() => {
  const roles = user.value?.roles ?? []
  if (roles.includes('admin')) {
    return [
      { href: '/dashboard',               icon: 'dashboard',        label: 'Dashboard' },
      { href: '/admin/operations',        icon: 'under-review',     label: 'Operaciones' },
      { href: '/admin/users',             icon: 'users',            label: 'Usuarios' },
      { href: '/admin/pending-teachers',  icon: 'verify-teachers',  label: 'Verificar profesores' },
      { href: '/admin/requests',          icon: 'requests',         label: 'Solicitudes' },
      { href: '/admin/lessons',           icon: 'classes',          label: 'Clases' },
      { href: '/admin/recharges',         icon: 'credits',          label: 'Recargas' },
      { href: '/admin/reviews',           icon: 'reviews',          label: 'Reseñas' },
      { href: '/admin/ai-usage',          icon: 'ai-usage',         label: 'Uso de IA' },
    ]
  }
  if (roles.includes('teacher')) {
    return [
      { href: '/dashboard',        icon: 'home',     label: 'Inicio' },
      { href: '/teacher/requests', icon: 'requests', label: 'Solicitudes' },
      { href: '/teacher/classes',  icon: 'classes',  label: 'Mis clases' },
      // Antes era el literal 'C' renderizado como texto (no un icono real) —
      // hallazgo de la auditoría de diseño, corregido aquí.
      { href: '/teacher/credits',  icon: 'credits',  label: 'Mis créditos' },
      { href: '/teacher/profile', icon: 'profile',  label: 'Mi perfil' },
    ]
  }
  return [
    { href: '/dashboard',      icon: 'home',         label: 'Inicio' },
    { href: '/my-reports',     icon: 'my-reports',   label: 'Mis reportes' },
    { href: '/marketplace',    icon: 'teachers',     label: 'Profesores' },
    { href: '/class-requests', icon: 'requests',     label: 'Solicitudes' },
    { href: '/my-classes',     icon: 'classes',      label: 'Clases' },
  ]
})
</script>
