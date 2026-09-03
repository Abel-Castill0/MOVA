<template>
  <AppLayout title="Pagar recarga">
    <div class="mx-auto max-w-lg space-y-6">
      <div>
        <Link :href="route('teacher.credits.index')" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 transition-colors hover:text-slate-700">
          <Icon name="back" :size="16" /> Volver a Mis créditos
        </Link>
        <h2 class="mt-3 text-xl font-black text-slate-900">Completa tu pago</h2>
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
        El pago automático no está disponible por ahora. Vuelve a Mis créditos para pagar de forma manual.
      </p>

      <section v-else class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" aria-live="polite">
        <!-- Selector de método: solo visible mientras no hay un resultado ni una verificación en curso.
             role="group" + aria-pressed (no role="tablist"/"tab"): ese patrón ARIA exige navegación
             por flechas y un solo elemento focuseable a la vez (roving tabindex) — esto son dos
             <button> independientes normales, cada uno enfocable con Tab, así que anunciarlos como
             "tab" prometería un comportamiento de teclado que no existe. -->
        <div v-if="showForm" class="mb-4 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1" role="group" aria-label="Método de pago">
          <button
            type="button"
            :aria-pressed="paymentMethod === 'yape'"
            class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-bold transition-colors"
            :class="paymentMethod === 'yape' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
            :disabled="isBusy"
            @click="selectPaymentMethod('yape')"
          >
            Yape
          </button>
          <button
            type="button"
            :aria-pressed="paymentMethod === 'card'"
            class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-bold transition-colors"
            :class="paymentMethod === 'card' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
            :disabled="isBusy"
            @click="selectPaymentMethod('card')"
          >
            Tarjeta
          </button>
        </div>

        <!-- Formulario Yape: solo visible mientras no hay un resultado ni una verificación en curso -->
        <form v-if="showForm && paymentMethod === 'yape'" class="space-y-4" @submit.prevent="submitYapePayment" novalidate>
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

        <!-- Card Payment Brick: mismo v-if/v-else-if que el resto de la sección (nunca
             v-show — necesita romper la cadena else-if de verificando/aprobado/fallido más
             abajo). El watcher de showCardBrick espera un nextTick() antes de montar, así
             que el <div id="cardPaymentBrick_container"> ya existe en el DOM cuando toca. -->
        <div v-else-if="showForm && paymentMethod === 'card'" class="space-y-3">
          <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
            <Icon name="credits" :size="18" /> Pagar con tarjeta
          </div>

          <div v-if="!brickReady" class="flex items-center justify-center py-8" aria-hidden="true">
            <svg class="h-6 w-6 motion-safe:animate-spin text-brand-600" viewBox="0 0 24 24" fill="none">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
          </div>

          <div id="cardPaymentBrick_container" :class="{ 'pointer-events-none opacity-60': isBusy }" />

          <InputError :message="submitError" />

          <p class="flex items-start gap-1.5 text-xs text-slate-400">
            <Icon name="secure-payment" :size="14" class="mt-0.5 flex-shrink-0" />
            Pago procesado directamente por Mercado Pago. MOVA nunca ve ni guarda el número completo, CVV ni fecha de tu tarjeta.
          </p>
        </div>

        <!-- 3DS Challenge (MOVA Card Payment Brick 3DS): el banco pide una verificación adicional
             dentro de un iframe — nunca se le dice al profesor que "está verificando" sin mostrarle
             qué hacer, y nunca se acredita ni se muestra "aprobado" solo porque el Challenge terminó
             (ver watchChallengeCompletion() en mercadoPagoChallenge.js: solo dispara un refresh, la
             verdad sigue viniendo de refresh()/status()). -->
        <div v-else-if="showChallenge" class="flex flex-col gap-3 py-2 text-center">
          <p class="font-semibold text-slate-900">{{ statusMessage }}</p>
          <p class="text-xs text-slate-400">No cierres ni actualices esta pantalla mientras completas la verificación.</p>
          <div ref="challengeContainer" class="overflow-hidden rounded-xl border border-gray-100" />
        </div>

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
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import Icon from '@/Components/Icon.vue'
import { createYapeToken } from '@/lib/mercadoPagoYape'
import { mountCardPaymentBrick, unmountCardPaymentBrick } from '@/lib/mercadoPagoCard'
import { preloadMercadoPagoSdk } from '@/lib/mercadoPago'
import { clearChallenge, renderChallenge, watchChallengeCompletion } from '@/lib/mercadoPagoChallenge'

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
// 3DS CHALLENGE: null salvo cuando el backend reporta
// action_required.type==='challenge' (ver CreditCheckoutController::safeStatus()) —
// nunca se infiere localmente, siempre viene tal cual del servidor.
const actionRequired = ref(props.initialStatus.action_required || null)
const challengeContainer = ref(null)
let stopWatchingChallenge = null

const form = reactive({ phoneNumber: '', otp: '' })
const fieldErrors = reactive({ phoneNumber: '', otp: '' })
const submitError = ref('')

