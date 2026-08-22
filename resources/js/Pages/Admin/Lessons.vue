<template>
  <AppLayout title="Clases — Admin">
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-900">Clases</h2>
        <div class="flex flex-wrap gap-2">
          <button v-for="s in statuses" :key="s.value"
            @click="setFilter(s.value)"
            :class="['px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors',
              statusFilter === s.value
                ? 'bg-brand-600 text-white border-brand-600'
                : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50']">
            {{ s.label }}
          </button>
        </div>
      </div>

      <div v-if="!lessons.data.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <p class="text-gray-400 text-sm">No hay clases con ese filtro</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm bg-white rounded-xl border border-gray-200 overflow-hidden">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-3 text-left">ID</th>
              <th class="px-4 py-3 text-left">Materia</th>
              <th class="px-4 py-3 text-left">Alumno</th>
              <th class="px-4 py-3 text-left">Padre</th>
              <th class="px-4 py-3 text-left">Profesor</th>
              <th class="px-4 py-3 text-left">Fecha</th>
              <th class="px-4 py-3 text-left">Estado</th>
              <th class="px-4 py-3 text-left">Acción</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="l in lessons.data" :key="l.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 text-gray-400">#{{ l.id }}</td>
              <td class="px-4 py-3 font-medium text-gray-900">{{ l.class_request?.subject?.name ?? '—' }}</td>
              <td class="px-4 py-3 text-gray-600">{{ l.student?.first_name }} {{ l.student?.last_name }}</td>
              <td class="px-4 py-3 text-gray-500 text-xs">{{ l.student?.parent?.name ?? '—' }}</td>
              <td class="px-4 py-3 text-gray-600">{{ l.teacher_profile?.user?.name ?? '—' }}</td>
              <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ fmtDate(l.start_time) }}</td>
              <td class="px-4 py-3">
                <StatusBadge :status="l.status" />
                <div v-if="l.cancel_reason" class="text-xs text-red-500 mt-0.5 max-w-[140px] truncate" :title="l.cancel_reason">
                  {{ l.cancel_reason }}
                </div>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-1">
                  <button v-if="l.status === 'scheduled'"
                    @click="openActionModal('cancel', l)"
                    class="text-xs text-red-600 hover:text-red-800 hover:bg-red-50 px-2 py-2 rounded transition-colors">
                    Cancelar
                  </button>
                  <template v-if="canForceSettle(l.status)">
                    <button @click="openActionModal('force-complete', l)"
                      class="text-xs text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50 px-2 py-2 rounded transition-colors">
                      Forzar cierre
                    </button>
                    <button @click="openActionModal('force-refund', l)"
                      class="text-xs text-red-600 hover:text-red-800 hover:bg-red-50 px-2 py-2 rounded transition-colors">
                      Forzar devolución
                    </button>
                  </template>
                  <span v-if="l.status !== 'scheduled' && !canForceSettle(l.status)" class="text-xs text-gray-300">—</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="lessons.last_page > 1" class="flex items-center justify-between text-sm text-gray-500">
        <span>{{ lessons.from }}–{{ lessons.to }} de {{ lessons.total }}</span>
        <div class="flex gap-1">
          <Link v-if="lessons.prev_page_url" :href="lessons.prev_page_url" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50">←</Link>
          <Link v-if="lessons.next_page_url" :href="lessons.next_page_url" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50">→</Link>
        </div>
      </div>
    </div>

    <!-- Modal de acción admin: cancelar / forzar cierre / forzar devolución.
         Las tres comparten forma (razón obligatoria + confirmar/volver) — un
         solo modal parametrizado por ACTION_CONFIG en vez de triplicar el
         markup, igual que LessonSettlementService evita triplicar la lógica
         de liquidación en el backend. -->
    <div v-if="actionModal" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
      @keydown.esc="closeActionModal">
      <div class="bg-white rounded-2xl border border-gray-200 p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">{{ activeActionConfig.title }} #{{ actionModal.lesson.id }}</h3>
        <p class="text-sm text-gray-500 mb-4">{{ fmtDate(actionModal.lesson.start_time) }}</p>
        <p v-if="activeActionConfig.warning" class="text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 mb-3">
          {{ activeActionConfig.warning }}
        </p>
        <label :for="'action-reason'" class="sr-only">Motivo</label>
        <textarea id="action-reason" v-model="actionReason" rows="3"
          :placeholder="activeActionConfig.placeholder"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 mb-1" />
        <p v-if="actionError" class="text-xs text-red-500 mb-2" role="alert">{{ actionError }}</p>
        <div class="flex gap-2 mt-3">
          <button @click="closeActionModal"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">
            Volver
          </button>
          <button @click="submitAction" :disabled="submitting"
            :class="['flex-1 px-4 py-2 text-sm font-semibold text-white rounded-lg transition-colors disabled:opacity-50', activeActionConfig.confirmClass]">
            {{ submitting ? 'Enviando…' : activeActionConfig.confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'

const props = defineProps({
  lessons:      { type: Object, required: true },
  statusFilter: { type: String, default: null },
})

const statuses = [
  { value: null, label: 'Todas' },
  { value: 'scheduled', label: 'Programadas' },
  { value: 'needs_admin_review', label: 'En revisión' },
  { value: 'completed', label: 'Completadas' },
  { value: 'cancelled', label: 'Canceladas' },
]

// C-1: paid/pending_parent_confirmation/needs_admin_review son los estados
// donde el crédito puede quedar varado más allá de 'scheduled' — ver
// LessonSettlementService::CONSUMABLE_STATES / REFUNDABLE_STATES. 'scheduled'
// se excluye a propósito: ya tiene su propio botón "Cancelar" (cancelLesson,
// que además libera el cupo de mentoría); mostrar también "forzar" ahí
// daría dos caminos distintos para lo mismo.
function canForceSettle(status) {
  return ['paid', 'pending_parent_confirmation', 'needs_admin_review'].includes(status)
}

const ACTION_CONFIG = {
  cancel: {
    title: 'Cancelar clase',
    routeName: 'admin.lessons.cancel',
    confirmLabel: 'Confirmar',
    confirmClass: 'bg-red-600 hover:bg-red-700',
    placeholder: 'Motivo de cancelación (obligatorio)',
  },
  'force-complete': {
    title: 'Forzar cierre de clase',
    routeName: 'admin.lessons.force-complete',
    confirmLabel: 'Forzar cierre',
    confirmClass: 'bg-emerald-600 hover:bg-emerald-700',
    placeholder: 'Motivo del cierre forzado (obligatorio)',
    warning: 'Esto consumirá el crédito reservado del profesor como si la clase se hubiera dictado.',
  },
  'force-refund': {
    title: 'Forzar devolución de clase',
    routeName: 'admin.lessons.force-refund',
    confirmLabel: 'Forzar devolución',
    confirmClass: 'bg-red-600 hover:bg-red-700',
    placeholder: 'Motivo de la devolución forzada (obligatorio)',
    warning: 'Esto devolverá el crédito reservado al profesor sin cobrar la clase.',
  },
}

const actionModal   = ref(null) // { type: 'cancel'|'force-complete'|'force-refund', lesson }
const actionReason  = ref('')
const actionError   = ref('')
const submitting    = ref(false)

const activeActionConfig = computed(() => actionModal.value ? ACTION_CONFIG[actionModal.value.type] : {})

function setFilter(status) {
  router.get(route('admin.lessons'), status ? { status } : {}, { preserveScroll: true })
}

function openActionModal(type, lesson) {
  actionModal.value  = { type, lesson }
  actionReason.value = ''
  actionError.value  = ''
}

function closeActionModal() {
  actionModal.value  = null
  actionReason.value = ''
  actionError.value  = ''
}

function submitAction() {
  if (actionReason.value.trim().length < 5) {
    actionError.value = 'El motivo debe tener al menos 5 caracteres.'
    return
  }
  submitting.value = true
  router.post(
    route(activeActionConfig.value.routeName, actionModal.value.lesson.id),
    { reason: actionReason.value.trim() },
    {
      onSuccess: closeActionModal,
      onError: (errors) => { actionError.value = errors.reason ?? 'No se pudo completar la acción.' },
      onFinish: () => { submitting.value = false },
    }
  )
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
  })
}

</script>
