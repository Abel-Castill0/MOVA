<template>
  <AppLayout title="Editar hijo">
    <div class="max-w-lg">
      <h2 class="text-2xl font-black text-slate-900 mb-6">Editar hijo/a</h2>
      <form @submit.prevent="submit" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-5">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Nombre</label>
            <input v-model="form.first_name" type="text" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" /><InputError class="mt-1" :message="form.errors.first_name" />
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Apellidos</label>
            <input v-model="form.last_name" type="text" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" /><InputError class="mt-1" :message="form.errors.last_name" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Fecha de nacimiento</label>
          <input v-model="form.birth_date" type="date" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" /><InputError class="mt-1" :message="form.errors.birth_date" />
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Nivel educativo</label>
          <select v-model="form.grade_level" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
            <option value="primaria">Primaria</option>
            <option value="secundaria">Secundaria</option>
            <option value="universidad">Universidad</option>
          </select>
          <InputError class="mt-1" :message="form.errors.grade_level" />
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Centro educativo</label>
          <input v-model="form.school" type="text" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" /><InputError class="mt-1" :message="form.errors.school" />
        </div>
        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="px-6 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 disabled:opacity-50 transition-all shadow-sm shadow-brand-600/20">
            Guardar cambios
          </button>
          <Link :href="route('students.index')" class="px-5 py-2.5 text-sm font-semibold text-slate-600 hover:text-slate-900 transition-colors">Cancelar</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
// F-19: este formulario no mostraba NINGÚN error de validación: un 422 dejaba
// al padre ante un formulario que no guardaba, sin explicación.
import InputError from '@/Components/InputError.vue'

const props = defineProps({ student: Object })

const form = useForm({
  first_name: props.student.first_name,
  last_name: props.student.last_name,
  birth_date: props.student.birth_date ?? '',
  grade_level: props.student.grade_level,
  school: props.student.school ?? '',
})

function submit() {
  form.patch(route('students.update', props.student.id))
}
</script>
