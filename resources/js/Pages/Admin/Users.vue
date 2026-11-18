<template>
  <AppLayout title="Usuarios">
    <div class="space-y-6">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <h2 class="text-2xl font-bold text-gray-900">Usuarios registrados</h2>
        <form class="flex flex-col gap-2 sm:flex-row sm:items-center" role="search" @submit.prevent="applyFilters">
          <label for="user-search" class="sr-only">Buscar por nombre, correo o teléfono</label>
          <input id="user-search" v-model="search" type="search" maxlength="100" autocomplete="off"
            placeholder="Buscar por nombre, correo o teléfono"
            class="min-h-[44px] w-full sm:w-72 rounded-lg border border-gray-300 px-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          <label class="inline-flex items-center gap-2 text-sm text-gray-600 min-h-[44px]">
            <input v-model="showErased" type="checkbox" class="rounded border-gray-300" @change="applyFilters" />
            Mostrar eliminadas
          </label>
          <button type="submit" class="min-h-[44px] px-4 text-sm font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded-lg">Buscar</button>
        </form>
      </div>

      <p v-if="!users.data.length" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-sm text-gray-500" role="status">
        No hay usuarios que coincidan con la búsqueda.
      </p>

      <!-- Móvil: tarjetas -->
      <ul v-else class="space-y-3 md:hidden">
        <li v-for="u in users.data" :key="u.id" :class="['rounded-xl border p-4', rowTone(u)]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-gray-900 truncate">{{ u.name }}</p>
              <p class="text-sm text-gray-600 break-all">{{ u.email }}</p>
              <p class="text-xs text-gray-500 mt-0.5">{{ u.phone ?? 'Sin teléfono' }} · {{ fmtDate(u.created_at) }}</p>
            </div>
            <StatusPill :user="u" />
          </div>
          <div class="mt-2 flex flex-wrap gap-1"><RoleBadges :roles="u.roles" /></div>
          <UserActions class="mt-3" :user="u" @suspend="openSuspend" @erase="openErase" />
        </li>
      </ul>

      <!-- Escritorio: tabla -->
      <div v-if="users.data.length" class="hidden md:block bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Tabla de usuarios">
          <table class="min-w-full divide-y divide-gray-200">
            <caption class="sr-only">Usuarios registrados</caption>
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Nombre</th>
                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Email</th>
                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Teléfono</th>
                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Rol</th>
                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Estado</th>
                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Registro</th>
                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="u in users.data" :key="u.id" :class="u.suspended_at ? 'bg-red-50' : 'hover:bg-gray-50'">
                <td class="px-5 py-3.5 text-sm font-medium text-gray-900">{{ u.name }}</td>
                <td class="px-5 py-3.5 text-sm text-gray-600 break-all">{{ u.email }}</td>
                <td class="px-5 py-3.5 text-sm text-gray-500">{{ u.phone ?? '–' }}</td>
                <td class="px-5 py-3.5"><RoleBadges :roles="u.roles" /></td>
                <td class="px-5 py-3.5"><StatusPill :user="u" /></td>
                <td class="px-5 py-3.5 text-sm text-gray-500 whitespace-nowrap">{{ fmtDate(u.created_at) }}</td>
                <td class="px-5 py-3.5"><UserActions :user="u" @suspend="openSuspend" @erase="openErase" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <nav v-if="users.last_page > 1" class="flex flex-wrap gap-1" aria-label="Paginación">
        <component v-for="link in users.links" :key="link.label"
          :is="link.url ? Link : 'span'"
          :href="link.url"
          v-html="link.label"
          :class="['px-3 py-2 min-h-[44px] inline-flex items-center rounded-lg text-sm transition-colors', link.active ? 'bg-brand-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-brand-300', !link.url && 'opacity-40 pointer-events-none']"
        />
      </nav>
    </div>

    <!-- Suspender -->
    <Modal :show="!!suspendTarget" max-width="md" title-id="suspend-title" @close="closeSuspend">
      <div v-if="suspendTarget" class="p-6">
        <h3 id="suspend-title" class="font-bold text-gray-900 mb-1">Suspender cuenta</h3>
        <p class="text-sm text-gray-600 mb-4">
          <strong>{{ suspendTarget.name }}</strong> no podrá usar MOVA hasta que la reactives. No se borra ningún dato ni se toca su saldo.
        </p>
        <label for="suspend-reason" class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional, queda registrado)</label>
        <textarea id="suspend-reason" v-model="suspendReason" rows="3" maxlength="500"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-4" />
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <SecondaryButton type="button" @click="closeSuspend">Cancelar</SecondaryButton>
          <DangerButton type="button" :loading="suspending" @click="confirmSuspend">Confirmar suspensión</DangerButton>
        </div>
      </div>
    </Modal>

    <!-- Eliminar -->
    <Modal :show="!!eraseTarget" max-width="lg" title-id="erase-title" @close="closeErase">
      <form v-if="eraseTarget" class="p-6" @submit.prevent="submitErase">
        <h3 id="erase-title" class="font-bold text-gray-900 text-lg mb-1">Eliminar cuenta de {{ eraseTarget.name }}</h3>
        <p class="text-sm text-red-700 font-medium mb-3">Esta acción no se puede deshacer.</p>

        <p v-if="previewLoading" class="text-sm text-gray-500" role="status">Calculando las consecuencias…</p>
        <p v-else-if="previewError" class="text-sm text-red-700" role="alert">{{ previewError }}</p>

        <template v-else-if="preview">
          <div v-if="preview.blockers.length" class="rounded-lg border border-red-200 bg-red-50 p-3 mb-4 text-sm text-red-800" role="alert">
            <p class="font-semibold mb-1">No se puede eliminar todavía:</p>
            <ul class="list-disc pl-5 space-y-1"><li v-for="b in preview.blockers" :key="b">{{ b }}</li></ul>
          </div>

          <div class="rounded-lg bg-gray-50 border border-gray-200 p-3 mb-4 text-sm text-gray-700">
            <p class="font-semibold text-gray-900 mb-1">Qué ocurrirá</p>
            <ul class="list-disc pl-5 space-y-1">
              <li>Se anonimizan nombre, correo, teléfono, foto, datos de los alumnos menores y perfil profesional; se cierran sus sesiones.</li>
              <li v-if="preview.summary.future_classes">Se cancelan {{ preview.summary.future_classes }} clase(s) futura(s) y se devuelve la reserva de créditos.</li>
              <li v-if="preview.summary.active_requests">{{ preview.summary.active_requests }} solicitud(es) activa(s) se cierran o se devuelven a búsqueda.</li>
              <li>Se conservan, sin datos personales: historial de clases, créditos, recargas y pagos (obligación contable).</li>
              <li>El correo y el teléfono quedan libres: se puede crear una cuenta nueva sin heredar datos, créditos, roles ni clases.</li>
              <li v-if="preview.suspended || preview.in_debt" class="font-semibold text-amber-800">
                Cuenta {{ preview.suspended ? 'suspendida' : 'con saldo negativo' }}: su correo y teléfono quedarán bloqueados para un nuevo registro.
              </li>
            </ul>
          </div>

          <label v-if="preview.summary.credits_forfeitable > 0" class="flex items-start gap-2 text-sm text-gray-800 mb-4">
            <input v-model="acceptLoss" type="checkbox" class="mt-1 rounded border-gray-300" />
            <span>Confirmo que se pierden {{ preview.summary.credits_forfeitable }} crédito(s) no utilizados de esta cuenta (queda un asiento de auditoría).</span>
          </label>

          <div class="mb-3">
            <label for="erase-reason" class="block text-sm font-medium text-gray-700 mb-1">Motivo (obligatorio, queda en el registro de auditoría)</label>
            <textarea id="erase-reason" v-model="form.reason" rows="2" maxlength="500" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400" />
            <InputError :message="form.errors.reason" class="mt-1" />
          </div>
          <div class="mb-4">
            <label for="erase-confirm" class="block text-sm font-medium text-gray-700 mb-1">Para confirmar, escribe el correo <span class="font-mono break-all">{{ eraseTarget.email }}</span></label>
            <input id="erase-confirm" v-model="form.confirm_email" type="email" autocomplete="off" autocapitalize="off" spellcheck="false" required
              class="w-full min-h-[44px] border border-gray-300 rounded-lg px-3 text-sm focus:outline-none focus:ring-2 focus:ring-red-400" />
            <InputError :message="form.errors.confirm_email" class="mt-1" />
          </div>

          <InputError :message="form.errors.erase || form.errors.accept_credit_loss" class="mb-3" />
        </template>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <SecondaryButton type="button" @click="closeErase">Cancelar</SecondaryButton>
          <DangerButton type="submit" :disabled="!canErase" :loading="form.processing">Eliminar cuenta definitivamente</DangerButton>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import DangerButton from '@/Components/DangerButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import InputError from '@/Components/InputError.vue'
