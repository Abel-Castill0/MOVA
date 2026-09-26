<template>
  <AppLayout title="Clases de mis hijos" :compact="true">
    <div class="flex-1 min-h-0 flex flex-col space-y-2">

      <div class="flex items-center justify-between gap-3 shrink-0">
        <span class="text-xs font-bold text-slate-500 bg-white/80 px-2.5 py-1 rounded-lg border border-slate-200/60 shadow-2xs">
          {{ lessons.length }} {{ lessons.length === 1 ? 'clase' : 'clases' }} en total
        </span>
        <div v-if="lessons.length" class="flex bg-slate-200/70 p-0.5 rounded-xl gap-0.5" role="tablist" aria-label="Modo de vista" @keydown="onTabsKeydown">
          <button ref="tabListRef" type="button" id="tab-list-parent" role="tab" :aria-selected="viewMode === 'list'"
            :tabindex="viewMode === 'list' ? 0 : -1" aria-controls="panel-lessons-parent" @click="viewMode = 'list'"
            :class="['px-3 py-1 rounded-lg text-xs font-bold transition-all cursor-pointer', viewMode === 'list' ? 'bg-white text-brand-700 shadow-xs' : 'text-slate-500 hover:text-slate-800']">
            Lista
          </button>
          <button ref="tabCalendarRef" type="button" id="tab-calendar-parent" role="tab" :aria-selected="viewMode === 'calendar'"
            :tabindex="viewMode === 'calendar' ? 0 : -1" aria-controls="panel-lessons-parent" @click="viewMode = 'calendar'"
            :class="['px-3 py-1 rounded-lg text-xs font-bold transition-all cursor-pointer', viewMode === 'calendar' ? 'bg-white text-brand-700 shadow-xs' : 'text-slate-500 hover:text-slate-800']">
            Calendario
          </button>
        </div>
      </div>

      <div v-if="!lessons.length" class="text-center py-16 bg-white rounded-3xl border border-gray-100 shadow-sm">
        <div class="text-5xl mb-3">📅</div>
        <p class="font-bold text-slate-800 text-base">No hay clases registradas</p>
        <p class="text-slate-400 text-sm mt-1">Envía tu primera solicitud y un profesor te contactará.</p>
        <Link :href="route('class-requests.create')" class="inline-block mt-4 px-6 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-2xl hover:bg-brand-700 shadow-sm shadow-brand-600/20 transition-all">
          Solicitar una clase →
        </Link>
      </div>

      <div v-else id="panel-lessons-parent" role="tabpanel" :aria-labelledby="viewMode === 'list' ? 'tab-list-parent' : 'tab-calendar-parent'" tabindex="0" :class="['flex-1 min-h-0 flex flex-col', viewMode === 'list' ? 'overflow-y-auto space-y-3 pr-1' : '']">
        <WeeklyCalendar v-if="viewMode === 'calendar'" :lessons="lessons" role="parent" @join="openJitsi" @reschedule="openReschedule" @cancel="openCancel" @pay="confirmPayment" />

        <template v-else>
          <!-- ── Esta semana ─────────────────────────────────────────────────── -->
          <div>
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-base font-bold text-slate-900">📅 Esta semana</h3>
              <span class="text-sm text-slate-400">{{ thisWeek.length }}</span>
            </div>
            <div v-if="thisWeek.length" class="space-y-3">
              <ParentLessonCard v-for="l in thisWeek" :key="l.id" :lesson="l"
                :paying-id="payingId" :payment-error-id="paymentErrorId" :payment-error="paymentError"
                @join="openJitsi" @pay="confirmPayment" @reschedule="openReschedule" @cancel="openCancel" />
            </div>
            <div v-else class="bg-white rounded-3xl border border-gray-100 py-8 text-center text-slate-400 shadow-2xs">
              <p class="text-sm">No tienes clases esta semana.</p>
            </div>
          </div>

          <!-- ── Clases pasadas ──────────────────────────────────────────────── -->
          <div>
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-base font-bold text-slate-900">📚 Clases pasadas</h3>
              <span class="text-sm text-slate-400">{{ past.length }}</span>
            </div>
            <div v-if="past.length" class="space-y-3">
              <ParentLessonCard v-for="l in past" :key="l.id" :lesson="l"
                :paying-id="payingId" :payment-error-id="paymentErrorId" :payment-error="paymentError"
                @join="openJitsi" @pay="confirmPayment" @reschedule="openReschedule" @cancel="openCancel" />
            </div>
            <div v-else class="bg-white rounded-3xl border border-gray-100 py-8 text-center text-slate-400 shadow-2xs">
              <p class="text-sm">Aún no hay clases pasadas.</p>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- ── Modales de acción (Cancel/Reschedule/Join) — un solo shell
         compartido (Modal.vue + BaseButton.vue), un solo composable dueño
         de la petición de red (useLessonActions). Antes cada modal era un
         div "fixed inset-0" propio, duplicado letra por letra con
         Lessons/TeacherIndex.vue. ─────────────────────────────────────── -->
    <CancelLessonModal :show="!!cancelTarget" :lesson="cancelTarget" :error="cancelError" :processing="cancelling"
      @close="closeCancel" @confirm="submitCancel" />

    <RescheduleLessonModal :show="!!rescheduleTarget" :lesson="rescheduleTarget" :error="rescheduleError" :processing="rescheduling"
      @close="closeReschedule" @confirm="submitReschedule" />

    <JitsiModal :show="showingJitsiModal" :lesson="activeLesson" :error="joinError" :connecting="connecting" @close="closeJitsi" />
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import JitsiModal from '@/Components/JitsiModal.vue'
import CancelLessonModal from '@/Components/Lessons/CancelLessonModal.vue'
import RescheduleLessonModal from '@/Components/Lessons/RescheduleLessonModal.vue'
import ParentLessonCard from '@/Components/Lessons/ParentLessonCard.vue'
import WeeklyCalendar from '@/Components/Lessons/WeeklyCalendar.vue'
import { useJitsiMeet } from '@/Composables/useJitsiMeet'
import { useLessonActions } from '@/Composables/useLessonActions'
import { splitByWeek } from '@/utils/weekGrouping'
import { useLessonsViewMode } from '@/Composables/useLessonsViewMode'