// MÉTODO DE PAGO (MOVA Card Payment Brick): 'yape' es el default — el
// checkout ya funcionaba así antes de esta ronda, cambiar el default
// alteraría el comportamiento de Yape sin necesidad (sección "NO tocar
// Yape salvo adaptación mínima compartida necesaria" del encargo).
const paymentMethod = ref('yape')
// true solo entre onReady() del Brick y su desmontaje — controla el
// spinner que cubre el contenedor mientras el Brick todavía está
// inicializando sus propios campos.
const brickReady = ref(false)
// Controller del Brick — variable de módulo local (no reactiva, igual
// criterio que pollTimer/pollAttempts más abajo): Vue no necesita
// reaccionar a sus cambios, solo el código de esta función.
let cardController = null

// 3DS CHALLENGE: el Challenge de Mercado Pago tiene una ventana oficial de
// ~5 minutos (ver documentación "Integrate 3DS") — el presupuesto anterior
// (40 intentos, ~4 min) alcanzaba de sobra para Yape pero se quedaba corto
// para dejar completar un Challenge de tarjeta entero. Subido a 46 intentos
// y un cuarto escalón (~320s totales) — nunca infinito, solo con margen
// suficiente para el caso nuevo; no cambia el comportamiento de Yape (que
// casi siempre resuelve en los primeros escalones).
const MAX_POLL_ATTEMPTS = 46
// POLLING QUALITY (MOVA Yape Checkout Pre-Card Hardening): backoff
// progresivo por escalones — la mayoría de los pagos Yape se resuelven en
// los primeros segundos, así que empieza agresivo (3s) y se relaja según
// pasa el tiempo sin resolverse, en vez de martillar el mismo intervalo
// fijo durante los ~2 minutos completos de presupuesto.
const POLL_INTERVAL_STEPS = [
  { afterAttempt: 0, ms: 3000 },
  { afterAttempt: 10, ms: 5000 },
  { afterAttempt: 20, ms: 8000 },
  { afterAttempt: 30, ms: 10000 },
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
// 3DS CHALLENGE: subconjunto de 'pending' — su v-else-if debe evaluarse
// ANTES que isVerifying en el template para ganarle la rama (ambos son
// simultáneamente 'true' mientras hay un Challenge activo).
const showChallenge = computed(() => status.value === 'pending' && actionRequired.value?.type === 'challenge')
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
// Gobierna el mount/unmount del Card Payment Brick (ver watcher más abajo)
// — nunca se monta mientras el formulario no está visible (verificando,
// aprobado, fallido), y nunca dos veces seguidas para el mismo estado.
const showCardBrick = computed(() => showForm.value && paymentMethod.value === 'card')

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

function selectPaymentMethod(method) {
  if (isBusy.value || paymentMethod.value === method) return // defensa adicional — los botones ya quedan disabled
  paymentMethod.value = method
  resetFieldErrors()
}

async function submitYapePayment() {
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
  actionRequired.value = payload.action_required || null

  if (payload.status === 'approved' || payload.status === 'failed') {
    stopPolling()
  }
}

/**
 * onSubmit del Card Payment Brick — llamado por el propio Brick cuando el
 * profesor hace clic en su botón nativo de pago, YA con la tarjeta
 * tokenizada (ver mercadoPagoCard.js). Nunca se llama a mano.
 *
 * DEBE devolver una Promise (el Brick la usa para su propia animación de
 * éxito/error) — nunca se envuelve/traga el rechazo silenciosamente aquí:
 * si el POST falla, el error se re-lanza después de fijar submitError.
 *
 * SERVER AUTHORITY (sección 9 del encargo): solo se reenvía lo que
 * identifica el MEDIO DE PAGO ya tokenizado (token/payment_method_id/
 * installments/issuer_id/identification) — nunca formData.transaction_amount
 * ni formData.payer.email, aunque el Brick los incluya; monto/payer los
 * vuelve a derivar el backend siempre desde RechargeRequest.
 */
async function submitCardPayment(formData) {
  submitError.value = ''

  if (isBusy.value) {
    // Defensa adicional (sección 8) — el Brick ya debería bloquear un
    // segundo submit mientras el primero sigue en vuelo.
    throw new Error('Ya hay un pago en curso.')
  }

  phase.value = 'submitting'

  const identification = formData?.payer?.identification

  try {
    const response = await window.axios.post(route('teacher.credits.checkout.pay', props.recharge.id), {
      payment_method: 'card',
      token: formData.token,
      payment_method_id: formData.payment_method_id,
      installments: formData.installments,
      issuer_id: formData.issuer_id || null,
      identification_type: identification?.type || null,
      identification_number: identification?.number || null,
    })
    applyStatus(response.data)
  } catch (error) {
    phase.value = 'idle'
    submitError.value = 'No se pudo enviar el pago. Intenta de nuevo.'

    throw error
  }

  if (isVerifying.value) startPolling()
}

/**
 * Monta una instancia NUEVA del Brick — solo se llama desde el watcher de
 * showCardBrick, nunca directamente desde el template (evita instancias
 * duplicadas si el usuario alterna método varias veces rápido: el watcher
 * serializa mount/unmount porque Vue solo dispara el callback una vez por
 * cambio real de valor).
 */
async function mountCardBrick() {
  if (!props.mercadoPagoPublicKey) return

  brickReady.value = false
  submitError.value = ''

  try {
    cardController = await mountCardPaymentBrick(props.mercadoPagoPublicKey, 'cardPaymentBrick_container', {
      // Mismo monto que ya se muestra en el resumen — puramente informativo
      // para el Brick (nunca lo que decide cuánto se cobra: eso lo vuelve a
      // derivar el backend desde RechargeRequest, ver
      // CreditCheckoutController::pay()).
      amount: Number(props.recharge.amount_pen),
      onReady: () => {
        brickReady.value = true
      },
      onSubmit: submitCardPayment,
      onError: () => {
        // Errores propios del Brick (tarjeta inválida, campo incompleto,
        // fallo de tokenización) — nunca se registra en consola nada que
        // pueda incluir datos de tarjeta; el Brick ya evita exponerlos.
        submitError.value = 'Revisa los datos de tu tarjeta e intenta de nuevo.'
      },
    })
  } catch (error) {
    submitError.value = 'No se pudo cargar el formulario de tarjeta. Intenta de nuevo.'
  }
}

async function unmountCardBrick() {
  if (!cardController) return

  const controller = cardController
  cardController = null
  brickReady.value = false

  await unmountCardPaymentBrick(controller)
}

// LIFECYCLE (sección 6 del encargo): única fuente de verdad para
// montar/desmontar el Brick — nunca se monta desde selectPaymentMethod()
// directamente, así cambiar de método/salir de la pantalla/reintentar
// después de un fallo pasan siempre por el mismo camino.
watch(showCardBrick, async (show) => {
  if (show) {
    await nextTick() // el <div id="cardPaymentBrick_container"> debe existir en el DOM antes de montar
    await mountCardBrick()
  } else {
    await unmountCardBrick()
  }
})

/**
 * Monta/desmonta el iframe del 3DS Challenge — mismo criterio de lifecycle
 * que el watcher de showCardBrick de arriba (única fuente de verdad para
 * montar/desmontar, nunca desde otro sitio). stopWatchingChallenge()
 * SIEMPRE se llama antes de un nuevo renderChallenge() o al salir, para no
 * dejar dos listeners de postMessage vivos si el profesor ve dos Challenges
 * seguidos (reintento tras un Challenge fallido, por ejemplo).
 */
watch(showChallenge, async (show) => {
  // immediate: true — a diferencia de showCardBrick (que arranca en
  // 'idle', nunca true al montar), showChallenge SÍ puede ser true desde el
  // primer render: recargar la pantalla de checkout a mitad de un Challenge
  // llega con initialStatus.action_required ya poblado por el backend, y
  // sin immediate el watcher nunca dispara porque el valor "no cambió".
  if (stopWatchingChallenge) {
    stopWatchingChallenge()
    stopWatchingChallenge = null
  }

  if (show) {
    await nextTick() // el <div ref="challengeContainer"> debe existir en el DOM antes de dibujar el iframe
    if (!challengeContainer.value || !actionRequired.value) return

    const challengeIframe = renderChallenge(challengeContainer.value, {
      externalResourceUrl: actionRequired.value.external_resource_url,
      creq: actionRequired.value.creq,
    })

    stopWatchingChallenge = watchChallengeCompletion(challengeIframe, () => {
      // NUNCA se acredita ni se marca "aprobado" aquí — el evento solo
      // significa "el profesor ya interactuó con el Challenge", no que el
      // pago se resolvió (ver docblock de mercadoPagoChallenge.js). Se
      // limita a adelantar el próximo poll ya programado (mismo
      // refresh()/status() de siempre) para que el resultado real se vea
      // apenas Mercado Pago lo confirme, sin esperar el resto del intervalo.
      if (pollingActive) {
        if (pollTimer) {
          window.clearTimeout(pollTimer)
          pollTimer = null
        }
        runPoll()
      }
    })
  } else {
    clearChallenge(challengeContainer.value)
  }
}, { immediate: true })

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
  actionRequired.value = null
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
  // LIFECYCLE (sección 6 del encargo): "cada vez que el usuario sale de la
  // pantalla... es necesario destruir la instancia actual" — nunca se deja
  // un controller de Brick vivo referenciando un <div> que Vue está a
  // punto de desmontar.
  unmountCardBrick()
  // 3DS CHALLENGE: mismo criterio — nunca dejar el listener de postMessage
  // vivo después de que Vue desmonte challengeContainer.
  if (stopWatchingChallenge) {
    stopWatchingChallenge()
    stopWatchingChallenge = null
  }
})
</script>
