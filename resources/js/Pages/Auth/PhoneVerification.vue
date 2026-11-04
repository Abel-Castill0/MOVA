<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-gray-200 p-8">
      <h1 class="text-2xl font-bold text-gray-900 mb-1">Verifica tu WhatsApp</h1>

      <!-- C-P1-PHONE-VERIFICATION: el código solo se entrega por WhatsApp.
           Si el canal no está habilitado no se ofrece un formulario que nunca
           podría completarse. -->
      <template v-if="!available">
        <div role="status" class="mt-4 p-4 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-700">
          <p class="font-semibold text-slate-900 mb-1">La verificación por WhatsApp todavía no está habilitada</p>
          <p>Por ahora no es posible verificar tu número de celular en MOVA. Puedes seguir usando tu cuenta; no necesitas hacer nada más aquí.</p>
        </div>
        <Link :href="route('dashboard')" class="mt-6 inline-flex w-full justify-center bg-brand-600 text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-brand-700 transition-colors">
          Ir a mi panel
        </Link>
      </template>

      <template v-else>
      <p class="text-sm text-gray-500 mb-6">
        Te enviamos por WhatsApp un código de 6 dígitos a tu número terminado en <strong>{{ phone }}</strong>.
      </p>

      <!-- Estado REAL del envío: lo decide la respuesta del servidor, nunca se da por hecho. «Aceptado» significa que
           WhatsApp recibió el mensaje para entregarlo, no que ya esté en el teléfono. -->
      <div aria-live="polite">
        <div v-if="sendState === 'preparing' || sendState === 'sending'" class="mb-4 p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-700">
          {{ sendState === 'preparing' ? 'Preparando tu código…' : 'Enviando tu código por WhatsApp…' }}
        </div>
        <div v-else-if="sendState === 'failed'" role="alert" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
          <p>{{ $page.props.errors.phone }}</p>
          <button v-if="canRetry" type="button" class="mt-2 font-semibold underline underline-offset-4 disabled:opacity-50" :disabled="busy" @click="resend">Reintentar envío</button>
        </div>
        <div v-else-if="sendState === 'accepted' && !$page.props.flash?.debugCode" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
          WhatsApp aceptó el envío. El código debería llegarte en unos segundos; si tu teléfono no tiene conexión, llegará cuando la recupere.
        </div>
        <div v-else-if="sendState === 'pending'" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
          Ya te enviamos un código hace poco y sigue vigente. Revisa tu WhatsApp.
        </div>
      </div>
      <div v-if="$page.props.flash?.debugCode" class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
        <p class="font-semibold mb-1">Modo desarrollo</p>
        <p>Tu código de verificación es: <span class="font-mono text-lg tracking-widest">{{ $page.props.flash.debugCode }}</span></p>
        <p class="text-xs text-amber-700 mt-1">También se intentó enviar por WhatsApp, pero en este entorno la entrega no está garantizada — usa este código si no te llega.</p>
      </div>
      <div v-if="verified || $page.props.flash?.status === 'phone-already-verified'" class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-700">
        Verificación completada: tu número ya está verificado.
      </div>

      <form @submit.prevent="verify" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Código de verificación</label>
          <input
            v-model="form.code"
            type="text"
            inputmode="numeric"
            maxlength="6"
            required
            autofocus
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-center tracking-widest text-lg font-mono focus:outline-none focus:ring-2 focus:ring-brand-500"
            placeholder="000000"
          />
          <p v-if="form.errors.code" class="text-xs text-red-500 mt-1">{{ form.errors.code }}</p>
        </div>

        <!--
          Casilla de consentimiento EXPLÍCITA, sin marcar por defecto.
          Verificar el número (arriba) es autenticación; esto es una decisión
          de producto distinta que le corresponde al usuario, no algo que se
          infiere automáticamente de haber completado el código.
        -->
        <p v-if="alreadyAuthorized" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800">
          Ya autorizaste los avisos por WhatsApp: se activarán en cuanto confirmes tu número.
        </p>
        <label v-else class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-gray-200 p-3">
          <input
            v-model="form.whatsapp_notifications"
            type="checkbox"
            class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
          />
          <span class="text-xs text-gray-600">
            <span class="font-medium text-gray-800">Quiero recibir recordatorios y actualizaciones de mis clases por WhatsApp.</span>
            Puedes cambiar esto cuando quieras desde tu perfil. Esto no afecta al código de verificación, que siempre podrás pedir.
          </span>
        </label>

        <button
          type="submit"
          :disabled="form.processing"
          class="w-full bg-brand-600 text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-brand-700 disabled:opacity-50 transition-colors"
        >
          Verificar
        </button>
      </form>

      <div class="mt-4 text-center">
        <form @submit.prevent="resend">
          <button
            type="submit"
            :disabled="busy || secondsLeft > 0"
            class="text-sm text-brand-600 hover:underline disabled:opacity-50"
          >
            {{ secondsLeft > 0 ? `Reenviar código (${secondsLeft} s)` : 'Reenviar código' }}
          </button>
        </form>
        <p v-if="$page.props.errors.resend" class="text-xs text-red-500 mt-1">{{ $page.props.errors.resend }}</p>
      </div>

      <p class="mt-4 text-center text-xs text-gray-500">
        Puedes verificar tu número más tarde desde tu perfil.
        <Link :href="route('dashboard')" class="text-brand-500 hover:underline">Omitir por ahora</Link>
      </p>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'

