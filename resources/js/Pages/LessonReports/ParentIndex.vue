<template>
  <AppLayout title="Reportes de aprendizaje">
    <div class="space-y-5">

      <div>
        <h2 class="text-xl font-black text-slate-900">Reportes de aprendizaje</h2>
        <p class="text-sm text-slate-500 mt-1">El profesor envía un reporte después de cada clase.</p>
      </div>

      <div v-if="!reports.length" class="text-center py-16 bg-white rounded-2xl border border-gray-100">
        <div class="text-5xl mb-3">📋</div>
        <p class="font-semibold text-slate-700">Aún no hay reportes</p>
        <p class="text-slate-400 text-sm mt-1">Los reportes aparecerán aquí después de cada clase.</p>
        <Link :href="route('class-requests.create')" class="inline-block mt-4 px-5 py-2 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 transition-colors">
          Solicitar una clase →
        </Link>
      </div>

      <div v-else class="space-y-4">
        <div v-for="r in reports" :key="r.id"
          class="bg-white rounded-2xl border border-gray-100 hover:border-brand-200 hover:shadow-md transition-all overflow-hidden">

          <!-- Color stripe by subject -->
          <div class="h-1 bg-brand-500"></div>

          <div class="p-5 sm:p-6 space-y-4">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
              <div>
                <div class="flex flex-wrap items-center gap-2 mb-1">
                  <span class="px-2.5 py-0.5 bg-brand-50 text-brand-700 text-xs font-semibold rounded-lg">{{ r.subject }}</span>
                  <span class="text-xs text-slate-400">{{ fmtDate(r.start_time) }}</span>
                </div>
                <p class="text-sm text-slate-600">
                  <span class="font-medium">{{ r.student_name }}</span>
                  · Prof. {{ r.teacher_name }}
                </p>
              </div>
            </div>

            <!-- Fields -->
            <div class="grid sm:grid-cols-2 gap-3">
              <div class="bg-slate-50 rounded-xl p-3">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">📚 Tema trabajado</p>
                <p class="text-sm text-slate-800">{{ r.topic_covered }}</p>
              </div>
              <div class="bg-slate-50 rounded-xl p-3">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">⭐ Desempeño</p>
                <p class="text-sm text-slate-800">{{ r.student_performance }}</p>
              </div>
              <div v-if="r.difficulties_detected" class="bg-orange-50 rounded-xl p-3">
                <p class="text-xs font-semibold text-orange-500 uppercase tracking-wide mb-1">⚠️ A practicar</p>
                <p class="text-sm text-slate-800">{{ r.difficulties_detected }}</p>
              </div>
              <div v-if="r.homework_assigned" class="bg-yellow-50 rounded-xl p-3">
                <p class="text-xs font-semibold text-yellow-600 uppercase tracking-wide mb-1">📝 Tarea</p>
                <p class="text-sm text-slate-800">{{ r.homework_assigned }}</p>
              </div>
              <div v-if="r.teacher_recommendation" class="sm:col-span-2 bg-blue-50 rounded-xl p-3">
                <p class="text-xs font-semibold text-blue-600 uppercase tracking-wide mb-1">💬 Mensaje del profesor</p>
                <p class="text-sm text-slate-800">{{ r.teacher_recommendation }}</p>
              </div>
              <div v-if="r.next_step" class="sm:col-span-2 bg-green-50 rounded-xl p-3">
                <p class="text-xs font-semibold text-green-600 uppercase tracking-wide mb-1">🎯 Próximo paso</p>
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

defineProps({ reports: Array })

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
  })
}
</script>