const props = defineProps({ lessons: Array })

const { viewMode, tabListRef, tabCalendarRef, onTabsKeydown } = useLessonsViewMode()
const {
  cancelTarget, cancelError, cancelling, openCancel, closeCancel, submitCancel,
  rescheduleTarget, rescheduleError, rescheduling, openReschedule, closeReschedule, submitReschedule,
} = useLessonActions()
const payingId       = ref(null)
const paymentError   = ref('')
const paymentErrorId = ref(null)
const { showingJitsiModal, joinError, connecting, activeLesson, openJitsi, closeJitsi } = useJitsiMeet()

const grouped  = computed(() => splitByWeek(props.lessons))
// El backend ordena `lessons` por start_time desc (historial arriba) — para
// "Esta semana" queremos la más próxima primero, así que se invierte solo
// ese balde.
const thisWeek = computed(() => [...grouped.value.thisWeek].reverse())
const past     = computed(() => grouped.value.past)

function confirmPayment(l) {
  payingId.value = l.id
  paymentErrorId.value = null
  router.post(route('lessons.confirm-payment', l.id), {}, {
    preserveScroll: true,
    // Encontrado en auditoría: descartaba en silencio el mensaje real del
    // servidor (bajo la clave `confirmPayment`, p. ej. "La clase aún no ha
    // finalizado.") y mostraba siempre el mismo texto genérico.
    onError: (e) => {
      paymentErrorId.value = l.id
      paymentError.value = e.confirmPayment || 'No se pudo confirmar el pago. Intenta nuevamente.'
    },
    onFinish: () => { payingId.value = null },
  })
}
</script>
