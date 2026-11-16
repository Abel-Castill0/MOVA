<template>
  <AppLayout title="Usuarios">
    <div class="space-y-6">
      <h2 class="text-2xl font-bold text-gray-900">Usuarios registrados</h2>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Nombre</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Email</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Teléfono</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Rol</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Estado</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Registro</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="u in users.data" :key="u.id" :class="u.suspended_at ? 'bg-red-50' : 'hover:bg-gray-50'">
                <td class="px-5 py-3.5 text-sm font-medium text-gray-900">{{ u.name }}</td>
                <td class="px-5 py-3.5 text-sm text-gray-600">{{ u.email }}</td>
                <td class="px-5 py-3.5 text-sm text-gray-500">{{ u.phone ?? '–' }}</td>
                <td class="px-5 py-3.5">
                  <span v-for="r in u.roles" :key="r.name" class="inline-block text-xs bg-brand-100 text-brand-700 px-2 py-0.5 rounded-full mr-1">{{ r.name }}</span>
                </td>
                <td class="px-5 py-3.5">
                  <span v-if="u.suspended_at" class="inline-flex items-center gap-1 text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-semibold">
                    🔒 Suspendido
                  </span>
                  <span v-else class="inline-flex items-center gap-1 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-semibold">
                    ✓ Activo
                  </span>
                </td>
                <td class="px-5 py-3.5 text-sm text-gray-400">{{ fmtDate(u.created_at) }}</td>
                <td class="px-5 py-3.5">
                  <!-- Skip admins -->
                  <template v-if="!u.roles?.some(r => r.name === 'admin')">
                    <button v-if="!u.suspended_at" type="button"
                      class="text-sm font-semibold text-red-700 hover:text-red-900 bg-red-50 hover:bg-red-100 px-3 py-2.5 rounded-lg transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600"
                      @click="confirmSuspend(u)">
                      Suspender
                    </button>
                    <Link v-else
                      :href="route('admin.users.unsuspend', u.id)"
                      method="post"
                      as="button"
                      class="text-xs font-semibold text-green-600 hover:text-green-800 bg-green-50 hover:bg-green-100 px-3 py-2 rounded-lg transition-colors">
                      Reactivar
                    </Link>
                  </template>
                  <span v-else class="text-xs text-gray-300">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="users.last_page > 1" class="flex gap-1">
        <component v-for="link in users.links" :key="link.label"
          :is="link.url ? Link : 'span'"
          :href="link.url"
          v-html="link.label"
          :class="['px-3 py-1.5 rounded-lg text-sm transition-colors', link.active ? 'bg-brand-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-brand-300', !link.url && 'opacity-40 pointer-events-none']"
        />
      </div>
    </div>

    <!-- Suspend modal -->
    <Modal :show="!!suspendTarget" maxWidth="sm" titleId="admin-suspend-title" :closeable="!suspending" @close="closeSuspend">
      <div v-if="suspendTarget" class="bg-white rounded-2xl p-6">
        <h3 id="admin-suspend-title" class="text-lg font-bold text-slate-900 mb-1">Suspender cuenta</h3>
        <p class="text-sm text-gray-500 mb-4">¿Seguro que quieres suspender a <strong>{{ suspendTarget.name }}</strong>?</p>
        <label for="admin-suspend-reason" class="block text-sm font-semibold text-slate-700 mb-1">Motivo de suspensión (opcional)</label>
        <textarea id="admin-suspend-reason" v-model="suspendReason" rows="2" maxlength="500"
          placeholder="Motivo de suspensión (opcional)"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-4" />
        <p v-if="suspendError" class="mb-3 text-sm font-semibold text-red-700" role="alert">{{ suspendError }}</p>
        <div class="flex gap-2">
          <button type="button" @click="closeSuspend" :disabled="suspending"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
            Cancelar
          </button>
          <button type="button" @click="submitSuspend" :disabled="suspending"
            class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors disabled:opacity-50">
            {{ suspending ? 'Suspendiendo…' : 'Confirmar suspensión' }}
          </button>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'

defineProps({ users: Object })

const suspendTarget = ref(null)
const suspendReason = ref('')
const suspendError = ref('')
const suspending = ref(false)

function confirmSuspend(user) {
  suspendTarget.value = user
  suspendReason.value = ''
  suspendError.value = ''
}

function closeSuspend() {
  if (suspending.value) return
  suspendTarget.value = null
  suspendReason.value = ''
  suspendError.value = ''
}

function submitSuspend() {
  if (suspending.value || !suspendTarget.value) return
  suspending.value = true
  suspendError.value = ''
  router.post(route('admin.users.suspend', suspendTarget.value.id), { reason: suspendReason.value.trim() }, {
    preserveScroll: true,
    onSuccess: () => {
      suspendTarget.value = null
      suspendReason.value = ''
    },
    onError: (errors) => {
      suspendError.value = errors.reason ?? 'No se pudo suspender la cuenta. Revisa el motivo e inténtalo de nuevo.'
    },
    onFinish: () => { suspending.value = false },
  })
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-PE', { timeZone: 'America/Lima', day: 'numeric', month: 'short', year: 'numeric' })
}
</script>
