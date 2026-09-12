<template>
  <AppLayout title="Operaciones">
    <!-- Esta pantalla pinta SU PROPIO lienzo en vez de heredarlo.
         AppLayout mantiene un `bg-slate-50` fijo porque el resto de páginas
         escriben texto oscuro fijo directamente sobre él (ver el comentario del
         layout); oscurecerlo allí las rompía. Como aquí todo el texto usa
         tokens de tinta, el fondo también tiene que ser un token, así que la
         página se lo pone ella.
         Los márgenes negativos anulan el padding de <main>
         (`px-4 sm:px-6 lg:px-8 py-6`) para que el lienzo llegue a los bordes, y
         se vuelve a aplicar el mismo padding por dentro. En tema claro
         `--canvas` vale 248 250 252, exactamente slate-50: no hay costura. -->
    <div class="-mx-4 -my-6 min-h-[calc(100vh-3.75rem)] bg-canvas px-4 py-6 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
    <div class="mx-auto max-w-5xl space-y-8">

      <!-- ── Encabezado ─────────────────────────────────────────────────── -->
      <header>
        <h1 class="text-2xl font-bold tracking-tight text-ink">Operaciones</h1>
        <p class="mt-1 text-sm text-ink-muted">
          Lo que MOVA ha detectado y necesita una decisión humana.
        </p>
      </header>

      <!-- ── Estado global: una pieza editorial, no una rejilla de KPIs ──── -->
      <section
        :class="[
          'rounded-elevated border px-5 py-4 sm:px-6 sm:py-5',
          summary.total_open === 0 ? 'border-line bg-surface' : 'border-line-strong bg-surface-raised',
        ]"
        aria-labelledby="ops-status"
      >
        <div class="flex items-start gap-3.5">
          <span
            :class="[
              'mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-pill',
              summary.critical_open > 0 ? 'bg-danger-bg text-danger-text'
                : summary.total_open > 0 ? 'bg-warning-bg text-warning-text'
                : 'bg-success-bg text-success-text',
            ]"
            aria-hidden="true"
          >
            <Icon :name="summary.total_open === 0 ? 'verified-badge' : 'under-review'" :size="18" />
          </span>

          <div class="min-w-0">
            <h2 id="ops-status" class="text-lg font-bold text-ink">
              {{ summary.total_open === 0 ? 'Todo en orden' : headline }}
            </h2>
            <p class="mt-0.5 text-sm text-ink-muted">
              <template v-if="summary.total_open === 0">
                No hay incidencias abiertas. Seguirás viendo aquí cualquiera que MOVA detecte.
              </template>
              <template v-else>{{ breakdown }}</template>
            </p>
          </div>
        </div>
      </section>

      <!-- ── Estado de las capacidades ──────────────────────────────────── -->
      <section aria-labelledby="ops-capabilities" class="space-y-3">
        <div>
          <h2 id="ops-capabilities" class="text-sm font-semibold text-ink">Estado de MOVA</h2>
          <p class="mt-0.5 text-xs text-ink-subtle">
            «Sin señal» significa que MOVA no vigila esa capacidad, no que funcione.
          </p>
        </div>

        <ul class="divide-y divide-line overflow-hidden rounded-card border border-line bg-surface">
          <li v-for="c in capabilities" :key="c.key" class="flex items-start gap-3 px-4 py-3">
            <span
              :class="['mt-1.5 h-2 w-2 shrink-0 rounded-pill', statusDot[c.status] ?? statusDot.unknown]"
              aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                <span class="text-sm font-medium text-ink">{{ c.label }}</span>
                <span :class="['text-xs font-semibold', statusText[c.status] ?? statusText.unknown]">
                  {{ statusLabel[c.status] ?? statusLabel.unknown }}
                </span>
              </div>
              <p class="mt-0.5 text-xs text-ink-muted">{{ c.detail }}</p>
            </div>
          </li>
        </ul>
      </section>

      <!-- ── Incidencias ────────────────────────────────────────────────── -->
      <section aria-labelledby="ops-list" class="space-y-4">
        <h2 id="ops-list" class="text-sm font-semibold text-ink">
          {{ filters.status === 'resolved' ? 'Incidencias cerradas' : 'Incidencias' }}
        </h2>

        <!-- Filtros: pills sobrias, envuelven en móvil sin toolbar -->
        <div class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4">
          <fieldset class="flex flex-wrap items-center gap-1.5">
            <legend class="sr-only">Estado</legend>
            <button
              v-for="o in statusOptions"
              :key="o.value"
              type="button"
              :aria-pressed="filters.status === o.value"
              :class="pillClass(filters.status === o.value)"
              @click="setFilter('status', o.value)"
            >{{ o.label }}</button>
          </fieldset>

          <span class="hidden h-4 w-px bg-line sm:block" aria-hidden="true" />

          <fieldset class="flex flex-wrap items-center gap-1.5">
            <legend class="sr-only">Severidad</legend>
            <button
              v-for="o in severityOptions"
              :key="String(o.value)"
              type="button"
              :aria-pressed="filters.severity === o.value"
              :class="pillClass(filters.severity === o.value)"
              @click="setFilter('severity', o.value)"
            >{{ o.label }}</button>
          </fieldset>

          <span class="hidden h-4 w-px bg-line sm:block" aria-hidden="true" />

          <fieldset class="flex flex-wrap items-center gap-1.5">
            <legend class="sr-only">Categoría</legend>
            <button
              v-for="o in categoryOptions"
              :key="String(o.value)"
              type="button"
              :aria-pressed="filters.category === o.value"
              :class="pillClass(filters.category === o.value)"
              @click="setFilter('category', o.value)"
            >{{ o.label }}</button>
          </fieldset>
        </div>

        <!-- Vacíos: distinguen "no hay nada" de "el filtro no encuentra nada" -->
        <EmptyState
          v-if="alerts.data.length === 0"
          :title="isFiltered ? 'Ningún resultado con estos filtros' : emptyTitle"
          :description="isFiltered ? 'Prueba a quitar alguno para ampliar la búsqueda.' : emptyDescription"
        />

        <ol v-else class="space-y-3">
          <li v-for="a in alerts.data" :key="a.id">
            <article
              :class="[
                'rounded-card border bg-surface px-4 py-4 sm:px-5',
                a.resolved_at ? 'border-line opacity-[0.85]'
                  : a.severity === 'critical' ? 'border-l-[3px] border-line border-l-danger-border'
                  : 'border-l-[3px] border-line border-l-warning-border',
              ]"
            >
              <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                <div class="min-w-0 flex-1 basis-64">
                  <div class="flex flex-wrap items-center gap-1.5">
                    <span
                      v-if="!a.resolved_at"
                      :class="[
                        'rounded-chip px-1.5 py-0.5 text-[11px] font-semibold',
                        a.severity === 'critical' ? 'bg-danger-bg text-danger-text' : 'bg-warning-bg text-warning-text',
                      ]"
                    >{{ a.severity === 'critical' ? 'Crítica' : 'Aviso' }}</span>
                    <span
                      v-else
                      class="rounded-chip bg-canvas px-1.5 py-0.5 text-[11px] font-semibold text-ink-muted"
                    >Cerrada</span>

                    <span class="text-[11px] text-ink-subtle">{{ a.type_label }}</span>
                    <span v-if="a.occurrences > 1" class="text-[11px] text-ink-subtle">
                      · detectada {{ a.occurrences }} veces
                    </span>
                  </div>

                  <h3 class="mt-1.5 font-semibold text-ink">{{ a.title }}</h3>
                  <p class="mt-1 text-sm leading-relaxed text-ink-muted">{{ a.message }}</p>
                </div>

                <div class="flex shrink-0 flex-wrap gap-2">
                  <a v-if="a.action" :href="a.action.url" :class="linkButtonClass">
                    {{ a.action.label }}
                  </a>
                  <BaseButton
                    v-if="!a.resolved_at && a.can_close"
                    variant="secondary"
                    size="sm"
                    @click="openClose(a)"
                  >Cerrar incidencia</BaseButton>
                </div>
              </div>

              <!-- Contexto: información secundaria, solo lo que llega -->
              <dl
                v-if="hasContext(a)"
                class="mt-3.5 flex flex-wrap gap-x-5 gap-y-1 border-t border-line pt-3 text-xs"
              >
                <div v-for="(v, k) in a.context" :key="k" class="flex gap-1.5">
                  <dt class="text-ink-subtle">{{ k }}</dt>
                  <dd class="font-medium text-ink-muted">{{ v }}</dd>
                </div>
              </dl>

              <p class="mt-3 text-xs text-ink-subtle">
                {{ a.occurrences > 1 ? 'Vista por última vez' : 'Detectada' }} {{ relative(a.last_detected_at) }}
              </p>

              <!-- Metadata del cierre ACTUAL. No es un historial: al reabrirse
                   se limpia, así que solo se afirma lo que hay persistido. -->
              <div v-if="a.resolved_at" class="mt-2.5 rounded-control bg-canvas px-3 py-2 text-xs">
                <p class="text-ink-muted">
                  Cerrada {{ relative(a.resolved_at) }}<template v-if="a.resolved_by"> por {{ a.resolved_by }}</template>
                </p>
                <p v-if="a.resolution_note" class="mt-0.5 break-words text-ink-subtle">
                  {{ a.resolution_note }}
                </p>
              </div>
            </article>
          </li>
        </ol>

        <!-- Paginación -->
        <nav v-if="alerts.links.length > 3" class="flex flex-wrap items-center gap-1.5" aria-label="Paginación">
          <component
            :is="l.url ? 'a' : 'span'"
            v-for="l in alerts.links"
            :key="l.label"
            :href="l.url || undefined"
            :aria-current="l.active ? 'page' : undefined"
            :class="[
              'inline-flex min-h-[40px] min-w-[40px] items-center justify-center rounded-control px-3 text-sm',
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2',
              l.active ? 'bg-brand-600 font-semibold text-white'
                : l.url ? 'border border-line text-ink-muted hover:bg-canvas'
                : 'text-ink-subtle',
            ]"
          >{{ pageLabel(l.label) }}</component>
        </nav>
      </section>
    </div>
    </div>

    <!-- ── Modal de cierre ──────────────────────────────────────────────── -->
    <Modal :show="closing !== null" max-width="lg" @close="cancelClose">
      <form class="p-6" @submit.prevent="submitClose">
        <h2 class="text-lg font-bold text-ink">Cerrar incidencia</h2>
        <p class="mt-1.5 text-sm leading-relaxed text-ink-muted">
          Esto cierra la incidencia operativa. <strong class="font-semibold text-ink">No modifica el pago,
          la clase, la recarga ni el ledger.</strong> Si el problema sigue ahí y MOVA vuelve a detectarlo,
          la incidencia se reabrirá.
        </p>

        <p v-if="closing" class="mt-3 rounded-control bg-canvas px-3 py-2 text-sm font-medium text-ink">
          {{ closing.title }}
        </p>

        <div class="mt-4">
          <InputLabel for="close-reason" value="Motivo (obligatorio)" />
          <textarea
            id="close-reason"
            ref="reasonInput"
            v-model="closeForm.reason"
            rows="3"
            maxlength="500"
            required
            :aria-invalid="closeForm.errors.reason ? 'true' : undefined"
            aria-describedby="close-reason-count"
            class="mt-1 block w-full rounded-control border-line bg-surface text-sm text-ink shadow-sm focus:border-brand-500 focus:ring-focus-ring"
          />
          <div class="mt-1 flex items-start justify-between gap-3">
            <InputError :message="closeForm.errors.reason" class="mt-0" />
            <span id="close-reason-count" class="shrink-0 text-xs tabular-nums text-ink-subtle">
              {{ closeForm.reason.length }}/500
            </span>
          </div>
        </div>

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="secondary" :disabled="closeForm.processing" @click="cancelClose">
            Cancelar
          </BaseButton>
          <BaseButton type="submit" variant="primary" :loading="closeForm.processing">
            Cerrar incidencia
          </BaseButton>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import BaseButton from '@/Components/BaseButton.vue'
