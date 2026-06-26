<template>
  <AppLayout title="Mi panel">
    <div class="space-y-5 sm:space-y-6">

      <!-- Welcome banner -->
      <div class="bg-gradient-to-r from-brand-800 to-brand-600 rounded-2xl p-5 sm:p-6 text-white shadow-lg shadow-brand-800/20 flex items-center justify-between">
        <div>
          <p class="text-white/70 text-sm font-medium">Bienvenido/a de vuelta</p>
          <h2 class="text-xl sm:text-2xl font-black mt-0.5">{{ user?.name?.split(' ')[0] }}</h2>
          <p class="text-white/60 text-sm mt-1">{{ today }}</p>
        </div>
        <div class="text-5xl sm:text-6xl hidden sm:block opacity-80">👨‍👩‍👧</div>
      </div>

      <!-- Pending approval alert -->
      <div v-if="pending_approval > 0" class="bg-orange-50 border border-orange-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="w-10 h-10 bg-orange-100 rounded-xl flex items-center justify-center text-xl flex-shrink-0">⚠️</div>
        <div class="flex-1">
          <p class="font-semibold text-orange-900">{{ pending_approval }} solicitud(es) esperan tu aprobación</p>
          <p class="text-sm text-orange-600 mt-0.5">Revisa y aprueba las clases de tus hijos</p>
        </div>
        <Link :href="route('class-requests.index')"
          class="flex-shrink-0 px-4 py-2 bg-orange-500 text-white text-sm font-bold rounded-xl hover:bg-orange-600 transition-colors self-start sm:self-auto">
          Revisar
        </Link>
      </div>

      <!-- Stats — 1 col mobile, 3 desktop -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow text-center">
          <p class="text-3xl font-black text-brand-600">{{ students.length }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Hijos registrados</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow text-center">
          <p class="text-3xl font-black text-green-600">{{ upcoming.length }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Clases próximas</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow text-center">
          <p class="text-3xl font-black text-orange-500">{{ pending_approval }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Por aprobar</p>
        </div>
      </div>

      <!-- Diagnostic CTA -->
      <div class="bg-gradient-to-r from-indigo-50 to-brand-50 border border-brand-100 rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center gap-4">
        <div class="text-3xl flex-shrink-0">🎯</div>
        <div class="flex-1">
          <p class="font-bold text-slate-900">¿No sabes qué profesor elegir?</p>
          <p class="text-sm text-slate-500 mt-0.5">Responde 5 preguntas y MOVA te recomienda profesores ideales para tu hijo</p>
        </div>
        <Link :href="route('diagnostics.create')"
          class="flex-shrink-0 px-5 py-2.5 bg-brand-600 text-white font-bold rounded-xl text-sm hover:bg-brand-700 transition-colors shadow-sm self-start sm:self-auto">
          Hacer diagnóstico →
        </Link>
      </div>

      <!-- Students list -->
      <div v-if="students.length" class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <h3 class="font-bold text-slate-900">Mis hijos</h3>
          <Link :href="route('students.index')" class="text-sm text-brand-600 font-medium hover:underline">Ver todos →</Link>
        </div>
        <div class="divide-y divide-gray-50">
          <div v-for="s in students" :key="s.id" class="px-5 sm:px-6 py-3.5 flex items-center gap-3 hover:bg-slate-50 transition-colors">
            <div class="w-9 h-9 bg-gradient-to-br from-green-400 to-teal-500 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
              {{ s.first_name?.charAt(0) }}
            </div>
            <div>
              <p class="text-sm font-semibold text-slate-900">{{ s.first_name }} {{ s.last_name }}</p>
              <p class="text-xs text-slate-400 capitalize">{{ s.grade_level ?? 'Sin nivel asignado' }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Upcoming classes with Zoom link + password -->
      <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <h3 class="font-bold text-slate-900">Próximas clases</h3>
          <Link :href="route('parent.lessons')" class="text-sm text-brand-600 font-medium hover:underline">Ver todas →</Link>
        </div>
        <div v-if="upcoming.length" class="divide-y divide-gray-50">
          <div v-for="l in upcoming" :key="l.id" class="px-5 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
              <div class="w-10 h-10 bg-brand-50 rounded-xl flex items-center justify-center flex-shrink-0 text-brand-600 font-bold text-sm">
                {{ l.class_request?.subject?.name?.charAt(0) ?? '?' }}
              </div>
              <div class="min-w-0">
                <p class="font-semibold text-slate-900 truncate">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                <p class="text-xs text-slate-400">
                  {{ l.student?.first_name }} · Prof. {{ l.teacher_profile?.user?.name }} · {{ fmtDate(l.start_time) }}
                </p>
                <p v-if="l.zoom_password" class="text-xs text-slate-400 mt-0.5">🔑 Contraseña: {{ l.zoom_password }}</p>
              </div>
            </div>
            <a v-if="l.zoom_link" :href="l.zoom_link" target="_blank"
              class="flex-shrink-0 inline-flex items-center gap-1.5 px-4 py-1.5 bg-brand-600 text-white text-xs font-bold rounded-xl hover:bg-brand-700 transition-colors shadow-sm shadow-brand-600/30 self-start sm:self-auto">
              <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/><path d="M14 6a2 2 0 012-2h2a2 2 0 012 2v8a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z"/></svg>
              Entrar a Zoom
            </a>
          </div>
        </div>
        <div v-else class="px-6 py-10 text-center text-slate-400">
          <div class="text-4xl mb-2">📭</div>
          <p class="text-sm">No hay clases próximas</p>
          <Link :href="route('marketplace')" class="inline-block mt-3 text-sm text-brand-600 hover:underline font-medium">
            Buscar un profesor →
          </Link>
        </div>
      </div>

      <!-- Last learning report -->
      <div v-if="last_report" class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <h3 class="font-bold text-slate-900">Último reporte de aprendizaje</h3>
          <Link :href="route('parent.reports')" class="text-sm text-brand-600 font-medium hover:underline">Ver todos →</Link>
        </div>
        <div class="p-5 sm:p-6 space-y-3">
          <div class="flex flex-wrap items-center gap-2 mb-1">
            <span class="px-2.5 py-0.5 bg-brand-50 text-brand-700 text-xs font-semibold rounded-lg">{{ last_report.subject }}</span>
            <span class="text-xs text-slate-400">{{ last_report.student_name }} · Prof. {{ last_report.teacher_name }}</span>
          </div>
          <div class="grid sm:grid-cols-2 gap-3">
            <div class="bg-slate-50 rounded-xl p-3">
              <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">📚 Tema</p>
              <p class="text-sm text-slate-800 line-clamp-2">{{ last_report.topic_covered }}</p>
            </div>
            <div class="bg-slate-50 rounded-xl p-3">
              <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">⭐ Desempeño</p>
              <p class="text-sm text-slate-800 line-clamp-2">{{ last_report.student_performance }}</p>
            </div>
            <div v-if="last_report.next_step" class="sm:col-span-2 bg-green-50 rounded-xl p-3">
              <p class="text-xs font-semibold text-green-600 uppercase tracking-wide mb-1">🎯 Próximo paso</p>
              <p class="text-sm text-slate-800">{{ last_report.next_step }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick actions — 1 col mobile, 2 desktop -->
      <div>
        <h3 class="text-base font-bold text-slate-900 mb-3">Acciones rápidas</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Link :href="route('marketplace')"
            class="group bg-brand-600 rounded-2xl p-5 hover:bg-brand-700 transition-all shadow-lg shadow-brand-600/25">
            <div class="text-3xl mb-3">🔍</div>
            <p class="font-bold text-white">Buscar profesor</p>
            <p class="text-sm text-brand-200 mt-0.5">Explora el marketplace</p>
          </Link>
          <Link :href="route('students.index')"
            class="group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
            <div class="text-3xl mb-3">🎒</div>
            <p class="font-bold text-slate-900">Gestionar hijos</p>
            <p class="text-sm text-slate-400 mt-0.5">Añade o edita sus datos</p>
          </Link>
          <Link :href="route('parent.reports')"
            class="group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
            <div class="text-3xl mb-3">📋</div>
            <p class="font-bold text-slate-900">Reportes</p>
            <p class="text-sm text-slate-400 mt-0.5">Historial de aprendizaje</p>
          </Link>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({ students: Array, upcoming: Array, pending_approval: Number, last_report: Object })

const user  = computed(() => usePage().props.auth?.user)
const today = computed(() => new Date().toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }))

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}
</script>
