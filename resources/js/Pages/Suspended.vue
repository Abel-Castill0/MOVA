<template>
  <Head title="Cuenta suspendida – MOVA" />
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-gray-200 p-8 text-center">
      <div class="w-16 h-16 bg-red-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
        <span class="text-3xl">🔒</span>
      </div>
      <h1 class="text-xl font-black text-gray-900 mb-2">Cuenta suspendida temporalmente</h1>
      <p class="text-sm text-gray-500 mb-6">
        Tu cuenta ha sido suspendida temporalmente. Contacta a soporte para más información.
      </p>
      <a href="mailto:abelcastillotrabajo@gmail.com"
        class="inline-block bg-indigo-600 text-white px-6 py-2.5 rounded-xl text-sm font-semibold hover:bg-indigo-700 transition-colors mb-4">
        Contactar soporte
      </a>
      <div class="pt-4 border-t border-gray-100">
        <Link :href="route('logout')" method="post" as="button"
          class="text-sm text-gray-400 hover:text-gray-600 transition-colors">
          Cerrar sesión
        </Link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'
</script>
