<template>
  <AppLayout title="Pagar recarga">
    <div class="mx-auto max-w-lg space-y-6">
      <PaymentsStatusBanner />
      <div>
        <Link :href="route('teacher.credits.index')" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 transition-colors hover:text-slate-700">
          <Icon name="back" :size="16" /> Volver a Mis créditos
        </Link>
        <h2 class="mt-3 text-xl font-black text-slate-900">Completa tu pago</h2>
        <p class="mt-1 text-sm text-slate-500">Pago seguro procesado por Culqi. Tus créditos se acreditan automáticamente cuando el pago se confirma.</p>
      </div>

      <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
        <dl class="space-y-2.5 text-sm">
          <div class="flex items-center justify-between">
            <dt class="text-slate-500">Paquete</dt>
            <dd class="font-bold text-slate-900">{{ recharge.package_name }}</dd>
          </div>
          <div class="flex items-center justify-between">
            <dt class="text-slate-500">Créditos que recibirás</dt>
            <dd class="font-bold text-brand-700">{{ recharge.credits }} créditos</dd>
          </div>
          <div class="flex items-center justify-between border-t border-gray-100 pt-2.5">
            <dt class="font-semibold text-slate-700">Total a pagar</dt>
            <dd class="text-2xl font-black text-slate-900" data-testid="total-amount">{{ currency }} {{ money(recharge.amount_pen) }}</dd>
          </div>
          <div class="flex items-center justify-between text-xs text-slate-500">
            <dt>Saldo actual</dt>
            <dd>{{ teacherProfile.credits_available }} créditos disponibles</dd>
          </div>
        </dl>
      </section>

      <p v-if="!checkoutEnabled || !culqi" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
        El pago automático no está disponible por ahora. Vuelve a Mis créditos para pagar de forma manual.
      </p>

      <p v-else-if="culqi.mode === 'test'" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-xs font-semibold text-sky-800">
        Entorno de PRUEBAS de Culqi: no se realizan cobros reales. Usa tarjetas de prueba.
      </p>

      <section v-if="checkoutEnabled && culqi" class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" aria-live="polite">
        <!-- Elegir cómo pagar: solo mientras no hay un intento en curso ni un resultado. -->
        <div v-if="showForm" class="space-y-4">
          <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
            <Icon name="credits" :size="18" /> Elige cómo pagar
          </div>

          <PrimaryButton v-if="hasTokenMethods" type="button" class="w-full" :loading="isBusy && busyKind === 'token'" :disabled="isBusy" data-testid="pay-token" @click="startTokenPayment">
            {{ isBusy && busyKind === 'token' ? busyLabel : tokenButtonLabel }}
          </PrimaryButton>

          <SecondaryButton v-if="hasOrderMethod" type="button" class="w-full" :disabled="isBusy" data-testid="pay-order" @click="startOrderPayment">
            {{ isBusy && busyKind === 'order' ? 'Creando tu orden de pago…' : 'Pagar en efectivo, banca móvil o billetera' }}
          </SecondaryButton>

          <p v-if="!hasTokenMethods && !hasOrderMethod" class="text-sm text-slate-500">No hay medios de pago automáticos habilitados por ahora.</p>

          <InputError :message="submitError" />

          <p class="flex items-start gap-1.5 text-xs text-slate-500">
            <Icon name="secure-payment" :size="14" class="mt-0.5 flex-shrink-0" />
            El pago se completa en la ventana segura de Culqi. MOVA nunca ve ni guarda el número completo, CVV ni fecha de tu tarjeta.
          </p>
          <p class="text-xs text-slate-500">
            Al pagar aceptas los <a :href="route('legal.terms')" target="_blank" rel="noopener" class="font-semibold text-brand-600 hover:underline">Términos y Condiciones</a>,
            la <a :href="route('legal.privacy')" target="_blank" rel="noopener" class="font-semibold text-brand-600 hover:underline">Política de Privacidad</a> y la
            <a :href="route('legal.refunds')" target="_blank" rel="noopener" class="font-semibold text-brand-600 hover:underline">Política de devoluciones</a> vigentes.
          </p>
        </div>

        <!-- 3DS: el banco pide una verificación adicional (ventana de Culqi). No se muestra "aprobado" por esto. -->
        <div v-else-if="showThreeDs" class="flex flex-col items-center gap-3 py-4 text-center" role="status">
          <svg class="h-8 w-8 motion-safe:animate-spin text-brand-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          <p class="font-semibold text-slate-900">{{ statusMessage }}</p>
          <p class="text-xs text-slate-500">No cierres ni actualices esta pantalla mientras completas la verificación de tu banco.</p>
        </div>

        <!-- Orden de pago creada: el pago ocurre fuera de esta pantalla; los créditos llegan al confirmarse. -->
        <div v-else-if="showOrderPending" class="flex flex-col items-center gap-3 py-4 text-center" role="status">
          <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 text-amber-600" aria-hidden="true">
            <Icon name="info" :size="24" />
          </span>
          <p class="font-bold text-slate-900">Pago pendiente</p>
          <p class="text-sm text-slate-500">{{ statusMessage }}</p>
          <p v-if="orderExpiresText" class="text-xs text-slate-500">Vence: {{ orderExpiresText }}</p>
          <p class="text-xs font-semibold text-slate-600">Aún no se te ha acreditado ningún crédito.</p>
          <SecondaryButton type="button" @click="reopenOrderCheckout">Ver código o QR de pago</SecondaryButton>
          <p class="text-xs text-slate-500">Puedes cerrar esta pantalla: cuando pagues, tus créditos se acreditarán solos.</p>
          <Link :href="route('teacher.credits.index')" class="text-xs text-slate-500 underline">Volver a Mis créditos</Link>
        </div>

        <div v-else-if="isVerifying && pollExhausted" class="flex flex-col items-center gap-3 py-4 text-center" role="status">
          <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 text-amber-600" aria-hidden="true">
            <Icon name="info" :size="24" />
          </span>
          <p class="font-bold text-slate-900">No pudimos confirmar tu pago todavía</p>
          <p class="text-sm text-slate-500">
            Culqi aún no nos confirma si el pago se aprobó. <strong>No vuelvas a pagar</strong>: si se aprobó, tus créditos se acreditarán solos al confirmarse; si no, no se te habrá cobrado.
          </p>
          <SecondaryButton type="button" class="mt-1" @click="consultAgain">Consultar estado</SecondaryButton>
          <Link :href="route('teacher.credits.index')" class="text-xs text-slate-500 underline">Volver a Mis créditos</Link>
        </div>

        <div v-else-if="isVerifying" class="flex flex-col items-center gap-3 py-4 text-center" role="status">
          <svg class="h-8 w-8 motion-safe:animate-spin text-brand-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          <p class="font-semibold text-slate-900" data-testid="status-message">{{ statusMessage }}</p>
          <p class="text-xs text-slate-500">Esto puede tardar hasta {{ pollBudgetText }}.</p>
        </div>

        <div v-else-if="status === 'approved'" class="flex flex-col items-center gap-2 py-4 text-center" data-testid="approved">
          <span class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
            <Icon name="check" :size="24" />
          </span>
          <p class="font-bold text-slate-900">¡Pago aprobado!</p>
          <p class="text-sm text-slate-500">{{ recharge.credits }} créditos ya están disponibles en tu cuenta.</p>
          <Link :href="route('teacher.credits.index')" class="mt-3">
            <PrimaryButton type="button">Ir a Mis créditos</PrimaryButton>
          </Link>
        </div>

        <div v-else-if="status === 'failed'" class="flex flex-col items-center gap-2 py-4 text-center" data-testid="failed">
          <span class="flex h-12 w-12 items-center justify-center rounded-full bg-rose-50 text-rose-600">
            <Icon name="close" :size="24" />
          </span>
          <p class="font-bold text-slate-900">El pago no pudo completarse</p>
          <p class="text-sm text-slate-500">{{ statusMessage }}</p>
          <SecondaryButton type="button" class="mt-3" @click="retry">Intentar de nuevo</SecondaryButton>
        </div>
      </section>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PaymentsStatusBanner from '@/Components/PaymentsStatusBanner.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import Icon from '@/Components/Icon.vue'
