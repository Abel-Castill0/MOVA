<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-gray-200 p-8">
      <h1 class="text-2xl font-bold text-gray-900 mb-1">Crear cuenta</h1>
      <p class="text-sm text-gray-500 mb-6">Únete a MOVA</p>

      <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-2 gap-3">
          <button type="button" @click="form.role = 'parent'"
            :class="['py-3 rounded-xl border-2 text-sm font-semibold transition-all',
              form.role === 'parent' ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600']">
            👨‍👩‍👧 Soy padre
          </button>
          <button type="button" @click="form.role = 'teacher'"
            :class="['py-3 rounded-xl border-2 text-sm font-semibold transition-all',
              form.role === 'teacher' ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600']">
            👨‍🏫 Soy profesor
          </button>
        </div>
        <p v-if="form.errors.role" class="text-xs text-red-500">{{ form.errors.role }}</p>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nombre completo</label>
          <input v-model="form.name" type="text" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p v-if="form.errors.name" class="text-xs text-red-500 mt-1">{{ form.errors.name }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input v-model="form.email" type="email" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p v-if="form.errors.email" class="text-xs text-red-500 mt-1">{{ form.errors.email }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Teléfono
            <span class="text-gray-400 font-normal">(para recordatorios por WhatsApp)</span>
          </label>
          <input v-model="form.phone" type="tel" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="987654321 o +51987654321" />
          <p class="text-xs text-gray-400 mt-1">Perú: 9 dígitos. Internacional: incluye el código de país (+51, +1…)</p>
          <p v-if="form.errors.phone" class="text-xs text-red-500 mt-1">{{ form.errors.phone }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
          <input v-model="form.password" type="password" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p v-if="form.errors.password" class="text-xs text-red-500 mt-1">{{ form.errors.password }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar contraseña</label>
          <input v-model="form.password_confirmation" type="password" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>

        <!-- Terms checkbox -->
        <div>
          <label class="flex items-start gap-2.5 cursor-pointer group">
            <input v-model="form.accepted_terms" type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 flex-shrink-0" />
            <span class="text-xs text-gray-600 leading-snug">
              He leído y acepto los
              <a :href="route('legal.terms')" target="_blank" class="text-indigo-600 hover:underline">Términos y Condiciones</a>
              y la
              <a :href="route('legal.privacy')" target="_blank" class="text-indigo-600 hover:underline">Política de Privacidad</a>
              de MOVA.
            </span>
          </label>
          <p v-if="form.errors.accepted_terms" class="text-xs text-red-500 mt-1">{{ form.errors.accepted_terms }}</p>
        </div>

        <button type="submit" :disabled="form.processing || !form.accepted_terms"
          class="w-full bg-indigo-600 text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50 transition-colors">
          Crear cuenta
        </button>
      </form>

      <p class="mt-4 text-center text-sm text-gray-500">
        ¿Ya tienes cuenta?
        <Link :href="route('login')" class="text-indigo-600 hover:underline">Inicia sesión</Link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'

const form = useForm({
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  role: 'parent',
  accepted_terms: false,
})

function submit() {
  form.post(route('register'))
}
</script>