const props = defineProps({
  phone: String,
  available: { type: Boolean, default: false },
  hasPhone: { type: Boolean, default: false },
  verified: { type: Boolean, default: false },
  pending: { type: Boolean, default: false },
  cooldown: { type: Number, default: 0 },
  locked: { type: Boolean, default: false },
})

const page = usePage()
const form = useForm({ code: '', whatsapp_notifications: false })
const alreadyAuthorized = computed(() => page.props.auth?.user?.whatsapp_status === 'needs_verification')
const resendForm = useForm({})
const autoForm = useForm({ auto: true })

// El primer código se pide solo al abrir la pantalla (mismo endpoint y límites que «Reenviar»). No se pide si ya hay uno
// vigente, si el número ya está verificado o si no hay teléfono; el servidor vuelve a comprobarlo todo.
// Cualquier error previo (fallo de envío, intentos agotados, espera) detiene el envío automático: evita bucles al recargar.
const willAutoSend = props.available && props.hasPhone && !props.verified && !props.pending && !props.locked
  && !String(page.props.flash?.status ?? '').startsWith('phone-') && Object.keys(page.props.errors ?? {}).length === 0
  && props.cooldown === 0
const started = ref(false)
const busy = computed(() => autoForm.processing || resendForm.processing)
const canRetry = computed(() => props.hasPhone && !props.verified)

const sendState = computed(() => {
  if (props.verified) return 'verified'
  if (busy.value) return 'sending'
  if (willAutoSend && !started.value) return 'preparing'
  if (page.props.errors?.phone) return 'failed'
  const status = page.props.flash?.status
  if (status === 'phone-verification-sent') return 'accepted'
  if (status === 'phone-verification-in-progress' || status === 'phone-verification-pending' || props.pending) return 'pending'
  return 'idle'
})

const secondsLeft = ref(props.cooldown)
let timer = null
function startCountdown(seconds) {
  secondsLeft.value = seconds
  clearInterval(timer)
  if (seconds > 0) {
    timer = setInterval(() => {
      secondsLeft.value = Math.max(0, secondsLeft.value - 1)
      if (secondsLeft.value === 0) clearInterval(timer)
    }, 1000)
  }
}
watch(() => props.cooldown, startCountdown)

onMounted(() => {
  startCountdown(props.cooldown)
  if (willAutoSend) {
    started.value = true
    autoForm.post(route('phone.verification.send'), { preserveScroll: true })
  }
})
onUnmounted(() => clearInterval(timer))

function verify() {
  form.post(route('phone.verification.verify'))
}

function resend() {
  if (busy.value) return
  resendForm.post(route('phone.verification.send'), { preserveScroll: true })
}
</script>
