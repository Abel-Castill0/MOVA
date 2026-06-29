<template>
  <AppLayout title="Solicitudes — Admin">
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-900">Solicitudes de clase</h2>
        <div class="flex flex-wrap gap-2">
          <button v-for="s in statuses" :key="s.value ?? 'all'"
            @click="setFilter(s.value)"
            :class="['px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors',
              statusFilter === s.value
                ? 'bg-brand-600 text-white border-brand-600'
                : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50']">
            {{ s.label }}
          </button>
        </div>
      </div>

      <div v-if="!requests.data.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <p class="text-gray-400 text-sm">No hay solicitudes con ese filtro</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm bg-white rounded-xl border border-gray-200 overflow-hidden">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-3 text-left">ID</th>
              <th class="px-4 py-3 text-left">Materia</th>
              <th class="px-4 py-3 text-left">Alumno / Padre</th>
              <th class="px-4 py-3 text-left">Profesor</th>
              <th class="px-4 py-3 text-left">Estado</th>
              <th class="px-4 py-3 text-left">Creada</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in requests.data" :key="r.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 text-gray-400">#{{ r.id }}</td>
              <td class="px-4 py-3 font-medium text-gray-900">{{ r.subject?.name ?? '—' }}</td>
              <td class="px-4 py-3">
                <p class="text-gray-700">{{ r.student?.first_name }} {{ r.student?.last_name }}</p>
                <p class="text-xs text-gray-400">{{ r.student?.parent?.name ?? '—' }}</p>
              </td>
              <td class="px-4 py-3 text-gray-600 text-xs">
                {{ r.class_offer?.teacher_profile?.user?.name ?? 'Sin asignar' }}
              </td>
              <td class="px-4 py-3">
                <span :class="['text-xs font-semibold px-2 py-0.5 rounded', badgeClass(r.status)]">
                  {{ statusLabel(r.status) }}
                </span>
                <div v-if="r.status === 'teacher_rejected' && r.teacher_rejection_reason"
                  class="text-xs text-red-500 mt-0.5 max-w-[160px] truncate" :title="r.teacher_rejection_reason">
                  {{ r.teacher_rejection_reason }}
                </div>
              </td>
              <td class="px-4 py-3 text-gray-500 whitespace-nowrap text-xs">{{ fmtDate(r.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="requests.last_page > 1" class="flex items-center justify-between text-sm text-gray-500">
        <span>{{ requests.from }}–{{ requests.to }} de {{ requests.total }}</span>
        <div class="flex gap-1">
          <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50">←</Link>
          <Link v-if="requests.next_page_url" :href="requests.next_page_url" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50">→</Link>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
  requests:     { type: Object, required: true },
  statusFilter: { type: String, default: null },
})

const statuses = [
  { value: null, label: 'Todas' },
  { value: 'open', label: 'Abiertas' },
  { value: 'accepted', label: 'Aceptadas' },
  { value: 'teacher_rejected', label: 'Rechazadas' },
  { value: 'pending_parent_approval', label: 'Pend. aprobación' },
]

function setFilter(status) {
  router.get(route('admin.requests'), status ? { status } : {}, { preserveScroll: true })
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' })
}

function statusLabel(s) {
  return {
    open: 'Abierta',
    accepted: 'Aceptada',
    rejected: 'Rechazada (padre)',
    teacher_rejected: 'Rechazada (prof.)',
    pending_parent_approval: 'Pend. aprobación',
  }[s] ?? s
}

function badgeClass(s) {
  return {
    open: 'bg-blue-50 text-blue-700',
    accepted: 'bg-green-50 text-green-700',
    rejected: 'bg-red-50 text-red-700',
    teacher_rejected: 'bg-orange-50 text-orange-700',
    pending_parent_approval: 'bg-yellow-50 text-yellow-700',
  }[s] ?? 'bg-gray-100 text-gray-600'
}
</script>
