<template>
  <AppLayout title="Solicitar clase">
    <div class="max-w-2xl">
      <h2 class="text-2xl font-black text-slate-900 mb-6">Solicitar clase</h2>

      <div v-if="offer" class="bg-brand-50 border border-brand-200 rounded-2xl p-4 mb-6">
        <p class="text-sm font-medium text-brand-700">Clase con: {{ offer.teacher_profile?.user?.name }}</p>
        <p class="text-sm text-brand-600">
          {{ offer.subject?.name }} · S/ {{ parseFloat(offer.specific_rate ?? offer.teacher_profile?.hourly_rate ?? 0).toFixed(0) }}/h
        </p>
        <p v-if="form.is_mentorship" class="mt-2 inline-flex rounded-lg bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
          Solicitud de acompañamiento continuo
        </p>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-5">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Hijo/a</label>
          <select v-model="form.student_id" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
            <option value="">Seleccionar...</option>
            <option v-for="s in students" :key="s.id" :value="s.id">{{ s.full_name }}</option>
          </select>
          <p v-if="form.errors.student_id" class="text-xs text-red-500 mt-1">{{ form.errors.student_id }}</p>
        </div>
        <div v-if="!offer">
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Asignatura</label>
          <select v-model="form.subject_id" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
            <option value="">Seleccionar...</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">¿En qué necesita ayuda?</label>
          <textarea v-model="form.help_needed" rows="4" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"></textarea>
          <p v-if="form.errors.help_needed" class="text-xs text-red-500 mt-1">{{ form.errors.help_needed }}</p>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-2">Disponibilidad horaria</label>
          <TimeSlotPicker v-model="form.preferred_times" />
        </div>
        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="px-6 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 disabled:opacity-50 transition-all shadow-sm shadow-brand-600/20">
            Enviar solicitud
          </button>
          <Link :href="route('marketplace')" class="px-6 py-2.5 text-sm font-semibold text-slate-600 hover:text-slate-900 transition-colors">Cancelar</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import TimeSlotPicker from '@/Components/TimeSlotPicker.vue'

const props = defineProps({ subjects: Array, students: Array, offer: Object, isMentorship: Boolean })

const form = useForm({
  student_id: '',
  subject_id: props.offer?.subject_id ?? '',
  class_offer_id: props.offer?.id ?? null,
  is_mentorship: props.isMentorship ?? false,
  help_needed: '',
  preferred_times: [],
})

function submit() {
  form.post(route('class-requests.store'))
}
</script>