import { closeCulqiCheckout, generateDeviceFingerprint, openCulqiCheckout, preloadCulqi, runThreeDs } from '@/lib/culqi'
import { pollBudgetLabel, pollIntervalMs, pollOutcome } from '@/lib/checkoutPolling'

const props = defineProps({
  recharge: { type: Object, required: true },
  teacherProfile: { type: Object, required: true },
  currency: { type: String, default: 'S/' },
  checkoutEnabled: { type: Boolean, default: false },
  // Solo la llave PÚBLICA y datos no sensibles; la llave secreta nunca llega al navegador.
  culqi: { type: Object, default: null },
  initialStatus: { type: Object, required: true },
})

// Estado local (antes de que el servidor tenga algo que reportar) + estado del servidor (verdad una vez que hay intento).
// 'idle' | 'tokenizing' | 'submitting' | 'threeds' son locales; 'approved' SOLO llega del servidor (ledger).
const phase = ref(props.initialStatus.status === 'idle' ? 'idle' : 'server')
const status = ref(props.initialStatus.status)
const statusMessage = ref(props.initialStatus.message)
const actionRequired = ref(props.initialStatus.action_required || null)
const submitError = ref('')

const pollBudgetText = pollBudgetLabel()
let pollTimer = null
let pollAttempts = 0
let pollingActive = false
const pollExhausted = ref(false)

