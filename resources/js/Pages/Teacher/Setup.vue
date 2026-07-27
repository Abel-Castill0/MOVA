<template>
  <AppLayout title="Configurar perfil">
    <div class="max-w-2xl">
      <h2 class="text-2xl font-bold text-gray-900 mb-2">Configura tu perfil de profesor</h2>
      <p class="text-sm text-gray-500 mb-6">Cuéntanos sobre ti para que los padres puedan encontrarte</p>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Presentación / Bio</label>
          <textarea v-model="form.bio" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Describe tu experiencia y metodología..."></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tarifa por hora (S/)</label>
          <input v-model="form.hourly_rate" type="number" min="0" step="0.5" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p v-if="form.errors.hourly_rate" class="text-xs text-red-500 mt-1">{{ form.errors.hourly_rate }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Cupos de acompañamiento continuo</label>
          <input v-model="form.mentorship_slots_total" type="number" min="0" max="50" step="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p class="text-xs text-gray-500 mt-1">Define cuántos alumnos fijos puedes acompañar en paralelo.</p>
          <p v-if="form.errors.mentorship_slots_total" class="text-xs text-red-500 mt-1">{{ form.errors.mentorship_slots_total }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Asignaturas que impartes</label>
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            <label v-for="s in subjects" :key="s.id"
              :class="['flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer transition-colors text-sm',
                form.subject_ids.includes(s.id) ? 'bg-indigo-50 border-indigo-400 text-indigo-700' : 'bg-white border-gray-200 text-gray-700']">
              <input type="checkbox" :value="s.id" v-model="form.subject_ids" class="sr-only" />
              {{ s.name }}
            </label>
          </div>
          <p v-if="form.errors.subject_ids" class="text-xs text-red-500 mt-1">{{ form.errors.subject_ids }}</p>
        </div>
        <button type="submit" :disabled="form.processing"
          class="px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
          Guardar y continuar
        </button>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ subjects: Array, profile: Object })

const form = useForm({
  bio: '',
  hourly_rate: '',
  mentorship_slots_total: props.profile?.mentorship_slots_total ?? 0,
  subject_ids: [],
})

function submit() {
  form.post(route('teacher.setup.store'))
}
</script>
