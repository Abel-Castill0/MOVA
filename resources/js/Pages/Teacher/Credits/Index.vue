<template>
  <AppLayout title="Mis creditos">
    <div class="space-y-6">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 class="text-xl font-black text-slate-900">Mis creditos MOVA</h2>
          <p class="text-sm text-slate-500">Consulta tu saldo y revisa todos tus movimientos.</p>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <section class="rounded-2xl border border-brand-100 bg-white p-5 shadow-sm">
          <p class="text-sm font-semibold text-slate-500">Creditos Disponibles</p>
          <p class="mt-3 text-4xl font-black text-brand-700">{{ teacherProfile.credits_available }}</p>
          <p class="mt-2 text-sm text-slate-500">Listos para aceptar nuevas clases.</p>
        </section>

        <section class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm">
          <p class="text-sm font-semibold text-slate-500">Creditos Reservados</p>
          <p class="mt-3 text-4xl font-black text-amber-600">{{ teacherProfile.credits_reserved }}</p>
          <p class="mt-2 text-sm text-slate-500">Apartados para clases programadas.</p>
        </section>
      </div>

      <section class="space-y-3">
        <div>
          <h3 class="text-base font-bold text-slate-900">Paquetes de recarga</h3>
          <p v-if="!rechargesEnabled" class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
            Las recargas se habilitarán próximamente.
          </p>
          <p v-else class="text-sm text-slate-500">Realiza el pago al destino indicado y registra el número de operación para revisión administrativa.</p>
        </div>

        <div v-if="rechargesEnabled" class="grid gap-4 lg:grid-cols-3">
          <article v-for="pack in packages" :key="pack.code" class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h4 class="text-lg font-black text-slate-900">{{ pack.name }}</h4>
                <p class="mt-1 text-sm text-slate-500">1 crédito equivale a S/ 2.00.</p>
              </div>
              <span class="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">{{ pack.credits }} creditos</span>
            </div>
            <p class="mt-5 text-3xl font-black text-slate-900">S/ {{ money(pack.amount_pen) }}</p>
            <button
              type="button"
              class="mt-5 w-full rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm shadow-brand-600/20 transition-colors hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2"
              @click="openRecharge(pack)"
            >
              Comprar
            </button>
          </article>
        </div>
      </section>

      <section class="rounded-2xl border border-gray-100 bg-white shadow-sm">
        <div class="flex flex-wrap gap-2 border-b border-gray-100 p-3">
          <button
            type="button"
            :class="tabClass(activeTab === 'transactions')"
            @click="activeTab = 'transactions'"
          >
            Historial de Transacciones
          </button>
          <button
            type="button"
            :class="tabClass(activeTab === 'recharges')"
            @click="activeTab = 'recharges'"
          >
            Estado de Recargas
          </button>
        </div>

        <div class="overflow-x-auto">
          <table v-if="activeTab === 'transactions'" class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-bold uppercase text-slate-500">
              <tr>
                <th class="px-4 py-3">Fecha</th>
                <th class="px-4 py-3">Tipo</th>
                <th class="px-4 py-3">Descripcion</th>
                <th class="px-4 py-3 text-right">Creditos</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="!creditTransactions.length">
                <td colspan="4" class="px-4 py-8 text-center text-slate-500">Aun no tienes movimientos de creditos.</td>
              </tr>
              <tr v-for="transaction in creditTransactions" :key="transaction.id">
                <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ fmtDate(transaction.created_at) }}</td>
                <td class="px-4 py-3">
                  <span :class="transactionBadge(transaction.type)">{{ transactionLabel(transaction.type) }}</span>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ transaction.description }}</td>
                <td class="px-4 py-3 text-right font-bold text-slate-900">{{ transaction.amount }}</td>
              </tr>
            </tbody>
          </table>

          <table v-else class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-bold uppercase text-slate-500">
              <tr>
                <th class="px-4 py-3">Fecha</th>
                <th class="px-4 py-3">Paquete</th>
                <th class="px-4 py-3">Operacion</th>
                <th class="px-4 py-3 text-right">Monto</th>
                <th class="px-4 py-3">Estado</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="!rechargeRequests.length">
                <td colspan="5" class="px-4 py-8 text-center text-slate-500">Aun no has enviado solicitudes de recarga.</td>
              </tr>
              <tr v-for="request in rechargeRequests" :key="request.id">
                <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ fmtDate(request.created_at) }}</td>
                <td class="px-4 py-3 font-semibold text-slate-900">{{ request.package_name }} · {{ request.credits }} creditos</td>
                <td class="px-4 py-3 text-slate-700">{{ request.operation_number }}</td>
                <td class="px-4 py-3 text-right text-slate-700">S/ {{ money(request.amount_pen) }}</td>
                <td class="px-4 py-3">
                  <span :class="rechargeBadge(request.status)">{{ rechargeLabel(request.status) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <Modal :show="showRechargeModal" max-width="lg" @close="closeRecharge">
      <form class="p-6" @submit.prevent="submitRecharge">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h3 class="text-lg font-black text-slate-900">Recargar paquete {{ selectedPackage?.name }}</h3>
            <p class="mt-1 text-sm text-slate-500">Realiza el pago exacto y registra el número de operación.</p>
          </div>
          <button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600" @click="closeRecharge">
            <span class="sr-only">Cerrar</span>
            x
          </button>
        </div>

        <div class="mt-5">
          <div class="rounded-lg border border-gray-100 bg-slate-50 p-4">
            <p class="text-sm font-semibold text-slate-700">Destino de pago</p>
            <p class="mt-2 text-lg font-black text-slate-900">{{ paymentDestination }}</p>
            <p class="mt-2 text-sm text-slate-500">
              Monto: <strong class="text-slate-900">S/ {{ money(selectedPackage?.amount_pen) }}</strong>
              · Creditos: <strong class="text-slate-900">{{ selectedPackage?.credits }}</strong>
            </p>
          </div>
        </div>

        <div class="mt-5">
          <InputLabel for="payment_method" value="Método de pago" />
          <select
            id="payment_method"
            v-model="form.payment_method"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500"
            required
          >
            <option value="" disabled>Selecciona un método</option>
            <option v-for="(label, code) in paymentMethods" :key="code" :value="code">{{ label }}</option>
          </select>
          <InputError class="mt-2" :message="form.errors.payment_method" />
        </div>

        <div class="mt-5">
          <InputLabel for="operation_number" value="Numero de Operacion" />
          <TextInput
            id="operation_number"
            v-model="form.operation_number"
            type="text"
            class="mt-1 block w-full"
            placeholder="Ej: 123456789"
            required
            autofocus
          />
          <InputError class="mt-2" :message="form.errors.operation_number" />
        </div>

        <InputError class="mt-3" :message="form.errors.package_code" />

        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <SecondaryButton type="button" @click="closeRecharge">Cancelar</SecondaryButton>
          <PrimaryButton :disabled="form.processing" :class="{ 'opacity-50': form.processing }">
            Enviar solicitud
          </PrimaryButton>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TextInput from '@/Components/TextInput.vue'

defineProps({
  teacherProfile: Object,
  creditTransactions: {
    type: Array,
    default: () => [],
  },
  rechargeRequests: {
    type: Array,
    default: () => [],
  },
  packages: {
    type: Array,
    default: () => [],
  },
  paymentMethods: {
    type: Object,
    default: () => ({}),
  },
  rechargesEnabled: {
    type: Boolean,
    default: false,
  },
  paymentDestination: {
    type: String,
    default: null,
  },
})

const activeTab = ref('transactions')
const showRechargeModal = ref(false)
const selectedPackage = ref(null)

const form = useForm({
  package_code: '',
  payment_method: '',
  operation_number: '',
})

function openRecharge(pack) {
  selectedPackage.value = pack
  form.clearErrors()
  form.package_code = pack.code
  form.payment_method = ''
  form.operation_number = ''
  showRechargeModal.value = true
}

function closeRecharge() {
  showRechargeModal.value = false
  selectedPackage.value = null
  form.reset()
  form.clearErrors()
}

function submitRecharge() {
  form.post(route('teacher.credits.recharge'), {
    preserveScroll: true,
    onSuccess: closeRecharge,
  })
}

function tabClass(active) {
  return [
    'rounded-xl px-4 py-2 text-sm font-bold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2',
    active ? 'bg-brand-600 text-white shadow-sm shadow-brand-600/20' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
  ]
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

function transactionLabel(type) {
  return {
    deposit: 'Deposito',
    reservation: 'Reserva',
    consumption: 'Consumo',
    refund: 'Devolucion',
  }[type] ?? type
}

function transactionBadge(type) {
  return [
    'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
    {
      deposit: 'bg-green-50 text-green-700',
      reservation: 'bg-amber-50 text-amber-700',
      consumption: 'bg-slate-100 text-slate-700',
      refund: 'bg-blue-50 text-blue-700',
    }[type] ?? 'bg-slate-100 text-slate-700',
  ]
}

function rechargeLabel(status) {
  return {
    pending: 'Pendiente',
    approved: 'Aprobada',
    rejected: 'Rechazada',
  }[status] ?? status
}

function rechargeBadge(status) {
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
