<template>
  <div class="bg-white rounded-2xl border border-gray-200/90 shadow-sm overflow-hidden flex flex-col font-sans flex-1 min-h-0">
    
    <!-- ══════════════════════════════════════════════
         TOP BAR ESTILO UTP: Ciclo, Semana y Navegación
         ══════════════════════════════════════════════ -->
    <div class="px-4 py-2 border-b border-gray-200 flex flex-col md:flex-row md:items-center justify-between gap-2 bg-white shrink-0">
      <div>
        <p class="text-[10px] font-bold text-slate-500 tracking-wider uppercase">
          {{ academicYearCycle }}
        </p>
        <h2 class="text-sm sm:text-base font-extrabold text-blue-950 tracking-tight leading-snug">
          {{ weekHeaderTitle }}
        </h2>
      </div>

      <!-- Controles de navegación: [ Hoy ] [ < ] [ > ] -->
      <div class="flex items-center gap-1.5">
        <button
          type="button"
          @click="goToToday"
          :class="[
            'px-3 py-1 rounded-lg text-xs font-bold border transition-all active:scale-95 shadow-2xs',
            weekOffset === 0
              ? 'bg-blue-50 border-blue-300 text-blue-700 pointer-events-none'
              : 'bg-white border-blue-200 text-blue-600 hover:bg-blue-50 hover:border-blue-300 cursor-pointer'
          ]"
        >
          Hoy
        </button>

        <div class="flex items-center gap-1">
          <button
            type="button"
            @click="shiftWeek(-1)"
            aria-label="Semana anterior"
            title="Semana anterior"
            class="w-7 h-7 rounded-lg bg-blue-600 hover:bg-blue-700 active:scale-95 text-white flex items-center justify-center transition-all shadow-2xs cursor-pointer"
          >
            <svg class="w-3.5 h-3.5 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
          </button>

          <button
            type="button"
            @click="shiftWeek(1)"
            aria-label="Semana siguiente"
            title="Semana siguiente"
            class="w-7 h-7 rounded-lg bg-blue-600 hover:bg-blue-700 active:scale-95 text-white flex items-center justify-center transition-all shadow-2xs cursor-pointer"
          >
            <svg class="w-3.5 h-3.5 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════
         FILA "TODO EL CICLO" (Banner MOVA Azul y Naranja)
         ══════════════════════════════════════════════ -->
    <div class="grid grid-cols-[60px_1fr] sm:grid-cols-[72px_1fr] border-b border-gray-200 bg-slate-50/70 items-center shrink-0">
      <div class="px-1 py-1.5 text-center border-r border-gray-200 flex flex-col justify-center">
        <span class="text-[9px] font-bold text-slate-500 uppercase tracking-tight leading-tight">Todo</span>
        <span class="text-[9px] font-bold text-slate-500 uppercase tracking-tight leading-tight">el ciclo</span>
      </div>
      <div class="p-1 sm:px-2.5">
        <div class="rounded-lg bg-gradient-to-r from-[#0D409A] via-[#2563EB] to-[#EA580C] text-white px-2.5 py-1 flex items-center justify-between text-xs font-semibold shadow-xs">
          <div class="flex items-center gap-1.5 truncate">
            <span class="w-1.5 h-1.5 rounded-full bg-amber-300 animate-pulse shrink-0"></span>
            <span class="font-bold text-xs truncate">Plataforma Educativa MOVA</span>
            <span class="hidden md:inline text-blue-100/90 text-[11px] truncate">· Clases sincrónicas y tutorías</span>
          </div>
          <span class="bg-white/20 backdrop-blur-xs text-white text-[9px] font-bold px-2 py-0.5 rounded-full shrink-0 ml-2 border border-white/20">
            Virtual
          </span>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════
         CUERPO DEL CALENDARIO: SCROLL VERTICAL Y HORIZONTAL
         ══════════════════════════════════════════════ -->
    <div
      ref="calendarScrollContainer"
      class="overflow-x-auto overflow-y-auto bg-white relative select-none flex-1 min-h-0 flex flex-col [scrollbar-width:thin] [scrollbar-color:#cbd5e1_transparent] [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:bg-slate-300 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-transparent"
    >
      <div class="min-w-[830px] flex flex-col relative">

        <!-- CABECERA DE DÍAS DE LA SEMANA (Lun .. Dom) STICKY TOP -->
        <div class="grid grid-cols-[60px_repeat(7,minmax(110px,1fr))] sm:grid-cols-[72px_repeat(7,minmax(120px,1fr))] border-b border-gray-200 bg-white sticky top-0 z-30 select-none shrink-0 shadow-2xs">
          <!-- Esquina vacía de horas -->
          <div class="border-r border-gray-200 py-2 px-1 flex items-center justify-center text-[10px] font-bold text-slate-400 uppercase tracking-wider bg-white">
            Hora
          </div>

          <!-- 7 Columnas de días -->
          <div
            v-for="day in weekDays"
            :key="day.toISOString()"
            :class="[
              'py-2 px-1.5 text-center border-r border-gray-200 last:border-r-0 flex items-center justify-center gap-1.5 transition-colors bg-white',
              isToday(day) ? '!bg-blue-50/80 text-blue-900 font-bold' : 'text-slate-700 font-semibold'
            ]"
          >
            <span class="text-xs uppercase tracking-wide">
              {{ dayShortName(day) }}
            </span>

            <!-- Número de día: en círculo azul si es hoy (exacto UTP) -->
            <span
              :class="[
                'text-xs font-black transition-all flex items-center justify-center',
                isToday(day)
                  ? 'w-5 h-5 rounded-full bg-blue-600 text-white shadow-2xs text-[11px]'
                  : 'text-slate-800'
              ]"
            >
              {{ day.getDate() }}
            </span>
          </div>
        </div>

        <!-- REJILLA DE 17 HORAS (07:00 a 24:00) -->
        <div class="grid grid-cols-[60px_repeat(7,minmax(110px,1fr))] sm:grid-cols-[72px_repeat(7,minmax(120px,1fr))] relative">
          
          <!-- COLUMNA IZQUIERDA DE HORAS -->
          <div class="border-r border-gray-200 bg-white select-none relative z-10 flex flex-col">
            <div
              v-for="hour in hours"
              :key="hour"
              class="h-[110px] border-b border-gray-100/90 pr-2 pt-2.5 text-right text-[11px] font-semibold text-slate-400 select-none flex flex-col justify-start"
            >
              {{ formatHourLabel(hour) }}
            </div>
          </div>

          <!-- 7 COLUMNAS DE DÍAS CON RANURAS HORARIAS Y CLASES -->
          <div
            v-for="(day, dayIndex) in weekDays"
            :key="day.toISOString()"
            :class="[
              'border-r border-gray-200 last:border-r-0 relative transition-colors',
              isToday(day) ? 'bg-blue-50/20' : 'bg-white'
            ]"
          >
            <!-- Líneas de fondo por cada hora y media hora -->
            <div
              v-for="hour in hours"
              :key="'grid-' + hour"
              class="h-[110px] border-b border-gray-100/80 relative"
            >
              <!-- Línea punteada de la media hora -->
              <div class="absolute inset-x-0 top-1/2 border-b border-gray-100/70 border-dashed pointer-events-none"></div>
            </div>

            <!-- LÍNEA INDICADORA DE HORA ACTUAL (roja si es hoy) -->
            <div
              v-if="isToday(day) && isCurrentHourVisible"
              class="absolute inset-x-0 z-20 pointer-events-none flex items-center"
              :style="{ top: `${currentMinutesPercent}%` }"
            >
              <div class="w-2.5 h-2.5 rounded-full bg-red-500 -ml-1.5 shadow-xs"></div>
              <div class="h-0.5 flex-1 bg-red-500/80"></div>
            </div>

            <!-- BLOQUES DE CLASES (Estilo UTP) -->
            <div
              v-for="l in lessonsFor(day)"
              :key="l.id"
              @click="openDetail(l)"
              :style="lessonStyle(l)"
              :class="[
                'absolute rounded-xl p-3 sm:p-3.5 text-white shadow-md transition-all cursor-pointer flex flex-col justify-between overflow-hidden group select-none z-10',
                subjectTheme(l).bg,
                subjectTheme(l).border,
                'hover:brightness-105 hover:shadow-lg hover:z-20 active:scale-[0.99]'
              ]"
              :title="`${l.class_request?.subject?.name ?? 'Clase'} - ${fmtTime(l.start_time)}`"
            >
              <div class="min-w-0">
                <!-- Nombre de la materia -->
                <p class="font-bold text-xs sm:text-sm leading-tight text-white line-clamp-1 drop-shadow-2xs">
                  {{ l.class_request?.subject?.name ?? 'Clase' }}
                </p>

                <!-- Persona (Alumno / Profesor) -->
                <p class="text-[11px] text-white/90 truncate mt-0.5 font-medium leading-tight">
                  {{ otherParty(l) }}
                </p>

                <!-- Horario -->
                <p class="text-[10px] text-white/80 font-mono mt-1 flex items-center gap-1 truncate leading-tight">
                  <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 1118 0z" />
                  </svg>
                  <span>{{ fmtTimeRange(l) }}</span>
                </p>
              </div>

              <!-- Badge de modalidad estilo UTP (Presencial / Virtual en vivo) -->
              <div class="mt-2 flex items-center justify-between gap-1">
                <span
                  :class="[
                    'text-[9px] sm:text-[10px] font-bold px-2 py-0.5 rounded-full shadow-2xs uppercase tracking-wider truncate',
                    subjectTheme(l).badgeBg
                  ]"
                >
                  {{ classModalityLabel(l) }}
                </span>

                <!-- Indicador de sala en vivo si aplica -->
                <span v-if="canJoinJitsi(l)" class="flex items-center gap-1 text-[10px] font-bold text-emerald-200 shrink-0">
                  <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span>
                  En vivo
                </span>
              </div>
            </div>

          </div>

        </div>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════
         MODAL / POPOVER DETALLE DE CLASE (Estilo UTP)
         ══════════════════════════════════════════════ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-95"
      >
        <div
          v-if="selectedLesson"
          class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs"
          @click.self="selectedLesson = null"
        >
          <div
            class="bg-white rounded-2xl shadow-2xl border border-gray-100 p-5 sm:p-6 max-w-md w-full relative overflow-hidden animate-in fade-in zoom-in-95 duration-150"
            role="dialog"
            aria-modal="true"
            :aria-label="selectedLesson.class_request?.subject?.name ?? 'Detalle de clase'"
          >
            <!-- Botón cerrar (X) en esquina superior derecha -->
            <button
              type="button"
              @click="selectedLesson = null"
              aria-label="Cerrar detalle"
              class="absolute top-4 right-4 w-8 h-8 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 flex items-center justify-center transition-all cursor-pointer"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>

            <!-- Badge superior estilo UTP -->
            <div class="mb-3">
              <span
                :class="[
                  'text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider inline-block',
                  classBadgeStyle(selectedLesson)
                ]"
              >
                {{ classModalityLabel(selectedLesson) }}
              </span>
            </div>

            <!-- Título del curso y participante -->
            <h3 class="text-lg font-black text-slate-900 leading-snug">
              {{ selectedLesson.class_request?.subject?.name ?? 'Clase' }}
            </h3>
            <p class="text-sm font-semibold text-slate-600 mt-1 flex items-center gap-1.5">
              <span>{{ role === 'teacher' ? 'Alumno:' : 'Profesor:' }}</span>
              <span class="text-slate-900 font-bold">{{ otherParty(selectedLesson) }}</span>
            </p>

            <!-- Fecha y hora con icono de calendario -->
            <div class="mt-4 pt-3 border-t border-gray-100 flex items-start gap-3">
              <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0 mt-0.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-400">Fecha y horario</p>
                <p class="text-sm font-bold text-slate-800 capitalize leading-snug">
                  {{ fmtFullDateRange(selectedLesson) }}
                </p>
                <p class="text-xs text-slate-500 mt-0.5">
                  Duración: {{ selectedLesson.duration_minutes ?? 60 }} minutos
                </p>
              </div>
            </div>

            <!-- Estado o aviso de reporte -->
            <div v-if="selectedLesson.status === 'paid' && !selectedLesson.lesson_report" class="mt-3.5 bg-violet-50 border border-violet-100 rounded-xl p-3 flex items-start gap-2.5">
              <Icon name="payment-received" :size="16" class="text-violet-600 flex-shrink-0 mt-0.5" />
              <p class="text-xs font-medium text-violet-700 leading-relaxed">
                El pago de esta clase está confirmado. Ya se puede redactar el reporte pedagógico.
              </p>
            </div>

            <div v-else-if="selectedLesson.lesson_report" class="mt-3.5 bg-emerald-50 border border-emerald-100 rounded-xl p-3 flex items-center gap-2">
              <Icon name="check" :size="15" class="text-emerald-600 flex-shrink-0" />
              <p class="text-xs font-bold text-emerald-700">
                Reporte pedagógico completado y disponible.
              </p>
            </div>

            <!-- Enlace secundario estilo UTP: "Ir al contenido del curso" o reporte -->
            <div class="mt-4 pt-3 border-t border-gray-100">
              <Link
                v-if="selectedLesson.lesson_report"
                :href="route('lesson-reports.show', selectedLesson.id)"
                class="inline-flex items-center gap-2 text-xs font-bold text-blue-600 hover:text-blue-800 hover:underline transition-colors"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Ver reporte pedagógico enviado
              </Link>
            </div>

            <!-- Botón principal de acción (Ingresar a sala o Escribir reporte) -->
            <div class="mt-4 space-y-2">
              <!-- Botón Ingresar a sala virtual (si está disponible) -->
              <button
                v-if="canJoinJitsi(selectedLesson)"
                type="button"
                @click="joinClass(selectedLesson)"
                class="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-700 active:scale-[0.98] text-white font-bold text-sm rounded-xl flex items-center justify-center gap-2 shadow-md shadow-blue-600/25 transition-all cursor-pointer"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                Ingresar a la Sala Virtual
              </button>

              <!-- Botón Escribir reporte pedagógico para profesor -->
              <Link
                v-else-if="role === 'teacher' && selectedLesson.status === 'paid' && !selectedLesson.lesson_report"
                :href="route('lesson-reports.create', selectedLesson.id)"
                class="w-full py-2.5 px-4 bg-orange-600 hover:bg-orange-700 active:scale-[0.98] text-white font-bold text-sm rounded-xl flex items-center justify-center gap-2 shadow-md shadow-orange-600/25 transition-all"
              >
                <Icon name="my-reports" :size="16" />
                Escribir reporte pedagógico
              </Link>

              <!-- Botón pagar para padre -->
              <button
                v-else-if="role === 'parent' && selectedLesson.status === 'scheduled'"
                type="button"
                @click="payClass(selectedLesson)"
                class="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] text-white font-bold text-sm rounded-xl flex items-center justify-center gap-2 shadow-md shadow-emerald-600/25 transition-all cursor-pointer"
              >
                Pagar clase
              </button>

              <!-- Acciones de reprogramar / cancelar si aplica -->
              <div v-if="selectedLesson.status === 'scheduled'" class="flex items-center justify-between pt-1">
                <button
                  type="button"
                  @click="rescheduleClass(selectedLesson)"
                  class="text-xs font-bold text-amber-700 hover:text-amber-800 hover:bg-amber-50 px-2.5 py-1.5 rounded-lg transition-colors cursor-pointer"
                >
                  Reprogramar
                </button>
                <button
                  type="button"
                  @click="cancelClass(selectedLesson)"
                  class="text-xs font-bold text-red-600 hover:text-red-700 hover:bg-red-50 px-2.5 py-1.5 rounded-lg transition-colors cursor-pointer"
                >
                  Cancelar clase
                </button>
              </div>
            </div>

          </div>
        </div>
      </Transition>
    </Teleport>

  </div>