import RoleBadges from './Partials/RoleBadges.vue'
import StatusPill from './Partials/StatusPill.vue'
import UserActions from './Partials/UserActions.vue'

const props = defineProps({ users: Object, filters: { type: Object, default: () => ({ q: '', erased: false }) } })

const search = ref(props.filters.q ?? '')
const showErased = ref(!!props.filters.erased)

function applyFilters() {
  router.get(route('admin.users'), {
    q: search.value.trim() || undefined,
    erased: showErased.value ? 1 : undefined,
  }, { preserveState: true, replace: true })
}

// ── Suspender ──
const suspendTarget = ref(null)
const suspendReason = ref('')
const suspending = ref(false)

function openSuspend(user) { suspendTarget.value = user; suspendReason.value = '' }
function closeSuspend() { if (!suspending.value) { suspendTarget.value = null; suspendReason.value = '' } }
function confirmSuspend() {
  if (suspending.value || !suspendTarget.value) return
  suspending.value = true
  router.post(route('admin.users.suspend', suspendTarget.value.id), { reason: suspendReason.value }, {
    preserveScroll: true,
    onSuccess: () => { suspendTarget.value = null; suspendReason.value = '' },
    onFinish: () => { suspending.value = false },
  })
}

// ── Eliminar ──
const eraseTarget = ref(null)
const preview = ref(null)
const previewLoading = ref(false)
const previewError = ref('')
const acceptLoss = ref(false)
const form = useForm({ reason: '', confirm_email: '', accept_credit_loss: 0 })

