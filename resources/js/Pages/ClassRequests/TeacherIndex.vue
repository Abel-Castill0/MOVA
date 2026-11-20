<template>
  <AppLayout title="Solicitudes" :compact="true">
    <div class="mx-auto w-full max-w-6xl space-y-5">
      <!-- Cabecera -->
      <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p class="text-sm text-ink-muted">Familias que buscan un profesor como tú. Responde a las que encajen con tu horario.</p>
        </div>
        <div class="flex items-center gap-3">
          <LiveIndicator />
          <button
            type="button"
            class="inline-flex min-h-10 items-center gap-2 rounded-xl border border-line bg-white px-3.5 text-sm font-semibold text-ink transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 disabled:opacity-60"
            :disabled="isRefreshing"
            aria-label="Actualizar solicitudes"
            @click="refresh"
          >
            <svg class="h-4 w-4" :class="{ 'motion-safe:animate-spin': isRefreshing }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M21 12a9 9 0 1 1-2.64-6.36" /><path d="M21 3v6h-6" />
            </svg>
            Actualizar
          </button>
        </div>
      </header>

      <!-- Perfil sin verificar: nada de datos de menores -->
      <section v-if="verificationPending" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900" data-testid="verification-pending">
        <h2 class="font-bold">Tu perfil está en verificación</h2>
        <p class="mt-1 text-sm">Por la seguridad de los menores, las solicitudes se muestran solo a profesores verificados. Te avisaremos cuando tu perfil esté aprobado.</p>
      </section>

      <template v-else>
        <!-- Contadores = pestañas -->
        <div role="tablist" aria-label="Estado de las solicitudes" class="grid grid-cols-3 gap-2 sm:gap-3">
          <button
            v-for="t in tabs"
            :id="`tab-${t.key}`"
            :key="t.key"
            type="button"
            role="tab"
            :aria-selected="statusTab === t.key"
            :aria-controls="'requests-panel'"
            :tabindex="statusTab === t.key ? 0 : -1"
            :class="[
              'rounded-2xl border px-3 py-3 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 sm:px-4',
              statusTab === t.key ? 'border-brand-600 bg-brand-50' : 'border-line bg-white hover:bg-slate-50',
            ]"
            :data-testid="`tab-${t.key}`"
            @click="setTab(t.key)"
            @keydown.right.prevent="focusTab(1)"
            @keydown.left.prevent="focusTab(-1)"
          >
            <span class="block text-2xl font-black text-ink">{{ t.count }}</span>
            <span class="block text-xs font-semibold text-ink-muted sm:text-sm">{{ t.label }}</span>
          </button>
        </div>

        <!-- Búsqueda y orden -->
        <div v-if="statusTab === 'open'" class="flex flex-col gap-2 sm:flex-row">
          <div class="relative flex-1">
            <label for="request-search" class="sr-only">Buscar solicitudes</label>
            <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
            <input
              id="request-search"
              v-model="searchQuery"
              type="search"
              placeholder="Buscar por materia, grado u objetivo"
              class="min-h-11 w-full rounded-xl border border-line bg-white pl-10 pr-3 text-sm text-ink placeholder:text-ink-muted focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200"
            />
          </div>
          <div>
            <label for="request-sort" class="sr-only">Ordenar por</label>
            <select id="request-sort" v-model="sortBy" class="min-h-11 w-full rounded-xl border border-line bg-white px-3 text-sm text-ink focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200 sm:w-auto">
              <option value="recent">Más recientes</option>
              <option value="oldest">Más antiguas</option>
              <option value="subject">Materia (A-Z)</option>
            </select>
          </div>
        </div>

        <p v-if="notice" class="rounded-xl border border-line bg-slate-50 px-3.5 py-2.5 text-sm text-ink-muted" role="status" data-testid="request-notice">{{ notice }}</p>

        <div id="requests-panel" role="tabpanel" :aria-labelledby="`tab-${statusTab}`" class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_400px]">
          <!-- Lista -->
          <div>
            <ul v-if="visibleRequests.length" class="space-y-2.5" aria-label="Lista de solicitudes">
              <li v-for="r in visibleRequests" :key="`${statusTab}-${r.id}`">
                <button
                  type="button"
                  :aria-pressed="selectedRequest?.id === r.id"
                  :class="[
                    'w-full rounded-2xl border bg-white p-4 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400',
                    selectedRequest?.id === r.id ? 'border-brand-600 ring-1 ring-brand-600' : 'border-line hover:border-brand-300',
                  ]"
                  :data-testid="`request-card-${r.id}`"
                  @click="selectRequest(r, $event)"
                >
                  <span class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-bold text-ink">{{ r.subject?.name ?? 'Clase' }}</span>
                    <StatusBadge :status="r.status" />
                  </span>
                  <span class="mt-0.5 block text-xs text-ink-muted">
                    {{ r.student?.grade_level || 'Grado no indicado' }} · {{ r.is_mentorship ? 'Acompañamiento continuo' : 'Clase puntual' }}
                  </span>
                  <span class="mt-2 line-clamp-2 block break-words text-sm text-ink">{{ requestGoal(r) }}</span>
                  <span v-if="r.preference_label" class="mt-1.5 block text-xs font-bold text-brand-700" :data-testid="`request-pref-${r.id}`">Pide: {{ r.preference_label }} · hora por confirmar</span>
                  <span v-if="r.respond_by && statusTab === 'open'" class="mt-1 block text-xs text-ink-muted" :data-testid="`request-deadline-${r.id}`">Recibe propuestas hasta el {{ fmtLimaDateShort(r.respond_by) }} · {{ timeLeftLabel(r.respond_by, now) }}</span>
                  <span class="mt-2 block text-xs text-ink-muted">Creada el {{ fmtLimaDateShort(r.created_at) }}</span>
                </button>
              </li>
            </ul>

            <div v-else class="rounded-2xl border border-line bg-white px-6 py-12 text-center" data-testid="empty-state">
              <p class="text-base font-bold text-ink">{{ emptyTitle }}</p>
              <p class="mx-auto mt-1 max-w-sm text-sm text-ink-muted">{{ emptyText }}</p>
              <button v-if="searchQuery" type="button" class="mt-4 text-sm font-semibold text-brand-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400" @click="searchQuery = ''">Limpiar búsqueda</button>
            </div>
          </div>

          <!-- Detalle en escritorio -->
          <aside v-if="isDesktop" class="sticky top-24 rounded-2xl border border-line bg-white p-5" aria-label="Detalle de la solicitud" data-testid="request-detail">
            <RequestDetail v-if="selectedRequest" :request="selectedRequest" :tab="statusTab" @counteroffer="openCounteroffer" @reject="openReject" />
            <p v-else class="py-10 text-center text-sm text-ink-muted">Elige una solicitud para ver su detalle.</p>
          </aside>
        </div>
      </template>
    </div>

    <!-- Detalle en móvil/tablet: hoja inferior accesible (foco atrapado, Escape, foco devuelto) -->
    <Modal :show="!isDesktop && detailOpen" max-width="lg" title-id="request-sheet-title" @close="closeDetail">
      <div class="p-5">
        <div class="mb-3 flex items-center justify-between">
          <h2 id="request-sheet-title" class="text-sm font-semibold text-ink-muted">Detalle de la solicitud</h2>
          <button type="button" class="flex h-10 w-10 items-center justify-center rounded-full text-ink-muted hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400" aria-label="Cerrar detalle" @click="closeDetail">
            <span aria-hidden="true" class="text-xl leading-none">×</span>
          </button>
        </div>
        <RequestDetail v-if="selectedRequest" :request="selectedRequest" :tab="statusTab" @counteroffer="openCounteroffer" @reject="openReject" />
      </div>
    </Modal>

    <!-- Proponer otro horario -->
    <Modal :show="Boolean(counterofferTarget)" max-width="md" title-id="counteroffer-title" @close="closeCounteroffer">
      <form class="p-6" @submit.prevent="submitCounteroffer">
        <h2 id="counteroffer-title" class="text-lg font-bold text-ink">{{ counterofferTarget?.preferred_date ? 'Proponer hora' : 'Proponer otro horario' }}</h2>
        <p class="mt-1 text-sm text-ink-muted">
          Clase de <strong class="text-ink">{{ counterofferTarget?.subject?.name }}</strong>. El padre verá tu propuesta en MOVA y podrá aceptarla o rechazarla.
        </p>

        <!-- La familia pidió un día + franja: el profesor propone la HORA exacta. Fuera de la franja se permite, pero se avisa
             y la familia deberá aceptarla expresamente. -->
        <div v-if="targetPreference" class="mt-3 rounded-xl border border-brand-200 bg-brand-50/70 px-3.5 py-2.5 text-sm text-brand-900" data-testid="counteroffer-preference">
          <p class="font-bold">{{ targetPreference.text }}</p>
          <p class="mt-0.5 text-xs">Franja: {{ targetPreference.range }} ({{ LIMA_LABEL }}). Propón una hora dentro de ella.</p>
        </div>

        <label for="counteroffer-time" class="mt-4 block text-sm font-semibold text-ink">Fecha y hora que propones <span class="font-normal text-ink-muted">({{ LIMA_LABEL }})</span></label>
        <input
          id="counteroffer-time"
          v-model="counterofferForm.counteroffer_time"
          type="datetime-local"
          required
          :min="minDatetime"
          :aria-invalid="Boolean(counterofferForm.errors.counteroffer_time)"
          aria-describedby="counteroffer-time-error"
          class="mt-1 min-h-11 w-full rounded-xl border border-line px-3 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200"
        />
        <p v-if="counterofferForm.errors.counteroffer_time" id="counteroffer-time-error" class="mt-1 text-xs text-rose-700" role="alert">{{ counterofferForm.errors.counteroffer_time }}</p>
        <p v-if="outsideSlot" class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900" role="status" data-testid="outside-slot-warning">
          Esta hora queda fuera de lo que pidió la familia. Se enviará como alternativa: la clase solo se agenda si el padre la acepta expresamente.
        </p>

        <fieldset class="mt-4">
          <legend class="text-sm font-semibold text-ink">Duración</legend>
          <div class="mt-1 grid grid-cols-4 gap-2">
            <button
              v-for="opt in durations"
              :key="opt.value"
              type="button"
              :aria-pressed="counterofferForm.duration_minutes === opt.value"
              :class="[
                'min-h-11 rounded-xl border text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400',
                counterofferForm.duration_minutes === opt.value ? 'border-brand-600 bg-brand-600 text-white' : 'border-line bg-white text-ink hover:border-brand-300',
              ]"
              @click="counterofferForm.duration_minutes = opt.value"
            >{{ opt.label }}</button>
          </div>
        </fieldset>
        <p v-if="counterofferForm.errors.duration_minutes" class="mt-1 text-xs text-rose-700" role="alert">{{ counterofferForm.errors.duration_minutes }}</p>

        <div class="mt-6 flex gap-2">
          <button type="button" class="min-h-11 flex-1 rounded-xl border border-line px-4 text-sm font-semibold text-ink hover:bg-slate-50 disabled:opacity-50" :disabled="counterofferForm.processing" @click="closeCounteroffer">Cancelar</button>
          <button type="submit" class="min-h-11 flex-1 rounded-xl bg-brand-600 px-4 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50" :disabled="counterofferForm.processing || !counterofferForm.counteroffer_time">
            {{ counterofferForm.processing ? 'Enviando…' : 'Enviar propuesta' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- Rechazar -->
    <Modal :show="Boolean(rejectTarget)" max-width="md" title-id="reject-title" @close="closeReject">
      <form class="p-6" @submit.prevent="submitReject">
        <h2 id="reject-title" class="text-lg font-bold text-ink">Rechazar solicitud</h2>
        <p class="mt-1 text-sm text-ink-muted">
          Solicitud de <strong class="text-ink">{{ rejectTarget?.subject?.name }}</strong>. La familia recibirá un aviso con tu motivo.
        </p>
        <label for="reject-reason" class="mt-4 block text-sm font-semibold text-ink">Motivo (obligatorio, mínimo 10 caracteres)</label>
        <textarea
          id="reject-reason"
          v-model="rejectReason"
          rows="3"
          :aria-invalid="Boolean(rejectError)"
          aria-describedby="reject-error"
          class="mt-1 w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200"
        />
        <p v-if="rejectError" id="reject-error" class="mt-1 text-xs text-rose-700" role="alert">{{ rejectError }}</p>
        <div class="mt-5 flex gap-2">
          <button type="button" class="min-h-11 flex-1 rounded-xl border border-line px-4 text-sm font-semibold text-ink hover:bg-slate-50" :disabled="rejecting" @click="closeReject">Cancelar</button>
          <button type="submit" class="min-h-11 flex-1 rounded-xl bg-rose-600 px-4 text-sm font-bold text-white hover:bg-rose-700 disabled:opacity-50" :disabled="rejecting">
            {{ rejecting ? 'Rechazando…' : 'Confirmar rechazo' }}
          </button>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { Search } from 'lucide-vue-next'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import LiveIndicator from '@/Components/LiveIndicator.vue'
import RequestDetail from '@/Components/Requests/RequestDetail.vue'
import { useVisibleInertiaRefresh } from '@/Composables/useVisibleInertiaRefresh'
import { useRealtimeRefresh } from '@/Composables/useRealtimeRefresh'
import { fmtLimaDateShort, limaLocalToIso, toLimaDatetimeLocal, LIMA_LABEL } from '@/utils/limaTime'
import { requestGoal, studentFirstName } from '@/utils/requestText'
import { describePreference, slotContains, slotRange, timeLeftLabel } from '@/utils/requestSlots'

const props = defineProps({
  requests: { type: Array, default: () => [] },
  rejectedRequests: { type: Array, default: () => [] },
  counterofferedRequests: { type: Array, default: () => [] },
  verificationPending: { type: Boolean, default: false },
  slots: { type: Array, default: () => [] },
})

const statusTab = ref('open')
// Reloj para «quedan X» (se limpia al desmontar, abajo).
const now = ref(new Date())
const nowTimer = setInterval(() => { now.value = new Date() }, 60000)
const searchQuery = ref('')
const sortBy = ref('recent')
const selectedRequest = ref(null)
const detailOpen = ref(false)
const notice = ref('')
const isDesktop = ref(true)
let desktopQuery = null
let detailOpener = null

const tabs = computed(() => [
  { key: 'open', label: 'Abiertas', count: props.requests.length },
  { key: 'counteroffered', label: 'Contraofertas', count: props.counterofferedRequests.length },
  { key: 'rejected', label: 'Rechazadas', count: props.rejectedRequests.length },
])

const listForTab = computed(() => ({
  open: props.requests,
  counteroffered: props.counterofferedRequests,
  rejected: props.rejectedRequests,
}[statusTab.value] ?? []))

const visibleRequests = computed(() => {
  let list = [...listForTab.value]
  const q = searchQuery.value.trim().toLowerCase()
  if (statusTab.value === 'open' && q) {
    list = list.filter((r) => [r.subject?.name, r.student?.grade_level, requestGoal(r), studentFirstName(r)].some((v) => String(v ?? '').toLowerCase().includes(q)))
  }
  if (statusTab.value === 'open') {
    if (sortBy.value === 'subject') list.sort((a, b) => (a.subject?.name ?? '').localeCompare(b.subject?.name ?? ''))
    else if (sortBy.value === 'oldest') list.sort((a, b) => new Date(a.created_at ?? 0) - new Date(b.created_at ?? 0))
    else list.sort((a, b) => new Date(b.created_at ?? 0) - new Date(a.created_at ?? 0))
  }

  return list
})

const emptyTitle = computed(() => {
  if (searchQuery.value && statusTab.value === 'open') return 'Sin resultados'
  return { open: 'No hay solicitudes abiertas', counteroffered: 'Sin contraofertas pendientes', rejected: 'Sin solicitudes rechazadas' }[statusTab.value]
})
const emptyText = computed(() => {
  if (searchQuery.value && statusTab.value === 'open') return 'Ninguna solicitud coincide con tu búsqueda. Prueba con otra palabra.'
  return {
    open: 'Cuando una familia pida una clase de tus materias, aparecerá aquí al instante. Mantén tus materias al día en tu perfil.',
    counteroffered: 'Aquí verás los horarios que propusiste mientras esperan la respuesta del padre.',
    rejected: 'Las solicitudes que rechaces se guardan aquí, con tu motivo.',
  }[statusTab.value]
})

function setTab(key) {
  statusTab.value = key
  notice.value = ''
  detailOpen.value = false
  selectedRequest.value = isDesktop.value ? (listForTab.value[0] ?? null) : null
}

function focusTab(step) {
  const keys = tabs.value.map((t) => t.key)
  const next = keys[(keys.indexOf(statusTab.value) + step + keys.length) % keys.length]
  setTab(next)
  nextTick(() => document.getElementById(`tab-${next}`)?.focus())
}

function selectRequest(request, event) {
  selectedRequest.value = request
  notice.value = ''
  if (!isDesktop.value) {
    detailOpener = event?.currentTarget ?? null
    detailOpen.value = true
  }
}

function closeDetail() {
  detailOpen.value = false
  const opener = detailOpener
  detailOpener = null
  if (opener) nextTick(() => opener.focus())
}

// El tiempo real o el refresco pueden quitar la solicitud abierta (la aceptó otro profesor o venció): el detalle no
// queda mostrando algo que ya no existe, y se avisa con calma.
watch([() => props.requests, () => props.counterofferedRequests, () => props.rejectedRequests], () => {
  if (!selectedRequest.value) {
    if (isDesktop.value && listForTab.value.length) selectedRequest.value = listForTab.value[0]
    return
  }
  const current = listForTab.value.find((r) => r.id === selectedRequest.value.id)
  if (current) {
    selectedRequest.value = current
    return
  }
  notice.value = 'Esa solicitud ya no está disponible: otro profesor la aceptó o venció.'
  detailOpen.value = false
  selectedRequest.value = isDesktop.value ? (listForTab.value[0] ?? null) : null
})

// ── Proponer otro horario ───────────────────────────────────────────────
const counterofferTarget = ref(null)
const durations = [
  { value: 30, label: '30 min' },
  { value: 60, label: '1 h' },
  { value: 90, label: '1:30 h' },
  { value: 120, label: '2 h' },
]
const minDatetime = computed(() => toLimaDatetimeLocal(new Date(Date.now() + 30 * 60 * 1000)))
const counterofferForm = useForm({ counteroffer_time: '', duration_minutes: 60 })

const slotDefs = computed(() => props.slots)
const targetSlot = computed(() => slotDefs.value.find((s) => s.value === counterofferTarget.value?.preferred_slot) ?? null)
const targetPreference = computed(() => {
  const t = counterofferTarget.value
  if (!t?.preferred_date || !targetSlot.value) return null

  return { text: `Clase solicitada para el ${describePreference(t.preferred_date, t.preferred_slot)}`, range: slotRange(targetSlot.value) }
})
// ¿La hora escrita cae fuera del día + franja pedidos? (aviso; el servidor lo vuelve a decidir)
const outsideSlot = computed(() => {
  if (!targetPreference.value || !counterofferForm.counteroffer_time) return false
  const iso = limaLocalToIso(counterofferForm.counteroffer_time)

  return iso !== '' && !slotContains(counterofferTarget.value.preferred_date, targetSlot.value, iso)
})

function openCounteroffer(request) {
  counterofferForm.reset()
  counterofferForm.clearErrors()
  // Con día + franja pedidos, la propuesta arranca ese día al empezar la franja: solo falta ajustar la hora.
  const slot = props.slots.find((s) => s.value === request.preferred_slot)
  if (request.preferred_date && slot) {
    const start = `${request.preferred_date}T${String(slot.start).padStart(2, '0')}:00`
    // Hoy, si la franja ya empezó, no se propone una hora que el servidor rechazaría por pasada.
    counterofferForm.counteroffer_time = start >= minDatetime.value ? start : ''
  }
  detailOpen.value = false // en móvil la hoja de detalle se cierra: nunca dos diálogos apilados
  counterofferTarget.value = request
}
function closeCounteroffer() {
  if (counterofferForm.processing) return
  counterofferTarget.value = null
}
function submitCounteroffer() {
  if (counterofferForm.processing || !counterofferTarget.value) return
  counterofferForm
    // El profesor escribe hora de Perú; el servidor recibe el instante exacto (UTC), sin reinterpretarlo.
    .transform((data) => ({ ...data, counteroffer_time: limaLocalToIso(data.counteroffer_time) }))
    .post(route('teacher.requests.counteroffer', counterofferTarget.value.id), {
      preserveScroll: true,
      onSuccess: () => {
        counterofferTarget.value = null
        detailOpen.value = false
      },
    })
}

// ── Rechazar ────────────────────────────────────────────────────────────
const rejectTarget = ref(null)
const rejectReason = ref('')
const rejectError = ref('')
const rejecting = ref(false)

function openReject(request) {
  detailOpen.value = false
  rejectTarget.value = request
  rejectReason.value = ''
  rejectError.value = ''
}
function closeReject() {
  if (rejecting.value) return
  rejectTarget.value = null
}
function submitReject() {
  if (rejecting.value) return
  if (rejectReason.value.trim().length < 10) {
    rejectError.value = 'El motivo debe tener al menos 10 caracteres.'
    return
  }
  rejecting.value = true
  router.post(route('teacher.requests.reject', rejectTarget.value.id), { reason: rejectReason.value.trim() }, {
    onSuccess: () => {
      rejectTarget.value = null
      detailOpen.value = false
    },
    onError: (errors) => { rejectError.value = errors.reason ?? 'No se pudo rechazar la solicitud.' },
    onFinish: () => { rejecting.value = false },
  })
}

// ── Actualización: tiempo real + refresco de seguridad ──────────────────
const busyWithDialog = () => Boolean(counterofferTarget.value) || Boolean(rejectTarget.value) || counterofferForm.processing || rejecting.value
const REQUEST_PROPS = ['requests', 'counterofferedRequests', 'rejectedRequests', 'verificationPending']
const { refresh, isRefreshing } = useVisibleInertiaRefresh(REQUEST_PROPS, { shouldRefresh: () => !busyWithDialog() })
useRealtimeRefresh({ requests: REQUEST_PROPS, dashboard: ['requests'] }, { shouldRefresh: () => !busyWithDialog() })

function syncViewport() {
  isDesktop.value = desktopQuery.matches
  if (isDesktop.value) {
    detailOpen.value = false
    if (!selectedRequest.value && listForTab.value.length) selectedRequest.value = listForTab.value[0]
  }
}

onMounted(() => {
  desktopQuery = window.matchMedia('(min-width: 1024px)')
  syncViewport()
  desktopQuery.addEventListener('change', syncViewport)
})
onUnmounted(() => {
  desktopQuery?.removeEventListener('change', syncViewport)
  clearInterval(nowTimer)
})
</script>
