<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-gray-200 p-8">
      <h1 class="text-2xl font-bold text-gray-900 mb-1">Verifica tu WhatsApp</h1>
      <p class="text-sm text-gray-500 mb-6">
        Enviamos un código de 6 dígitos a tu número terminado en <strong>{{ phone }}</strong>.
      </p>

      <div v-if="$page.props.flash?.status === 'phone-verification-sent' && !$page.props.flash?.debugCode" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
        Código enviado. Revisa tu WhatsApp.
      </div>
      <div v-if="$page.props.flash?.debugCode" class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
        <p class="font-semibold mb-1">Modo desarrollo</p>
        <p>Tu código de verificación es: <span class="font-mono text-lg tracking-widest">{{ $page.props.flash.debugCode }}</span></p>
        <p class="text-xs text-amber-700 mt-1">También se intentó enviar por WhatsApp, pero en este entorno la entrega no está garantizada — usa este código si no te llega.</p>
      </div>
      <div v-if="$page.props.flash?.status === 'phone-already-verified'" class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-700">
        Tu número ya está verificado.
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
        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-gray-200 p-3">
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
            :disabled="resendForm.processing"
            class="text-sm text-brand-600 hover:underline disabled:opacity-50"
          >
            Reenviar código
          </button>
        </form>
      </div>

      <p class="mt-4 text-center text-xs text-gray-400">
        Puedes verificar tu número más tarde desde tu perfil.
        <Link :href="route('dashboard')" class="text-brand-500 hover:underline">Omitir por ahora</Link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'

const props = defineProps({
  phone: String,
})

const form = useForm({ code: '', whatsapp_notifications: false })
const resendForm = useForm({})

function verify() {
  form.post(route('phone.verification.verify'))
}

function resend() {
  resendForm.post(route('phone.verification.send'))
}
</script>
