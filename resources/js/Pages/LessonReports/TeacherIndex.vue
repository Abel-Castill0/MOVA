<template>
  <AppLayout title="Mis reportes">
    <div class="space-y-5">

      <div class="flex items-center justify-between">
        <h2 class="text-xl font-black text-slate-900">Reportes enviados</h2>
        <span class="text-sm text-slate-400">{{ reports.length }} en total</span>
      </div>

      <div v-if="!reports.length" class="text-center py-16 bg-white rounded-2xl border border-gray-100">
        <div class="text-5xl mb-3">📝</div>
        <p class="font-semibold text-slate-700">Aún no has enviado reportes</p>
        <p class="text-slate-400 text-sm mt-1">Después de cada clase completada, podrás enviar un reporte al padre.</p>
        <Link :href="route('teacher.lessons')" class="inline-block mt-4 px-5 py-2 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 transition-colors">
          Ver mis clases →
        </Link>
      </div>

      <div v-else class="space-y-3">
        <div v-for="r in reports" :key="r.id"
          class="bg-white rounded-2xl border border-gray-100 hover:border-brand-200 hover:shadow-md transition-all p-5 flex flex-col sm:flex-row sm:items-start gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="px-2 py-0.5 bg-brand-50 text-brand-700 text-xs font-semibold rounded-lg">{{ r.subject }}</span>
              <span class="text-xs text-slate-400">{{ fmtDate(r.start_time) }}</span>
            </div>
            <p class="text-sm font-semibold text-slate-900">{{ r.student_name }}</p>
            <p class="text-sm text-slate-500 mt-1 line-clamp-2">{{ r.topic_covered }}</p>
          </div>
          <Link :href="route('lesson-reports.show', r.lesson_id)"
            class="flex-shrink-0 inline-flex items-center gap-1.5 px-4 py-2 border border-brand-200 text-brand-700 text-sm font-semibold rounded-xl hover:bg-brand-50 transition-colors self-start">
            Ver reporte
          </Link>
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
    day: 'numeric', month: 'short', year: 'numeric',
  })
}
</script>
