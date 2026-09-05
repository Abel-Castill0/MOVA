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
                  <p class="mb-1 text-xs font-semibold uppercase text-slate-500">{{ recharge.payment_method ?? 'legacy' }}</p>
                  <!--
                    P1 (MOVA Yape Checkout Pre-Card Hardening): una recarga
                    mercadopago no tiene operation_number (NULL a propósito
                    — ver migración 2026_09_02_000001) porque no hay ningún
                    número que el profesor haya escrito; su identidad de
                    pago vive en PaymentOrder.provider_order_id, no aquí.
                  -->
                  <span
                    v-if="recharge.operation_number"
                    class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700"
                  >
                    {{ recharge.operation_number }}
                  </span>
                  <span v-else class="text-xs italic text-slate-400">Automático (sin número de operación)</span>
                </td>
                <td class="px-5 py-3.5">
                  <span :class="['inline-flex rounded-full px-2.5 py-1 text-xs font-bold', rechargeStatusStyle(recharge.status).color]">{{ rechargeStatusStyle(recharge.status).label }}</span>
                </td>
                <td class="px-5 py-3.5">
                  <!--
                    P0 (MOVA Yape Checkout Pre-Card Hardening): una recarga
                    payment_method=mercadopago no lleva botones de Aprobar/
                    Rechazar — su verdad financiera la decide únicamente la
                    reconciliación server-to-server con Mercado Pago
                    (RechargeRequestPolicy::approve()/reject() ya lo rechaza
                    con 403 si se intenta igual). Mostrar botones que
                    terminan en un 403 silencioso sería peor que no
                    mostrarlos.
                  -->
                  <div
                    v-if="recharge.status === 'pending' && recharge.payment_method !== 'mercadopago'"
                    class="flex justify-end gap-2"
                  >
                    <button
                      type="button"
                      class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-green-700 disabled:opacity-50"
                      :disabled="isProcessing(recharge.id)"
                      @click="openActionModal('approve', recharge)"
                    >
                      Aprobar
                    </button>
                    <button
                      type="button"
                      class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 transition-colors hover:bg-red-100 disabled:opacity-50"
                      :disabled="isProcessing(recharge.id)"
                      @click="openActionModal('reject', recharge)"
                    >
                      Rechazar
                    </button>
                  </div>
                  <span
                    v-else-if="recharge.status === 'pending' && recharge.payment_method === 'mercadopago'"
                    class="block text-right text-xs text-slate-400"
                  >
                    Gestionado por Mercado Pago · intento {{ recharge.latest_payment_order?.status ?? 'sin registrar' }}
                  </span>
                  <!--
                    H-02: una recarga aprobada por error (p. ej. un número de
                    operación Yape falso que pasó la revisión) no tenía forma de
                    deshacerse desde el producto. Mismo criterio que arriba para
                    mercadopago: esas las revierte la conciliación con evidencia
                    del proveedor, así que no se pinta un botón que acabaría en
                    403 (RechargeRequestPolicy::reverse()).
                  -->
                  <div
                    v-else-if="recharge.status === 'approved' && recharge.payment_method !== 'mercadopago'"
                    class="flex justify-end"
                  >
                    <button
                      type="button"
                      class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 transition-colors hover:bg-red-100 disabled:opacity-50"
                      :disabled="isProcessing(recharge.id)"
                      @click="openActionModal('reverse', recharge)"
                    >
                      Revertir
                    </button>
                  </div>
                  <span
                    v-else-if="recharge.status === 'reversed'"
                    class="block text-right text-xs font-semibold text-rose-600"
                  >
                    Revertida
                  </span>
                  <span v-else class="block text-right text-xs text-gray-400">Revisada</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!--
        F-13: antes esto era window.confirm() + window.prompt(). Eran la única
        superficie de la app con diálogos nativos, en la aprobación de un abono
        de dinero real, y prompt() está bloqueado en contextos sandbox. Se
        adopta el patrón que ya usaba Admin/Lessons.vue: modal con el efecto
        financiero explícito y motivo obligatorio validado.
      -->
      <Modal :show="!!actionModal" max-width="lg" @close="closeActionModal">
        <div class="p-6">
          <h3 class="text-lg font-black text-slate-900">{{ activeConfig.title }}</h3>

          <div v-if="actionModal" class="mt-4 rounded-xl border border-gray-100 bg-slate-50 p-4 text-sm">
            <dl class="space-y-1.5">
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Profesor</dt>
                <dd class="font-semibold text-slate-900">
                  {{ actionModal.recharge.teacher_profile?.user?.name ?? 'No disponible' }}
                </dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Paquete</dt>
                <dd class="font-semibold text-slate-900">{{ actionModal.recharge.package_name }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Créditos</dt>
                <dd class="font-semibold text-slate-900">{{ actionModal.recharge.credits }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Monto</dt>
                <dd class="font-semibold text-slate-900">S/ {{ money(actionModal.recharge.amount_pen) }}</dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Operación</dt>
                <dd class="font-semibold text-slate-900">{{ actionModal.recharge.operation_number }}</dd>
              </div>
            </dl>
          </div>

          <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
            {{ activeConfig.warning }}
          </p>

          <div v-if="activeConfig.requiresReason" class="mt-4">
            <InputLabel for="action_reason" value="Motivo (obligatorio)" />
            <textarea
              id="action_reason"
              v-model="actionReason"
              rows="3"
              class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-brand-500 focus:ring-brand-500"
              :placeholder="activeConfig.reasonPlaceholder ?? 'Indica el motivo'"
            />
          </div>

          <p v-if="actionError" class="mt-3 text-sm font-semibold text-red-600">{{ actionError }}</p>

          <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <SecondaryButton type="button" :disabled="submitting" @click="closeActionModal">Cancelar</SecondaryButton>
            <button
              type="button"
              class="rounded-xl px-5 py-2.5 text-sm font-bold text-white transition-colors disabled:opacity-50"
              :class="activeConfig.confirmClass"
              :disabled="submitting"
              @click="submitAction"
            >
              {{ submitting ? 'Procesando…' : activeConfig.confirmLabel }}
            </button>
          </div>
        </div>
      </Modal>

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
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import InputLabel from '@/Components/InputLabel.vue'
import Modal from '@/Components/Modal.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { rechargeStatusStyle } from '@/utils/rechargeStatusColors'

defineProps({
  recharges: Object,
})

// Mismo patrón que Admin/Lessons.vue: cada acción declara su copy y, sobre
// todo, el EFECTO que tendrá — aprobar una recarga abona créditos reales.
const ACTION_CONFIG = {
  approve: {
    title: 'Aprobar recarga',
    routeName: 'admin.recharges.approve',
    confirmLabel: 'Aprobar y abonar',
    confirmClass: 'bg-green-600 hover:bg-green-700',
    warning: 'Esto abonará los créditos al profesor de inmediato y quedará registrado en el ledger.',
    requiresReason: false,
  },
  reject: {
    title: 'Rechazar recarga',
    routeName: 'admin.recharges.reject',
    confirmLabel: 'Rechazar',
    confirmClass: 'bg-red-600 hover:bg-red-700',
    warning: 'No se abonará ningún crédito. El profesor verá el motivo que indiques.',
    requiresReason: true,
    minReason: 5,
    reasonPlaceholder: 'Indica el motivo del rechazo',
  },
  // H-02 — Revertir una recarga YA aprobada.
  //
  // minReason: 10 coincide EXACTAMENTE con la validación del backend
  // (RechargeController::reverse(), 'min:10'). Antes el frontend exigía 5 para
  // todas las acciones: un motivo de 6 caracteres pasaba la comprobación del
  // navegador y rebotaba en el servidor.
  reverse: {
    title: 'Revertir recarga aprobada',
    routeName: 'admin.recharges.reverse',
    confirmLabel: 'Revertir y descontar',
    confirmClass: 'bg-rose-600 hover:bg-rose-700',
    // §7: el texto anterior decia que el saldo "puede quedar en negativo".
    // Desde que la reversion manual es fail-closed eso ya no ocurre nunca por
    // esta via, asi que ese aviso habria sido falso.
    warning: 'Esto DESCONTARÁ los créditos ya abonados y quedará como un asiento de reversión en el '
      + 'ledger. Si el profesor ya los gastó, la reversión se rechazará y se abrirá una incidencia '
      + 'para revisarla a mano: MOVA no crea saldos negativos. El profesor será notificado.',
    requiresReason: true,
    minReason: 10,
    reasonPlaceholder: 'Ej.: el número de operación no corresponde a ningún abono recibido',
  },
}

const actionModal = ref(null) // { type: 'approve'|'reject'|'reverse', recharge }
const actionReason = ref('')
const actionError = ref('')
const submitting = ref(false)

const activeConfig = computed(() => actionModal.value ? ACTION_CONFIG[actionModal.value.type] : {})

function isProcessing(id) {
  return submitting.value && actionModal.value?.recharge.id === id
}

function openActionModal(type, recharge) {
  actionModal.value = { type, recharge }
  actionReason.value = ''
  actionError.value = ''
}

function closeActionModal() {
  if (submitting.value) return
  actionModal.value = null
  actionReason.value = ''
  actionError.value = ''
}

function submitAction() {
  const config = activeConfig.value

  const minReason = config.minReason ?? 5

  if (config.requiresReason && actionReason.value.trim().length < minReason) {
    actionError.value = `El motivo debe tener al menos ${minReason} caracteres.`
    return
  }

  submitting.value = true
  router.post(
    route(config.routeName, actionModal.value.recharge.id),
    config.requiresReason ? { reason: actionReason.value.trim() } : {},
    {
      preserveScroll: true,
      onSuccess: () => { submitting.value = false; actionModal.value = null },
      onError: (errors) => { actionError.value = errors.reason ?? 'No se pudo completar la acción.' },
      onFinish: () => { submitting.value = false },
    }
  )
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

</script>
