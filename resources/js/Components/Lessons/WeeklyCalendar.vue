<template>
  <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
    <!-- Navegación de semana -->
    <div class="flex items-center justify-between gap-2 px-3 sm:px-4 py-3 border-b border-gray-100">
      <button type="button" @click="shiftWeek(-1)" aria-label="Semana anterior"
        class="w-11 h-11 flex-shrink-0 flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-50 hover:text-brand-600 active:scale-95 transition-all">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
      </button>

      <div class="text-center min-w-0">
        <p class="text-sm font-bold text-slate-900 truncate">{{ weekRangeLabel }}</p>
        <button v-if="weekOffset !== 0" type="button" @click="weekOffset = 0"
          class="text-xs text-brand-600 hover:text-brand-700 hover:underline font-medium">
          Volver a hoy
        </button>
      </div>

      <button type="button" @click="shiftWeek(1)" aria-label="Semana siguiente"
        class="w-11 h-11 flex-shrink-0 flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-50 hover:text-brand-600 active:scale-95 transition-all">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </button>
    </div>

    <!-- Semana totalmente vacía: mensaje que enseña la navegación, no un
         grid de 7 columnas en blanco sin explicación. -->
    <p v-if="!weekLessons.length" class="text-center text-sm text-slate-400 py-12 px-4">
      No tienes clases esta semana.
      <span v-if="weekOffset === 0">Usa las flechas para ver otras semanas.</span>
    </p>

    <!-- Grid: 7 columnas, scroll horizontal en pantallas angostas (cada
         columna mantiene un ancho mínimo legible en vez de comprimirse). -->
    <div v-else class="overflow-x-auto">
      <Transition name="week-fade" mode="out-in">
        <div :key="weekOffset" class="grid grid-cols-7 divide-x divide-gray-100 min-w-[900px]">
          <div v-for="day in weekDays" :key="day.toISOString()" class="flex flex-col min-w-[128px]">
            <div :class="['px-2 py-2 text-center border-b border-gray-100 sticky top-0', isToday(day) ? 'bg-brand-50' : 'bg-slate-50']">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ dayName(day) }}</p>
              <p :class="['text-sm font-black', isToday(day) ? 'text-brand-700' : 'text-slate-800']">{{ day.getDate() }}</p>
            </div>

            <div class="flex-1 p-1.5 space-y-1.5 min-h-[120px]">
              <component :is="canJoinJitsi(l) ? 'button' : 'div'" v-for="l in lessonsFor(day)" :key="l.id"
                type="button" @click="canJoinJitsi(l) && $emit('join', l)"
                :class="['w-full text-left rounded-xl border p-2.5 text-xs transition-all',
                  statusStyle(l.status).color, statusStyle(l.status).ring,
                  canJoinJitsi(l) ? 'hover:brightness-[0.97] active:scale-[0.98] cursor-pointer' : 'cursor-default']">
                <div class="flex items-center justify-between gap-1">
                  <p class="font-bold">{{ fmtTime(l.start_time) }}</p>
                  <span v-if="canJoinJitsi(l)" aria-hidden="true">🎥</span>
                </div>
                <p class="truncate font-semibold mt-0.5">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                <p class="truncate opacity-75">{{ otherParty(l) }}</p>
                <p v-if="canJoinJitsi(l)" class="mt-1.5 font-bold">Unirse →</p>
              </component>
            </div>
          </div>
        </div>
      </Transition>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { statusStyle } from '@/utils/statusColors'
import { canJoinJitsi } from '@/utils/lessonJoin'
import { startOfWeek } from '@/utils/weekGrouping'

const props = defineProps({
  lessons: { type: Array, required: true },
  role: { type: String, required: true }, // 'parent' | 'teacher' — decide qué nombre mostrar en cada bloque
})
defineEmits(['join'])

const weekOffset = ref(0)

const weekStart = computed(() => {
  const base = startOfWeek()
  base.setDate(base.getDate() + weekOffset.value * 7)
  return base
})

const weekDays = computed(() =>
  Array.from({ length: 7 }, (_, i) => {
    const d = new Date(weekStart.value)
    d.setDate(d.getDate() + i)
    return d
  })
)

const weekLessons = computed(() => {
  const start = weekStart.value
  const end = new Date(start)
  end.setDate(end.getDate() + 7)
  return props.lessons.filter((l) => {
    const t = new Date(l.start_time)
    return t >= start && t < end
  })
})

function lessonsFor(day) {
  return weekLessons.value
    .filter((l) => sameDay(new Date(l.start_time), day))
    .sort((a, b) => new Date(a.start_time) - new Date(b.start_time))
}

function sameDay(a, b) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

function isToday(day) {
  return sameDay(day, new Date())
}

function shiftWeek(delta) {
  weekOffset.value += delta
}

function otherParty(l) {
  return props.role === 'teacher'
    ? [l.student?.first_name, l.student?.last_name].filter(Boolean).join(' ') || 'Alumno'
    : l.teacher_profile?.user?.name ?? 'Profesor'
}

function dayName(day) {
  return day.toLocaleDateString('es-ES', { weekday: 'short' }).replace('.', '')
}

function fmtTime(d) {
  return new Date(d).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })
}

const weekRangeLabel = computed(() => {
  const start = weekDays.value[0]
  const end = weekDays.value[6]
  const sameMonth = start.getMonth() === end.getMonth()
  const startLabel = start.toLocaleDateString('es-ES', { day: 'numeric', month: sameMonth ? undefined : 'short' })
  const endLabel = end.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' })
  return `${startLabel} – ${endLabel}`
})
</script>

<style scoped>
/* Cambio de semana: crossfade breve, ya cubierto por la regla global de
   prefers-reduced-motion (transition-duration) — no necesita guard propio. */
.week-fade-enter-active,
.week-fade-leave-active {
  transition: opacity 180ms ease-out;
}
.week-fade-enter-from,
.week-fade-leave-to {
  opacity: 0;
}
</style>
