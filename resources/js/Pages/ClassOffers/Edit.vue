<template>
  <AppLayout title="Editar oferta">
    <div class="max-w-2xl">
      <h2 class="text-2xl font-bold text-gray-900 mb-6">Editar oferta</h2>
      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Asignatura</label>
          <select v-model="form.subject_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Título</label>
          <input v-model="form.title" type="text" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
          <textarea v-model="form.description" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tarifa específica (€/h)</label>
          <input v-model="form.specific_rate" type="number" min="0" step="0.5" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-50">
            Guardar cambios
          </button>
          <Link :href="route('class-offers.index')" class="px-6 py-2.5 text-sm text-gray-600 hover:text-gray-900">Cancelar</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ offer: Object, subjects: Array })

const form = useForm({
  subject_id: props.offer.subject_id,
  title: props.offer.title,
  description: props.offer.description ?? '',
  specific_rate: props.offer.specific_rate ?? '',
  availability_schedule: props.offer.availability_schedule,
})

function submit() {
  form.patch(route('class-offers.update', props.offer.id))
}
</script>
