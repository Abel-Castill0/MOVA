<template>
  <AppLayout title="Recargas">
    <div class="space-y-6">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 class="text-2xl font-bold text-gray-900">Solicitudes de recarga</h2>
          <p class="text-sm text-gray-500">Revisa los pagos reportados por profesores y abona créditos manualmente.</p>
        </div>
        <span class="text-sm text-slate-400">{{ recharges.total }} solicitudes</span>
      </div>

      <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Fecha</th>
                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Profesor</th>
                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Paquete</th>
                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Operacion</th>
                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Estado</th>
                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="!recharges.data.length">
                <td colspan="6" class="px-5 py-12 text-center text-sm text-gray-500">
                  No hay solicitudes de recarga registradas.
                </td>
              </tr>

              <tr v-for="recharge in recharges.data" :key="recharge.id" class="hover:bg-gray-50">
                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-gray-500">
                  {{ fmtDate(recharge.created_at) }}
                </td>
                <td class="px-5 py-3.5">
                  <p class="text-sm font-semibold text-gray-900">{{ recharge.teacher_profile?.user?.name ?? 'Profesor no disponible' }}</p>
                  <p class="text-xs text-gray-500">{{ recharge.teacher_profile?.user?.email ?? '' }}</p>
                </td>
                <td class="px-5 py-3.5">
                  <p class="text-sm font-semibold text-gray-900">{{ recharge.package_name }} · {{ recharge.credits }} créditos</p>
                  <p class="text-xs text-gray-500">S/ {{ money(recharge.amount_pen) }}</p>
                </td>
                <td class="px-5 py-3.5">
                  <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                    {{ recharge.operation_number }}
                  </span>
                </td>
                <td class="px-5 py-3.5">
                  <span :class="statusBadge(recharge.status)">{{ statusLabel(recharge.status) }}</span>
                </td>
                <td class="px-5 py-3.5">
                  <div v-if="recharge.status === 'pending'" class="flex justify-end gap-2">
                    <button
                      type="button"
                      class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-green-700 disabled:opacity-50"
                      :disabled="isProcessing(recharge.id)"
                      @click="approve(recharge)"
                    >
                      Aprobar
                    </button>
                    <button
                      type="button"
                      class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 transition-colors hover:bg-red-100 disabled:opacity-50"
                      :disabled="isProcessing(recharge.id)"
                      @click="reject(recharge)"
                    >
                      Rechazar
                    </button>
                  </div>
                  <span v-else class="block text-right text-xs text-gray-400">Revisada</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="recharges.last_page > 1" class="flex flex-wrap gap-1">
        <component
          v-for="link in recharges.links"
          :key="link.label"
          :is="link.url ? Link : 'span'"
          :href="link.url"
          v-html="link.label"
          :class="[
            'rounded-lg px-3 py-1.5 text-sm transition-colors',
            link.active ? 'bg-brand-600 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:border-brand-300',
            !link.url && 'pointer-events-none opacity-40',
          ]"
        />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
  recharges: Object,
})

const processing = ref({ id: null, action: null })

function isProcessing(id) {
  return processing.value.id === id
}

function approve(recharge) {
  if (!confirm(`Aprobar recarga de ${recharge.credits} créditos para ${recharge.teacher_profile?.user?.name ?? 'este profesor'}?`)) {
    return
  }

  processing.value = { id: recharge.id, action: 'approve' }
  router.post(route('admin.recharges.approve', recharge.id), {}, {
    preserveScroll: true,
    onFinish: () => { processing.value = { id: null, action: null } },
  })
}

function reject(recharge) {
  if (!confirm('Rechazar esta solicitud de recarga?')) {
    return
  }

  processing.value = { id: recharge.id, action: 'reject' }
  router.post(route('admin.recharges.reject', recharge.id), {}, {
    preserveScroll: true,
    onFinish: () => { processing.value = { id: null, action: null } },
  })
}

function fmtDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleDateString('es-PE', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function money(value) {
  return Number(value ?? 0).toFixed(2)
}

function statusLabel(status) {
  return {
    pending: 'Pendiente',
    approved: 'Aprobada',
    rejected: 'Rechazada',
  }[status] ?? status
}

function statusBadge(status) {
  return [
    'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
    {
      pending: 'bg-amber-50 text-amber-700',
      approved: 'bg-green-50 text-green-700',
      rejected: 'bg-red-50 text-red-700',
    }[status] ?? 'bg-slate-100 text-slate-700',
  ]
}
</script>
