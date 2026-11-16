<template>
  <AppLayout title="Mis solicitudes">
    <div class="space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-2xl font-bold text-slate-900">Solicitudes de clase</h2>
          <p class="mt-1 text-sm text-slate-600">Conoce el estado de tus solicitudes y cuál es el siguiente paso.</p>
        </div>
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
        <p class="font-semibold text-slate-800">Todavía no tienes solicitudes</p>
        <p class="mt-2 text-sm text-slate-500">Cuéntanos qué materia necesita reforzar el alumno para empezar.</p>
        <Link :href="route('class-requests.create')" class="mt-4 inline-flex rounded-xl bg-brand-600 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">Crear mi primera solicitud</Link>
      </div>

      <div v-else class="space-y-4">
        <div class="flex flex-wrap items-center gap-3 rounded-2xl bg-white border border-slate-200 p-3">
          <label for="parent-request-filter" class="text-sm font-medium text-slate-700">Filtrar por estado</label>
          <select id="parent-request-filter" v-model="statusFilter" class="rounded-xl border-slate-200 text-sm text-slate-800 focus:border-brand-500 focus:ring-brand-500">
            <option value="all">Todas ({{ requests.length }})</option>
            <option value="pending">Pendientes o abiertas</option>
            <option value="counteroffered">Propuestas recibidas</option>
            <option value="accepted">Aceptadas</option>
            <option value="closed">Rechazadas o vencidas</option>
          </select>
          <span class="text-xs text-slate-500" role="status">{{ visibleRequests.length }} solicitudes</span>
        </div>
        <p v-if="!visibleRequests.length" class="rounded-2xl bg-white border border-slate-200 px-5 py-8 text-center text-sm text-slate-600">No hay solicitudes con ese estado.</p>
        <div v-for="r in visibleRequests" :key="r.id"
          class="bg-white rounded-3xl border border-gray-100 p-5 sm:p-6 hover:border-brand-300 hover:shadow-md transition-all">
          <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-1">
                <p class="font-bold text-slate-900">{{ r.subject?.name }}</p>
                <StatusBadge :status="r.status" />
              </div>
              <p class="text-sm text-slate-500">{{ r.student?.first_name }} {{ r.student?.last_name }}</p>
              <p class="text-sm text-slate-500 mt-1 line-clamp-2 break-words">{{ r.help_needed }}</p>
              <p class="mt-2 text-xs text-slate-500">Creada: {{ fmtCreated(r.created_at) }} · Hora de Perú</p>
              <p class="mt-2 text-xs font-medium text-brand-800" role="status">{{ nextStep(r) }}</p>
              <p v-if="r.delivery === 'no_teachers'" class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900" data-testid="delivery-no-teachers">
                Sin profesores compatibles por ahora: ningún profesor verificado puede verla todavía. Sigue abierta hasta que venza.
              </p>
              <p v-else-if="r.delivery === 'published'" class="mt-1 text-xs text-emerald-700" data-testid="delivery-published">Publicada: los profesores verificados de la materia ya pueden verla.</p>
              <p v-else-if="r.delivery === 'directed'" class="mt-1 text-xs text-emerald-700" data-testid="delivery-directed">Enviada al profesor que elegiste.</p>
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
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import { useVisibleInertiaRefresh } from '@/Composables/useVisibleInertiaRefresh.js'

const props = defineProps({
  requests: { type: Array, default: () => [] },
})

const statusFilter = ref('all')
const visibleRequests = computed(() => props.requests.filter(r => {
  if (statusFilter.value === 'pending') return ['open', 'pending_parent_approval'].includes(r.status)
  if (statusFilter.value === 'counteroffered') return r.status === 'counteroffered'
  if (statusFilter.value === 'accepted') return r.status === 'accepted'
  if (statusFilter.value === 'closed') return ['rejected', 'teacher_rejected', 'expired'].includes(r.status)
  return true
}))

function nextStep(r) {
  if (r.status === 'pending_parent_approval') return 'Siguiente paso: aprueba o rechaza esta solicitud.'
  if (r.status === 'counteroffered') return 'Siguiente paso: revisa la contraoferta y decide si aceptas el nuevo horario.'
  if (r.status === 'accepted') return 'Clase programada. Consulta Mis clases para conocer los detalles.'
  if (r.status === 'rejected' || r.status === 'teacher_rejected') return 'Esta solicitud fue rechazada. Puedes crear otra solicitud.'
  if (r.status === 'expired') return 'Esta solicitud venció. Puedes crear otra con nuevos horarios.'
  if (r.delivery === 'no_teachers') return 'La solicitud sigue abierta, pero aún no hay profesores compatibles.'
  if (r.delivery === 'directed') return 'Esperando respuesta del profesor elegido.'
  return 'Esperando que un profesor compatible responda.'
}

function fmtCreated(date) {
  if (!date) return '—'
  return new Date(date).toLocaleString('es-PE', {
    timeZone: 'America/Lima', day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

const processingId = ref(null)
const action = ref(null)
const counterofferError = ref('')

useVisibleInertiaRefresh(['requests'], {
  intervalMs: 30000,
  shouldRefresh: () => processingId.value === null,
})

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
