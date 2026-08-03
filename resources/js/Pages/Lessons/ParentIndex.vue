<template>
  <AppLayout title="Clases de mis hijos">
    <div class="space-y-5">

      <div class="flex items-center justify-between">
        <h2 class="text-xl font-black text-slate-900">Clases de mis hijos</h2>
        <span class="text-sm text-slate-400">{{ lessons.length }} en total</span>
      </div>

      <div v-if="!lessons.length" class="text-center py-16 bg-white rounded-2xl border border-gray-100">
        <div class="text-5xl mb-3">📅</div>
        <p class="font-semibold text-slate-700">No hay clases registradas</p>
        <p class="text-slate-400 text-sm mt-1">Busca un profesor y solicita la primera clase.</p>
        <Link :href="route('marketplace')" class="inline-block mt-4 px-5 py-2 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 transition-colors">
          Buscar profesor →
        </Link>
      </div>

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
          <div v-else class="bg-white rounded-2xl border border-gray-100 py-8 text-center text-slate-400">
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
          <div v-else class="bg-white rounded-2xl border border-gray-100 py-8 text-center text-slate-400">
            <p class="text-sm">Aún no hay clases pasadas.</p>
          </div>
        </div>
      </template>
    </div>

    <!-- ── Modal cancelación ──────────────────────────────────────────────────── -->
    <div v-if="cancelTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-lg p-6 w-full max-w-sm">
        <h3 class="font-bold text-slate-900 mb-1">Cancelar clase</h3>
        <p class="text-sm text-slate-500 mb-4">{{ fmtDate(cancelTarget.start_time) }}</p>
        <textarea v-model="cancelReason" rows="3"
          placeholder="Motivo de cancelación (opcional)"
          class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 focus:border-transparent transition mb-1" />
        <div class="flex gap-2 mt-3">
          <button @click="cancelTarget = null; cancelReason = ''"
            class="flex-1 px-4 py-2.5 text-sm font-semibold text-slate-600 border border-gray-200 rounded-xl hover:bg-slate-50 transition-colors">
            Volver
          </button>
          <button @click="submitCancel"
            class="flex-1 px-4 py-2.5 text-sm font-bold text-white bg-red-600 hover:bg-red-700 active:scale-95 rounded-xl transition-all">
            Cancelar clase
          </button>
        </div>
      </div>
    </div>

    <!-- ── Modal reprogramación ───────────────────────────────────────────────── -->
    <div v-if="rescheduleTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-lg p-6 w-full max-w-sm">
        <h3 class="font-bold text-slate-900 mb-1">Reprogramar clase</h3>
        <p class="text-sm text-slate-500 mb-3">Original: {{ fmtDate(rescheduleTarget.start_time) }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Nueva fecha y hora</label>
            <input v-model="rescheduleForm.start_time" type="datetime-local"
              class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent transition" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Duración (minutos)</label>
            <input v-model.number="rescheduleForm.duration_minutes" type="number" min="30" max="240" step="15"
              class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent transition" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Motivo (opcional)</label>
            <input v-model="rescheduleForm.reason" type="text" maxlength="500"
              placeholder="Ej: Por disponibilidad del alumno"
              class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent transition" />
          </div>
          <p v-if="rescheduleError" class="text-xs text-red-500">{{ rescheduleError }}</p>
        </div>
        <div class="flex gap-2 mt-4">
          <button @click="rescheduleTarget = null"
            class="flex-1 px-4 py-2.5 text-sm font-semibold text-slate-600 border border-gray-200 rounded-xl hover:bg-slate-50 transition-colors">
            Volver
          </button>
          <button @click="submitReschedule"
            class="flex-1 px-4 py-2.5 text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 active:scale-95 rounded-xl transition-all">
            Reprogramar
          </button>
        </div>
      </div>
    </div>

    <!-- ── Modal Sala Virtual (Jitsi) ─────────────────────────────────────────── -->
    <JitsiModal :show="showingJitsiModal" :lesson="activeLesson" :error="joinError" @close="closeJitsi" />
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import JitsiModal from '@/Components/JitsiModal.vue'
import ParentLessonCard from '@/Components/Lessons/ParentLessonCard.vue'
import { useJitsiMeet } from '@/Composables/useJitsiMeet'
import { splitByWeek } from '@/utils/weekGrouping'

const props = defineProps({ lessons: Array })

const cancelTarget     = ref(null)
const cancelReason     = ref('')
const rescheduleTarget = ref(null)
const rescheduleError  = ref('')
const rescheduleForm   = ref({ start_time: '', duration_minutes: 60, reason: '' })
const payingId         = ref(null)
const paymentError     = ref('')
const paymentErrorId   = ref(null)
const { showingJitsiModal, joinError, activeLesson, openJitsi, closeJitsi } = useJitsiMeet()

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
    onError: () => {
      paymentErrorId.value = l.id
      paymentError.value = 'No se pudo confirmar el pago. Intenta nuevamente.'
    },
    onFinish: () => { payingId.value = null },
  })
}

function openCancel(l) {
  cancelTarget.value = l
  cancelReason.value = ''
}

function submitCancel() {
  router.post(
    route('lessons.cancel', cancelTarget.value.id),
    { reason: cancelReason.value.trim() || null },
    { onSuccess: () => { cancelTarget.value = null; cancelReason.value = '' } }
  )
}

function openReschedule(l) {
  rescheduleTarget.value = l
  rescheduleError.value  = ''
  rescheduleForm.value = {
    start_time: toDatetimeLocal(l.start_time),
    duration_minutes: l.duration_minutes,
    reason: '',
  }
}

function submitReschedule() {
  if (!rescheduleForm.value.start_time) {
    rescheduleError.value = 'Selecciona una fecha y hora.'
    return
  }
  router.post(
    route('lessons.reschedule', rescheduleTarget.value.id),
    {
      ...rescheduleForm.value,
      start_time: new Date(rescheduleForm.value.start_time).toISOString(),
    },
    {
      onSuccess: () => { rescheduleTarget.value = null },
      onError: (e) => { rescheduleError.value = e.start_time || 'Error al reprogramar.' },
    }
  )
}

function toDatetimeLocal(d) {
  const dt = new Date(d)
  dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset())
  return dt.toISOString().slice(0, 16)
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
  })
}
</script>
