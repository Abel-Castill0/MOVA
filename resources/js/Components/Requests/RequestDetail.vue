<script setup>
/**
 * Detalle de una solicitud para el profesor. Solo datos autorizados antes de aceptar: materia, grado, nombre de pila,
 * objetivo, mensaje, horarios preferidos y fechas. Las acciones dependen de la pestaña:
 *   open → Aceptar solicitud (principal), Proponer otro horario (secundaria), Rechazar (diferenciada)
 *   counteroffered → solo informa que se espera al padre
 *   rejected → muestra el motivo
 */
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import StatusBadge from '@/Components/StatusBadge.vue'
import { timeSlotLabel } from '@/utils/timeSlots'
import { fmtLimaDate, fmtLimaDateShort } from '@/utils/limaTime'
import { requestGoal, requestMessage, studentFirstName } from '@/utils/requestText'
import { dayLabel, slotPhrase, timeLeftLabel } from '@/utils/requestSlots'

const props = defineProps({
  request: { type: Object, required: true },
  tab: { type: String, default: 'open' },
})
defineEmits(['counteroffer', 'reject'])

const goal = computed(() => requestGoal(props.request))
// El mensaje solo se muestra si añade algo al objetivo (no la misma frase dos veces).
const message = computed(() => {
  const text = requestMessage(props.request)
  const same = (v) => String(v ?? '').trim().replace(/\.$/, '').toLowerCase()

  return text && same(text) !== same(goal.value) ? text : null
})
const times = computed(() => (props.request.preferred_times ?? []).map(timeSlotLabel))
// Solicitudes nuevas: día + franja. Las anteriores solo traen `preferred_times` y se muestran como siempre.
const hasDay = computed(() => Boolean(props.request.preferred_date && props.request.preferred_slot))
const dayText = computed(() => {
  const label = dayLabel(props.request.preferred_date)

  return label ? label.charAt(0).toUpperCase() + label.slice(1) : ''
})
const slotText = computed(() => slotPhrase(props.request.preferred_slot).replace('por la ', '').replace(/^./, (c) => c.toUpperCase()))
</script>

<template>
  <article class="space-y-5" :aria-label="`Solicitud de ${request.subject?.name ?? 'clase'}`">
    <header>
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-lg font-bold text-ink">{{ request.subject?.name ?? 'Clase' }}</h2>
        <StatusBadge :status="request.status" />
      </div>
      <p class="mt-0.5 text-sm text-ink-muted">Solicitud creada el {{ fmtLimaDateShort(request.created_at) }}</p>
    </header>

    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
      <div>
        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Alumno</dt>
        <dd class="mt-0.5 font-medium text-ink">{{ studentFirstName(request) }}</dd>
      </div>
      <div>
        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Grado</dt>
        <dd class="mt-0.5 font-medium text-ink">{{ request.student?.grade_level || 'No indicado' }}</dd>
      </div>
      <div class="col-span-2">
        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Modalidad</dt>
        <dd class="mt-0.5 font-medium text-ink">{{ request.is_mentorship ? 'Acompañamiento continuo' : 'Clase puntual' }}</dd>
      </div>
    </dl>

    <section>
      <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Objetivo académico</h3>
      <p class="mt-1 text-sm text-ink">{{ goal }}</p>
      <p v-if="message" class="mt-2 whitespace-pre-line break-words rounded-xl bg-slate-50 px-3 py-2 text-sm text-ink-muted">{{ message }}</p>
    </section>

    <!-- Día + franja: una PREFERENCIA de la familia. La hora exacta la propone el profesor. -->
    <section v-if="hasDay" data-testid="request-preference" class="rounded-xl border border-brand-200 bg-brand-50/70 px-3.5 py-3">
      <dl class="space-y-1.5 text-sm">
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Fecha solicitada</dt><dd class="mt-0.5 font-bold text-ink" data-testid="preference-day">{{ dayText }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Preferencia</dt><dd class="mt-0.5 font-bold text-ink" data-testid="preference-slot">{{ slotText }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Hora exacta</dt><dd class="mt-0.5 font-medium text-ink-muted" data-testid="preference-hour">Pendiente de confirmar (hora de Perú)</dd></div>
      </dl>
    </section>

    <!-- Hasta cuándo se puede proponer: su oportunidad real (fin de la franja, o el plazo de un acompañamiento). -->
    <section v-if="tab === 'open' && request.respond_by" class="text-sm" data-testid="request-respond-by">
      <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Recibe propuestas hasta</h3>
      <p class="mt-0.5 font-medium text-ink">{{ fmtLimaDate(request.respond_by) }} (hora de Perú) · {{ timeLeftLabel(request.respond_by) }}</p>
    </section>

    <section v-if="times.length">
      <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Horarios que prefiere la familia</h3>
      <ul class="mt-1.5 flex flex-wrap gap-1.5">
        <li v-for="t in times" :key="t" class="rounded-full border border-line bg-white px-2.5 py-1 text-xs font-medium text-ink">{{ t }}</li>
      </ul>
      <p class="mt-1 text-xs text-ink-muted">Orientativo: el horario exacto se coordina al aceptar (hora de Perú).</p>
    </section>

    <section v-if="tab === 'counteroffered'" class="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-sm text-amber-900" data-testid="counteroffer-waiting">
      <p class="font-semibold">Esperando respuesta del padre</p>
      <p class="mt-0.5">Propusiste {{ fmtLimaDate(request.counteroffer_time) }} (hora de Perú), {{ request.counteroffer_duration_minutes }} min.</p>
    </section>

    <section v-if="tab === 'rejected' && request.teacher_rejection_reason" class="rounded-xl border border-line bg-slate-50 px-3.5 py-3 text-sm text-ink-muted">
      <p class="font-semibold text-ink">Motivo que registraste</p>
      <p class="mt-0.5 break-words">{{ request.teacher_rejection_reason }}</p>
    </section>

    <footer v-if="tab === 'open'" class="space-y-2.5 border-t border-line pt-4">
      <!-- Con día + franja no se agenda al instante: el profesor propone la hora y la familia la confirma. -->
      <Link
        v-if="!hasDay"
        :href="route('teacher.requests.accept', request.id)"
        class="flex min-h-11 w-full items-center justify-center rounded-xl bg-brand-600 px-4 text-sm font-bold text-white transition-colors hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2"
        data-testid="accept-request"
      >
        Aceptar solicitud
      </Link>
      <button
        type="button"
        :class="hasDay
          ? 'flex min-h-11 w-full items-center justify-center rounded-xl bg-brand-600 px-4 text-sm font-bold text-white transition-colors hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2'
          : 'flex min-h-11 w-full items-center justify-center rounded-xl border border-brand-200 bg-white px-4 text-sm font-semibold text-brand-700 transition-colors hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400'"
        data-testid="propose-time"
        @click="$emit('counteroffer', request)"
      >
        {{ hasDay ? 'Proponer hora' : 'Proponer otro horario' }}
      </button>
      <button
        type="button"
        class="flex min-h-11 w-full items-center justify-center rounded-xl px-4 text-sm font-semibold text-rose-700 transition-colors hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-400"
        data-testid="reject-request"
        @click="$emit('reject', request)"
      >
        Rechazar solicitud
      </button>
    </footer>
  </article>
</template>