import EmptyState from '@/Components/EmptyState.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import Icon from '@/Components/Icon.vue'

const props = defineProps({
  summary: { type: Object, required: true },
  alerts: { type: Object, required: true },
  capabilities: { type: Array, required: true },
  filters: { type: Object, required: true },
})

// ── Estado global ──────────────────────────────────────────────────────────
const headline = computed(() => {
  const n = props.summary.total_open
  return n === 1 ? '1 asunto necesita atención' : `${n} asuntos necesitan atención`
})

const breakdown = computed(() => {
  const parts = []
  if (props.summary.critical_open) {
    parts.push(props.summary.critical_open === 1 ? '1 crítica' : `${props.summary.critical_open} críticas`)
  }
  if (props.summary.warning_open) {
    parts.push(props.summary.warning_open === 1 ? '1 aviso' : `${props.summary.warning_open} avisos`)
  }
  return parts.join(' · ')
})

// ── Capacidades. `unknown` es neutral y NUNCA se pinta como sano. ──────────
const statusDot = {
  healthy: 'bg-success-text',
  attention: 'bg-danger-text',
  unknown: 'bg-line-strong',
}
const statusText = {
  healthy: 'text-success-text',
  attention: 'text-danger-text',
  unknown: 'text-ink-subtle',
}
const statusLabel = {
  healthy: 'Operativo',
  attention: 'Requiere atención',
  unknown: 'Sin señal suficiente',
}

