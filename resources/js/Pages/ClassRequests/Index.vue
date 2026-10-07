<template>
  <AppLayout title="Mis solicitudes">
    <div class="space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-2xl font-black text-slate-900">Solicitudes de clase</h2>
        <Link :href="route('class-requests.create')"
          class="px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-2xl hover:bg-brand-700 active:scale-95 transition-all shadow-md shadow-brand-600/25">
          + Nueva solicitud
        </Link>
      </div>

      <!-- Errores de negocio de la contraoferta (propuesta vencida, profesor ya
           no disponible, créditos/agenda cambiados…) — anunciados a lectores
           de pantalla. -->
      <div aria-live="polite">
        <p v-if="counterofferError" role="alert"
          class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-2xl px-4 py-3">
          {{ counterofferError }}
        </p>
      </div>

      <div v-if="!requests.length" class="text-center py-16 bg-white rounded-3xl border border-gray-100 shadow-sm">
        <p class="text-slate-500">Sin solicitudes</p>
      </div>

      <div v-else class="space-y-3.5">
        <div v-for="r in requests" :key="r.id"
          class="bg-white rounded-3xl border border-gray-100 p-5 sm:p-6 hover:border-brand-300 hover:shadow-md transition-all">
          <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-1">
                <p class="font-bold text-slate-900">{{ r.subject?.name }}</p>
                <StatusBadge :status="r.status" />
              </div>
              <p class="text-sm text-slate-500">{{ r.student?.first_name }} {{ r.student?.last_name }}</p>
              <p class="text-sm text-slate-500 mt-1 line-clamp-2 break-words">{{ r.help_needed }}</p>
              <p v-if="r.class_offer" class="text-xs text-brand-600 mt-1">
                Prof. {{ r.class_offer?.teacher_profile?.user?.name }}
              </p>
              <div v-if="r.status === 'teacher_rejected' && r.teacher_rejection_reason"
                class="mt-2 text-xs text-red-600 bg-red-50 rounded-xl px-2.5 py-1.5">
                El profesor rechazó: {{ r.teacher_rejection_reason }}
              </div>

              <!-- Propuesta de horario del profesor -->
              <div v-if="r.status === 'counteroffered'"
                class="mt-3 rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3">
                <p class="text-sm font-bold text-amber-900">
                  {{ r.counteroffer_teacher?.name || 'Un profesor' }} propone otro horario
                </p>
                <dl class="mt-1.5 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm text-amber-900">
                  <div class="flex gap-1.5"><dt class="font-semibold">Fecha y hora:</dt><dd>{{ fmtLima(r.counteroffer_time) }}</dd></div>
                  <div class="flex gap-1.5"><dt class="font-semibold">Duración:</dt><dd>{{ r.counteroffer_duration_minutes }} min</dd></div>
                </dl>
                <p class="mt-1.5 text-xs text-amber-800">
                  Si la aceptas, la clase queda programada al instante. Si la rechazas, tu solicitud vuelve a estar abierta para otros profesores.
                </p>
              </div>
            </div>

            <div v-if="r.status === 'pending_parent_approval'" class="flex gap-2 flex-shrink-0">
              <Link :href="route('class-requests.approve', r.id)" method="post" as="button"
                class="px-4 py-2 bg-green-600 text-white text-xs font-bold rounded-2xl hover:bg-green-700 active:scale-95 transition-all shadow-sm shadow-green-600/20">
                Aprobar
              </Link>
              <Link :href="route('class-requests.reject', r.id)" method="post" as="button"
                class="px-4 py-2 bg-red-100 text-red-700 text-xs font-bold rounded-2xl hover:bg-red-200 active:scale-95 transition-all">
                Rechazar
              </Link>
            </div>

            <div v-else-if="r.status === 'counteroffered'" class="flex gap-2 flex-shrink-0 sm:pt-1">
              <button type="button" @click="respond(r, 'accept')" :disabled="processingId !== null"
                :aria-busy="processingId === r.id && action === 'accept'"
                class="flex-1 sm:flex-none px-4 py-2 bg-green-600 text-white text-xs font-bold rounded-2xl hover:bg-green-700 active:scale-95 transition-all shadow-sm shadow-green-600/20 disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                {{ processingId === r.id && action === 'accept' ? 'Aceptando…' : 'Aceptar' }}
              </button>
              <button type="button" @click="respond(r, 'reject')" :disabled="processingId !== null"
                :aria-busy="processingId === r.id && action === 'reject'"
                class="flex-1 sm:flex-none px-4 py-2 bg-red-100 text-red-700 text-xs font-bold rounded-2xl hover:bg-red-200 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400 focus-visible:ring-offset-2">
                {{ processingId === r.id && action === 'reject' ? 'Rechazando…' : 'Rechazar' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'

defineProps({ requests: Array })

const processingId = ref(null)
const action = ref(null)
const counterofferError = ref('')

// Hora de Perú explícita: la propuesta se guarda en UTC y el padre siempre
// la ve en la zona del servicio, sin depender de la del dispositivo.
function fmtLima(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-PE', {
    timeZone: 'America/Lima',
    weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
  })
}

function respond(r, kind) {
  if (processingId.value !== null) return
  processingId.value = r.id
  action.value = kind
  counterofferError.value = ''

  // Se reenvía la huella de la propuesta mostrada: si cambió en el servidor
  // (otra pestaña, nueva propuesta), no se acepta ni rechaza nada a ciegas.
  router.post(route(`class-requests.counteroffer.${kind}`, r.id), { counteroffer_ref: r.counteroffer_ref }, {
    preserveScroll: true,
    onError: (errors) => {
      counterofferError.value = errors.counteroffer || errors.counteroffer_ref || errors.accept || errors.start_time
        || 'No se pudo completar la acción. Recarga la página e inténtalo de nuevo.'
    },
    onFinish: () => {
      processingId.value = null
      action.value = null
    },
  })
}
</script>
