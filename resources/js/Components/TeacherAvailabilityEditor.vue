<template>
  <form
    @submit.prevent="submit"
    class="bg-white rounded-2xl border border-slate-100 shadow-xs p-4 sm:p-5"
    aria-labelledby="availability-title"
  >
    <div class="flex items-center gap-2 mb-0.5">
      <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h2 id="availability-title" class="text-sm sm:text-base font-bold text-slate-900 tracking-tight">Mi disponibilidad semanal</h2>
    </div>
    <p class="text-[11px] text-slate-400 ml-6">
      Indica en qué franjas sueles poder dar clases. Horarios en hora de Lima (UTC−5).
      Sirve para recomendarte a las familias; no bloquea ni reserva ninguna clase.
    </p>

    <p v-if="form.errors.slots" class="mt-3 text-xs text-red-600" role="alert">{{ form.errors.slots }}</p>

    <ul class="mt-3.5 divide-y divide-slate-100">
      <li v-for="day in days" :key="day.value" class="py-2.5 flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4">
        <span class="w-24 flex-shrink-0 text-xs font-semibold text-slate-700 pt-2">{{ day.label }}</span>

        <div class="flex-1 space-y-2">
          <p v-if="!slotsOf(day.value).length" class="text-xs text-slate-400 pt-2">Sin franjas</p>

          <div v-for="{ slot, index } in slotsOf(day.value)" :key="index">
            <div class="flex flex-wrap items-center gap-2">
              <label class="sr-only" :for="`start-${index}`">Inicio, {{ day.label }}</label>
              <input
                :id="`start-${index}`"
                v-model="slot.start_time"
                type="time"
                step="900"
                required
                class="px-2.5 py-1.5 bg-white border border-slate-200 rounded-xl text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400"
              />
              <span class="text-xs text-slate-400" aria-hidden="true">a</span>
              <label class="sr-only" :for="`end-${index}`">Fin, {{ day.label }}</label>
              <input
                :id="`end-${index}`"
                v-model="slot.end_time"
                type="time"
                step="900"
                required
                class="px-2.5 py-1.5 bg-white border border-slate-200 rounded-xl text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400"
              />
              <button
                type="button"
                @click="removeSlot(index)"
                class="text-xs text-slate-500 hover:text-red-600 px-2 py-1 rounded-lg hover:bg-red-50 transition"
                :aria-label="`Quitar franja de ${day.label}`"
              >
                Quitar
              </button>
            </div>
            <p v-if="form.errors[`slots.${index}.start_time`]" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors[`slots.${index}.start_time`] }}</p>
            <p v-if="form.errors[`slots.${index}.end_time`]" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors[`slots.${index}.end_time`] }}</p>
            <p v-if="form.errors[`slots.${index}.day_of_week`]" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors[`slots.${index}.day_of_week`] }}</p>
          </div>

          <button
            type="button"
            @click="addSlot(day.value)"
            :disabled="form.slots.length >= maxSlots"
            class="text-xs font-semibold text-blue-600 hover:text-blue-700 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            + Agregar franja
          </button>
        </div>
      </li>
    </ul>

    <div class="flex items-center justify-between gap-3 mt-3.5">
      <p class="text-[11px] text-slate-400">{{ form.slots.length }} de {{ maxSlots }} franjas</p>
      <button
        type="submit"
        :disabled="form.processing"
        class="inline-flex items-center gap-2 px-5 py-2 bg-[#155dfc] hover:bg-blue-700 active:scale-95 text-white font-bold text-sm rounded-xl shadow-md shadow-blue-500/20 transition disabled:opacity-60"
      >
        {{ form.processing ? 'Guardando...' : 'Guardar disponibilidad' }}
      </button>
    </div>
  </form>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
  // [{ day_of_week: 0-6 (0 = domingo), start_time: 'HH:MM', end_time: 'HH:MM' }]
  slots: { type: Array, default: () => [] },
  maxSlots: { type: Number, default: 28 },
})

// Lunes primero; 0 = domingo (Carbon::dayOfWeek en el servidor).
const days = [
  { value: 1, label: 'Lunes' },
  { value: 2, label: 'Martes' },
  { value: 3, label: 'Miércoles' },
  { value: 4, label: 'Jueves' },
  { value: 5, label: 'Viernes' },
  { value: 6, label: 'Sábado' },
  { value: 0, label: 'Domingo' },
]

const form = useForm({
  slots: props.slots.map((s) => ({ day_of_week: s.day_of_week, start_time: s.start_time, end_time: s.end_time })),
})

function slotsOf(day) {
  return form.slots
    .map((slot, index) => ({ slot, index }))
    .filter(({ slot }) => slot.day_of_week === day)
}

function addSlot(day) {
  if (form.slots.length >= props.maxSlots) return
  form.slots.push({ day_of_week: day, start_time: '15:00', end_time: '18:00' })
}

function removeSlot(index) {
  form.slots.splice(index, 1)
}

function submit() {
  form.put(route('teacher.availability.update'), { preserveScroll: true })
}
</script>