</template>

<script setup>
import { computed, ref, onMounted, nextTick } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icon from '@/Components/Icon.vue'
import { canJoinJitsi } from '@/utils/lessonJoin'
import { startOfWeek } from '@/utils/weekGrouping'

const props = defineProps({
  lessons: { type: Array, required: true },
  role: { type: String, required: true }, // 'parent' | 'teacher'
})

const emit = defineEmits(['join', 'reschedule', 'cancel', 'pay'])

const weekOffset = ref(0)
const selectedLesson = ref(null)
const calendarScrollContainer = ref(null)

// Rango completo de horas continuo: Mañana a Noche (07:00 a 24:00)
const START_HOUR = 7
const END_HOUR = 24
const ROW_HEIGHT_PX = 110
const TOTAL_HOURS = END_HOUR - START_HOUR
const TOTAL_MINUTES = TOTAL_HOURS * 60

const hours = computed(() =>
  Array.from({ length: TOTAL_HOURS }, (_, i) => START_HOUR + i)
)

// Cálculo de semanas
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

function scrollToInitialHour() {
  nextTick(() => {
    if (!calendarScrollContainer.value) return
    const curHour = new Date().getHours()
    let targetHour = 8
    if (weekOffset.value === 0 && curHour >= START_HOUR && curHour <= END_HOUR) {
      targetHour = Math.max(START_HOUR, curHour - 1)
    }
    const hourOffset = targetHour - START_HOUR
    const targetScrollTop = hourOffset * ROW_HEIGHT_PX
    calendarScrollContainer.value.scrollTo({
      top: targetScrollTop,
      behavior: 'smooth',
    })
  })
}

