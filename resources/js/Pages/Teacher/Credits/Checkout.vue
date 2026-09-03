<template>
  <AppLayout title="Pagar recarga">
    <div class="mx-auto max-w-lg space-y-6">
      <div>
        <Link :href="route('teacher.credits.index')" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 transition-colors hover:text-slate-700">
          <Icon name="back" :size="16" /> Volver a Mis créditos
        </Link>
        <h2 class="mt-3 text-xl font-black text-slate-900">Pagar con Yape</h2>
        <p class="mt-1 text-sm text-slate-500">Pago seguro procesado por Mercado Pago. Tus créditos se acreditan automáticamente al confirmarse.</p>
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
            <dd class="text-2xl font-black text-slate-900">{{ currency }} {{ money(recharge.amount_pen) }}</dd>
          </div>
          <div class="flex items-center justify-between text-xs text-slate-400">
            <dt>Saldo actual</dt>
            <dd>{{ teacherProfile.credits_available }} créditos disponibles</dd>
          </div>
        </dl>
      </section>

      <p v-if="!checkoutEnabled" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
        El pago automático con Yape no está disponible por ahora. Vuelve a Mis créditos para pagar de forma manual.
      </p>

      <section v-else class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" aria-live="polite">
        <!-- Formulario Yape: solo visible mientras no hay un resultado ni una verificación en curso -->
        <form v-if="showForm" class="space-y-4" @submit.prevent="submitPayment" novalidate>
          <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
            <Icon name="credits" :size="18" /> Pagar con Yape
          </div>
          <p class="text-xs text-slate-500">
            Abre tu app Yape, genera el código para pagar y escribe aquí tu número de celular y el código de 6 dígitos.
          </p>

          <div>
            <InputLabel for="yape_phone" value="Número de celular (Yape)" />
            <TextInput
              id="yape_phone"
              v-model="form.phoneNumber"
              type="tel"
              inputmode="numeric"
              autocomplete="tel"
              class="mt-1 block w-full"
              placeholder="987654321"
              :disabled="isBusy"
              required
            />
            <InputError class="mt-1" :message="fieldErrors.phoneNumber" />
          </div>

          <div>
            <InputLabel for="yape_otp" value="Código Yape (6 dígitos)" />
            <TextInput
              id="yape_otp"
              v-model="form.otp"
              type="text"
              inputmode="numeric"
              maxlength="6"
              class="mt-1 block w-full tracking-[0.3em]"
              placeholder="000000"
              :disabled="isBusy"
              required
            />
            <InputError class="mt-1" :message="fieldErrors.otp" />
          </div>

          <InputError :message="submitError" />

          <PrimaryButton type="submit" class="w-full" :disabled="!formFilled" :loading="isBusy">
            {{ busyLabel }}
          </PrimaryButton>

          <p class="flex items-start gap-1.5 text-xs text-slate-400">
            <Icon name="secure-payment" :size="14" class="mt-0.5 flex-shrink-0" />
            Pago procesado directamente por Mercado Pago. MOVA nunca ve ni guarda tu código Yape.
          </p>
        </form>

        <!-- Verificando / pendiente / incierto: NUNCA se muestra como "falló", nunca invita a pagar de nuevo -->
        <div v-else-if="isVerifying" class="flex flex-col items-center gap-3 py-4 text-center">
          <svg class="h-8 w-8 motion-safe:animate-spin text-brand-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          <p class="font-semibold text-slate-900">{{ statusMessage }}</p>
          <p class="text-xs text-slate-400">Esto puede tardar hasta un minuto.</p>
        </div>

        <!-- Aprobado -->
        <div v-else-if="status === 'approved'" class="flex flex-col items-center gap-2 py-4 text-center">
          <span class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
            <Icon name="check" :size="24" />
          </span>
          <p class="font-bold text-slate-900">¡Pago aprobado!</p>
          <p class="text-sm text-slate-500">{{ recharge.credits }} créditos ya están disponibles en tu cuenta.</p>
          <Link :href="route('teacher.credits.index')" class="mt-3">
            <PrimaryButton type="button">Ir a Mis créditos</PrimaryButton>
          </Link>
        </div>

        <!-- Falló: sí se permite reintentar, con un intento nuevo -->
        <div v-else-if="status === 'failed'" class="flex flex-col items-center gap-2 py-4 text-center">
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
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import Icon from '@/Components/Icon.vue'
import { createYapeToken } from '@/lib/mercadoPagoYape'
import { preloadMercadoPagoSdk } from '@/lib/mercadoPago'

