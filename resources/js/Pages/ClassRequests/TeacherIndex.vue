<template>
  <AppLayout title="Solicitudes recibidas">
    <div class="space-y-8">
      <h2 class="text-2xl font-bold text-gray-900">Solicitudes de clase</h2>

      <!-- ── Solicitudes abiertas ──────────────────────────────────────────── -->
      <section>
        <h3 class="text-base font-bold text-slate-700 mb-3">
          Abiertas ({{ requests.length }})
        </h3>

        <div v-if="!requests.length" class="text-center py-12 bg-white rounded-xl border border-gray-200">
          <div class="text-4xl mb-2">📋</div>
          <p class="text-gray-400 text-sm">No hay solicitudes abiertas</p>
        </div>

        <div v-else class="space-y-3">
          <div v-for="r in requests" :key="r.id" class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <p class="font-semibold text-gray-900">{{ r.subject?.name }}</p>
                <p class="text-sm text-gray-500">Alumno: {{ r.student?.first_name }} {{ r.student?.last_name }}</p>
                <p class="text-sm text-gray-600 mt-2 line-clamp-3">{{ r.help_needed }}</p>
                <div v-if="r.preferred_times?.length" class="mt-2 flex flex-wrap gap-1">
                  <span v-for="t in r.preferred_times" :key="t"
                    class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">
                    {{ timeSlotLabel(t) }}
                  </span>
                </div>
              </div>
              <div class="flex flex-col gap-2 flex-shrink-0">
                <Link :href="route('teacher.requests.accept', r.id)"
                  class="px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 text-center">
                  Aceptar
                </Link>
                <button @click="openRejectModal(r)"
                  class="px-4 py-2 bg-red-50 text-red-600 text-sm font-semibold rounded-lg hover:bg-red-100">
                  Rechazar
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ── Solicitudes rechazadas (últimas 10) ──────────────────────────── -->
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

    <!-- ── Modal de rechazo ─────────────────────────────────────────────────── -->
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
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
  requests:         { type: Array, default: () => [] },
  rejectedRequests: { type: Array, default: () => [] },
})

const rejectTarget = ref(null)
const rejectReason = ref('')
const rejectError  = ref('')
const rejecting    = ref(false)

const TIME_SLOT_LABELS = {
  morning_weekday:   '🌅 Mañana (L-V)',
  afternoon_weekday: '☀️ Tarde (L-V)',
  evening_weekday:   '🌆 Noche (L-V)',
  morning_weekend:   '🌅 Mañana (S-D)',
  afternoon_weekend: '☀️ Tarde (S-D)',
  flexible:          '🔄 Flexible',
}

function timeSlotLabel(t) {
  return TIME_SLOT_LABELS[t] ?? t.replace(/_/g, ' ')
}

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

// F-12 (segunda ronda): esta página quedó fuera de la primera corrección.
// Importa más que las otras: rechazar cierra una solicitud, y el botón vecino
// de aceptar crea una Lesson y RESERVA créditos del profesor.
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

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' })
}
</script>
