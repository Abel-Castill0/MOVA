<template>
  <AppLayout title="Diagnóstico completado">
    <div class="mx-auto max-w-2xl space-y-5">
      <div class="rounded-2xl bg-gradient-to-r from-brand-800 to-brand-600 p-6 text-white">
        <p class="mb-1 text-sm text-white/70">Diagnóstico para {{ diagnostic.student }}</p>
        <h1 class="text-2xl font-black">Solicitud enviada a profesores verificados</h1>
        <div class="mt-3 flex flex-wrap gap-2">
          <span class="rounded-lg bg-white/20 px-2.5 py-1 text-xs font-medium">{{ goalLabel }}</span>
          <span class="rounded-lg bg-white/20 px-2.5 py-1 text-xs font-medium">{{ urgencyLabel }}</span>
          <span v-if="diagnostic.subject" class="rounded-lg bg-white/20 px-2.5 py-1 text-xs font-medium">{{ diagnostic.subject }}</span>
        </div>
      </div>

      <div v-if="diagnostic.ai_summary" class="flex items-start gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-5 py-4">
        <span class="text-lg text-indigo-400">✨</span>
        <div>
          <p class="mb-0.5 text-xs font-semibold uppercase tracking-wide text-indigo-600">MOVA entendió que necesitas</p>
          <p class="text-sm leading-snug text-indigo-900">{{ diagnostic.ai_summary }}</p>
        </div>
      </div>

      <div class="rounded-2xl border border-gray-100 bg-white p-6">
        <p class="font-semibold text-slate-900">Tu solicitud quedó registrada.</p>
        <p class="mt-1 text-sm text-slate-500">
          Los profesores verificados que enseñan esta materia podrán verla y responder desde su panel.
        </p>
        <Link :href="route('class-requests.index')" class="mt-5 inline-block rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-brand-700">
          Ver mis solicitudes
        </Link>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  diagnostic: Object,
})

const goalLabels = {
  prepare_exam: 'Preparar examen',
  solve_homework: 'Resolver tarea',
  continuous_support: 'Acompañamiento continuo',
}

const urgencyLabels = {
  today_or_tomorrow: 'Hoy o mañana',
  this_week: 'Esta semana',
  flexible: 'Sin prisa',
}

const goalLabel = computed(() => goalLabels[props.diagnostic.goal] ?? props.diagnostic.goal)
const urgencyLabel = computed(() => urgencyLabels[props.diagnostic.urgency] ?? props.diagnostic.urgency)
</script>
