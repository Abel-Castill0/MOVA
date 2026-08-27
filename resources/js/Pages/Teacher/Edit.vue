<template>
  <AppLayout title="Mi perfil">
    <div class="max-w-2xl space-y-5">
      <h2 class="text-2xl font-black text-slate-900">Editar perfil</h2>

      <!-- Foto de perfil: mismo componente que Profile/Edit.vue (genérico,
           lee auth.user directo) — antes solo vivía en /profile, una página
           que un profesor nunca visita desde su navegación normal. -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <UpdateAvatarForm />
      </div>

      <!-- Tu código de profesor: único lugar donde el profesor lo encuentra
           para compartirlo (fuera de la app — WhatsApp, en persona, etc.)
           con un alumno que quiere volver a elegirlo directamente. No es un
           campo editable, así que vive fuera del <form> de abajo. -->
      <div v-if="profile?.referral_code" class="bg-brand-50 border border-brand-100 rounded-2xl p-5 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <p class="text-sm font-semibold text-brand-900">Tu código de profesor</p>
          <p class="text-xs text-brand-700 mt-0.5">Compártelo con un alumno después de su primera clase para que pueda pedirte directamente la próxima vez.</p>
        </div>
        <button type="button" @click="copyReferralCode"
          class="flex items-center gap-2 px-4 py-2 bg-white border border-brand-200 text-brand-700 text-sm font-bold rounded-xl hover:bg-brand-100 active:scale-95 transition-all whitespace-nowrap">
          <span v-if="copied" class="inline-flex items-center gap-1.5"><Icon name="check" :size="16" /> Copiado</span>
          <span v-else class="font-mono tracking-widest">{{ profile.referral_code }}</span>
        </button>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-6">
        <div>
          <h3 class="text-sm font-bold text-slate-900 mb-1">Sobre ti</h3>
          <label class="block text-xs font-semibold text-slate-500 mb-1.5">Bio</label>
          <textarea v-model="form.bio" rows="4" placeholder="Cuéntales a los padres tu experiencia enseñando esta materia..."
            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"></textarea>
        </div>

        <div class="pt-1 border-t border-gray-50">
          <h3 class="text-sm font-bold text-slate-900 mb-2 mt-4">Tarifa automática</h3>
          <div class="flex items-center gap-3 px-3.5 py-3 rounded-xl border border-gray-200 bg-slate-50">
            <span class="text-lg font-black text-slate-900">S/ {{ Number(props.profile?.hourly_rate ?? 20).toFixed(2) }}</span>
            <span :class="tier.badgeClass" class="px-2.5 py-0.5 rounded-full text-xs font-bold">{{ tier.label }}</span>
          </div>
          <p class="text-xs text-slate-400 mt-1.5">Tu tarifa se actualiza automáticamente según tus clases completadas y calificación promedio.</p>
        </div>

        <div class="pt-1 border-t border-gray-50">
          <h3 class="text-sm font-bold text-slate-900 mb-2 mt-4">Métodos de pago</h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-500 mb-1.5">Número Yape</label>
              <input v-model="form.yape_number" type="text" maxlength="20" placeholder="999 999 999"
                class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" />
              <p v-if="form.errors.yape_number" class="text-xs text-red-500 mt-1">{{ form.errors.yape_number }}</p>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 mb-1.5">Número Plin</label>
              <input v-model="form.plin_number" type="text" maxlength="20" placeholder="999 999 999"
                class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" />
              <p v-if="form.errors.plin_number" class="text-xs text-red-500 mt-1">{{ form.errors.plin_number }}</p>
            </div>
          </div>
          <p class="text-xs text-slate-400 mt-2">Los padres verán estos números para confirmar el pago offline de tus clases.</p>
        </div>

        <div class="pt-1 border-t border-gray-50">
          <h3 class="text-sm font-bold text-slate-900 mb-2 mt-4">Materias</h3>
          <div v-if="subjects.length" class="grid grid-cols-2 sm:grid-cols-3 gap-2 mb-3">
            <label v-for="s in subjects" :key="s.id"
              :class="['flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer transition-colors text-sm',
                form.subject_ids.includes(s.id) ? 'bg-brand-50 border-brand-300 text-brand-700' : 'bg-white border-gray-200 text-slate-700 hover:border-gray-300']">
              <input type="checkbox" :value="s.id" v-model="form.subject_ids" class="sr-only" />
              {{ s.name }}
            </label>
          </div>
          <div class="flex gap-2">
            <input v-model="subjectDraft" type="text" list="subject-suggestions" placeholder="Escribe una materia nueva y presiona Enter"
              class="flex-1 border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
              @keydown.enter.prevent="addSubjectName" />
            <datalist id="subject-suggestions">
              <option v-for="s in subjects" :key="s.id" :value="s.name" />
            </datalist>
            <button type="button" @click="addSubjectName"
              class="px-4 py-2 rounded-xl border border-gray-200 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
              Agregar
            </button>
          </div>
          <div v-if="form.subject_names.length" class="flex flex-wrap gap-2 mt-2">
            <button v-for="name in form.subject_names" :key="name" type="button" @click="removeSubjectName(name)"
              class="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 hover:bg-brand-100 transition-colors">
              {{ name }} ×
            </button>
          </div>
          <p class="text-xs text-slate-400 mt-1.5">Si no existe, MOVA la creará. Tu código de profesor ya se generó automáticamente — no depende de las materias que elijas.</p>
        </div>

        <button type="submit" :disabled="form.processing"
          class="px-6 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 disabled:opacity-50 transition-all shadow-sm shadow-brand-600/20">
          {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
        </button>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import UpdateAvatarForm from '@/Pages/Profile/Partials/UpdateAvatarForm.vue'
import Icon from '@/Components/Icon.vue'

const props = defineProps({ profile: Object, subjects: Array })

const form = useForm({
  bio: props.profile?.bio ?? '',
  yape_number: props.profile?.yape_number ?? '',
  plin_number: props.profile?.plin_number ?? '',
  mentorship_slots_total: props.profile?.mentorship_slots_total ?? 0,
  subject_ids: props.profile?.subjects?.map(s => s.id) ?? [],
  subject_names: [],
})

// Escala de tiers, no un color suelto: gris (Base) → azul (Experto) → ámbar
// (Élite) es una progresión de intensidad reconocible (como medallas
// bronce/plata/oro), no "indigo porque estaba disponible" — el indigo
// original no codificaba ningún significado propio de este sistema.
const tier = computed(() => {
  const rate = Number(props.profile?.hourly_rate ?? 20)
  if (rate >= 30) return { label: 'Élite', badgeClass: 'bg-amber-100 text-amber-700' }
  if (rate >= 25) return { label: 'Experto', badgeClass: 'bg-blue-100 text-blue-700' }
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
