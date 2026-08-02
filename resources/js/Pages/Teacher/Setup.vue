<template>
  <AppLayout title="Configurar perfil">
    <div class="max-w-2xl">
      <h2 class="text-2xl font-bold text-gray-900 mb-2">Configura tu perfil de profesor</h2>
      <p class="text-sm text-gray-500 mb-6">Cuéntanos sobre ti para que los padres puedan encontrarte</p>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Presentación / Bio</label>
          <textarea v-model="form.bio" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Describe tu experiencia y metodología..."></textarea>
        </div>
        <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2.5">
          <p class="text-sm text-indigo-900">
            Tu tarifa inicial es <span class="font-bold">S/20 por hora</span>. Aumentará automáticamente según tu experiencia y calificaciones.
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Asignaturas que impartes</label>
          <div v-if="subjects.length" class="grid grid-cols-2 sm:grid-cols-3 gap-2 mb-3">
            <label v-for="s in subjects" :key="s.id"
              :class="['flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer transition-colors text-sm',
                form.subject_ids.includes(s.id) ? 'bg-indigo-50 border-indigo-400 text-indigo-700' : 'bg-white border-gray-200 text-gray-700']">
              <input type="checkbox" :value="s.id" v-model="form.subject_ids" class="sr-only" />
              {{ s.name }}
            </label>
          </div>
          <div class="flex gap-2">
            <input v-model="subjectDraft" type="text" list="subject-suggestions" placeholder="Escribe una materia nueva y presiona Enter"
              class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              @keydown.enter.prevent="addSubjectName" />
            <datalist id="subject-suggestions">
              <option v-for="s in subjects" :key="s.id" :value="s.name" />
            </datalist>
            <button type="button" @click="addSubjectName"
              class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
              Agregar
            </button>
          </div>
          <div v-if="form.subject_names.length" class="flex flex-wrap gap-2 mt-2">
            <button v-for="name in form.subject_names" :key="name" type="button" @click="removeSubjectName(name)"
              class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition-colors">
              {{ name }} ×
            </button>
          </div>
          <p class="text-xs text-gray-500 mt-1">Si no existe, MOVA la creará.</p>
          <p v-if="form.errors.subject_ids" class="text-xs text-red-500 mt-1">{{ form.errors.subject_ids }}</p>
        </div>
        <button type="submit" :disabled="form.processing"
          class="px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
          Guardar y continuar
        </button>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ subjects: Array, profile: Object })

const form = useForm({
  bio: '',
  mentorship_slots_total: props.profile?.mentorship_slots_total ?? 0,
  subject_ids: [],
  subject_names: [],
})

const subjectDraft = ref('')

function addSubjectName() {
  const value = subjectDraft.value.trim()
  if (!value) return

  const existing = props.subjects?.find(s => s.name.toLowerCase() === value.toLowerCase())
  if (existing) {
    if (!form.subject_ids.includes(existing.id)) form.subject_ids.push(existing.id)
  } else if (!form.subject_names.some(n => n.toLowerCase() === value.toLowerCase())) {
    form.subject_names.push(value)
  }
  subjectDraft.value = ''
}

function removeSubjectName(name) {
  form.subject_names = form.subject_names.filter(n => n !== name)
}

function submit() {
  form.post(route('teacher.setup.store'))
}
</script>
