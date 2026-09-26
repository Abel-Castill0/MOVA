<template>
  <AppLayout title="Reportes de aprendizaje">
    <div class="space-y-6 max-w-5xl mx-auto">

      <!-- Header de la sección -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h2 class="text-2xl font-black text-slate-900 tracking-tight">Reportes de aprendizaje</h2>
          <p class="text-sm text-slate-500 mt-1">
            Consulta el progreso y los temas trabajados por tus hijos en cada clase, tal como los registró su profesor.
          </p>
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="!reports.length" class="text-center py-16 bg-white rounded-3xl border border-gray-100 shadow-sm">
        <div class="text-5xl mb-3">📋</div>
        <p class="font-bold text-slate-800 text-base">Aún no hay reportes</p>
        <p class="text-slate-400 text-sm mt-1">Los reportes aparecerán aquí después de cada clase.</p>
        <Link :href="route('class-requests.create')" class="inline-block mt-4 px-6 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-2xl hover:bg-brand-700 shadow-sm shadow-brand-600/20 transition-all">
          Solicitar una clase →
        </Link>
      </div>

      <!-- Lista de reportes -->
      <div v-else class="space-y-6">
        <div
          v-for="r in reports"
          :key="r.id"
          class="bg-white rounded-3xl border border-slate-200/80 hover:border-brand-200 hover:shadow-lg transition-all duration-300 overflow-hidden"
        >
          <!-- Barra superior de color por materia -->
          <div :class="['h-1.5 w-full bg-gradient-to-r', subjectTheme(r.subject).stripeGradient]"></div>

          <div class="p-5 sm:p-7 space-y-6">

            <!-- Encabezado del reporte: Curso, Alumno, Profesor y Fecha -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
              <div class="space-y-1.5">
                <div class="flex flex-wrap items-center gap-2">
                  <!-- Badge del Curso -->
                  <span :class="['inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold border shadow-2xs', subjectTheme(r.subject).badge]">
                    <span>{{ subjectTheme(r.subject).icon }}</span>
                    <span>{{ r.subject }}</span>
                  </span>

                  <!-- Fecha de la clase -->
                  <span class="text-xs font-medium text-slate-400 flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    {{ fmtDate(r.start_time) }}
                  </span>
                </div>

                <!-- Alumno y Profesor -->
                <h3 class="text-lg font-black text-slate-900 flex flex-wrap items-center gap-x-2">
                  <span>{{ r.student_name }}</span>
                  <span class="text-slate-300 font-normal">·</span>
                  <span class="text-sm font-semibold text-slate-500">Prof. {{ r.teacher_name }}</span>
                </h3>
              </div>
            </div>

            <!-- Detalles del reporte pedagógico -->
            <div class="grid sm:grid-cols-2 gap-3 pt-2">
              <div class="bg-slate-50/80 border border-slate-100 rounded-2xl p-4 transition-colors hover:bg-slate-50">
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>📚</span>
                  <span>Tema trabajado</span>
                </p>
                <p class="text-sm font-medium text-slate-800">{{ r.topic_covered }}</p>
              </div>

              <div class="bg-slate-50/80 border border-slate-100 rounded-2xl p-4 transition-colors hover:bg-slate-50">
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>⭐</span>
                  <span>Desempeño en clase</span>
                </p>
                <p class="text-sm font-medium text-slate-800">{{ r.student_performance }}</p>
              </div>

              <div v-if="r.difficulties_detected" class="bg-orange-50/70 border border-orange-100 rounded-2xl p-4">
                <p class="text-xs font-bold text-orange-600 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>⚠️</span>
                  <span>Puntos a reforzar</span>
                </p>
                <p class="text-sm text-slate-800">{{ r.difficulties_detected }}</p>
              </div>

              <div v-if="r.homework_assigned" class="bg-amber-50/70 border border-amber-100 rounded-2xl p-4">
                <p class="text-xs font-bold text-amber-700 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>📝</span>
                  <span>Tarea asignada</span>
                </p>
                <p class="text-sm text-slate-800">{{ r.homework_assigned }}</p>
              </div>

              <div v-if="r.teacher_recommendation" class="sm:col-span-2 bg-blue-50/70 border border-blue-100 rounded-2xl p-4">
                <p class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>💬</span>
                  <span>Mensaje y recomendación del profesor</span>
                </p>
                <p class="text-sm text-slate-800 leading-relaxed">{{ r.teacher_recommendation }}</p>
              </div>

              <div v-if="r.next_step" class="sm:col-span-2 bg-emerald-50/70 border border-emerald-100 rounded-2xl p-4">
                <p class="text-xs font-bold text-emerald-700 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>🎯</span>
                  <span>Próximo paso</span>
                </p>
                <p class="text-sm text-slate-800">{{ r.next_step }}</p>
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

// Solo datos reales del reporte que registró el profesor — sin métricas de
// "mejora" calculadas en el navegador ni cuestionarios sin respaldo en MOVA.
defineProps({ reports: { type: Array, default: () => [] } })

// Temas y colores por materia
function subjectTheme(subject) {
  const s = (subject || '').toLowerCase()
  if (s.includes('mat')) {
    return {
      icon: '📐',
      badge: 'bg-blue-50 text-blue-700 border-blue-200',
      stripeGradient: 'from-blue-600 via-indigo-600 to-brand-600'
    }
  }
  if (s.includes('ing') || s.includes('leng') || s.includes('idiom')) {
    return {
      icon: '🇬🇧',
      badge: 'bg-purple-50 text-purple-700 border-purple-200',
      stripeGradient: 'from-purple-600 via-indigo-600 to-brand-600'
    }
  }
  if (s.includes('cien') || s.includes('fís') || s.includes('quím') || s.includes('bio')) {
    return {
      icon: '🔬',
      badge: 'bg-emerald-50 text-emerald-700 border-emerald-200',
      stripeGradient: 'from-emerald-600 via-teal-600 to-brand-600'
    }
  }
  return {
    icon: '📚',
    badge: 'bg-brand-50 text-brand-700 border-brand-200',
    stripeGradient: 'from-brand-600 via-blue-600 to-indigo-600'
  }
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
  })
}
</script>
