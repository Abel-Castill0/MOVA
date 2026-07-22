<template>
  <div class="min-h-screen bg-slate-50">

    <!-- Mobile overlay -->
    <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-200" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="sidebarOpen" class="fixed inset-0 bg-black/40 z-20 lg:hidden" @click="sidebarOpen = false" />
    </Transition>

    <!-- Sidebar -->
    <aside :class="[
        'fixed inset-y-0 left-0 z-30 w-64 bg-white border-r border-gray-100 flex flex-col shadow-sm transition-transform duration-200 ease-in-out',
        sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
      ]">

      <!-- Logo -->
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <Link href="/" class="flex items-center gap-2">
          <div class="w-8 h-8 bg-gradient-to-br from-brand-600 to-brand-800 rounded-lg flex items-center justify-center shadow">
            <span class="text-white font-black text-sm">M</span>
          </div>
          <span class="font-black text-lg text-brand-900">MOVA</span>
        </Link>
        <button @click="sidebarOpen = false" class="lg:hidden p-1 rounded-lg text-slate-400 hover:text-slate-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <!-- Nav -->
      <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
        <Link v-for="item in navItems" :key="item.href"
          :href="item.href"
          @click="sidebarOpen = false"
          :class="['flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all',
            isActive(item.href)
              ? 'bg-brand-600 text-white shadow-md shadow-brand-600/30'
              : 'text-slate-600 hover:bg-slate-50 hover:text-brand-700']">
          <span class="text-base">{{ item.icon }}</span>
          {{ item.label }}
        </Link>
      </nav>

      <!-- User -->
      <div class="px-3 py-4 border-t border-gray-100">
        <div class="flex items-center gap-3 px-2 mb-2">
          <div class="w-9 h-9 bg-gradient-to-br from-brand-500 to-brand-700 rounded-xl flex items-center justify-center text-white font-black text-sm flex-shrink-0 shadow">
            {{ user?.name?.charAt(0)?.toUpperCase() }}
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-slate-900 truncate">{{ user?.name }}</p>
            <p class="text-xs text-slate-400 truncate capitalize">{{ user?.roles?.[0] ?? 'usuario' }}</p>
          </div>
          <NotificationBell class="flex-shrink-0" />
        </div>
        <Link :href="route('logout')" method="post" as="button"
          class="w-full text-left px-3 py-2 text-sm text-slate-500 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors mt-1">
          Cerrar sesión
        </Link>
      </div>
    </aside>

    <!-- Main content -->
    <div class="lg:ml-64 flex flex-col min-h-screen">

      <!-- Top bar -->
      <header class="bg-white border-b border-gray-100 px-4 sm:px-6 lg:px-8 py-3 sticky top-0 z-10 flex items-center gap-3">
        <!-- Hamburger (mobile only) -->
        <button @click="sidebarOpen = true"
          class="lg:hidden p-2 rounded-xl text-slate-500 hover:bg-slate-100 transition-colors flex-shrink-0">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
          </svg>
        </button>
        <h1 class="text-base font-bold text-slate-900 flex-1">{{ title }}</h1>
        <!-- Mobile notification bell -->
        <div class="lg:hidden">
          <NotificationBell />
        </div>
      </header>

      <!-- Flash messages -->
      <div v-if="flash.success || flash.error" class="px-4 sm:px-6 lg:px-8 pt-4">
        <div v-if="flash.success" class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
          <span>✅</span> {{ flash.success }}
        </div>
        <div v-if="flash.error" class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
          <span>❌</span> {{ flash.error }}
        </div>
      </div>

      <!-- Toast de notificación en tiempo real -->
      <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 -translate-y-2" leave-active-class="transition duration-200" leave-to-class="opacity-0">
        <div v-if="realtimeToast" class="px-4 sm:px-6 lg:px-8 pt-4">
          <div class="bg-brand-50 border border-brand-200 text-brand-800 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
            <span>🔔</span> {{ realtimeToast }}
          </div>
        </div>
      </Transition>

      <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import NotificationBell from '@/Components/NotificationBell.vue'

defineProps({ title: String })

const page        = usePage()
const user        = computed(() => page.props.auth?.user)
const flash       = computed(() => page.props.flash ?? {})
const sidebarOpen = ref(false)
const realtimeToast = ref('')
let toastTimeout

function showRealtimeToast(message) {
  if (!message) return
  clearTimeout(toastTimeout)
  realtimeToast.value = message
  toastTimeout = setTimeout(() => { realtimeToast.value = '' }, 6000)
}

let channel
onMounted(() => {
  const userId = user.value?.id
  if (!userId || !window.Echo) return

  channel = window.Echo.private(`App.Models.User.${userId}`)
  channel.notification((notification) => {
    router.reload({ only: ['lessons', 'notifications', 'auth'], preserveScroll: true })
    window.dispatchEvent(new CustomEvent('mova:notification', { detail: notification }))
    showRealtimeToast(notification.message)
  })
})

onUnmounted(() => {
  clearTimeout(toastTimeout)
  const userId = user.value?.id
  if (userId && window.Echo) {
    window.Echo.leave(`App.Models.User.${userId}`)
  }
})

function isActive(href) {
  return page.url.startsWith(href) && href !== '/'
}

const navItems = computed(() => {
  const roles = user.value?.roles ?? []
  if (roles.includes('admin')) {
    return [
      { href: '/dashboard',               icon: '📊', label: 'Dashboard' },
      { href: '/admin/users',             icon: '👥', label: 'Usuarios' },
      { href: '/admin/pending-teachers',  icon: '✅', label: 'Verificar profesores' },
      { href: '/admin/requests',          icon: '📋', label: 'Solicitudes' },
      { href: '/admin/lessons',           icon: '📅', label: 'Clases' },
      { href: '/admin/recharges',         icon: '💳', label: 'Recargas' },
      { href: '/admin/reviews',           icon: '⭐', label: 'Reseñas' },
      { href: '/admin/ai-usage',          icon: '🤖', label: 'Uso de IA' },
    ]
  }
  if (roles.includes('teacher')) {
    return [
      { href: '/dashboard',       icon: '🏠', label: 'Inicio' },
      { href: '/teacher/requests',icon: '📋', label: 'Solicitudes' },
      { href: '/class-offers',    icon: '📚', label: 'Mis ofertas' },
      { href: '/teacher/classes', icon: '📅', label: 'Mis clases' },
      { href: '/teacher/credits', icon: 'C', label: 'Mis créditos' },
      { href: '/teacher/profile', icon: '👤', label: 'Mi perfil' },
    ]
  }
  return [
    { href: '/dashboard',      icon: '🏠', label: 'Inicio' },
    { href: '/students',       icon: '🎒', label: 'Mis hijos' },
    { href: '/marketplace',    icon: '🔍', label: 'Buscar profesor' },
    { href: '/class-requests', icon: '📋', label: 'Solicitudes' },
    { href: '/my-classes',     icon: '📅', label: 'Clases' },
  ]
})
</script>
