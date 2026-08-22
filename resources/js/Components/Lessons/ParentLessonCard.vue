<template>
  <div class="bg-white rounded-2xl border border-gray-100 hover:border-brand-200 transition-all hover:shadow-md overflow-hidden">
    <div :class="['h-1', statusStripe(lesson.status)]"></div>

    <div class="p-5">
      <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-2 mb-2">
            <p class="font-bold text-slate-900">{{ lesson.class_request?.subject?.name ?? 'Clase' }}</p>
            <StatusBadge :status="lesson.status" />
          </div>
          <p class="text-sm text-slate-600">
            <span class="font-medium">Alumno:</span> {{ lesson.student?.first_name }} {{ lesson.student?.last_name }}
          </p>
          <p class="text-sm text-slate-500">
            <span class="font-medium">Profesor:</span> {{ lesson.teacher_profile?.user?.name }}
            <span v-if="lesson.teacher_profile?.referral_code"
              class="ml-1 text-xs font-mono text-slate-400 bg-slate-50 px-1.5 py-0.5 rounded"
              title="Código del profesor">{{ lesson.teacher_profile.referral_code }}</span>
          </p>
          <p class="text-sm text-slate-500 mt-0.5">
            📅 {{ fmtDate(lesson.start_time) }} · {{ lesson.duration_minutes }} min
          </p>
          <div v-if="lesson.status === 'cancelled' && lesson.cancel_reason"
            class="mt-2 text-xs text-red-600 bg-red-50 rounded px-2 py-1">
            Motivo de cancelación: {{ lesson.cancel_reason }}
          </div>
          <div v-if="lesson.rescheduled_at && lesson.original_start_time"
            class="mt-1 text-xs text-amber-700 bg-amber-50 rounded px-2 py-1">
            Reprogramada (original: {{ fmtDateShort(lesson.original_start_time) }})
          </div>
          <!-- Review section -->
          <div v-if="lesson.teacher_review" class="mt-2 flex items-center gap-1 text-xs text-amber-600">
            <span v-for="n in 5" :key="n" :class="n <= lesson.teacher_review.rating ? 'text-amber-400' : 'text-gray-200'">★</span>
            <span class="text-slate-500 ml-1">Reseña enviada</span>
          </div>
        </div>

        <div v-if="canJoinJitsi(lesson)" class="flex-shrink-0 bg-brand-50 border border-brand-100 rounded-xl p-4 min-w-0 sm:min-w-[220px] flex items-center">
          <button @click="$emit('join', lesson)"
            class="flex items-center gap-2 w-full px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow shadow-brand-600/25">
            🎥 Ingresar a la Sala Virtual
          </button>
        </div>

        <div v-else-if="lesson.status !== 'scheduled'" class="flex-shrink-0 px-4 py-3 bg-slate-50 rounded-xl text-center">
          <p class="text-sm text-slate-400">{{ statusLabel(lesson.status) }}</p>
        </div>
      </div>

      <!-- Tarjeta de pago: la clase ya fue programada, falta confirmar el pago offline -->
      <div v-if="lesson.status === 'scheduled'" class="mt-3 bg-emerald-50 border border-emerald-100 rounded-xl p-4">
        <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wide mb-2">Confirmar pago de la clase</p>
        <p class="text-sm text-slate-700 mb-3">
          Monto a pagar: <span class="font-bold text-slate-900">S/ {{ amountToPay(lesson) }}</span>
        </p>
        <div class="flex flex-wrap gap-6 mb-3">
          <div v-if="lesson.teacher_profile?.yape_number">
            <span class="text-xs text-slate-500 block">Yape</span>
            <span class="font-semibold text-slate-800 select-all">{{ lesson.teacher_profile.yape_number }}</span>
          </div>
          <div v-if="lesson.teacher_profile?.plin_number">
            <span class="text-xs text-slate-500 block">Plin</span>
            <span class="font-semibold text-slate-800 select-all">{{ lesson.teacher_profile.plin_number }}</span>
          </div>
          <p v-if="!lesson.teacher_profile?.yape_number && !lesson.teacher_profile?.plin_number" class="text-xs text-slate-400">
            El profesor aún no registró un número de Yape/Plin.
          </p>
        </div>
        <button v-if="hasClassEnded(lesson)" @click="$emit('pay', lesson)" :disabled="payingId === lesson.id"
          class="px-5 py-2.5 bg-emerald-600 text-white text-sm font-bold rounded-xl hover:bg-emerald-700 active:scale-95 transition-all shadow-sm disabled:opacity-50">
          {{ payingId === lesson.id ? 'Confirmando...' : '✓ Ya pagué' }}
        </button>
        <p v-else class="text-xs text-slate-400">Podrás confirmar el pago cuando la clase finalice.</p>
        <p v-if="paymentErrorId === lesson.id" class="text-xs text-red-500 mt-2">{{ paymentError }}</p>
      </div>

      <!-- Banner destacado: el profesor ya subió el reporte, falta que el padre califique para cerrar la clase -->
      <div v-if="lesson.status === 'pending_parent_confirmation'" class="mt-3 bg-yellow-50 border-2 border-yellow-300 rounded-xl p-4">
        <p class="text-sm font-semibold text-yellow-900">
          📋 El profesor ha finalizado la clase y subido el reporte. Para cerrar la clase, por favor califica al profesor.
        </p>
        <Link :href="route('reviews.create', lesson.id)"
          class="inline-block mt-3 px-5 py-2.5 bg-yellow-500 text-white text-sm font-bold rounded-xl hover:bg-yellow-600 active:scale-95 transition-all shadow-sm">
          ★ Calificar y Confirmar
        </Link>
      </div>

      <!-- C-1: esta clase quedó sin confirmar y la está revisando el equipo
           MOVA — etiqueta explícita para el padre (Fase 3B §17), sin detalle
           financiero (el crédito retenido es un concepto del profesor). -->
      <div v-if="lesson.status === 'needs_admin_review'" class="mt-3 bg-orange-50 border border-orange-100 rounded-xl p-3">
        <p class="text-xs font-medium text-orange-700">
          ⏳ Esta clase está en revisión por el equipo MOVA. Te avisaremos apenas se resuelva.
        </p>
      </div>

      <!-- Actions footer for scheduled lessons -->
      <div v-if="lesson.status === 'scheduled'" class="mt-3 pt-3 border-t border-gray-50 flex flex-wrap items-center justify-end gap-2">
        <button @click="$emit('reschedule', lesson)"
          class="text-xs text-amber-600 hover:text-amber-800 hover:bg-amber-50 px-3 py-1.5 rounded-lg transition-colors font-medium">
          Reprogramar
        </button>
        <button @click="$emit('cancel', lesson)"
          class="text-xs text-red-500 hover:text-red-700 hover:bg-red-50 px-3 py-1.5 rounded-lg transition-colors font-medium">
          Cancelar clase
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import StatusBadge from '@/Components/StatusBadge.vue'

