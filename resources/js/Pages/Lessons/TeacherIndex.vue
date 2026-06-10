<template>
  <AppLayout title="Mis clases">
    <div class="space-y-5">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <h2 class="text-xl font-black text-slate-900">Mis clases</h2>
        <span class="text-sm text-slate-400">{{ lessons.length }} en total</span>
      </div>

      <!-- Empty state -->
      <div v-if="!lessons.length" class="text-center py-16 bg-white rounded-2xl border border-gray-100">
        <div class="text-5xl mb-3">📅</div>
        <p class="font-semibold text-slate-700">No hay clases programadas</p>
        <p class="text-slate-400 text-sm mt-1">Cuando aceptes solicitudes, tus clases aparecerán aquí.</p>
        <Link :href="route('teacher.requests')" class="inline-block mt-4 px-5 py-2 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 transition-colors">
          Ver solicitudes →
        </Link>
      </div>

      <!-- Lessons list -->
      <div v-else class="space-y-3">
        <div v-for="l in lessons" :key="l.id"
          class="bg-white rounded-2xl border border-gray-100 hover:border-brand-200 transition-all hover:shadow-md overflow-hidden">

          <!-- Status stripe -->
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
                <p class="text-sm text-slate-500 mt-0.5">
                  📅 {{ fmtDate(l.start_time) }} · {{ l.duration_minutes }} min
                </p>
              </div>

              <!-- Zoom block (only when scheduled) -->
              <div v-if="l.status === 'scheduled' && l.zoom_link" class="flex-shrink-0 bg-brand-50 border border-brand-100 rounded-xl p-4 min-w-0 sm:min-w-[220px]">
                <p class="text-xs font-semibold text-brand-600 uppercase tracking-wide mb-2">Enlace Zoom</p>
                <a :href="l.zoom_link" target="_blank" rel="noopener"
                  class="flex items-center gap-2 w-full px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow shadow-brand-600/25 mb-2">
                  <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                    <path d="M14 6a2 2 0 012-2h2a2 2 0 012 2v8a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z"/>
                  </svg>
                  Unirse a la clase
                </a>
                <p v-if="l.zoom_password" class="text-xs text-slate-500 text-center">
                  🔑 Contraseña: <strong class="text-slate-700 select-all">{{ l.zoom_password }}</strong>
                </p>
                <p class="text-xs text-slate-400 truncate mt-1 text-center" :title="l.zoom_link">
                  ID: {{ zoomId(l.zoom_link) }}
                </p>
              </div>

              <!-- Cancelled / completed state -->
              <div v-else-if="l.status !== 'scheduled'" class="flex-shrink-0 text-center px-4 py-3 bg-slate-50 rounded-xl">
                <p class="text-sm text-slate-400 capitalize">{{ statusLabel(l.status) }}</p>
              </div>

            </div>

            <!-- Cancel button -->
            <div v-if="l.status === 'scheduled'" class="mt-3 pt-3 border-t border-gray-50 flex justify-end">
              <Link :href="route('lessons.cancel', l.id)" method="post" as="button"
                class="text-xs text-red-500 hover:text-red-700 hover:bg-red-50 px-3 py-1.5 rounded-lg transition-colors font-medium"
                :data="{ _method: 'POST' }">
                Cancelar clase
              </Link>
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

function zoomId(url) {
  return url?.match(/\/j\/(\d+)/)?.[1] ?? '—'
}

function statusStripe(s) {
  return { scheduled: 'bg-brand-500', completed: 'bg-green-500', cancelled: 'bg-red-400' }[s] ?? 'bg-slate-300'
}

function statusLabel(s) {
  return { scheduled: 'Programada', completed: 'Completada', cancelled: 'Cancelada' }[s] ?? s
}
</script>
