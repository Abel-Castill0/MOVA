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
  <div class="min-h-screen relative overflow-x-clip bg-[#EBF2FA]">

    <!-- Mobile overlay -->
    <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-200" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="sidebarOpen" class="fixed inset-0 bg-black/40 z-40 lg:hidden" @click="sidebarOpen = false" />
    </Transition>

    <!-- Sidebar (Diseño curvo rounded-r-3xl y frosted glass en todas las secciones) -->
    <aside ref="sidebarRef" id="app-sidebar" aria-label="Menú principal" @keydown.tab="trapDrawerFocus" :class="[
        'fixed inset-y-0 left-0 z-50 flex flex-col transition-[width,transform] duration-200 ease-out motion-reduce:transition-none',
        'bg-white border-r border-slate-200 shadow-xl lg:shadow-none',
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
        <button ref="drawerCloseRef" @click="sidebarOpen = false" aria-label="Cerrar menú"
          class="lg:hidden flex items-center justify-center w-11 h-11 -mr-2 rounded-xl text-slate-400 hover:text-slate-600 active:bg-slate-100 transition-colors">
          <Icon name="close" :size="20" />
        </button>
      </div>

      <!-- Nav con bordes redondeados y píldora active rounded-r-full -->
      <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto" aria-label="Navegación principal">
        <Link v-for="item in navItems" :key="item.href"
          :href="item.href"
          @click="sidebarOpen = false"
          :aria-current="isActive(item.href) ? 'page' : undefined"
          :title="isSidebarCompressed ? item.label : undefined"
          :aria-label="isSidebarCompressed ? item.label : undefined"
          :class="[
            'flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400',
            isSidebarCompressed ? 'lg:justify-center lg:px-0' : '',
            isActive(item.href) ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
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
        <AccountMenu :compact="isSidebarCompressed" @navigate="sidebarOpen = false" />
        <div class="flex justify-center"><NotificationBell /></div>

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


      </div>
    </aside>

    <!-- Main content -->
    <div :class="['flex flex-col min-h-screen relative z-10 transition-all duration-300 ease-in-out', isSidebarCompressed ? 'lg:ml-20' : 'lg:ml-64', compact ? 'lg:h-screen lg:max-h-screen' : '']">

      <!-- Top bar -->
      <header :class="headerClasses">
        <!-- Hamburger (mobile only) -->
        <button ref="drawerOpenerRef" @click="sidebarOpen = true" aria-label="Abrir menú" aria-controls="app-sidebar" :aria-expanded="sidebarOpen"
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

        <!-- Movi vive en la cabecera (no flota sobre los botones de acción de cada pantalla). -->
        <button
          v-if="$page.props.movi?.enabled"
          type="button"
          class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors hover:border-brand-300 hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-brand-300/60 active:scale-95"
          aria-label="Abrir chat con Movi"
          title="Pregúntale a Movi"
          @click="moviOpen = true"
        >
          <MoviMascot pose="saluda" :size="30" />
        </button>

        <AccountMenu class="lg:hidden" compact placement="down" @navigate="sidebarOpen = false" />

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

    <ChatbotWidget v-if="$page.props.movi?.enabled" v-model="moviOpen" :floating="false" />
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AccountMenu from '@/Components/AccountMenu.vue'
import NotificationBell from '@/Components/NotificationBell.vue'
import ChatbotWidget from '@/Components/ChatbotWidget.vue'
import MoviMascot from '@/Components/MoviMascot.vue'
import MovaLogo from '@/Components/MovaLogo.vue'
import Icon from '@/Components/Icon.vue'
import { roleLabel } from '@/utils/roleLabels'

const moviOpen = ref(false)

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

const headerClasses = computed(() => [
  'w-full sticky top-0 z-30 flex items-center gap-3 transition-colors duration-200',
  props.compact ? 'px-4 sm:px-6 lg:px-8 h-[60px]' : 'px-4 sm:px-6 lg:px-8 py-3.5 min-h-[64px]',
  // Móvil: barra siempre visible. Escritorio: transparente arriba y con fondo al desplazarse.
  isScrolled.value
    ? 'bg-[#EBF2FA]/95 backdrop-blur-sm border-b border-blue-200/60'
    : 'bg-[#EBF2FA]/95 border-b border-blue-200/60 lg:bg-transparent lg:border-transparent',
])

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