function shiftWeek(delta) {
  weekOffset.value += delta
}

function goToToday() {
  weekOffset.value = 0
  scrollToInitialHour()
}

function otherParty(l) {
  if (props.role === 'teacher') {
    return [l.student?.first_name, l.student?.last_name].filter(Boolean).join(' ') || 'Alumno'
  }
  return l.teacher_profile?.user?.name ?? 'Profesor'
}

function dayShortName(day) {
  const name = day.toLocaleDateString('es-ES', { weekday: 'short' }).replace('.', '')
  return name.charAt(0).toUpperCase() + name.slice(1, 3)
}

function formatHourLabel(h) {
  if (h === 0 || h === 24) return '12 a. m.'
  if (h < 12) return `${h < 10 ? '0' + h : h} a. m.`
  if (h === 12) return '12 p. m.'
  const pm = h - 12
  return `${pm < 10 ? '0' + pm : pm} p. m.`
}

function fmtTime(d) {
  return new Date(d).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })
}

function fmtTimeRange(l) {
  const start = new Date(l.start_time)
  const duration = l.duration_minutes || 60
  const end = new Date(start.getTime() + duration * 60000)
  return `${fmtTime(start)} - ${fmtTime(end)}`
}

function fmtFullDateRange(l) {
  const start = new Date(l.start_time)
  const dateStr = start.toLocaleDateString('es-ES', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  })
  return `${dateStr} de ${fmtTimeRange(l)}`
}

