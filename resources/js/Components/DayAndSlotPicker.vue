<template>
  <div class="space-y-4">
    <!-- 1. Día -->
    <div>
      <p :id="`${uid}-day`" class="text-[11px] sm:text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">1 · El día</p>

      <div class="rounded-2xl border-2 border-slate-200/90 bg-white p-2.5 sm:p-3.5 shadow-2xs" role="group" :aria-labelledby="`${uid}-day`">
        <div class="mb-2 flex items-center justify-between">
          <button type="button" class="flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 disabled:opacity-30 disabled:hover:bg-transparent"
            :disabled="!canPrev" aria-label="Mes anterior" data-testid="cal-prev" @click="shiftMonth(-1)">
            <span aria-hidden="true" class="text-lg font-black">‹</span>
          </button>
          <p class="text-sm sm:text-base font-black text-slate-900 capitalize" aria-live="polite" data-testid="cal-month">{{ monthTitle }}</p>
          <button type="button" class="flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 disabled:opacity-30 disabled:hover:bg-transparent"
            :disabled="!canNext" aria-label="Mes siguiente" data-testid="cal-next" @click="shiftMonth(1)">
            <span aria-hidden="true" class="text-lg font-black">›</span>
          </button>
        </div>

        <div class="grid grid-cols-7 gap-1 text-center" aria-hidden="true">
          <span v-for="w in WEEK" :key="w" class="pb-1 text-[11px] font-bold uppercase text-slate-500">{{ w }}</span>
        </div>
        <div class="grid grid-cols-7 gap-1">
          <template v-for="(week, wi) in grid" :key="wi">
            <template v-for="(key, di) in week" :key="`${wi}-${di}`">
              <span v-if="!key" aria-hidden="true" />
              <button v-else type="button"
                :disabled="isDisabled(key)"
                :aria-pressed="key === date"
                :aria-label="ariaDay(key)"
                :data-testid="`day-${key}`"
                :class="['relative flex h-11 items-center justify-center rounded-xl text-sm font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400',
                  key === date ? 'bg-brand-600 text-white shadow-sm'
                    : isDisabled(key) ? 'text-slate-300 cursor-not-allowed'
                    : 'text-slate-800 hover:bg-brand-50']"
                @click="pickDay(key)">
                {{ Number(key.slice(8)) }}
                <span v-if="key === today && key !== date" class="absolute bottom-1 h-1 w-1 rounded-full bg-brand-600" aria-hidden="true" />
              </button>
            </template>
          </template>
        </div>
        <p class="mt-2 text-[11px] sm:text-xs text-slate-500">Hora de Perú. Puedes pedir hasta {{ maxDaysAhead }} días por adelantado.</p>
      </div>
    </div>

    <!-- 2. Franja -->
    <div>
      <p :id="`${uid}-slot`" class="text-[11px] sm:text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">2 · El momento</p>
      <div class="grid grid-cols-3 gap-2 sm:gap-3" role="radiogroup" :aria-labelledby="`${uid}-slot`">
        <button v-for="s in slots" :key="s.value" type="button" role="radio"
          :aria-checked="slot === s.value"
          :disabled="!date || ended(s)"
          :data-testid="`slot-${s.value}`"
          :class="['flex flex-col items-center gap-1 rounded-2xl border-2 px-2 py-3 sm:py-4 text-center transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-40',
            slot === s.value ? 'border-brand-600 bg-brand-50/90 text-brand-900 shadow-sm ring-2 ring-brand-500/10'
              : 'border-slate-200/90 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50/60']"
          @click="$emit('update:slot', s.value)">
          <Icon :name="SLOT_ICONS[s.value]" :size="26" :class="slot === s.value ? 'text-brand-600' : 'text-slate-500'" />
          <span class="text-sm sm:text-base font-black">{{ s.label }}</span>
          <span class="text-[11px] sm:text-xs font-medium text-slate-500">{{ slotRange(s) }}</span>
          <span v-if="slot === s.value" class="text-[11px] font-bold text-brand-700" aria-hidden="true">✓ Elegida</span>
        </button>
      </div>
      <p v-if="!date" class="mt-1.5 text-[11px] sm:text-xs text-slate-500">Primero elige el día.</p>
      <p v-else-if="allEnded" class="mt-1.5 text-[11px] sm:text-xs text-amber-700" role="status">Las franjas de hoy ya terminaron. Elige otro día.</p>
    </div>

    <p v-if="error" class="text-xs font-semibold text-rose-600" role="alert">{{ error }}</p>

    <!-- Resumen de lo elegido -->
    <p v-if="date && slot" class="rounded-xl border border-brand-200 bg-brand-50/70 px-3.5 py-2.5 text-sm text-brand-900" role="status" data-testid="day-slot-summary">
      <strong>{{ describePreference(date, slot) }}.</strong> El profesor propondrá la hora exacta dentro de ese momento y tú la confirmas.
    </p>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import Icon from '@/Components/Icon.vue'