let removeNavigateListener
let stopNotificationSignal

onMounted(async () => {
  window.addEventListener('scroll', checkScroll, { passive: true, capture: true })
  if (mainRef.value) {
    mainRef.value.addEventListener('scroll', checkScroll, { passive: true })
  }
  removeNavigateListener = router.on('navigate', () => {
    sidebarOpen.value = false
    isScrolled.value = false
    setTimeout(checkScroll, 50)
  })

  const userId = user.value?.id
  if (!userId) return

  // Tiempo real (Azure SignalR). Apagado o sin configurar es un estado normal, no un error: la interfaz sigue con su
  // refresco habitual. Las señales no traen datos; cada página pide los suyos al servidor.
  const realtime = await import('@/realtime.js')
  stopNotificationSignal = realtime.onSignal('notifications', () => {
    // La campana vuelve a pedir sus avisos; el contador de `auth` se actualiza con el mismo viaje.
    router.reload({ only: ['auth'], preserveScroll: true, preserveState: true })
    window.dispatchEvent(new CustomEvent('mova:notification'))
  })
  realtime.startRealtime(page.props.realtime, userId)
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
  stopNotificationSignal?.()
  // La conexión NO se cierra aquí: cada página tiene su propio layout y navegar no debe reabrirla. realtime.js la cierra
  // al salir de la sesión o cambiar de cuenta (lo detecta en cada navegación).
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
  const path = page.url.split('?')[0].split('#')[0]
  if (href === '/marketplace' && (path.startsWith('/marketplace') || path.startsWith('/teachers'))) return true
  if (href === '/') return false

  return path === href || path.startsWith(`${href}/`)
}

// Drawer móvil: Escape lo cierra, el foco entra al abrir y vuelve al botón que lo abrió, y Tab no escapa al fondo.
const sidebarRef = ref(null)
const drawerCloseRef = ref(null)
const drawerOpenerRef = ref(null)

function onDrawerKeydown(event) {
  if (event.key === 'Escape' && sidebarOpen.value) sidebarOpen.value = false
}

function trapDrawerFocus(event) {
  if (!sidebarOpen.value || !sidebarRef.value) return
  const items = [...sidebarRef.value.querySelectorAll('a[href], button:not([disabled])')].filter((el) => el.offsetParent !== null)
  if (!items.length) return
  const first = items[0]
  const last = items[items.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(sidebarOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
  if (open) {
    document.addEventListener('keydown', onDrawerKeydown)
    nextTick(() => drawerCloseRef.value?.focus())
  } else {
    document.removeEventListener('keydown', onDrawerKeydown)
    nextTick(() => drawerOpenerRef.value?.focus())
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', onDrawerKeydown)
  document.body.style.overflow = ''
})

const navItems = computed(() => {
  const roles = user.value?.roles ?? []
  if (roles.includes('admin')) {
    return [
      { href: '/dashboard',               icon: 'dashboard',        label: 'Dashboard' },
      { href: '/admin/operations',        icon: 'under-review',     label: 'Operaciones' },
      { href: '/admin/whatsapp',          icon: 'whatsapp',         label: 'WhatsApp' },
      { href: '/admin/users',             icon: 'users',            label: 'Usuarios' },
      { href: '/admin/pending-teachers',  icon: 'verify-teachers',  label: 'Verificar profesores' },
      { href: '/admin/requests',          icon: 'requests',         label: 'Solicitudes' },
      { href: '/admin/lessons',           icon: 'classes',          label: 'Clases' },
      { href: '/admin/recharges',         icon: 'credits',          label: 'Recargas' },
      { href: '/admin/reviews',           icon: 'reviews',          label: 'Reseñas' },
      { href: '/admin/ai-usage',          icon: 'ai-usage',         label: 'Uso de IA' },
      { href: '/admin/account-deletions', icon: 'profile', label: 'Privacidad' },
      { href: '/admin/complaints',        icon: 'reviews',          label: 'Reclamaciones' },
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