// Etiquetas de cabecera del calendario
const academicYearCycle = computed(() => {
  const year = weekStart.value.getFullYear()
  return `${year} · Clases MOVA`
})

const weekHeaderTitle = computed(() => {
  const start = weekDays.value[0]
  const end = weekDays.value[6]

  const sameMonth = start.getMonth() === end.getMonth()
  const startStr = sameMonth
    ? `${start.getDate()}`
    : start.toLocaleDateString('es-ES', { day: 'numeric', month: 'long' })
  const endStr = end.toLocaleDateString('es-ES', { day: 'numeric', month: 'long' })

  const prefix = weekOffset.value === 0 ? 'Semana actual' : 'Semana'
  return `${prefix} – Del ${startStr} al ${endStr}`
})

// ══════════════════════════════════════════════
// PALETAS DE COLOR OFICIALES ESTILO UTP+class
// ══════════════════════════════════════════════
const UTP_PALETTES = [
  {
    bg: 'bg-[#C2541A]', // Terracota cálido
    border: 'border-l-4 border-[#8B340B]',
    badgeBg: 'bg-white text-[#8B340B]',
  },
  {
    bg: 'bg-[#0D606E]', // Teal / Azul petróleo
    border: 'border-l-4 border-[#073F49]',
    badgeBg: 'bg-white text-[#0D606E]',
  },
  {
    bg: 'bg-[#9D174D]', // Magenta / Vino oscuro
    border: 'border-l-4 border-[#700D34]',
    badgeBg: 'bg-white text-[#700D34]',
  },
  {
    bg: 'bg-[#1E40AF]', // Azul institucional
    border: 'border-l-4 border-[#172554]',
    badgeBg: 'bg-white text-[#1E40AF]',
  },
  {
    bg: 'bg-[#B45309]', // Ámbar / Cognac
    border: 'border-l-4 border-[#78350F]',
    badgeBg: 'bg-white text-[#78350F]',
  },
  {
    bg: 'bg-[#047857]', // Verde esmeralda
    border: 'border-l-4 border-[#064E3B]',
    badgeBg: 'bg-white text-[#047857]',
  },
]

