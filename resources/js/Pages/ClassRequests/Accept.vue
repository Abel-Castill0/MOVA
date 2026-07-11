<template>
  <AppLayout title="Programar clase">
    <div class="max-w-xl">

      <!-- Header -->
      <div class="mb-6">
        <Link :href="route('teacher.requests')" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-brand-600 mb-3 transition-colors">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          Volver a solicitudes
        </Link>
        <h2 class="text-2xl font-black text-slate-900">Programar clase</h2>
        <p class="text-slate-500 text-sm mt-1">Elige la fecha y duración. Se creará una reunión Zoom automáticamente.</p>
      </div>

      <!-- Request summary card -->
      <div class="bg-brand-50 border border-brand-200 rounded-2xl p-4 mb-6 flex items-start gap-3">
        <div class="w-10 h-10 bg-brand-600 rounded-xl flex items-center justify-center text-white text-lg flex-shrink-0">📚</div>
        <div>
          <p class="font-bold text-brand-900">{{ classRequest.subject?.name }}</p>
          <p class="text-sm text-brand-700">Alumno: <strong>{{ classRequest.student?.first_name }} {{ classRequest.student?.last_name }}</strong></p>
          <p v-if="classRequest.help_needed" class="text-xs text-slate-500 mt-1 line-clamp-2">{{ classRequest.help_needed }}</p>
        </div>
      </div>

      <!-- Zoom error banner -->
      <div v-if="form.errors.zoom" class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-5 flex items-start gap-3">
        <span class="text-xl flex-shrink-0">❌</span>
        <div>
          <p class="font-semibold text-red-900 text-sm">Error al crear la reunión de Zoom</p>
          <p class="text-red-700 text-sm mt-0.5">{{ form.errors.zoom }}</p>
          <p class="text-red-500 text-xs mt-2">Asegúrate de que las credenciales ZOOM_* en el archivo .env sean correctas y que la app Zoom tenga el scope <code class="bg-red-100 px-1 rounded">meeting:write:admin</code>.</p>
        </div>
      </div>

      <!-- Form -->
      <form @submit.prevent="submit" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-5">

        <!-- Date/time -->
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">
            Fecha y hora de inicio <span class="text-red-500">*</span>
          </label>
          <input v-model="form.start_time" type="datetime-local" required :min="minDateTime"
            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" />
          <p v-if="form.errors.start_time" class="text-xs text-red-500 mt-1">{{ form.errors.start_time }}</p>
        </div>

        <!-- Duration -->
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Duración</label>
          <div class="grid grid-cols-4 gap-2">
            <button v-for="opt in durationOptions" :key="opt.value" type="button"
              @click="form.duration_minutes = opt.value"
              :class="['py-2.5 rounded-xl text-sm font-semibold border transition-all',
                form.duration_minutes === opt.value
                  ? 'bg-brand-600 text-white border-brand-600 shadow-md shadow-brand-600/25'
                  : 'bg-white text-slate-600 border-gray-200 hover:border-brand-300 hover:text-brand-700']">
              {{ opt.label }}
            </button>
          </div>
        </div>

        <!-- Zoom info note -->
        <div class="bg-slate-50 rounded-xl p-3 flex items-center gap-3 text-sm text-slate-600">
          <svg class="w-5 h-5 text-brand-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/><path d="M14 6a2 2 0 012-2h2a2 2 0 012 2v8a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z"/></svg>
          Se creará una reunión Zoom automáticamente con enlace y contraseña únicos.
        </div>

        <!-- Submit -->
        <div class="flex flex-col sm:flex-row gap-3 pt-2">
          <button type="submit" :disabled="form.processing || !form.start_time"
            class="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-brand-600 text-white font-bold rounded-xl hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-lg shadow-brand-600/20 hover:shadow-brand-600/30">
            <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <span>{{ form.processing ? 'Creando reunión Zoom...' : 'Confirmar y crear clase' }}</span>
          </button>
          <Link :href="route('teacher.requests')"
            class="px-6 py-3 text-sm font-semibold text-slate-600 hover:text-slate-900 rounded-xl border border-gray-200 hover:bg-slate-50 transition-colors text-center">
            Cancelar
          </Link>
        </div>
      </form>

    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ classRequest: Object })

const form = useForm({
  class_request_id: props.classRequest.id,
  start_time:       '',
  duration_minutes: 60,
})

const durationOptions = [
  { value: 30,  label: '30 min' },
  { value: 60,  label: '1 h' },
  { value: 90,  label: '1:30 h' },
  { value: 120, label: '2 h' },
]

// Minimum selectable time = now + 5 min
const minDateTime = computed(() => {
  const d = new Date(Date.now() + 5 * 60000)
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 16)
})

function submit() {
  form
    .transform(data => ({
      ...data,
      start_time: new Date(data.start_time).toISOString(),
    }))
    .post(route('lessons.store'))
}
</script>