const props = defineProps({
  recharge: { type: Object, required: true },
  teacherProfile: { type: Object, required: true },
  currency: { type: String, default: 'S/' },
  checkoutEnabled: { type: Boolean, default: false },
  mercadoPagoPublicKey: { type: String, default: null },
  initialStatus: { type: Object, required: true },
})

// phase local (antes de que el backend tenga ningún estado que reportar) +
// status del servidor (fuente de verdad una vez que se envió un intento).
// 'idle' | 'tokenizing' | 'submitting' son puramente locales; a partir de
// ahí todo viene de initialStatus/status() — nunca se inventa un estado
// "aprobado" en el cliente.
const phase = ref(props.initialStatus.status === 'idle' ? 'idle' : 'server')
const status = ref(props.initialStatus.status)
const statusMessage = ref(props.initialStatus.message)

const form = reactive({ phoneNumber: '', otp: '' })
const fieldErrors = reactive({ phoneNumber: '', otp: '' })
const submitError = ref('')

const MAX_POLL_ATTEMPTS = 40 // presupuesto total de polls, nunca infinito
// POLLING QUALITY (MOVA Yape Checkout Pre-Card Hardening): backoff
// progresivo por escalones — la mayoría de los pagos Yape se resuelven en
// los primeros segundos, así que empieza agresivo (3s) y se relaja según
// pasa el tiempo sin resolverse, en vez de martillar el mismo intervalo
// fijo durante los ~2 minutos completos de presupuesto.
const POLL_INTERVAL_STEPS = [
  { afterAttempt: 0, ms: 3000 },
  { afterAttempt: 10, ms: 5000 },
  { afterAttempt: 20, ms: 8000 },
]
let pollTimer = null
let pollAttempts = 0
let pollingActive = false // intención de seguir sondeando; independiente de si HAY un timer vivo ahora mismo (puede estar en pausa por document.hidden)

function currentPollIntervalMs() {
  let ms = POLL_INTERVAL_STEPS[0].ms
  for (const step of POLL_INTERVAL_STEPS) {
    if (pollAttempts >= step.afterAttempt) ms = step.ms
  }

  return ms
}

const isBusy = computed(() => phase.value === 'tokenizing' || phase.value === 'submitting')
const isVerifying = computed(() => ['pending', 'uncertain', 'review'].includes(status.value))
// Bug real encontrado en el E2E negativo de esta ronda: incluir 'failed'
// aquí hacía que, tras un pago rechazado, el formulario vacío reapareciera
// en silencio (showForm ganaba el v-if/v-else-if antes de llegar al panel
// dedicado de "El pago no pudo completarse" más abajo) — el profesor no
// veía NINGÚN motivo ni confirmación de que su intento anterior falló.
// 'failed' tiene su PROPIO panel (con "Intentar de nuevo"); retry() es
// quien pone status de vuelta a 'idle', y RECIÉN ahí showForm debe
// reaparecer — nunca directamente desde 'failed'.
const showForm = computed(() => status.value === 'idle')
const formFilled = computed(() => form.phoneNumber.trim().length >= 6 && form.otp.trim().length === 6)

const busyLabel = computed(() => {
  if (phase.value === 'tokenizing') return 'Verificando con Yape…'
  if (phase.value === 'submitting') return 'Enviando pago…'
  return 'Pagar'
})

function money(value) {
  return Number(value ?? 0).toFixed(2)
}

function resetFieldErrors() {
  fieldErrors.phoneNumber = ''
  fieldErrors.otp = ''
  submitError.value = ''
}

