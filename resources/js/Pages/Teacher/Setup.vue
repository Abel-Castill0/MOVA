<template>
  <AppLayout title="Configurar perfil">
    <div class="max-w-2xl">
      <h2 class="text-2xl font-bold text-gray-900 mb-2">Configura tu perfil de profesor</h2>
      <p class="text-sm text-gray-500 mb-6">Cuéntanos sobre ti para que los padres puedan encontrarte</p>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Presentación / Bio</label>
          <textarea v-model="form.bio" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" placeholder="Describe tu experiencia y metodología..."></textarea>
        </div>
        <div class="rounded-lg border border-brand-100 bg-brand-50 px-3 py-2.5">
          <p class="text-sm text-brand-900">
            Tu tarifa inicial es <span class="font-bold">S/20 por hora</span>. Aumentará automáticamente según tu experiencia y calificaciones.
          </p>
        </div>
        <div>
          <p class="block text-sm font-medium text-gray-700 mb-1">Asignaturas que impartes</p>
          <p class="text-xs text-gray-500 mb-3">Elige del catálogo de MOVA. Se publicarán automáticamente cuando verifiquemos tu perfil.</p>
          <SubjectPicker v-model="form.subject_ids" :subjects="subjects" input-id="setup-subject-search" />
          <p v-if="form.errors.subject_ids || form.errors['subject_ids.0']" class="text-xs text-red-600 mt-1">{{ form.errors.subject_ids || form.errors['subject_ids.0'] }}</p>
        </div>
        <button type="submit" :disabled="form.processing"
          class="px-6 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 disabled:opacity-50 transition-colors">
          Guardar y continuar
        </button>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import SubjectPicker from '@/Components/SubjectPicker.vue'

const props = defineProps({
  subjects: Array,
  profile: Object,
  // Materias que ya eligió al registrarse: se conservan marcadas.
  selectedSubjectIds: { type: Array, default: () => [] },
})

const form = useForm({
  bio: '',
  mentorship_slots_total: props.profile?.mentorship_slots_total ?? 0,
  subject_ids: [...props.selectedSubjectIds],
})

function submit() {
  form.post(route('teacher.setup.store'))
}
</script>
