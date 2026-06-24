<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-gray-200 p-8">
      <h1 class="text-2xl font-bold text-gray-900 mb-1">Verifica tu WhatsApp</h1>
      <p class="text-sm text-gray-500 mb-6">
        Enviamos un código de 6 dígitos a tu número terminado en <strong>{{ phone }}</strong>.
      </p>

      <div v-if="$page.props.flash?.status === 'phone-verification-sent'" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
        Código enviado. Revisa tu WhatsApp.
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
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-center tracking-widest text-lg font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
            placeholder="000000"
          />
          <p v-if="form.errors.code" class="text-xs text-red-500 mt-1">{{ form.errors.code }}</p>
        </div>

        <button
          type="submit"
          :disabled="form.processing"
          class="w-full bg-indigo-600 text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50 transition-colors"
        >
          Verificar
        </button>
      </form>

      <div class="mt-4 text-center">
        <form @submit.prevent="resend">
          <button
            type="submit"
            :disabled="resendForm.processing"
            class="text-sm text-indigo-600 hover:underline disabled:opacity-50"
          >
            Reenviar código
          </button>
        </form>
      </div>

      <p class="mt-4 text-center text-xs text-gray-400">
        Puedes verificar tu número más tarde desde tu perfil.
        <Link :href="route('dashboard')" class="text-indigo-500 hover:underline">Omitir por ahora</Link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'

const props = defineProps({
  phone: String,
})

const form = useForm({ code: '' })
const resendForm = useForm({})

function verify() {
  form.post(route('phone.verification.verify'))
}

function resend() {
  resendForm.post(route('phone.verification.send'))
}
</script>