async function submitPayment() {
  if (isBusy.value) return // el botón ya queda disabled, esto es defensa adicional (sección 9)
  resetFieldErrors()

  if (!/^\d{6,15}$/.test(form.phoneNumber.trim())) {
    fieldErrors.phoneNumber = 'Ingresa un número de celular válido.'

    return
  }
  if (!/^\d{6}$/.test(form.otp.trim())) {
    fieldErrors.otp = 'El código Yape tiene 6 dígitos.'

    return
  }

  phase.value = 'tokenizing'

  let token
  try {
    token = await createYapeToken(props.mercadoPagoPublicKey, {
      otp: form.otp.trim(),
      phoneNumber: form.phoneNumber.trim(),
    })
  } catch (error) {
    phase.value = 'idle'
    submitError.value = error?.message || 'No se pudo verificar el código Yape. Intenta de nuevo.'
    // OTP/teléfono nunca se registran en logs/consola — solo el mensaje de
    // error genérico queda en pantalla (sección 5/6 del encargo).
    form.otp = ''

    return
  }

  phase.value = 'submitting'

  // pay() responde JSON, no una página Inertia — se llama vía axios
  // directamente (window.axios ya envía X-Requested-With/XSRF-TOKEN, ver
  // resources/js/bootstrap.js) en vez de router.post(), que espera una
  // respuesta Inertia.
  try {
    const response = await window.axios.post(route('teacher.credits.checkout.pay', props.recharge.id), { token })
    applyStatus(response.data)
  } catch (error) {
    phase.value = 'idle'
    submitError.value = 'No se pudo enviar el pago. Intenta de nuevo.'

    return
  }

  form.otp = ''
  form.phoneNumber = ''

  if (isVerifying.value) startPolling()
}

function applyStatus(payload) {
  phase.value = 'server'
  status.value = payload.status
  statusMessage.value = payload.message

  if (payload.status === 'approved' || payload.status === 'failed') {
    stopPolling()
  }
}

function startPolling() {
  stopPolling()
  pollAttempts = 0
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

// setTimeout que se reprograma a sí mismo (no setInterval): el intervalo
// cambia con currentPollIntervalMs(), y así nunca se acumulan dos polls en
// vuelo si uno tarda más de lo esperado en responder.
function scheduleNextPoll() {
  if (!pollingActive || document.hidden) return // pausado; visibilitychange lo reanuda
  pollTimer = window.setTimeout(runPoll, currentPollIntervalMs())
}

async function runPoll() {
  pollAttempts += 1
  if (pollAttempts > MAX_POLL_ATTEMPTS) {
    stopPolling()

    return
  }

  try {
    // STATUS SEMANTICS (MOVA Yape Final Pre-Card Gate): el polling llama a
    // refresh() (POST, reconcilia de verdad), no a status() (GET, lectura
    // pura) — ambos devuelven exactamente el mismo payload.
    const response = await window.axios.post(route('teacher.credits.checkout.refresh', props.recharge.id))
    applyStatus(response.data) // 'approved'/'failed' ya llama stopPolling() internamente
  } catch (error) {
    // hipo transitorio de red — el próximo tick lo reintenta, nunca se
    // interpreta como "falló" (sección 7 del encargo: uncertain/pending
    // nunca se traduce a un estado de error).
  }

  scheduleNextPoll()
}

// Pestaña en segundo plano: pausa el polling (nada que ganar gastando
// presupuesto/red mientras el profesor no está mirando) y retoma con un
// poll INMEDIATO al volver — nunca esperando el resto del intervalo — para
// que el estado se sienta al día apenas regresa.
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
  resetFieldErrors()
}

onMounted(() => {
  if (props.checkoutEnabled && props.mercadoPagoPublicKey) {
    preloadMercadoPagoSdk()
  }
  document.addEventListener('visibilitychange', handleVisibilityChange)
  if (isVerifying.value) startPolling()
})

onBeforeUnmount(() => {
  stopPolling()
  document.removeEventListener('visibilitychange', handleVisibilityChange)
})
</script>
