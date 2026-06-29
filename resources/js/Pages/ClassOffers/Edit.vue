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
          <label class="block text-sm font-medium text-gray-700 mb-1">Tarifa específica (S//h)</label>
          <input v-model="form.specific_rate" type="number" min="0" step="0.5" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
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
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvailabilityPicker from '@/Components/AvailabilityPicker.vue';

const props = defineProps({ offer: Object, subjects: Array });

const showAvailability = ref(!!props.offer.availability_schedule);

const emptySchedule = () => ({
  timezone: 'America/Lima',
  days: { monday: [], tuesday: [], wednesday: [], thursday: [], friday: [], saturday: [], sunday: [] },
});

const form = useForm({
  subject_id:            props.offer.subject_id,
  title:                 props.offer.title,
  description:           props.offer.description ?? '',
  specific_rate:         props.offer.specific_rate ?? '',
  availability_schedule: props.offer.availability_schedule ?? emptySchedule(),
});

function submit() {
  form.patch(route('class-offers.update', props.offer.id));
}
</script>
