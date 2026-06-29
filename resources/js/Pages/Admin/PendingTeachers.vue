<template>
  <AppLayout title="Verificación de profesores">
    <div class="space-y-8">
      <h2 class="text-2xl font-bold text-gray-900">Verificación de profesores</h2>

      <!-- ── Pendientes ──────────────────────────────────────────────────────── -->
      <section>
        <h3 class="text-base font-bold text-slate-700 mb-3">
          Pendientes de verificación ({{ pendingTeachers.length }})
        </h3>

        <div v-if="!pendingTeachers.length" class="text-center py-12 bg-white rounded-xl border border-gray-200">
          <div class="text-4xl mb-2">✅</div>
          <p class="text-gray-500 text-sm">No hay profesores pendientes</p>
        </div>

        <div v-else class="space-y-4">
          <div v-for="t in pendingTeachers" :key="t.id" class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-2">
                  <div class="w-10 h-10 bg-brand-100 rounded-full flex items-center justify-center text-brand-700 font-bold flex-shrink-0">
                    {{ t.user?.name?.charAt(0) }}
                  </div>
                  <div>
                    <p class="font-semibold text-gray-900">{{ t.user?.name }}</p>
                    <p class="text-sm text-gray-500">{{ t.user?.email }}</p>
                  </div>
                </div>
                <p class="text-sm text-gray-600 mb-2">{{ t.bio || 'Sin descripción' }}</p>
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-sm font-semibold text-gray-900">S/ {{ parseFloat(t.hourly_rate || 0).toFixed(0) }}/h</span>
                  <span class="text-gray-300">·</span>
                  <span v-for="s in t.subjects" :key="s.id"
                    class="text-xs bg-brand-50 text-brand-700 px-2 py-0.5 rounded">{{ s.name }}</span>
                </div>
              </div>
              <div class="flex gap-2 flex-shrink-0">
                <Link :href="route('admin.teachers.verify', t.id)" method="post" as="button"
                  class="px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 transition-colors">
                  Verificar
                </Link>
                <button @click="openRejectModal(t)"
                  class="px-4 py-2 bg-red-100 text-red-700 text-sm font-semibold rounded-lg hover:bg-red-200 transition-colors">
                  Rechazar
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ── Rechazados ──────────────────────────────────────────────────────── -->
      <section v-if="rejectedTeachers.length">
        <h3 class="text-base font-bold text-slate-700 mb-3">
          Rechazados anteriormente ({{ rejectedTeachers.length }})
        </h3>
        <div class="space-y-4">
          <div v-for="t in rejectedTeachers" :key="t.id"
            class="bg-white rounded-xl border border-red-100 p-5 opacity-80">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-2">
                  <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center text-red-600 font-bold flex-shrink-0">
                    {{ t.user?.name?.charAt(0) }}
                  </div>
                  <div>
                    <p class="font-semibold text-gray-900">{{ t.user?.name }}</p>
                    <p class="text-sm text-gray-500">{{ t.user?.email }}</p>
                  </div>
                </div>
                <div class="text-xs text-red-700 bg-red-50 rounded-lg px-3 py-2 space-y-0.5 mt-2">
                  <p><span class="font-semibold">Rechazado el:</span> {{ fmtDate(t.rejected_at) }}</p>
                  <p><span class="font-semibold">Motivo:</span> {{ t.rejection_reason }}</p>
                  <p v-if="t.reviewed_by">
                    <span class="font-semibold">Revisado por:</span> {{ t.reviewed_by?.name }}
                  </p>
                </div>
              </div>
              <div class="flex-shrink-0">
                <Link :href="route('admin.teachers.verify', t.id)" method="post" as="button"
                  class="px-4 py-2 bg-brand-100 text-brand-700 text-sm font-semibold rounded-lg hover:bg-brand-200 transition-colors">
                  Re-verificar
                </Link>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- ── Modal de rechazo ────────────────────────────────────────────────── -->
    <div v-if="rejectTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Rechazar perfil docente</h3>
        <p class="text-sm text-gray-500 mb-4">
          El perfil de <strong>{{ rejectTarget.user?.name }}</strong> no será eliminado.<br>
          El profesor recibirá una notificación con el motivo.
        </p>
        <textarea v-model="rejectReason" rows="3"
          placeholder="Motivo del rechazo (obligatorio, mínimo 10 caracteres)"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-1" />
        <p v-if="rejectError" class="text-xs text-red-500 mb-2">{{ rejectError }}</p>
        <div class="flex gap-2 mt-3">
          <button @click="closeRejectModal"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
            Cancelar
          </button>
          <button @click="submitReject"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors">
            Confirmar rechazo
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

defineProps({
  pendingTeachers:  { type: Array, default: () => [] },
  rejectedTeachers: { type: Array, default: () => [] },
})

const rejectTarget = ref(null)
const rejectReason = ref('')
const rejectError  = ref('')

function openRejectModal(teacher) {
  rejectTarget.value = teacher
  rejectReason.value = ''
  rejectError.value  = ''
}

function closeRejectModal() {
  rejectTarget.value = null
  rejectReason.value = ''
  rejectError.value  = ''
}

function submitReject() {
  if (rejectReason.value.trim().length < 10) {
    rejectError.value = 'El motivo debe tener al menos 10 caracteres.'
    return
  }
  router.post(
    route('admin.teachers.reject', rejectTarget.value.id),
    { reason: rejectReason.value.trim() },
    { onSuccess: () => closeRejectModal() }
  )
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>