import {
  SLOT_ICONS, addDays, dayLabel, describePreference, monthGrid, monthLabel, parseDayKey, slotHasEnded, slotRange,
} from '@/utils/requestSlots'

const props = defineProps({
  date: { type: String, default: '' },
  slot: { type: String, default: '' },
  slots: { type: Array, required: true },       // [{value,label,start,end}] desde config/class_requests.php
  today: { type: String, required: true },      // «hoy» en Lima, calculado por el servidor
  maxDaysAhead: { type: Number, default: 60 },
  error: { type: String, default: '' },
  now: { type: Object, default: null },         // solo pruebas: reloj inyectable
})
const emit = defineEmits(['update:date', 'update:slot'])

const uid = `ds-${Math.random().toString(36).slice(2, 8)}`
const WEEK = ['L', 'M', 'M', 'J', 'V', 'S', 'D']
const max = computed(() => addDays(props.today, props.maxDaysAhead))

const start = parseDayKey(props.date) ?? parseDayKey(props.today)
const view = ref({ y: start.y, m: start.m })
watch(() => props.date, (d) => {
  const p = parseDayKey(d)
  if (p) view.value = { y: p.y, m: p.m }
})

const grid = computed(() => monthGrid(view.value.y, view.value.m))
const monthTitle = computed(() => monthLabel(view.value.y, view.value.m))
const ym = (y, m) => y * 12 + m
const minYm = computed(() => { const p = parseDayKey(props.today); return ym(p.y, p.m) })
const maxYm = computed(() => { const p = parseDayKey(max.value); return ym(p.y, p.m) })
const canPrev = computed(() => ym(view.value.y, view.value.m) > minYm.value)
const canNext = computed(() => ym(view.value.y, view.value.m) < maxYm.value)

function shiftMonth(delta) {
  const t = ym(view.value.y, view.value.m) + delta
  if (t < minYm.value || t > maxYm.value) return
  view.value = { y: Math.floor(t / 12), m: t % 12 }
}

const nowDate = () => props.now ?? new Date()
const ended = (s) => Boolean(props.date) && slotHasEnded(props.date, s, nowDate())
const allEnded = computed(() => Boolean(props.date) && props.slots.every(ended))
const isDisabled = (key) => key < props.today || key > max.value || props.slots.every((s) => slotHasEnded(key, s, nowDate()))
const ariaDay = (key) => `${dayLabel(key)}${key === props.today ? ', hoy' : ''}${key === props.date ? ', seleccionado' : ''}`

function pickDay(key) {
  if (isDisabled(key)) return
  emit('update:date', key)
  // Cambiar de día no deja una franja que ya terminó (p. ej. «Mañana» de hoy por la tarde).
  const current = props.slots.find((s) => s.value === props.slot)
  if (current && slotHasEnded(key, current, nowDate())) emit('update:slot', '')
}
</script>
