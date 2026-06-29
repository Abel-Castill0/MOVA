<template>
  <AppLayout title="Clases — Admin">
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-900">Clases</h2>
        <div class="flex gap-2">
          <button v-for="s in statuses" :key="s.value"
            @click="setFilter(s.value)"
            :class="['px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors',
              statusFilter === s.value
                ? 'bg-indigo-600 text-white border-indigo-600'
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
                <span :class="['text-xs font-semibold px-2 py-0.5 rounded', badgeClass(l.status)]">
                  {{ statusLabel(l.status) }}
                </span>
                <div v-if="l.cancel_reason" class="text-xs text-red-500 mt-0.5 max-w-[140px] truncate" :title="l.cancel_reason">
                  {{ l.cancel_reason }}
                </div>
              </td>
              <td class="px-4 py-3">
                <button v-if="l.status === 'scheduled'"
                  @click="openCancelModal(l)"
                  class="text-xs text-red-600 hover:text-red-800 hover:bg-red-50 px-2 py-1 rounded transition-colors">
                  Cancelar
                </button>
                <span v-else class="text-xs text-gray-300">—</span>
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

    <!-- Modal cancelación admin -->
    <div v-if="cancelTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Cancelar clase #{{ cancelTarget.id }}</h3>
        <p class="text-sm text-gray-500 mb-4">{{ fmtDate(cancelTarget.start_time) }}</p>
        <textarea v-model="cancelReason" rows="3"
          placeholder="Motivo de cancelación (obligatorio)"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-1" />
        <p v-if="cancelError" class="text-xs text-red-500 mb-2">{{ cancelError }}</p>
        <div class="flex gap-2 mt-3">
          <button @click="cancelTarget = null; cancelReason = ''; cancelError = ''"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">
            Volver
          </button>
          <button @click="submitCancel"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg">
            Confirmar
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

const props = defineProps({
  lessons:      { type: Object, required: true },
  statusFilter: { type: String, default: null },
})

const statuses = [
  { value: null, label: 'Todas' },
  { value: 'scheduled', label: 'Programadas' },
  { value: 'completed', label: 'Completadas' },
  { value: 'cancelled', label: 'Canceladas' },
]

const cancelTarget = ref(null)
const cancelReason = ref('')
const cancelError  = ref('')

function setFilter(status) {
  router.get(route('admin.lessons'), status ? { status } : {}, { preserveScroll: true })
}

function openCancelModal(l) {
  cancelTarget.value = l
  cancelReason.value = ''
  cancelError.value  = ''
}

function submitCancel() {
  if (cancelReason.value.trim().length < 5) {
    cancelError.value = 'El motivo debe tener al menos 5 caracteres.'
    return
  }
  router.post(
    route('admin.lessons.cancel', cancelTarget.value.id),
    { reason: cancelReason.value.trim() },
    { onSuccess: () => { cancelTarget.value = null; cancelReason.value = '' } }
  )
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
  })
}

function statusLabel(s) {
  return { scheduled: 'Programada', completed: 'Completada', cancelled: 'Cancelada' }[s] ?? s
}

function badgeClass(s) {
  return {
    scheduled: 'bg-blue-50 text-blue-700',
    completed: 'bg-green-50 text-green-700',
    cancelled: 'bg-red-50 text-red-700',
  }[s] ?? 'bg-gray-100 text-gray-600'
}
</script>
