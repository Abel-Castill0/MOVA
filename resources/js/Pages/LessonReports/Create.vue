<template>
  <AppLayout title="Crear reporte">
    <div class="max-w-2xl mx-auto space-y-6">

      <!-- Header -->
      <div>
        <Link :href="route('teacher.lessons')" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-600 transition-colors mb-4">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
          </svg>
          Mis clases
        </Link>
        <h2 class="text-2xl font-black text-slate-900">Reporte de clase</h2>
        <p class="text-sm text-slate-500 mt-1">
          <span class="font-medium text-slate-700">{{ lesson.subject }}</span>
          · {{ lesson.student_name }}
          · {{ fmtDate(lesson.start_time) }}
        </p>
      </div>

      <!-- Info banner -->
      <div class="bg-brand-50 border border-brand-100 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 bg-brand-100 rounded-lg flex items-center justify-center text-brand-600 flex-shrink-0 text-sm">📝</div>
        <p class="text-sm text-brand-700">
          Este reporte se enviará al padre automáticamente. Sé concreto y positivo para que el padre entienda el progreso de su hijo.
        </p>
      </div>

      <!-- Form -->
      <form @submit.prevent="submit" class="space-y-5">

        <!-- Topic -->
        <div class="bg-white rounded-2xl border border-gray-100 p-5 space-y-1.5">
          <label class="block text-sm font-semibold text-slate-900">Tema trabajado <span class="text-red-500">*</span></label>
          <p class="text-xs text-slate-400">Ej: Fracciones equivalentes, análisis de texto narrativo, derivadas</p>
          <textarea
            v-model="form.topic_covered"
            rows="2"
            placeholder="¿Qué tema desarrollaron en esta clase?"
            class="w-full mt-1 px-3 py-2.5 border rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
            :class="errors.topic_covered ? 'border-red-300 bg-red-50' : 'border-gray-200'"
          ></textarea>
          <p v-if="errors.topic_covered" class="text-xs text-red-500">{{ errors.topic_covered }}</p>
        </div>

        <!-- Performance -->
        <div class="bg-white rounded-2xl border border-gray-100 p-5 space-y-1.5">
          <label class="block text-sm font-semibold text-slate-900">Desempeño del alumno <span class="text-red-500">*</span></label>
          <p class="text-xs text-slate-400">Ej: Participó activamente, logró resolver los ejercicios con poca ayuda</p>
          <textarea
            v-model="form.student_performance"
            rows="2"
            placeholder="¿Cómo estuvo el alumno durante la clase?"
            class="w-full mt-1 px-3 py-2.5 border rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
            :class="errors.student_performance ? 'border-red-300 bg-red-50' : 'border-gray-200'"
          ></textarea>
          <p v-if="errors.student_performance" class="text-xs text-red-500">{{ errors.student_performance }}</p>
        </div>

        <!-- Difficulties -->
        <div class="bg-white rounded-2xl border border-gray-100 p-5 space-y-1.5">
          <label class="block text-sm font-semibold text-slate-900">Dificultades detectadas</label>
          <p class="text-xs text-slate-400">Ej: Confunde multiplicación con suma, le cuesta leer en voz alta</p>
          <textarea
            v-model="form.difficulties_detected"
            rows="2"
            placeholder="¿Qué puntos requieren más práctica? (opcional)"
            class="w-full mt-1 px-3 py-2.5 border border-gray-200 rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
          ></textarea>
        </div>

        <!-- Homework -->
        <div class="bg-white rounded-2xl border border-gray-100 p-5 space-y-1.5">
          <label class="block text-sm font-semibold text-slate-900">Tarea o práctica recomendada</label>
          <p class="text-xs text-slate-400">Ej: Ejercicios 3–8 del libro p.45, leer 10 min diarios</p>
          <textarea
            v-model="form.homework_assigned"
            rows="2"
            placeholder="¿Qué debe practicar en casa? (opcional)"
            class="w-full mt-1 px-3 py-2.5 border border-gray-200 rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
          ></textarea>
        </div>

        <!-- Recommendation -->
        <div class="bg-white rounded-2xl border border-gray-100 p-5 space-y-1.5">
          <label class="block text-sm font-semibold text-slate-900">Mensaje para el padre</label>
          <p class="text-xs text-slate-400">Ej: Está mejorando mucho, sigan reforzando en casa con ejemplos prácticos</p>
          <textarea
            v-model="form.teacher_recommendation"
            rows="2"
            placeholder="Recomendación directa para el padre (opcional)"
            class="w-full mt-1 px-3 py-2.5 border border-gray-200 rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
          ></textarea>
        </div>

        <!-- Next step -->
        <div class="bg-white rounded-2xl border border-gray-100 p-5 space-y-1.5">
          <label class="block text-sm font-semibold text-slate-900">Próximo paso</label>
          <p class="text-xs text-slate-400">Ej: En la próxima clase veremos ecuaciones de segundo grado</p>
          <textarea
            v-model="form.next_step"
            rows="2"
            placeholder="¿Qué viene en la próxima clase? (opcional)"
            class="w-full mt-1 px-3 py-2.5 border border-gray-200 rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
          ></textarea>
        </div>

        <!-- Submit -->
        <button
          type="submit"
          :disabled="processing || !canSubmit"
          class="w-full py-3.5 px-6 bg-brand-600 text-white font-bold text-sm rounded-2xl hover:bg-brand-700 transition-colors shadow-lg shadow-brand-600/25 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
        >
          <svg v-if="processing" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
          </svg>
          {{ processing ? 'Enviando...' : 'Enviar reporte al padre' }}
        </button>

      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ lesson: Object })

const form = ref({
  topic_covered: '',
  student_performance: '',
  difficulties_detected: '',
  homework_assigned: '',
  teacher_recommendation: '',
  next_step: '',
})

const processing = ref(false)
const errors     = ref({})

const canSubmit = computed(() =>
  form.value.topic_covered.trim() && form.value.student_performance.trim()
)

function submit() {
  processing.value = true
  errors.value = {}
  router.post(route('lesson-reports.store', props.lesson.id), form.value, {
    onError: (e) => { errors.value = e },
    onFinish: () => { processing.value = false },
  })
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  })
}
</script>
