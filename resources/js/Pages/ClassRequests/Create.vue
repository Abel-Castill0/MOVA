<template>
  <AppLayout title="Solicitar clase">
    <div class="max-w-2xl">
      <h2 class="text-2xl font-bold text-gray-900 mb-6">Solicitar clase</h2>

      <div v-if="offer" class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 mb-6">
        <p class="text-sm font-medium text-indigo-700">Clase con: {{ offer.teacher_profile?.user?.name }}</p>
        <p class="text-sm text-indigo-600">{{ offer.subject?.name }} · €{{ parseFloat(offer.specific_rate ?? offer.teacher_profile?.hourly_rate ?? 0).toFixed(2) }}/h</p>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Hijo/a</label>
          <select v-model="form.student_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">Seleccionar...</option>
            <option v-for="s in students" :key="s.id" :value="s.id">{{ s.full_name }}</option>
          </select>
          <p v-if="form.errors.student_id" class="text-xs text-red-500 mt-1">{{ form.errors.student_id }}</p>
        </div>
        <div v-if="!offer">
          <label class="block text-sm font-medium text-gray-700 mb-1">Asignatura</label>
          <select v-model="form.subject_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">Seleccionar...</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">¿En qué necesita ayuda?</label>
          <textarea v-model="form.help_needed" rows="4" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
          <p v-if="form.errors.help_needed" class="text-xs text-red-500 mt-1">{{ form.errors.help_needed }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Disponibilidad horaria</label>
          <TimeSlotPicker v-model="form.preferred_times" />
        </div>
        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-50">
            Enviar solicitud
          </button>
          <Link :href="route('marketplace')" class="px-6 py-2.5 text-sm text-gray-600 hover:text-gray-900">Cancelar</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import TimeSlotPicker from '@/Components/TimeSlotPicker.vue'

const props = defineProps({ subjects: Array, students: Array, offer: Object })

const form = useForm({
  student_id: '',
  subject_id: props.offer?.subject_id ?? '',
  class_offer_id: props.offer?.id ?? null,
  help_needed: '',
  preferred_times: [],
})

function submit() {
  form.post(route('class-requests.store'))
}
</script>