// El token vive SOLO en memoria durante el 3DS (nunca en estado reactivo, localStorage ni la URL).
let pendingToken = null
let pendingDevice = null

const methods = computed(() => props.culqi?.methods ?? [])
const hasTokenMethods = computed(() => methods.value.includes('card') || methods.value.includes('yape'))
const hasOrderMethod = computed(() => methods.value.includes('order'))
const tokenButtonLabel = computed(() => {
  const card = methods.value.includes('card')
  const yape = methods.value.includes('yape')
  if (card && yape) return 'Pagar con tarjeta o Yape'
  return yape ? 'Pagar con Yape' : 'Pagar con tarjeta'
})

// Qué botón disparó la operación en curso: solo ese muestra el estado de "ocupado".
const busyKind = ref('token')
const isBusy = computed(() => phase.value === 'tokenizing' || phase.value === 'submitting')
const busyLabel = computed(() => {
  if (phase.value === 'tokenizing') return 'Preparando pago seguro…'
  if (phase.value === 'submitting') return 'Enviando pago…'
  return ''
})
const showForm = computed(() => status.value === 'idle')
const showThreeDs = computed(() => status.value === 'pending' && actionRequired.value?.type === '3ds')
const showOrderPending = computed(() => status.value === 'pending' && actionRequired.value?.type === 'order')
const isVerifying = computed(() => ['pending', 'uncertain', 'review'].includes(status.value))
const orderExpiresText = computed(() => {
  const iso = actionRequired.value?.expires_at
  if (!iso) return ''
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' })
})

function money(value) {
  return Number(value ?? 0).toFixed(2)
}

function applyStatus(payload) {
  phase.value = 'server'
  status.value = payload.status
  statusMessage.value = payload.message
  actionRequired.value = payload.action_required || null

  if (payload.status === 'approved' || payload.status === 'failed') {
    stopPolling()
    pendingToken = null
    pendingDevice = null
  }
}

