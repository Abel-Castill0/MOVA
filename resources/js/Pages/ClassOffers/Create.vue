<template>
  <AppLayout title="Nueva oferta">
    <div class="max-w-2xl">
      <h2 class="text-2xl font-bold text-gray-900 mb-6">Nueva oferta de clase</h2>
      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Asignatura</label>
          <select v-model="form.subject_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">Seleccionar...</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
          <p v-if="form.errors.subject_id" class="text-xs text-red-500 mt-1">{{ form.errors.subject_id }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Título del anuncio</label>
          <input v-model="form.title" type="text" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p v-if="form.errors.title" class="text-xs text-red-500 mt-1">{{ form.errors.title }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
          <textarea v-model="form.description" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tarifa específica (S//h, opcional)</label>
          <input v-model="form.specific_rate" type="number" min="0" step="0.5" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Dejar vacío para usar tu tarifa general" />
        </div>

        <!-- Availability -->
        <div class="border border-gray-100 rounded-xl overflow-hidden">
          <button type="button" @click="showAvailability = !showAvailability"
            class="w-full flex items-center justify-between px-4 py-3 bg-slate-50 text-sm font-medium text-slate-700 hover:bg-slate-100 transition-colors">
            <span>🗓 Disponibilidad horaria <span class="text-xs text-slate-400 font-normal">(opcional)</span></span>
            <span class="text-slate-400 text-xs">{{ showAvailability ? '▲ ocultar' : '▼ ver' }}</span>
          </button>
          <div v-if="showAvailability" class="p-4">
            <AvailabilityPicker v-model="form.availability_schedule" />
          </div>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
            Publicar oferta
          </button>
          <Link :href="route('class-offers.index')" class="px-6 py-2.5 text-sm text-gray-600 hover:text-gray-900">Cancelar</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvailabilityPicker from '@/Components/AvailabilityPicker.vue';

defineProps({ subjects: Array });

const showAvailability = ref(false);

const emptySchedule = () => ({
  timezone: 'America/Lima',
  days: { monday: [], tuesday: [], wednesday: [], thursday: [], friday: [], saturday: [], sunday: [] },
});

const form = useForm({
  subject_id:            '',
  title:                 '',
  description:           '',
  specific_rate:         '',
  availability_schedule: emptySchedule(),
});

function submit() {
  form.post(route('class-offers.store'));
}
</script>