// ── Filtros ────────────────────────────────────────────────────────────────
const statusOptions = [
  { value: 'open', label: 'Abiertas' },
  { value: 'resolved', label: 'Cerradas' },
  { value: 'all', label: 'Todas' },
]
const severityOptions = [
  { value: null, label: 'Toda severidad' },
  { value: 'critical', label: 'Críticas' },
  { value: 'warning', label: 'Avisos' },
]
const categoryOptions = [
  { value: null, label: 'Toda categoría' },
  { value: 'finance', label: 'Finanzas' },
  { value: 'lesson', label: 'Clases' },
  { value: 'system', label: 'Sistema' },
]

const pillClass = (active) => [
  'inline-flex min-h-[36px] items-center rounded-pill px-3 text-xs font-medium',
  'transition-colors duration-micro',
  'focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2',
  active
    ? 'bg-ink text-canvas'
    : 'border border-line text-ink-muted hover:bg-canvas',
]

// `router.get` deja los filtros en la query string, así que el botón atrás del
// navegador restaura el estado anterior sin lógica propia.
const setFilter = (key, value) => {
  router.get('/admin/operations', { ...props.filters, [key]: value }, {
    preserveState: true,
    preserveScroll: true,
  })
}

const isFiltered = computed(() =>
  props.filters.severity !== null ||
  props.filters.category !== null ||
  props.filters.status !== 'open')

