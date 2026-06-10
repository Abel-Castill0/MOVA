<template>
  <AppLayout title="Clases de mis hijos">
    <div class="space-y-5">

      <div class="flex items-center justify-between">
        <h2 class="text-xl font-black text-slate-900">Clases de mis hijos</h2>
        <span class="text-sm text-slate-400">{{ lessons.length }} en total</span>
      </div>

      <div v-if="!lessons.length" class="text-center py-16 bg-white rounded-2xl border border-gray-100">
        <div class="text-5xl mb-3">📅</div>
        <p class="font-semibold text-slate-700">No hay clases registradas</p>
        <p class="text-slate-400 text-sm mt-1">Busca un profesor y solicita la primera clase.</p>
        <Link :href="route('marketplace')" class="inline-block mt-4 px-5 py-2 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 transition-colors">
          Buscar profesor →
        </Link>
      </div>

      <div v-else class="space-y-3">
        <div v-for="l in lessons" :key="l.id"
          class="bg-white rounded-2xl border border-gray-100 hover:border-brand-200 transition-all hover:shadow-md overflow-hidden">

          <div :class="['h-1', statusStripe(l.status)]"></div>

          <div class="p-5">
            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">

              <!-- Info -->
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                  <p class="font-bold text-slate-900">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                  <StatusBadge :status="l.status" />
                </div>
                <p class="text-sm text-slate-600">
                  <span class="font-medium">Alumno:</span> {{ l.student?.first_name }} {{ l.student?.last_name }}
                </p>
                <p class="text-sm text-slate-500">
                  <span class="font-medium">Profesor:</span> {{ l.teacher_profile?.user?.name }}
                </p>
                <p class="text-sm text-slate-500 mt-0.5">
                  📅 {{ fmtDate(l.start_time) }} · {{ l.duration_minutes }} min
                </p>
              </div>

              <!-- Zoom block -->
              <div v-if="l.status === 'scheduled' && l.zoom_link" class="flex-shrink-0 bg-brand-50 border border-brand-100 rounded-xl p-4 min-w-0 sm:min-w-[220px]">
                <p class="text-xs font-semibold text-brand-600 uppercase tracking-wide mb-2">Unirse a la clase</p>
                <a :href="l.zoom_link" target="_blank" rel="noopener"
                  class="flex items-center gap-2 w-full px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow shadow-brand-600/25 mb-2">
                  <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                    <path d="M14 6a2 2 0 012-2h2a2 2 0 012 2v8a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z"/>
                  </svg>
                  Entrar a Zoom
                </a>
                <p v-if="l.zoom_password" class="text-xs text-center text-slate-500">
                  🔑 Contraseña: <strong class="text-slate-700 select-all">{{ l.zoom_password }}</strong>
                </p>
              </div>

              <div v-else-if="l.status !== 'scheduled'" class="flex-shrink-0 px-4 py-3 bg-slate-50 rounded-xl text-center">
                <p class="text-sm text-slate-400">{{ statusLabel(l.status) }}</p>
              </div>

            </div>
          </div>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'

defineProps({ lessons: Array })

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
  })
}

function statusStripe(s) {
  return { scheduled: 'bg-brand-500', completed: 'bg-green-500', cancelled: 'bg-red-400' }[s] ?? 'bg-slate-300'
}

function statusLabel(s) {
  return { scheduled: 'Programada', completed: 'Completada', cancelled: 'Cancelada' }[s] ?? s
}
</script>
