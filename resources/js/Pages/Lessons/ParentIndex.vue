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

      <div v-else class="space-y-3">
        <div v-for="l in lessons" :key="l.id"
          class="bg-white rounded-2xl border border-gray-100 hover:border-brand-200 transition-all hover:shadow-md overflow-hidden">
          <div :class="['h-1', statusStripe(l.status)]"></div>

          <div class="p-5">
            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                  <p class="font-bold text-slate-900">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                  <StatusBadge :status="l.status" />
                </div>
                <p class="text-sm text-slate-600">
                  <span class="font-medium">Alumno:</span> {{ l.student?.first_name }} {{ l.student?.last_name }}
                </p>
                <p class="text-sm text-slate-500">
                  <span class="font-medium">Profesor:</span> {{ l.teacher_profile?.user?.name }}
                </p>
                <p class="text-sm text-slate-500 mt-0.5">
                  📅 {{ fmtDate(l.start_time) }} · {{ l.duration_minutes }} min
                </p>
                <div v-if="l.status === 'cancelled' && l.cancel_reason"
                  class="mt-2 text-xs text-red-600 bg-red-50 rounded px-2 py-1">
                  Motivo de cancelación: {{ l.cancel_reason }}
                </div>
                <div v-if="l.rescheduled_at && l.original_start_time"
                  class="mt-1 text-xs text-amber-700 bg-amber-50 rounded px-2 py-1">
                  Reprogramada (original: {{ fmtDateShort(l.original_start_time) }})
                </div>
                <!-- Review section -->
                <div v-if="l.teacher_review" class="mt-2 flex items-center gap-1 text-xs text-amber-600">
                  <span v-for="n in 5" :key="n" :class="n <= l.teacher_review.rating ? 'text-amber-400' : 'text-gray-200'">★</span>
                  <span class="text-slate-500 ml-1">Reseña enviada</span>
                </div>
              </div>

              <div v-if="canJoinJitsi(l)" class="flex-shrink-0 bg-brand-50 border border-brand-100 rounded-xl p-4 min-w-0 sm:min-w-[220px] flex items-center">
                <button @click="openJitsi(l)"
                  class="flex items-center gap-2 w-full px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow shadow-brand-600/25">
                  🎥 Ingresar a la Sala Virtual
                </button>
              </div>

              <div v-else-if="l.status !== 'scheduled'" class="flex-shrink-0 px-4 py-3 bg-slate-50 rounded-xl text-center">
                <p class="text-sm text-slate-400">{{ statusLabel(l.status) }}</p>
              </div>
            </div>

            <!-- Tarjeta de pago: la clase ya fue programada, falta confirmar el pago offline -->
            <div v-if="l.status === 'scheduled'" class="mt-3 bg-emerald-50 border border-emerald-100 rounded-xl p-4">
              <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wide mb-2">Confirmar pago de la clase</p>
              <p class="text-sm text-slate-700 mb-3">
                Monto a pagar: <span class="font-bold text-slate-900">S/ {{ amountToPay(l) }}</span>
              </p>
              <div class="flex flex-wrap gap-6 mb-3">
                <div v-if="l.teacher_profile?.yape_number">
                  <span class="text-xs text-slate-500 block">Yape</span>
                  <span class="font-semibold text-slate-800 select-all">{{ l.teacher_profile.yape_number }}</span>
                </div>
                <div v-if="l.teacher_profile?.plin_number">
                  <span class="text-xs text-slate-500 block">Plin</span>
                  <span class="font-semibold text-slate-800 select-all">{{ l.teacher_profile.plin_number }}</span>
                </div>
                <p v-if="!l.teacher_profile?.yape_number && !l.teacher_profile?.plin_number" class="text-xs text-slate-400">
                  El profesor aún no registró un número de Yape/Plin.
                </p>
              </div>
              <button @click="confirmPayment(l)" :disabled="payingId === l.id"
                class="px-5 py-2.5 bg-emerald-600 text-white text-sm font-bold rounded-xl hover:bg-emerald-700 active:scale-95 transition-all shadow-sm disabled:opacity-50">
                {{ payingId === l.id ? 'Confirmando...' : '✓ Ya pagué' }}
              </button>
              <p v-if="paymentErrorId === l.id" class="text-xs text-red-500 mt-2">{{ paymentError }}</p>
            </div>

            <!-- Banner destacado: el profesor ya subió el reporte, falta que el padre califique para cerrar la clase -->
            <div v-if="l.status === 'pending_parent_confirmation'" class="mt-3 bg-yellow-50 border-2 border-yellow-300 rounded-xl p-4">
              <p class="text-sm font-semibold text-yellow-900">
                📋 El profesor ha finalizado la clase y subido el reporte. Para cerrar la clase, por favor califica al profesor.
              </p>
              <Link :href="route('reviews.create', l.id)"
                class="inline-block mt-3 px-5 py-2.5 bg-yellow-500 text-white text-sm font-bold rounded-xl hover:bg-yellow-600 active:scale-95 transition-all shadow-sm">
                ★ Calificar y Confirmar
              </Link>
            </div>

            <!-- Actions footer for scheduled lessons -->
            <div v-if="l.status === 'scheduled'" class="mt-3 pt-3 border-t border-gray-50 flex flex-wrap items-center justify-end gap-2">
              <button @click="openReschedule(l)"
                class="text-xs text-amber-600 hover:text-amber-800 hover:bg-amber-50 px-3 py-1.5 rounded-lg transition-colors font-medium">
                Reprogramar
              </button>
              <button @click="openCancel(l)"
                class="text-xs text-red-500 hover:text-red-700 hover:bg-red-50 px-3 py-1.5 rounded-lg transition-colors font-medium">
                Cancelar clase
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Modal cancelación ──────────────────────────────────────────────────── -->
    <div v-if="cancelTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Cancelar clase</h3>
        <p class="text-sm text-gray-500 mb-4">{{ fmtDate(cancelTarget.start_time) }}</p>
        <textarea v-model="cancelReason" rows="3"
          placeholder="Motivo de cancelación (opcional)"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-1" />
        <div class="flex gap-2 mt-3">
          <button @click="cancelTarget = null; cancelReason = ''"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">
            Volver
          </button>
          <button @click="submitCancel"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg">
            Cancelar clase
          </button>
        </div>
      </div>
    </div>

    <!-- ── Modal reprogramación ───────────────────────────────────────────────── -->
    <div v-if="rescheduleTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Reprogramar clase</h3>
        <p class="text-sm text-gray-500 mb-3">Original: {{ fmtDate(rescheduleTarget.start_time) }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Nueva fecha y hora</label>
            <input v-model="rescheduleForm.start_time" type="datetime-local"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Duración (minutos)</label>
            <input v-model.number="rescheduleForm.duration_minutes" type="number" min="30" max="240" step="15"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <input v-model="rescheduleForm.reason" type="text" maxlength="500"
              placeholder="Ej: Por disponibilidad del alumno"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
          </div>
          <p v-if="rescheduleError" class="text-xs text-red-500">{{ rescheduleError }}</p>
        </div>
        <div class="flex gap-2 mt-4">
          <button @click="rescheduleTarget = null"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">
            Volver
          </button>
          <button @click="submitReschedule"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-amber-600 hover:bg-amber-700 rounded-lg">
            Reprogramar
          </button>
        </div>
      </div>
    </div>

    <!-- ── Modal Sala Virtual (Jitsi) ─────────────────────────────────────────── -->
    <Modal :show="showingJitsiModal" max-width="7xl" @close="closeJitsi">
      <iframe :src="currentJitsiUrl" allow="camera; microphone; fullscreen; display-capture"
        class="w-full h-[80vh] border-0"></iframe>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import Modal from '@/Components/Modal.vue'

defineProps({ lessons: Array })

const cancelTarget     = ref(null)
const cancelReason     = ref('')
const rescheduleTarget = ref(null)
const rescheduleError  = ref('')
const rescheduleForm   = ref({ start_time: '', duration_minutes: 60, reason: '' })
const payingId         = ref(null)
const paymentError     = ref('')
const paymentErrorId   = ref(null)
const showingJitsiModal = ref(false)
const currentJitsiUrl   = ref('')

function canJoinJitsi(l) {
  if (!l.jitsi_room) return false
  if (l.status === 'paid') return true
  if (l.status !== 'scheduled') return false
  const minutesToStart = (new Date(l.start_time).getTime() - Date.now()) / 60000
  return minutesToStart <= 15
}

function openJitsi(l) {
  currentJitsiUrl.value = `https://meet.jit.si/${l.jitsi_room}`
  showingJitsiModal.value = true
}

function closeJitsi() {
  showingJitsiModal.value = false
  currentJitsiUrl.value = ''
}

function amountToPay(l) {
  const rate = parseFloat(l.teacher_profile?.hourly_rate ?? 0)
  return ((rate * l.duration_minutes) / 60).toFixed(2)
}

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

function fmtDateShort(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function statusStripe(s) {
  return {
    scheduled: 'bg-brand-500',
    paid: 'bg-indigo-500',
    pending_parent_confirmation: 'bg-amber-500',
    completed: 'bg-green-500',
    cancelled: 'bg-red-400',
  }[s] ?? 'bg-slate-300'
}

function statusLabel(s) {
  return {
    scheduled: 'Programada',
    paid: 'Pagada',
    pending_parent_confirmation: 'Esperando tu calificación',
    completed: 'Completada',
    cancelled: 'Cancelada',
  }[s] ?? s
}
</script>
