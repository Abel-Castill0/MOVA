<template>
  <AppLayout title="Mis solicitudes">
    <div class="mx-auto w-full max-w-4xl space-y-5">
      <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p class="text-sm text-ink-muted">Sigue cada pedido de clase: desde que lo creas hasta que queda programada.</p>
        </div>
        <div class="flex items-center gap-3">
          <LiveIndicator />
          <Link
            :href="route('class-requests.create')"
            class="inline-flex min-h-11 items-center rounded-xl bg-brand-600 px-4 text-sm font-bold text-white transition-colors hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2"
          >
            Nueva solicitud
          </Link>
        </div>
      </header>

      <!-- Errores de negocio de la contraoferta (propuesta vencida, profesor ya no disponible…) -->
      <div aria-live="polite">
        <p v-if="counterofferError" role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ counterofferError }}</p>
      </div>

      <div v-if="!requests.length" class="rounded-2xl border border-line bg-white px-6 py-14 text-center" data-testid="empty-state">
        <p class="text-base font-bold text-ink">Aún no has pedido ninguna clase</p>
        <p class="mx-auto mt-1 max-w-sm text-sm text-ink-muted">Cuéntanos qué necesita tu hijo y los profesores verificados de esa materia podrán responderte.</p>
        <Link :href="route('class-requests.create')" class="mt-5 inline-flex min-h-11 items-center rounded-xl bg-brand-600 px-4 text-sm font-bold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">Crear mi primera solicitud</Link>
      </div>

      <template v-else>
        <!-- Filtros -->
        <div class="flex gap-2 overflow-x-auto pb-1" role="group" aria-label="Filtrar solicitudes">
          <button
            v-for="f in filters"
            :key="f.key"
            type="button"
            :aria-pressed="filter === f.key"
            :class="[
              'inline-flex min-h-10 flex-shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400',
              filter === f.key ? 'border-brand-600 bg-brand-600 text-white' : 'border-line bg-white text-ink hover:bg-slate-50',
            ]"
            :data-testid="`filter-${f.key}`"
            @click="filter = f.key"
          >
            {{ f.label }}
            <span :class="['rounded-full px-1.5 text-xs', filter === f.key ? 'bg-white/20' : 'bg-slate-100 text-ink-muted']">{{ f.count }}</span>
          </button>
        </div>

        <p v-if="!visible.length" class="rounded-2xl border border-line bg-white px-6 py-10 text-center text-sm text-ink-muted">No hay solicitudes en este filtro.</p>

        <ul class="space-y-3.5">
          <li v-for="r in visible" :key="r.id">
            <!-- Las clases bg-white/rounded-3xl/border-gray-100 identifican la tarjeta en las pruebas E2E -->
            <article class="bg-white rounded-3xl border border-gray-100 p-5 sm:p-6" :data-testid="`request-${r.id}`">
              <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-bold text-ink">{{ r.subject?.name }}</h2>
                    <StatusBadge :status="r.status" />
                  </div>
                  <p class="mt-0.5 text-sm text-ink-muted">
                    {{ r.student?.first_name }} {{ r.student?.last_name }} · creada el {{ fmtLimaDateShort(r.created_at) }}
                  </p>
                </div>
                <p v-if="r.class_offer" class="text-xs font-semibold text-brand-700">Prof. {{ r.class_offer?.teacher_profile?.user?.name }}</p>
              </div>

              <!-- Seguimiento del proceso -->
              <ol class="mt-4 grid grid-cols-4 gap-1.5" :aria-label="`Avance: ${view(r).stepLabel}`">
                <li v-for="(label, i) in view(r).steps" :key="label" class="min-w-0">
                  <span :class="['block h-1.5 rounded-full', stepClass(view(r), i)]" aria-hidden="true" />
                  <span :class="['mt-1 block truncate text-[11px] font-semibold sm:text-xs', i <= view(r).reached ? 'text-ink' : 'text-ink-muted']">{{ label }}</span>
                </li>
              </ol>

              <!-- Qué pasa ahora -->
              <div :class="['mt-4 rounded-xl border px-3.5 py-3 text-sm', view(r).tone]" :data-testid="`next-step-${view(r).key}`">
                <p class="font-semibold">{{ view(r).title }}</p>
                <p class="mt-0.5">{{ view(r).text }}</p>

                <dl v-if="r.status === 'counteroffered'" class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                  <div class="flex gap-1.5"><dt class="font-semibold">Fecha y hora:</dt><dd>{{ fmtLimaLong(r.counteroffer_time) }} (hora de Perú)</dd></div>
                  <div class="flex gap-1.5"><dt class="font-semibold">Duración:</dt><dd>{{ r.counteroffer_duration_minutes }} min</dd></div>
                </dl>
                <p v-if="r.status === 'teacher_rejected' && r.teacher_rejection_reason" class="mt-2 break-words">Motivo: {{ r.teacher_rejection_reason }}</p>
              </div>

              <!-- Detalle -->
              <details class="group mt-3">
                <summary class="inline-flex min-h-10 cursor-pointer list-none items-center gap-1 rounded-lg text-sm font-semibold text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                  <span class="group-open:hidden">Ver lo que pediste</span><span class="hidden group-open:inline">Ocultar detalle</span>
                </summary>
                <p class="mt-1 whitespace-pre-line break-words rounded-xl bg-slate-50 px-3 py-2 text-sm text-ink-muted">{{ r.help_needed }}</p>
                <p v-if="r.preferred_times?.length" class="mt-2 text-xs text-ink-muted">Horarios preferidos: {{ r.preferred_times.map(timeSlotLabel).join(', ') }}</p>
              </details>

              <!-- Acciones del momento -->
              <div v-if="r.status === 'pending_parent_approval'" class="mt-4 flex flex-wrap gap-2">
                <Link :href="route('class-requests.approve', r.id)" method="post" as="button" class="min-h-11 flex-1 rounded-xl bg-brand-600 px-4 text-sm font-bold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 sm:flex-none">Aprobar</Link>
                <Link :href="route('class-requests.reject', r.id)" method="post" as="button" class="min-h-11 flex-1 rounded-xl px-4 text-sm font-semibold text-rose-700 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400 sm:flex-none">Rechazar</Link>
              </div>
              <div v-else-if="r.status === 'counteroffered'" class="mt-4 flex flex-wrap gap-2">
                <button type="button" :disabled="processingId !== null" :aria-busy="processingId === r.id && action === 'accept'" class="min-h-11 flex-1 rounded-xl bg-brand-600 px-4 text-sm font-bold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 sm:flex-none" @click="respond(r, 'accept')">
                  {{ processingId === r.id && action === 'accept' ? 'Aceptando…' : 'Aceptar' }}
                </button>
                <button type="button" :disabled="processingId !== null" :aria-busy="processingId === r.id && action === 'reject'" class="min-h-11 flex-1 rounded-xl px-4 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400 sm:flex-none" @click="respond(r, 'reject')">
                  {{ processingId === r.id && action === 'reject' ? 'Rechazando…' : 'Rechazar' }}
                </button>
              </div>
              <div v-else-if="view(r).cta" class="mt-4">
                <Link :href="view(r).cta.href" class="inline-flex min-h-11 items-center rounded-xl border border-brand-200 px-4 text-sm font-semibold text-brand-700 hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">{{ view(r).cta.label }}</Link>
              </div>
            </article>
          </li>
        </ul>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import LiveIndicator from '@/Components/LiveIndicator.vue'
