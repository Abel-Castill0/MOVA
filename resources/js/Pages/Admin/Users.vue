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
                    <Link v-if="!u.suspended_at"
                      :href="route('admin.users.suspend', u.id)"
                      method="post"
                      as="button"
                      class="text-xs font-semibold text-red-600 hover:text-red-800 bg-red-50 hover:bg-red-100 px-3 py-2 rounded-lg transition-colors"
                      @click.prevent="confirmSuspend(u)">
                      Suspender
                    </Link>
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
    <div v-if="suspendTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Suspender cuenta</h3>
        <p class="text-sm text-gray-500 mb-4">¿Seguro que quieres suspender a <strong>{{ suspendTarget.name }}</strong>?</p>
        <textarea v-model="suspendReason" rows="2"
          placeholder="Motivo de suspensión (opcional)"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-4" />
        <div class="flex gap-2">
          <button @click="suspendTarget = null; suspendReason = ''"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
            Cancelar
          </button>
          <Link :href="route('admin.users.suspend', suspendTarget.id)"
            method="post"
            as="button"
            :data="{ reason: suspendReason }"
            @click="suspendTarget = null; suspendReason = ''"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors">
            Confirmar suspensión
          </Link>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({ users: Object })

const suspendTarget = ref(null)
const suspendReason = ref('')

function confirmSuspend(user) {
  suspendTarget.value = user
  suspendReason.value = ''
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>