function subjectTheme(lesson) {
  const name = lesson.class_request?.subject?.name || 'Materia'
  let hash = 0
  for (let i = 0; i < name.length; i++) {
    hash = (hash << 5) - hash + name.charCodeAt(i)
    hash |= 0
  }
  const index = Math.abs(hash) % UTP_PALETTES.length
  return UTP_PALETTES[index]
}

function classModalityLabel(lesson) {
  if (lesson.status === 'completed') return 'Completada'
  if (lesson.jitsi_room || lesson.has_jitsi_room) return 'Virtual en vivo'
  return 'Presencial'
}

function classBadgeStyle(lesson) {
  const label = classModalityLabel(lesson)
  if (label === 'Virtual en vivo') return 'bg-cyan-100 text-cyan-800'
  if (label === 'Presencial') return 'bg-orange-100 text-orange-800'
  if (label === 'Completada') return 'bg-emerald-100 text-emerald-800'
  return 'bg-blue-100 text-blue-800'
}

// Posicionamiento absoluto de bloques de clase en la cuadrícula
function lessonStyle(lesson) {
  const start = new Date(lesson.start_time)
  const hour = start.getHours()
  const minutes = start.getMinutes()
  const duration = lesson.duration_minutes || 60

  const rawMinutesFromStart = Math.max(0, (hour - START_HOUR) * 60 + minutes)
  const topPercent = (rawMinutesFromStart / TOTAL_MINUTES) * 100
  const heightPercent = (duration / TOTAL_MINUTES) * 100

  // Asegura que ninguna tarjeta se desborde fuera del fondo de la cuadrícula
  const boundedTop = Math.max(0.1, Math.min(topPercent, Math.max(0.1, 100 - heightPercent - 0.2)))
  const boundedHeight = Math.min(heightPercent, 100 - boundedTop)

  return {
    top: `calc(${boundedTop}% + 2px)`,
    height: `calc(${boundedHeight}% - 4px)`,
    left: '3px',
    right: '3px',
    minHeight: '106px',
  }
}

// Indicador de hora actual en vivo
const nowMinutes = ref(new Date().getHours() * 60 + new Date().getMinutes())
const isCurrentHourVisible = computed(() => {
  const currentHour = Math.floor(nowMinutes.value / 60)
  return currentHour >= START_HOUR && currentHour < END_HOUR
})

const currentMinutesPercent = computed(() => {
  const minutesFromGridStart = Math.max(0, nowMinutes.value - START_HOUR * 60)
  return Math.min(100, Math.max(0, (minutesFromGridStart / TOTAL_MINUTES) * 100))
})

// Acciones del modal
function openDetail(lesson) {
  selectedLesson.value = lesson
}

function joinClass(lesson) {
  selectedLesson.value = null
  emit('join', lesson)
}

function rescheduleClass(lesson) {
  selectedLesson.value = null
  emit('reschedule', lesson)
}

function cancelClass(lesson) {
  selectedLesson.value = null
  emit('cancel', lesson)
}

function payClass(lesson) {
  selectedLesson.value = null
  emit('pay', lesson)
}

onMounted(() => {
  scrollToInitialHour()
})
</script>
