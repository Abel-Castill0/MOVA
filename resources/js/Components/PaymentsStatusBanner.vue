<template>
  <div
    v-if="state"
    :class="['rounded-2xl border px-4 py-3 text-sm', state.tone]"
    role="status"
    aria-live="polite"
    data-testid="payments-status"
  >
    <p class="font-bold">{{ state.title }}</p>
    <p class="mt-0.5">{{ state.text }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

// Estado HONESTO del pago en línea (compartido por el backend en `payments`): nunca se presenta como disponible
// lo que no lo está ni se hace pasar por real un cobro de integración.
const page = usePage()
const state = computed(() => {
  const p = page.props.payments
  if (!p) return null
  if (p.online && p.testMode) {
    return {
      tone: 'border-amber-300 bg-amber-50 text-amber-900',
      title: 'Entorno de pruebas — no se realizarán cargos reales',
      text: 'El pago funciona en modo de integración con Culqi: usa solo tarjetas de prueba; no se descuenta dinero de ninguna tarjeta real.',
    }
  }
  if (p.online) return null
  return {
    tone: 'border-slate-200 bg-white text-slate-700',
    title: 'Pago en línea en activación',
    text: 'Estamos completando la habilitación de los pagos en línea con Culqi. Puedes revisar paquetes y precios; la compra se activará en cuanto el proveedor apruebe el comercio.',
  }
})
</script>
