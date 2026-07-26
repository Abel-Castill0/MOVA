<template>
  <div class="space-y-3">
    <p class="text-xs text-slate-500">Marca los días y horarios en que puedes dar clases. Si lo dejas vacío no afecta tu visibilidad.</p>
    <div v-for="day in days" :key="day.key" class="flex items-start gap-3">
      <div class="w-24 flex-shrink-0 pt-2">
        <label class="flex items-center gap-1.5 cursor-pointer select-none">
          <input type="checkbox" :checked="hasSlots(day.key)" @change="toggleDay(day.key)"
            class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
          <span class="text-sm text-slate-700 font-medium">{{ day.label }}</span>
        </label>
      </div>
      <div v-if="hasSlots(day.key)" class="flex-1 space-y-1.5">
        <div v-for="(slot, idx) in modelValue.days[day.key]" :key="idx"
          class="flex items-center gap-2">
          <input type="time" :value="slot.start" @change="updateSlot(day.key, idx, 'start', $event.target.value)"
            class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm w-28 focus:ring-2 focus:ring-brand-500 focus:outline-none" />
          <span class="text-slate-400 text-xs">a</span>
          <input type="time" :value="slot.end" @change="updateSlot(day.key, idx, 'end', $event.target.value)"
            class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm w-28 focus:ring-2 focus:ring-brand-500 focus:outline-none" />
          <button type="button" @click="removeSlot(day.key, idx)"
            class="text-slate-300 hover:text-red-400 transition-colors text-lg leading-none">×</button>
        </div>
        <button v-if="modelValue.days[day.key].length < 3" type="button" @click="addSlot(day.key)"
          class="text-xs text-brand-600 hover:text-brand-700 font-medium">+ Agregar horario</button>
      </div>
    </div>
  </div>
</template>

<script setup>

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({
      timezone: 'America/Lima',
      days: { monday: [], tuesday: [], wednesday: [], thursday: [], friday: [], saturday: [], sunday: [] },
    }),
  },
});

const emit = defineEmits(['update:modelValue']);

const days = [
  { key: 'monday',    label: 'Lunes' },
  { key: 'tuesday',   label: 'Martes' },
  { key: 'wednesday', label: 'Miércoles' },
  { key: 'thursday',  label: 'Jueves' },
  { key: 'friday',    label: 'Viernes' },
  { key: 'saturday',  label: 'Sábado' },
  { key: 'sunday',    label: 'Domingo' },
];

function clone() {
  return JSON.parse(JSON.stringify(props.modelValue));
}

function hasSlots(day) {
  return (props.modelValue?.days?.[day]?.length ?? 0) > 0;
}

function toggleDay(day) {
  const val = clone();
  val.days[day] = hasSlots(day) ? [] : [{ start: '08:00', end: '10:00' }];
  emit('update:modelValue', val);
}

function addSlot(day) {
  const val = clone();
  val.days[day].push({ start: '08:00', end: '10:00' });
  emit('update:modelValue', val);
}

function removeSlot(day, idx) {
  const val = clone();
  val.days[day].splice(idx, 1);
  emit('update:modelValue', val);
}

function updateSlot(day, idx, field, value) {
  const val = clone();
  val.days[day][idx][field] = value;
  emit('update:modelValue', val);
}
</script>
