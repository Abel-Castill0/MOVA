<template>
  <AppLayout title="Añadir hijo">
    <div class="max-w-lg">
      <h2 class="text-2xl font-black text-slate-900 mb-6">Añadir hijo/a</h2>
      <form @submit.prevent="submit" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-5">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label for="first_name" class="block text-sm font-semibold text-slate-700 mb-1.5">Nombre</label>
            <input id="first_name" v-model="form.first_name" type="text" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" /><InputError class="mt-1" :message="form.errors.first_name" />
          </div>
          <div>
            <label for="last_name" class="block text-sm font-semibold text-slate-700 mb-1.5">Apellidos</label>
            <input id="last_name" v-model="form.last_name" type="text" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" /><InputError class="mt-1" :message="form.errors.last_name" />
          </div>
        </div>
        <div>
          <label for="birth_date" class="block text-sm font-semibold text-slate-700 mb-1.5">Fecha de nacimiento</label>
          <input id="birth_date" v-model="form.birth_date" type="date" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" /><InputError class="mt-1" :message="form.errors.birth_date" />
        </div>
        <div>
          <label for="grade_level" class="block text-sm font-semibold text-slate-700 mb-1.5">Nivel educativo</label>
          <select id="grade_level" v-model="form.grade_level" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
            <option value="primaria">Primaria</option>
            <option value="secundaria">Secundaria</option>
            <option value="universidad">Universidad</option>
          </select>
          <InputError class="mt-1" :message="form.errors.grade_level" />
        </div>
        <div>
          <label for="school" class="block text-sm font-semibold text-slate-700 mb-1.5">Centro educativo</label>
          <input id="school" v-model="form.school" type="text" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" /><InputError class="mt-1" :message="form.errors.school" />
        </div>
        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="px-6 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 disabled:opacity-50 transition-all shadow-sm shadow-brand-600/20">
            Guardar
          </button>
          <Link :href="route('dashboard')" class="px-5 py-2.5 text-sm font-semibold text-slate-600 hover:text-slate-900 transition-colors">Omitir</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
// F-19: mismo hueco que Students/Edit.
import InputError from '@/Components/InputError.vue'

const form = useForm({
  first_name: '',
  last_name: '',
  birth_date: '',
  grade_level: 'primaria',
  school: '',
})

function submit() {
  form.post(route('students.store'))
}
</script>
