<template>
  <AppLayout title="Solicitudes recibidas">
    <div class="space-y-8">
      <h2 class="text-2xl font-bold text-gray-900">Solicitudes de clase</h2>

      <!-- Flash de éxito (contraoferta enviada, etc.) -->
      <div v-if="$page.props.flash?.success"
        class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-xl px-4 py-3 flex items-center gap-2">
        <Icon name="check" :size="16" class="flex-shrink-0 text-green-600" />
        {{ $page.props.flash.success }}
      </div>

      <!-- ── Solicitudes abiertas ───────────────────────────────────────── -->
      <section>
        <h3 class="text-base font-bold text-slate-700 mb-3">
          Abiertas ({{ requests.length }})
        </h3>

        <div v-if="!requests.length" class="text-center py-12 bg-white rounded-xl border border-gray-200">
          <div class="mb-2 flex justify-center text-gray-300"><Icon name="requests" :size="32" :stroke-width="1.5" /></div>
          <p class="text-gray-400 text-sm">No hay solicitudes abiertas</p>
        </div>

        <!-- Grid responsivo de tarjetas -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <div v-for="r in requests" :key="r.id"
            class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow flex flex-col h-full">

            <!-- Encabezado: Materia + Badge 0% Comisión -->
            <div class="flex justify-between items-start mb-3">
              <p class="text-lg font-bold text-gray-900">{{ r.subject?.name }}</p>
              <span v-if="r.is_zero_commission"
                class="bg-green-100 text-green-700 text-xs font-bold px-2.5 py-1 rounded-full flex-shrink-0 ml-2">
                0% comisión
              </span>
            </div>

            <!-- Cuerpo: Datos del alumno -->
            <p class="text-sm text-gray-500 mb-2">Alumno: {{ r.student?.first_name }} {{ r.student?.last_name }}</p>
            <p class="text-sm text-gray-700 flex-grow line-clamp-3">{{ r.help_needed }}</p>

            <!-- Franjas horarias -->
            <div v-if="r.preferred_times?.length" class="mt-3 flex flex-wrap gap-1">
              <span v-for="t in r.preferred_times" :key="t"
                class="inline-flex items-center gap-1 text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">
                <Icon :name="timeSlotIconName(t)" :size="12" />
                {{ timeSlotLabel(t) }}
              </span>
            </div>

            <!-- Pie: Botones apilados a ancho completo -->
            <div class="mt-4 flex flex-col gap-2">
              <Link :href="route('teacher.requests.accept', r.id)"
                class="w-full bg-[#1F5AA6] hover:bg-blue-700 text-white font-medium py-2 rounded-xl text-sm transition-colors text-center">
                Aceptar ahora
              </Link>
              <button @click="openCounterofferModal(r)"
                class="w-full bg-white border border-[#F59E0B] text-[#F59E0B] hover:bg-orange-50 font-medium py-2 rounded-xl text-sm transition-colors">
                Proponer hora
              </button>
              <button @click="openRejectModal(r)"
                class="w-full bg-red-50 hover:bg-red-100 text-red-600 font-medium py-2 rounded-xl text-sm transition-colors">
                Rechazar
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- ── Solicitudes con contraoferta pendiente ─────────────────────── -->
      <section v-if="counterofdRequests.length">
        <h3 class="text-base font-bold text-slate-700 mb-3">
          Con contraoferta pendiente ({{ counterofdRequests.length }})
        </h3>
        <div class="space-y-2">
          <div v-for="r in counterofdRequests" :key="r.id"
            class="bg-amber-50 rounded-xl border border-amber-200 p-4">
            <div class="flex items-start gap-3">
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-gray-800">{{ r.subject?.name }}</p>
                <p class="text-xs text-gray-500">{{ r.student?.first_name }} {{ r.student?.last_name }}</p>
                <p class="text-xs text-amber-700 mt-1">
                  Propusiste: {{ fmtDateTime(r.counteroffer_time) }} — esperando respuesta del padre.
                </p>
              </div>
              <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-semibold flex-shrink-0">
                Pendiente
              </span>
            </div>
          </div>
        </div>
      </section>

      <!-- ── Solicitudes rechazadas (últimas 10) ────────────────────────── -->
      <section v-if="rejectedRequests.length">
        <h3 class="text-base font-bold text-slate-700 mb-3">
          Rechazadas recientemente ({{ rejectedRequests.length }})
        </h3>
        <div class="space-y-2">
          <div v-for="r in rejectedRequests" :key="r.id"
            class="bg-white rounded-xl border border-red-100 p-4 opacity-80">
            <div class="flex items-start gap-3">
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-gray-700">{{ r.subject?.name }}</p>
                <p class="text-xs text-gray-500">{{ r.student?.first_name }} {{ r.student?.last_name }}</p>
                <p class="text-xs text-red-600 mt-1">Motivo: {{ r.teacher_rejection_reason }}</p>
              </div>
              <span class="text-xs text-red-500 flex-shrink-0">{{ fmtDate(r.teacher_rejected_at) }}</span>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- ── Modal de CONTRAOFERTA ──────────────────────────────────────────── -->
    <div v-if="counterofferTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 shadow-xl p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Proponer otra hora</h3>
        <p class="text-sm text-gray-500 mb-4">
          Clase de <strong>{{ counterofferTarget.subject?.name }}</strong> con
          {{ counterofferTarget.student?.first_name }}.
          El padre recibirá la propuesta y podrá aceptar o rechazar.
        </p>

        <label class="block text-xs font-semibold text-gray-700 mb-1">Hora que propones</label>
        <input
          v-model="counterofferForm.counteroffer_time"
          type="datetime-local"
          :min="minDatetime"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 mb-1"
        />
        <p v-if="counterofferForm.errors.counteroffer_time" class="text-xs text-red-500 mb-2">
          {{ counterofferForm.errors.counteroffer_time }}
        </p>

        <div class="flex gap-2 mt-4">
          <button @click="closeCounterofferModal"
            :disabled="counterofferForm.processing"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-50">
            Cancelar
          </button>
          <button @click="submitCounteroffer"
            :disabled="counterofferForm.processing || !counterofferForm.counteroffer_time"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-accent-500 hover:bg-accent-600 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
            {{ counterofferForm.processing ? 'Enviando…' : 'Enviar propuesta' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Modal de RECHAZO ──────────────────────────────────────────────── -->
    <div v-if="rejectTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Rechazar solicitud</h3>
        <p class="text-sm text-gray-500 mb-4">
          Solicitud de <strong>{{ rejectTarget.subject?.name }}</strong> de
          {{ rejectTarget.student?.first_name }}.
          El padre recibirá una notificación.
        </p>
        <textarea v-model="rejectReason" rows="3"
          placeholder="Motivo del rechazo (obligatorio, mínimo 10 caracteres)"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-1" />
        <p v-if="rejectError" class="text-xs text-red-500 mb-2">{{ rejectError }}</p>
        <div class="flex gap-2 mt-3">
          <button @click="closeRejectModal"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">
            Cancelar
          </button>
          <button @click="submitReject" :disabled="rejecting"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed">
            {{ rejecting ? 'Rechazando…' : 'Confirmar rechazo' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import { timeSlotLabel, timeSlotIconName } from '@/utils/timeSlots'

defineProps({
  requests:             { type: Array, default: () => [] },
  rejectedRequests:     { type: Array, default: () => [] },
  counterofdRequests:   { type: Array, default: () => [] },
})

// ── Modal de contraoferta ─────────────────────────────────────────────────
const counterofferTarget = ref(null)

// datetime-local mínimo: ahora + 30 min (evitar proponer en el pasado)
const minDatetime = computed(() => {
  const d = new Date(Date.now() + 30 * 60 * 1000)
  // format: "YYYY-MM-DDTHH:MM"
  return d.toISOString().slice(0, 16)
})

const counterofferForm = useForm({
  counteroffer_time: '',
})

function openCounterofferModal(r) {
  counterofferTarget.value = r
  counterofferForm.reset()
  counterofferForm.errors.counteroffer_time = null
}

function closeCounterofferModal() {
  if (counterofferForm.processing) return
  counterofferTarget.value = null
}

function submitCounteroffer() {
  if (counterofferForm.processing) return
  counterofferForm.post(
    route('teacher.requests.counteroffer', counterofferTarget.value.id),
    {
      onSuccess: () => closeCounterofferModal(),
      onError: () => {}, // errores mapeados a counterofferForm.errors automáticamente
    }
  )
}

// ── Modal de rechazo (sin cambios) ───────────────────────────────────────
const rejectTarget = ref(null)
const rejectReason = ref('')
const rejectError  = ref('')
const rejecting    = ref(false)

function openRejectModal(r) {
  rejectTarget.value = r
  rejectReason.value = ''
  rejectError.value  = ''
}

function closeRejectModal() {
  if (rejecting.value) return
  rejectTarget.value = null
  rejectReason.value = ''
  rejectError.value  = ''
}

function submitReject() {
  if (rejecting.value) return
  if (rejectReason.value.trim().length < 10) {
    rejectError.value = 'El motivo debe tener al menos 10 caracteres.'
    return
  }
  rejecting.value = true
  router.post(
    route('teacher.requests.reject', rejectTarget.value.id),
    { reason: rejectReason.value.trim() },
    {
      onSuccess: () => closeRejectModal(),
      onError: (errors) => { rejectError.value = errors.reason ?? 'No se pudo rechazar la solicitud.' },
      onFinish: () => { rejecting.value = false },
    }
  )
}

// ── Helpers de fecha ──────────────────────────────────────────────────────
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' })
}

function fmtDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleString('es-ES', {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  })
}
</script>
