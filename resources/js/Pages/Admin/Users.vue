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
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Catálogo docente</th>
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
                <td class="px-5 py-3.5 text-sm">
                  <template v-if="u.teacher_profile?.is_verified">
                    <span :class="u.teacher_profile.is_publicly_listed ? 'text-emerald-700' : 'text-amber-700'" class="block text-xs mb-1">
                      {{ u.teacher_profile.is_publicly_listed ? 'Publicado' : 'Oculto' }}
                    </span>
                    <button type="button" class="text-xs font-semibold underline underline-offset-2"
                      @click="openAction(u, u.teacher_profile.is_publicly_listed ? 'hide' : 'publish')">
                      {{ u.teacher_profile.is_publicly_listed ? 'Ocultar ficha' : 'Publicar ficha' }}
                    </button>
                  </template>
                  <span v-else class="text-xs text-gray-400">—</span>
                </td>
                <td class="px-5 py-3.5">
                  <!-- Skip admins -->
                  <template v-if="!u.roles?.some(r => r.name === 'admin')">
                    <button v-if="!u.suspended_at" type="button"
                      class="text-xs font-semibold text-red-600 hover:text-red-800 bg-red-50 hover:bg-red-100 px-3 py-2 rounded-lg transition-colors"
                      @click="openAction(u, 'suspend')">
                      Suspender
                    </button>
                    <button v-else type="button"
                      class="text-xs font-semibold text-green-600 hover:text-green-800 bg-green-50 hover:bg-green-100 px-3 py-2 rounded-lg transition-colors"
                      @click="openAction(u, 'unsuspend')">
                      Reactivar
                    </button>
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

    <!-- A modal opens without issuing a request. Only the confirm button can mutate an account. -->
    <Modal :show="Boolean(actionTarget)" max-width="sm" title-id="admin-user-action-title"
      :closeable="!actionForm.processing" @close="closeAction">
      <div v-if="actionTarget" class="bg-white rounded-2xl p-6">
        <h3 id="admin-user-action-title" class="font-bold text-gray-900 mb-1">
          {{ { suspend: 'Suspender cuenta', unsuspend: 'Reactivar cuenta', hide: 'Ocultar ficha docente', publish: 'Publicar ficha docente' }[actionMode] }}
        </h3>
        <p class="text-sm text-gray-500 mb-4">
          ¿Seguro que quieres {{ { suspend: 'suspender la cuenta de', unsuspend: 'reactivar la cuenta de', hide: 'ocultar del catálogo a', publish: 'publicar en el catálogo a' }[actionMode] }}
          <strong>{{ actionTarget.name }}</strong>?
        </p>
        <label v-if="actionMode === 'suspend'" for="admin-suspend-reason" class="block text-sm font-medium text-gray-700 mb-1">
          Motivo de suspensión (opcional)
        </label>
        <textarea v-if="actionMode === 'suspend'" id="admin-suspend-reason" v-model="actionForm.reason" rows="2"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-4" />
        <p v-if="actionForm.errors.reason" role="alert" class="text-xs text-red-600 mb-4">{{ actionForm.errors.reason }}</p>
        <div class="flex gap-2 mt-3">
          <button type="button" :disabled="actionForm.processing" @click="closeAction"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors disabled:opacity-50">
            Cancelar
          </button>
          <button type="button" :disabled="actionForm.processing" @click="confirmAction"
            :class="['flex-1 px-4 py-2 text-sm font-semibold text-white rounded-lg transition-colors disabled:opacity-50', actionMode === 'suspend' || actionMode === 'hide' ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700']">
            {{ actionForm.processing ? 'Procesando…' : 'Confirmar' }}
          </button>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'

defineProps({ users: Object })

const actionTarget = ref(null)
const actionMode = ref('suspend')
const actionForm = useForm({ reason: '' })

function openAction(user, mode) {
  actionTarget.value = user
  actionMode.value = mode
  actionForm.reset()
  actionForm.clearErrors()
}

function closeAction(force = false) {
  if (actionForm.processing && force !== true) return
  actionTarget.value = null
  actionForm.reset()
  actionForm.clearErrors()
}

function confirmAction() {
  if (!actionTarget.value || actionForm.processing) return
  const isPublicAction = actionMode.value === 'hide' || actionMode.value === 'publish'
  const routeName = isPublicAction ? 'admin.teachers.public-listing'
    : actionMode.value === 'suspend' ? 'admin.users.suspend' : 'admin.users.unsuspend'
  const id = isPublicAction ? actionTarget.value.teacher_profile?.id : actionTarget.value.id
  if (!id) return
  actionForm.transform((data) => isPublicAction ? { published: actionMode.value === 'publish' }
    : actionMode.value === 'suspend' ? { reason: data.reason } : {})
  actionForm.post(route(routeName, id), {
    preserveScroll: true,
    onSuccess: () => closeAction(true),
  })
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>