defineProps({
  lesson: { type: Object, required: true },
  payingId: { type: [Number, String], default: null },
  paymentErrorId: { type: [Number, String], default: null },
  paymentError: { type: String, default: '' },
})
defineEmits(['join', 'pay', 'reschedule', 'cancel'])

function canJoinJitsi(l) {
  if (!l.has_jitsi_room) return false
  if (l.status === 'paid') return true
  if (l.status !== 'scheduled') return false
  const minutesToStart = (new Date(l.start_time).getTime() - Date.now()) / 60000
  return minutesToStart <= 15
}

function hasClassEnded(l) {
  return Date.now() >= new Date(l.end_time).getTime()
}

function amountToPay(l) {
  if (l.price_frozen_pen !== null && l.price_frozen_pen !== undefined) {
    return parseFloat(l.price_frozen_pen).toFixed(2)
  }
  // Fallback para clases legacy agendadas antes de congelar el precio.
  const rate = parseFloat(l.teacher_profile?.hourly_rate ?? 0)
  return ((rate * l.duration_minutes) / 60).toFixed(2)
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
    needs_admin_review: 'bg-orange-500',
    completed: 'bg-green-500',
    cancelled: 'bg-red-400',
  }[s] ?? 'bg-slate-300'
}

function statusLabel(s) {
  return {
    scheduled: 'Programada',
    paid: 'Pagada',
    pending_parent_confirmation: 'Esperando tu calificación',
    needs_admin_review: 'En revisión por el equipo MOVA',
    completed: 'Completada',
    cancelled: 'Cancelada',
  }[s] ?? s
}
</script>