function describeError(error) {
  const errors = error?.response?.data?.errors
  if (errors) return Object.values(errors).flat()[0]

  return error?.response?.data?.message || 'No pudimos iniciar el pago. Inténtalo de nuevo.'
}

/** Una petición pudo haber llegado al servidor aunque la respuesta se perdió: se verifica, NUNCA se asume fallo. */
function onNetworkUncertainty() {
  applyStatus({ status: 'uncertain', message: 'Estamos verificando tu pago. No vuelvas a pagar mientras termina la verificación.', action_required: null })
  startPolling()
}

async function post(routeName, data = {}) {
  return window.axios.post(route(routeName, props.recharge.id), data)
}

// ── Tarjeta / Yape (token de Culqi) ───────────────────────────────────────

async function startTokenPayment() {
  if (isBusy.value) return
  submitError.value = ''
  busyKind.value = 'token'
  phase.value = 'tokenizing'

  let outcome
  try {
    const device = methods.value.includes('card') ? await generateDeviceFingerprint(props.culqi.publicKey) : null
    pendingDevice = device
    // Mientras el modal de Culqi está abierto la pantalla NO queda bloqueada: Culqi no documenta un evento de
    // cierre, así que si la persona lo cierra sin pagar simplemente puede volver a pulsar el botón.
    phase.value = 'idle'
    outcome = await openCulqiCheckout({
      publicKey: props.culqi.publicKey,
      amountMinor: props.recharge.amount_minor,
      email: props.culqi.email,
      methods: methods.value,
    })
  } catch (error) {
    phase.value = 'idle'
    submitError.value = error?.message || 'No se pudo abrir el pago seguro. Inténtalo de nuevo.'

    return
  }

  if (outcome.kind === 'error') {
    submitError.value = 'Culqi no pudo procesar los datos de pago. Revisa los datos e inténtalo de nuevo.'

    return
  }
  if (outcome.kind !== 'token') return

  await submitToken(outcome.token, pendingDevice)
}

async function submitToken(token, device) {
  if (isBusy.value) return
  phase.value = 'submitting'
  pendingToken = token

  try {
    const response = await post('teacher.credits.checkout.pay', { token, device_fingerprint_id: device })
    await handleServerStatus(response.data)
  } catch (error) {
    if (error?.response && error.response.status >= 400 && error.response.status < 500) {
      phase.value = 'idle'
      pendingToken = null
      submitError.value = describeError(error)

      return
    }
    onNetworkUncertainty()
  }
}

async function handleServerStatus(payload) {
  applyStatus(payload)

  if (payload.action_required?.type === '3ds') {
    await completeThreeDs(payload.action_required)

    return
  }
  if (isVerifying.value) startPolling()
}

async function completeThreeDs(action) {
  const token = pendingToken
  const device = pendingDevice
  if (!token || !device) {
    // Se recargó la página a mitad de 3DS: el token solo vive en memoria, el intento se cierra y se reintenta.
    await closeThreeDs('failed')

    return
  }

  phase.value = 'threeds'
  let result
  try {
    result = await runThreeDs({
      publicKey: props.culqi.publicKey,
      token,
      amountMinor: action.amount_minor,
      email: action.email || props.culqi.email,
      returnUrl: window.location.href,
    })
  } catch (error) {
    result = { ok: false, reason: 'failed' }
  }

  if (!result.ok) {
    await closeThreeDs(result.reason === 'cancelled' ? 'cancelled' : 'failed')

    return
  }

  try {
    const response = await post('teacher.credits.checkout.three-ds', { token, device_fingerprint_id: device, parameters3DS: result.parameters3DS })
    pendingToken = null
    pendingDevice = null
    applyStatus(response.data)
    if (isVerifying.value) startPolling()
  } catch (error) {
    if (error?.response && error.response.status >= 400 && error.response.status < 500) {
      submitError.value = describeError(error)
      await refreshOnce()

      return
    }
    onNetworkUncertainty()
  }
}