async function openErase(user) {
  eraseTarget.value = user
  preview.value = null
  previewError.value = ''
  acceptLoss.value = false
  form.reset()
  form.clearErrors()
  previewLoading.value = true
  try {
    const { data } = await axios.get(route('admin.users.erasure-preview', user.id))
    if (eraseTarget.value?.id === user.id) preview.value = data
  } catch (e) {
    previewError.value = e?.response?.status === 429
      ? 'Demasiadas consultas seguidas. Espera un momento e inténtalo de nuevo.'
      : 'No se pudieron calcular las consecuencias. Cierra y vuelve a intentarlo; no se ha eliminado nada.'
  } finally {
    previewLoading.value = false
  }
}
function closeErase() { if (!form.processing) { eraseTarget.value = null; preview.value = null } }

const canErase = computed(() => {
  if (!preview.value || !eraseTarget.value || form.processing) return false
  if (preview.value.blockers.length) return false
  if (form.reason.trim().length < 10) return false
  if (form.confirm_email.trim().toLowerCase() !== eraseTarget.value.email.toLowerCase()) return false
  if (preview.value.summary.credits_forfeitable > 0 && !acceptLoss.value) return false
  return true
})

function submitErase() {
  if (!canErase.value) return
  form.transform((d) => ({ ...d, accept_credit_loss: acceptLoss.value ? preview.value.summary.credits_forfeitable : 0 }))
    .post(route('admin.users.erase', eraseTarget.value.id), {
      preserveScroll: true,
      onSuccess: () => { eraseTarget.value = null; preview.value = null },
    })
}

function rowTone(u) {
  if (u.account_erased_at || u.account_closed_at) return 'bg-gray-50 border-gray-200'
  return u.suspended_at ? 'bg-red-50 border-red-200' : 'bg-white border-gray-200'
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'America/Lima' })
}
</script>
