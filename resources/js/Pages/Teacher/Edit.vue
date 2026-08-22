<template>
  <AppLayout title="Mi perfil">
    <div class="max-w-2xl">
      <h2 class="text-2xl font-bold text-gray-900 mb-6">Editar perfil</h2>

      <!-- Tu código de profesor: único lugar donde el profesor lo encuentra
           para compartirlo (fuera de la app — WhatsApp, en persona, etc.)
           con un alumno que quiere volver a elegirlo directamente. No es un
           campo editable, así que vive fuera del <form> de abajo. -->
      <div v-if="profile?.referral_code" class="bg-brand-50 border border-brand-100 rounded-xl p-4 mb-5 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <p class="text-sm font-semibold text-brand-900">Tu código de profesor</p>
          <p class="text-xs text-brand-700 mt-0.5">Compártelo con un alumno después de su primera clase para que pueda pedirte directamente la próxima vez.</p>
        </div>
        <button type="button" @click="copyReferralCode"
          class="flex items-center gap-2 px-4 py-2 bg-white border border-brand-200 text-brand-700 text-sm font-bold rounded-xl hover:bg-brand-100 transition-colors whitespace-nowrap">
          <span v-if="copied">✓ Copiado</span>
          <span v-else class="font-mono tracking-widest">{{ profile.referral_code }}</span>
        </button>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Bio</label>
          <textarea v-model="form.bio" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tarifa por hora</label>
          <div class="flex items-center gap-3 px-3 py-2.5 rounded-lg border border-gray-200 bg-gray-50">
            <span class="text-lg font-bold text-gray-900">S/ {{ Number(props.profile?.hourly_rate ?? 20).toFixed(2) }}</span>
            <span :class="tier.badgeClass" class="px-2.5 py-0.5 rounded-full text-xs font-semibold">{{ tier.label }}</span>
          </div>
          <p class="text-xs text-gray-500 mt-1">Tu tarifa se actualiza automáticamente según tus clases completadas y calificación promedio.</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Número Yape</label>
            <input v-model="form.yape_number" type="text" maxlength="20" placeholder="999 999 999"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            <p v-if="form.errors.yape_number" class="text-xs text-red-500 mt-1">{{ form.errors.yape_number }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Número Plin</label>
            <input v-model="form.plin_number" type="text" maxlength="20" placeholder="999 999 999"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            <p v-if="form.errors.plin_number" class="text-xs text-red-500 mt-1">{{ form.errors.plin_number }}</p>
          </div>
          <p class="sm:col-span-2 text-xs text-gray-500">
            Los padres verán estos números para confirmar el pago offline de tus clases.
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Asignaturas</label>
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
        </div>
        <button type="submit" :disabled="form.processing"
          class="px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
          Guardar cambios
        </button>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ profile: Object, subjects: Array })

const form = useForm({
  bio: props.profile?.bio ?? '',
  yape_number: props.profile?.yape_number ?? '',
  plin_number: props.profile?.plin_number ?? '',
  mentorship_slots_total: props.profile?.mentorship_slots_total ?? 0,
  subject_ids: props.profile?.subjects?.map(s => s.id) ?? [],
  subject_names: [],
})

const tier = computed(() => {
  const rate = Number(props.profile?.hourly_rate ?? 20)
  if (rate >= 30) return { label: 'Élite', badgeClass: 'bg-amber-100 text-amber-700' }
  if (rate >= 25) return { label: 'Experto', badgeClass: 'bg-indigo-100 text-indigo-700' }
  return { label: 'Base', badgeClass: 'bg-gray-200 text-gray-700' }
})

const copied = ref(false)
async function copyReferralCode() {
  try {
    await navigator.clipboard.writeText(props.profile.referral_code)
  } catch {
    return // portapapeles no disponible — sin feedback falso
  }
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}

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
  form.patch(route('teacher.profile.update'))
}
</script>