async function closeThreeDs(reason) {
  pendingToken = null
  pendingDevice = null
  try {
    const response = await post('teacher.credits.checkout.three-ds.cancel', { reason })
    applyStatus(response.data)
  } catch (error) {
    await refreshOnce()
  }
}

// ── Órdenes de pago (efectivo, banca móvil, billeteras) ───────────────────

async function startOrderPayment() {
  if (isBusy.value) return
  submitError.value = ''
  busyKind.value = 'order'
  phase.value = 'submitting'

  try {
    const response = await post('teacher.credits.checkout.order')
    applyStatus(response.data)
  } catch (error) {
    if (error?.response && error.response.status >= 400 && error.response.status < 500) {
      phase.value = 'idle'
      submitError.value = describeError(error)

      return
    }
    onNetworkUncertainty()

    return
  }

  if (actionRequired.value?.type === 'order') {
    await openOrderCheckout()
    startPolling()
  } else if (isVerifying.value) {
    startPolling()
  }
}

async function openOrderCheckout() {
  const action = actionRequired.value
  if (action?.type !== 'order') return

  try {
    await openCulqiCheckout({
      publicKey: props.culqi.publicKey,
      amountMinor: action.amount_minor,
      email: props.culqi.email,
      methods: methods.value,
      order: action.order_id,
    })
  } catch (error) {
    submitError.value = error?.message || 'No se pudo abrir el pago seguro. Inténtalo de nuevo.'
  }
}

function reopenOrderCheckout() {
  openOrderCheckout()
}

// ── Sondeo de estado (la verdad la dicta el servidor) ─────────────────────

async function refreshOnce() {
  try {
    const response = await post('teacher.credits.checkout.refresh')
    applyStatus(response.data)
  } catch (error) {
    // hipo transitorio: el siguiente sondeo lo reintenta
  }
}

function startPolling() {
  stopPolling()
  pollAttempts = 0
  pollExhausted.value = false
  pollingActive = true
  scheduleNextPoll()
}

function stopPolling() {
  pollingActive = false
  if (pollTimer) {
    window.clearTimeout(pollTimer)
    pollTimer = null
  }
}

function scheduleNextPoll() {
  if (!pollingActive || document.hidden) return
  pollTimer = window.setTimeout(runPoll, pollIntervalMs(pollAttempts))
}

async function runPoll() {
  if (pollOutcome(pollAttempts) === 'exhausted') {
    markPollExhausted()

    return
  }

  pollAttempts += 1
  await refreshOnce()

  if (pollingActive && pollOutcome(pollAttempts) === 'exhausted' && isVerifying.value) {
    markPollExhausted()

    return
  }

  scheduleNextPoll()
}

function markPollExhausted() {
  stopPolling()
  if (isVerifying.value) pollExhausted.value = true
}

function consultAgain() {
  startPolling()
}

function handleVisibilityChange() {
  if (!pollingActive) return

  if (document.hidden) {
    if (pollTimer) {
      window.clearTimeout(pollTimer)
      pollTimer = null
    }

    return
  }

  if (!pollTimer) runPoll()
}

function retry() {
  status.value = 'idle'
  phase.value = 'idle'
  actionRequired.value = null
  submitError.value = ''
}

onMounted(() => {
  if (props.checkoutEnabled && props.culqi?.publicKey) {
    preloadCulqi({ withThreeDs: methods.value.includes('card') })
  }
  document.addEventListener('visibilitychange', handleVisibilityChange)
  if (isVerifying.value && !showThreeDs.value) startPolling()
  // Recarga a mitad de 3DS: el token no sobrevive a la recarga; se cierra el intento para poder reintentar.
  if (showThreeDs.value) closeThreeDs('failed')
})

onBeforeUnmount(() => {
  stopPolling()
  document.removeEventListener('visibilitychange', handleVisibilityChange)
  closeCulqiCheckout()
  pendingToken = null
  pendingDevice = null
})
</script>
