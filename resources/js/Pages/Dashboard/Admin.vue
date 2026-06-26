<template>
  <AppLayout title="Panel de administración">
    <div class="space-y-5 sm:space-y-6">

      <!-- Welcome -->
      <div class="bg-gradient-to-r from-brand-800 to-brand-600 rounded-2xl p-5 sm:p-6 text-white shadow-lg shadow-brand-800/20">
        <p class="text-white/70 text-sm font-medium mb-1">Bienvenido de vuelta</p>
        <h2 class="text-xl sm:text-2xl font-black">Panel de administración</h2>
        <p class="text-white/60 text-sm mt-1">{{ today }}</p>
      </div>

      <!-- Primary stats — 1 col mobile, 2 tablet, 4 desktop -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between mb-3">
            <span class="text-2xl">👥</span>
            <span class="text-xs font-semibold text-brand-600 bg-brand-50 px-2 py-0.5 rounded-full">Total</span>
          </div>
          <p class="text-3xl font-black text-slate-900">{{ stats.users }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Usuarios registrados</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between mb-3">
            <span class="text-2xl">⏳</span>
            <span class="text-xs font-semibold text-orange-600 bg-orange-50 px-2 py-0.5 rounded-full">Pendiente</span>
          </div>
          <p class="text-3xl font-black text-orange-500">{{ stats.pending_teachers }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Profesores por verificar</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between mb-3">
            <span class="text-2xl">🎓</span>
            <span class="text-xs font-semibold text-green-600 bg-green-50 px-2 py-0.5 rounded-full">Hoy</span>
          </div>
          <p class="text-3xl font-black text-green-600">{{ stats.classes_today }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Clases programadas hoy</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between mb-3">
            <span class="text-2xl">📚</span>
            <span class="text-xs font-semibold text-purple-600 bg-purple-50 px-2 py-0.5 rounded-full">Catálogo</span>
          </div>
          <p class="text-3xl font-black text-purple-600">{{ stats.subjects }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Materias disponibles</p>
        </div>
      </div>

      <!-- Quality & operational metrics -->
      <div>
        <h3 class="text-base font-bold text-slate-900 mb-3">Control de calidad</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
              <span class="text-2xl">📋</span>
              <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                :class="stats.incomplete_profiles > 0 ? 'text-yellow-700 bg-yellow-50' : 'text-green-600 bg-green-50'">
                {{ stats.incomplete_profiles > 0 ? 'Atención' : 'OK' }}
              </span>
            </div>
            <p class="text-3xl font-black" :class="stats.incomplete_profiles > 0 ? 'text-yellow-600' : 'text-green-600'">{{ stats.incomplete_profiles }}</p>
            <p class="text-sm text-slate-500 mt-0.5">Perfiles incompletos</p>
          </div>
          <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
              <span class="text-2xl">📬</span>
              <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                :class="stats.open_requests > 0 ? 'text-orange-600 bg-orange-50' : 'text-green-600 bg-green-50'">
                {{ stats.open_requests > 0 ? 'Pendiente' : 'OK' }}
              </span>
            </div>
            <p class="text-3xl font-black" :class="stats.open_requests > 0 ? 'text-orange-500' : 'text-green-600'">{{ stats.open_requests }}</p>
            <p class="text-sm text-slate-500 mt-0.5">Solicitudes abiertas</p>
          </div>
          <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
              <span class="text-2xl">📝</span>
              <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                :class="stats.completed_without_report > 0 ? 'text-red-600 bg-red-50' : 'text-green-600 bg-green-50'">
                {{ stats.completed_without_report > 0 ? 'Faltante' : 'OK' }}
              </span>
            </div>
            <p class="text-3xl font-black" :class="stats.completed_without_report > 0 ? 'text-red-500' : 'text-green-600'">{{ stats.completed_without_report }}</p>
            <p class="text-sm text-slate-500 mt-0.5">Clases sin reporte</p>
          </div>
          <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
              <span class="text-2xl">⚠️</span>
              <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                :class="stats.failed_jobs > 0 ? 'text-red-600 bg-red-50' : 'text-green-600 bg-green-50'">
                {{ stats.failed_jobs > 0 ? 'Error' : 'OK' }}
              </span>
            </div>
            <p class="text-3xl font-black" :class="stats.failed_jobs > 0 ? 'text-red-600' : 'text-green-600'">{{ stats.failed_jobs }}</p>
            <p class="text-sm text-slate-500 mt-0.5">Jobs fallidos en cola</p>
          </div>
          <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
              <span class="text-2xl">✉️</span>
              <span class="text-xs font-semibold text-slate-500 bg-slate-50 px-2 py-0.5 rounded-full">Email</span>
            </div>
            <p class="text-3xl font-black text-slate-700">{{ stats.unverified_email }}</p>
            <p class="text-sm text-slate-500 mt-0.5">Sin email verificado</p>
          </div>
          <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
              <span class="text-2xl">📱</span>
              <span class="text-xs font-semibold text-slate-500 bg-slate-50 px-2 py-0.5 rounded-full">WhatsApp</span>
            </div>
            <p class="text-3xl font-black text-slate-700">{{ stats.unverified_phone }}</p>
            <p class="text-sm text-slate-500 mt-0.5">Sin teléfono verificado</p>
          </div>
        </div>
      </div>

      <!-- Quick actions — 1 col mobile, 3 desktop -->
      <div>
        <h3 class="text-base font-bold text-slate-900 mb-3">Acciones rápidas</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <Link :href="route('admin.teachers.pending')"
            class="group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
            <div class="w-11 h-11 bg-brand-50 group-hover:bg-brand-100 rounded-xl flex items-center justify-center text-xl mb-3 transition-colors">✅</div>
            <p class="font-bold text-slate-900">Verificar profesores</p>
            <p class="text-sm text-slate-400 mt-0.5">{{ stats.pending_teachers }} pendientes</p>
          </Link>
          <Link :href="route('admin.users')"
            class="group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
            <div class="w-11 h-11 bg-brand-50 group-hover:bg-brand-100 rounded-xl flex items-center justify-center text-xl mb-3 transition-colors">👥</div>
            <p class="font-bold text-slate-900">Gestionar usuarios</p>
            <p class="text-sm text-slate-400 mt-0.5">{{ stats.users }} usuarios en total</p>
          </Link>
          <Link :href="route('marketplace')"
            class="group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
            <div class="w-11 h-11 bg-brand-50 group-hover:bg-brand-100 rounded-xl flex items-center justify-center text-xl mb-3 transition-colors">🏪</div>
            <p class="font-bold text-slate-900">Ver marketplace</p>
            <p class="text-sm text-slate-400 mt-0.5">Explora la plataforma</p>
          </Link>
        </div>
      </div>

      <!-- Recent users table with horizontal scroll on mobile -->
      <div v-if="recentUsers?.length" class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <h3 class="font-bold text-slate-900">Usuarios recientes</h3>
          <Link :href="route('admin.users')" class="text-sm text-brand-600 font-medium hover:underline">Ver todos →</Link>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[400px]">
            <tbody class="divide-y divide-gray-50">
              <tr v-for="u in recentUsers" :key="u.id" class="hover:bg-slate-50 transition-colors">
                <td class="px-5 sm:px-6 py-3">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-gradient-to-br from-brand-500 to-brand-700 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                      {{ u.name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-slate-900 truncate">{{ u.name }}</p>
                      <p class="text-xs text-slate-400 truncate">{{ u.email }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-5 sm:px-6 py-3 text-right">
                  <span :class="roleClass(u.roles?.[0])" class="text-xs font-semibold px-2.5 py-1 rounded-full capitalize whitespace-nowrap">
                    {{ u.roles?.[0] ?? 'sin rol' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({ stats: Object, recentUsers: Array })

const today = computed(() => new Date().toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }))

function roleClass(role) {
  return { admin: 'bg-red-50 text-red-600', teacher: 'bg-brand-50 text-brand-700', parent: 'bg-green-50 text-green-700' }[role] ?? 'bg-slate-100 text-slate-500'
}
</script>