import { useRealtimeRefresh } from '@/Composables/useRealtimeRefresh'
import { fmtLimaDateShort, LIMA_TZ } from '@/utils/limaTime'
import { timeSlotLabel } from '@/utils/timeSlots'

const props = defineProps({ requests: { type: Array, default: () => [] } })

useRealtimeRefresh({ requests: ['requests'], lessons: ['requests'] })

const processingId = ref(null)
const action = ref(null)
const counterofferError = ref('')
const filter = ref('all')

const NEEDS_ACTION = ['pending_parent_approval', 'counteroffered']
const IN_PROGRESS = ['open']
const CLOSED = ['accepted', 'teacher_rejected', 'rejected', 'expired']

const filters = computed(() => [
  { key: 'all', label: 'Todas', count: props.requests.length },
  { key: 'action', label: 'Requieren tu respuesta', count: props.requests.filter((r) => NEEDS_ACTION.includes(r.status)).length },
  { key: 'progress', label: 'Esperando profesor', count: props.requests.filter((r) => IN_PROGRESS.includes(r.status)).length },
  { key: 'closed', label: 'Cerradas', count: props.requests.filter((r) => CLOSED.includes(r.status)).length },
])

const visible = computed(() => {
  const group = { action: NEEDS_ACTION, progress: IN_PROGRESS, closed: CLOSED }[filter.value]

  return group ? props.requests.filter((r) => group.includes(r.status)) : props.requests
})