const emptyTitle = computed(() =>
  props.filters.status === 'resolved' ? 'Todavía no hay incidencias cerradas' : 'No hay incidencias abiertas')

const emptyDescription = computed(() =>
  props.filters.status === 'resolved'
    ? 'Cuando cierres una, aparecerá aquí con su motivo.'
    : 'Cuando MOVA detecte algo que necesite tu decisión, lo verás en esta lista.')

const hasContext = (a) => a.context && Object.keys(a.context).length > 0

// ── Cierre ─────────────────────────────────────────────────────────────────
const closing = ref(null)
const closeForm = useForm({ reason: '' })
const reasonInput = ref(null)

const openClose = async (alert) => {
  closeForm.reset()
  closeForm.clearErrors()
  closing.value = alert
  await nextTick()
  reasonInput.value?.focus()
}

// No se cierra mientras la petición está en vuelo: cancelar a medias dejaría al
// admin sin saber si el cierre llegó a aplicarse.
const cancelClose = () => {
  if (closeForm.processing) return
  closing.value = null
}

// Solo se cierra tras la confirmación del backend (onSuccess). `processing`
// bloquea el doble envío y BaseButton fuerza disabled mientras carga.
const submitClose = () => {
  if (closeForm.processing || !closing.value) return

  closeForm.post(`/admin/operations/${closing.value.id}/close`, {
    preserveScroll: true,
    onSuccess: () => { closing.value = null },
  })
}

// ── Formato ────────────────────────────────────────────────────────────────
// Tiempo relativo calculado en el render, sin timers: esta pantalla se abre, se
// lee y se cierra; un reloj por tarjeta no aporta nada y cuesta.
const relative = (iso) => {
  if (!iso) return '—'
  const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (min < 1) return 'hace unos segundos'
  if (min < 60) return `hace ${min} min`
  const h = Math.round(min / 60)
  if (h < 24) return `hace ${h} h`
  const d = Math.round(h / 24)
  if (d < 30) return `hace ${d} ${d === 1 ? 'día' : 'días'}`
  return new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' })
}

// Las etiquetas del paginador de Laravel traen entidades HTML (&laquo;). Se
// decodifican con un mapa explícito en vez de v-html: la plantilla no debe
// tener ni un solo punto donde inyectar HTML, aunque hoy el dato venga del
// framework y no del usuario.
const pageLabel = (label) => String(label)
  .replace(/&laquo;/g, '«')
  .replace(/&raquo;/g, '»')
  .replace(/&amp;/g, '&')
  .replace(/<[^>]*>/g, '')
  .trim()

const linkButtonClass = [
  'inline-flex min-h-[36px] items-center justify-center rounded-card border border-line-strong',
  'bg-surface px-3.5 text-xs font-semibold text-ink',
  'transition-colors duration-micro hover:bg-canvas',
  'focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2',
]
</script>
