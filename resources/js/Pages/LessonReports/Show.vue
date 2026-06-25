<template>
  <AppLayout title="Reporte de clase">
    <div class="max-w-2xl mx-auto space-y-6">

      <!-- Back -->
      <Link :href="route('teacher.lessons')" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-600 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        Mis clases
      </Link>

      <!-- Header -->
      <div class="bg-white rounded-2xl border border-gray-100 p-6 sm:p-8">
        <div class="flex items-start gap-4">
          <div class="w-12 h-12 bg-green-100 rounded-2xl flex items-center justify-center text-2xl flex-shrink-0">✅</div>
          <div>
            <h2 class="text-xl font-black text-slate-900">Reporte enviado</h2>
            <p class="text-sm text-slate-500 mt-0.5">
              <span class="font-medium text-slate-700">{{ lesson.subject }}</span>
              · {{ lesson.student_name }}
              · {{ fmtDate(lesson.start_time) }}
            </p>
            <p v-if="lesson.teacher_name" class="text-xs text-slate-400 mt-0.5">Prof. {{ lesson.teacher_name }}</p>
          </div>
        </div>
      </div>

      <!-- Report content -->
      <div class="bg-white rounded-2xl border border-gray-100 divide-y divide-gray-50">
        <ReportField label="Tema trabajado" :value="report.topic_covered" icon="📚" />
        <ReportField label="Desempeño del alumno" :value="report.student_performance" icon="⭐" />
        <ReportField v-if="report.difficulties_detected" label="Dificultades detectadas" :value="report.difficulties_detected" icon="⚠️" />
        <ReportField v-if="report.homework_assigned" label="Tarea asignada" :value="report.homework_assigned" icon="📝" />
        <ReportField v-if="report.teacher_recommendation" label="Mensaje para el padre" :value="report.teacher_recommendation" icon="💬" />
        <ReportField v-if="report.next_step" label="Próximo paso" :value="report.next_step" icon="🎯" />
      </div>

      <p v-if="report.sent_to_parent_at" class="text-xs text-slate-400 text-center">
        Enviado al padre el {{ fmtDateFull(report.sent_to_parent_at) }}
      </p>

    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { defineComponent, h } from 'vue'

defineProps({ lesson: Object, report: Object })

const ReportField = defineComponent({
  props: { label: String, value: String, icon: String },
  setup(p) {
    return () => h('div', { class: 'px-5 sm:px-6 py-4' }, [
      h('div', { class: 'flex items-center gap-2 mb-1' }, [
        h('span', { class: 'text-base' }, p.icon),
        h('p', { class: 'text-xs font-semibold text-slate-500 uppercase tracking-wide' }, p.label),
      ]),
      h('p', { class: 'text-sm text-slate-800 leading-relaxed' }, p.value),
    ])
  },
})

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  })
}
function fmtDateFull(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}
</script>