const TONES = {
  info: 'border-brand-200 bg-brand-50 text-brand-900',
  warn: 'border-amber-200 bg-amber-50 text-amber-900',
  ok: 'border-emerald-200 bg-emerald-50 text-emerald-900',
  muted: 'border-line bg-slate-50 text-ink-muted',
  bad: 'border-rose-200 bg-rose-50 text-rose-900',
}
const STEPS = ['Creada', 'Publicada', 'Respuesta', 'Programada']
const newRequest = { label: 'Crear otra solicitud', href: route('class-requests.create') }

/** Estado visible de una solicitud: avance, qué pasa ahora y qué puede hacer el padre. Sale del estado que dice el servidor. */
function view(r) {
  const base = { steps: STEPS, stopped: false, cta: null }
  switch (r.status) {
    case 'pending_parent_approval':
      return { ...base, key: 'awaiting_parent', steps: ['Creada', 'Tu aprobación', 'Respuesta', 'Programada'], reached: 0, stepLabel: 'pendiente de tu aprobación', tone: TONES.warn,
        title: 'Falta tu aprobación', text: 'Ningún profesor puede verla todavía. Apruébala para publicarla o recházala si ya no la necesitas.' }
    case 'open':
      if (r.delivery === 'no_teachers') {
        return { ...base, key: 'no_teachers', reached: 0, stepLabel: 'guardada, sin profesores compatibles', tone: TONES.warn,
          title: 'Sin profesores compatibles por ahora', text: 'Hoy ningún profesor verificado puede verla. Sigue abierta hasta que venza; puedes probar con otra materia o con el código de un profesor.', cta: newRequest }
      }
      if (r.delivery === 'directed') {
        return { ...base, key: 'directed', reached: 1, stepLabel: 'enviada al profesor', tone: TONES.info,
          title: 'Enviada al profesor que elegiste', text: 'Ya puede verla en su panel. Te avisaremos cuando la acepte o te proponga un horario.' }
      }

      return { ...base, key: 'published', reached: 1, stepLabel: 'publicada', tone: TONES.info,
        title: 'Publicada para profesores compatibles', text: 'Los profesores verificados de la materia ya pueden verla. Te avisaremos cuando alguno la acepte o te proponga un horario.' }
    case 'counteroffered':
      return { ...base, key: 'counteroffer', reached: 2, stepLabel: 'propuesta de horario recibida', tone: TONES.warn,
        title: `${r.counteroffer_teacher?.name || 'Un profesor'} te propone un horario`, text: 'Si la aceptas, la clase queda programada al instante. Si la rechazas, tu solicitud vuelve a estar abierta para otros profesores.' }
    case 'accepted':
      return { ...base, key: 'accepted', reached: 3, stepLabel: 'clase programada', tone: TONES.ok,
        title: 'Clase programada', text: 'Un profesor aceptó tu solicitud. Revisa la fecha y la sala en «Mis clases».', cta: { label: 'Ver mis clases', href: route('parent.lessons') } }
    case 'teacher_rejected':
      return { ...base, key: 'teacher_rejected', reached: 2, stopped: true, stepLabel: 'rechazada por el profesor', tone: TONES.bad,
        title: 'El profesor no pudo tomarla', text: 'Puedes crear otra solicitud; si era para un profesor concreto, prueba sin código para que la vean más profesores.', cta: newRequest }
    case 'rejected':
      return { ...base, key: 'rejected', reached: 0, stopped: true, stepLabel: 'rechazada', tone: TONES.muted,
        title: 'La rechazaste', text: 'Esta solicitud no se publicó. Puedes crear otra cuando quieras.', cta: newRequest }
    case 'expired':
      return { ...base, key: 'expired', reached: 1, stopped: true, stepLabel: 'vencida', tone: TONES.muted,
        title: 'Venció sin respuesta', text: 'Ningún profesor la tomó a tiempo. No es un rechazo: puedes volver a publicarla.', cta: newRequest }
    default:
      return { ...base, key: 'other', reached: 0, stepLabel: r.status, tone: TONES.muted, title: 'En proceso', text: 'Te avisaremos de cualquier novedad.' }
  }
}

function stepClass(v, i) {
  if (i > v.reached) return 'bg-slate-200'
  if (v.stopped && i === v.reached) return 'bg-slate-400'

  return 'bg-brand-600'
}

function fmtLimaLong(iso) {
  if (!iso) return '—'

  return new Date(iso).toLocaleString('es-PE', { timeZone: LIMA_TZ, weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
}

function respond(r, kind) {
  if (processingId.value !== null) return
  processingId.value = r.id
  action.value = kind
  counterofferError.value = ''

  // Se reenvía la huella de la propuesta mostrada: si cambió en el servidor (otra pestaña, nueva propuesta), no se
  // acepta ni rechaza nada a ciegas.
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
