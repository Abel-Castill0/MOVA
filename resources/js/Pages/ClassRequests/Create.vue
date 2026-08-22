<template>
  <AppLayout title="Solicitar clase">
    <div class="max-w-2xl">
      <h2 class="text-2xl font-black text-slate-900 mb-6">Solicitar clase</h2>

      <div v-if="offer" class="bg-brand-50 border border-brand-200 rounded-2xl p-4 mb-6">
        <p class="text-sm font-medium text-brand-700">Clase con: {{ offer.teacher_profile?.user?.name }}</p>
        <p class="text-sm text-brand-600">
          {{ offer.subject?.name }} · S/ {{ parseFloat(offer.specific_rate ?? offer.teacher_profile?.hourly_rate ?? 0).toFixed(0) }}/h
        </p>
        <p v-if="form.is_mentorship" class="mt-2 inline-flex rounded-lg bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
          Solicitud de acompañamiento continuo
        </p>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-5">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Hijo/a</label>
          <select v-model="form.student_id" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
            <option value="">Seleccionar...</option>
            <option v-for="s in students" :key="s.id" :value="s.id">{{ s.full_name }}</option>
          </select>
          <p v-if="form.errors.student_id" class="text-xs text-red-500 mt-1">{{ form.errors.student_id }}</p>
        </div>
        <div v-if="!offer">
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Asignatura</label>
          <select v-model="form.subject_id" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
            <option value="">Seleccionar...</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>

        <div v-if="!offer">
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Código del profesor (opcional)</label>
          <p class="text-xs text-slate-400 mb-1.5">Si un profesor te dio su código, pégalo aquí y la solicitud le llegará directo a él, sin pasar por otros profesores.</p>
          <input v-model="referralCodeInput" type="text" maxlength="6" placeholder="Ej: WW5VRD"
            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm uppercase tracking-widest font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition" />
          <p v-if="codeLookup.status === 'checking'" class="text-xs text-slate-400 mt-1.5">Buscando...</p>
          <p v-else-if="codeLookup.status === 'found'" class="text-xs text-emerald-600 font-medium mt-1.5">
            ✓ Se enviará a: {{ codeLookup.name }}
          </p>
          <p v-else-if="codeLookup.status === 'not-found'" class="text-xs text-red-500 mt-1.5">
            Código de profesor no encontrado.
          </p>
          <p v-if="form.errors.teacher_referral_code" class="text-xs text-red-500 mt-1">{{ form.errors.teacher_referral_code }}</p>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">¿En qué necesita ayuda?</label>
          <textarea v-model="form.help_needed" rows="4" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"></textarea>
          <p v-if="form.errors.help_needed" class="text-xs text-red-500 mt-1">{{ form.errors.help_needed }}</p>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-2">Disponibilidad horaria</label>
          <TimeSlotPicker v-model="form.preferred_times" />
        </div>
        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="px-6 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 disabled:opacity-50 transition-all shadow-sm shadow-brand-600/20">
            Enviar solicitud
          </button>
          <Link :href="route('marketplace')" class="px-6 py-2.5 text-sm font-semibold text-slate-600 hover:text-slate-900 transition-colors">Cancelar</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import TimeSlotPicker from '@/Components/TimeSlotPicker.vue'

const props = defineProps({
  subjects: Array,
  students: Array,
  offer: Object,
  isMentorship: Boolean,
  prefillReferralCode: { type: String, default: null },
})

const form = useForm({
  student_id: '',
  subject_id: props.offer?.subject_id ?? '',
  class_offer_id: props.offer?.id ?? null,
  teacher_referral_code: '',
  is_mentorship: props.isMentorship ?? false,
  help_needed: '',
  preferred_times: [],
})

const referralCodeInput = ref(props.prefillReferralCode ?? '')
// status: 'idle' | 'checking' | 'found' | 'not-found'
const codeLookup = ref({ status: 'idle', name: null })
let lookupTimer = null

// Búsqueda en vivo, debounced — solo feedback (Fase UX), la resolución real
// vuelve a ocurrir en el backend dentro de store(), nunca confía en esto.
async function lookupCode(code) {
  clearTimeout(lookupTimer)

  if (code.length !== 6) {
    codeLookup.value = { status: 'idle', name: null }
    return
  }

  codeLookup.value = { status: 'checking', name: null }
  lookupTimer = setTimeout(async () => {
    try {
      const { data } = await axios.get(route('class-requests.lookup-code'), { params: { code } })
      codeLookup.value = data.found ? { status: 'found', name: data.name } : { status: 'not-found', name: null }
    } catch {
      codeLookup.value = { status: 'idle', name: null }
    }
  }, 400)
}

watch(referralCodeInput, (value) => {
  const code = value.trim().toUpperCase()
  referralCodeInput.value = code
  form.teacher_referral_code = code
  lookupCode(code)
})

onMounted(() => {
  // ?code= desde "Solicitar clase" en el marketplace — dispara la búsqueda
  // de una vez, sin esperar a que el padre toque el input.
  if (referralCodeInput.value) {
    form.teacher_referral_code = referralCodeInput.value
    lookupCode(referralCodeInput.value)
  }
})

function submit() {
  form.post(route('class-requests.store'))
}
</script>
