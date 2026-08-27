<template>
  <AppLayout title="Solicitar clase">
    <div class="max-w-2xl space-y-6">
      <div>
        <h2 class="text-2xl font-black text-ink">Solicitar una clase</h2>
        <p class="text-sm text-ink-muted mt-1">
          Cuéntanos qué necesita tu hijo/a. Un profesor revisará tu solicitud y te
          contactará para coordinar el horario y confirmar los detalles.
        </p>
      </div>

      <!-- Contexto del profesor: solo cuando se llegó con una oferta concreta
           (?offer_id=). La tarifa es una REFERENCIA por hora, nunca "el precio
           de esta clase" — la duración todavía no existe en este punto del
           flujo (se decide recién cuando el profesor acepta y agenda), así
           que no hay forma honesta de mostrar un total. Ver el mapa de
           existencia/finalidad de datos en docs/MOVA_DESIGN_AUDIT_FINAL.md. -->
      <div v-if="offer" class="bg-surface rounded-elevated border border-line shadow-elevation-1 p-5 flex items-start gap-4">
        <div class="w-12 h-12 bg-gradient-to-br from-brand-500 to-brand-700 rounded-control flex items-center justify-center text-white font-black text-base flex-shrink-0">
          {{ offer.teacher_profile?.user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
        </div>
        <div class="min-w-0 flex-1">
          <p class="text-xs font-semibold text-brand-600 uppercase tracking-wide">Solicitud dirigida a</p>
          <p class="font-bold text-ink truncate">{{ offer.teacher_profile?.user?.name }}</p>
          <p class="text-sm text-ink-muted mt-0.5">
            {{ offer.subject?.name }} · S/ {{ referenceRate }}/hora
            <span class="text-ink-subtle">(tarifa referencial, no el precio final)</span>
          </p>
        </div>
      </div>

      <form @submit.prevent="submit" class="bg-surface rounded-elevated border border-line shadow-elevation-1 p-6 space-y-6">
        <!-- Alumno -->
        <div>
          <InputLabel value="Hijo/a" />
          <select v-model="form.student_id" required
            class="mt-1.5 w-full bg-surface text-ink border border-line-strong rounded-control shadow-elevation-1 px-3 py-2.5 text-sm transition-colors duration-micro focus:border-focus-ring focus:ring-2 focus:ring-focus-ring/40 focus:ring-offset-0 focus:outline-none">
            <option value="">Seleccionar...</option>
            <option v-for="s in students" :key="s.id" :value="s.id">{{ s.full_name }}</option>
          </select>
          <InputError :message="form.errors.student_id" class="mt-1" />
        </div>

        <!-- Materia (heredada de la oferta si ya hay una) -->
        <div v-if="!offer">
          <InputLabel value="Materia" />
          <select v-model="form.subject_id" required
            class="mt-1.5 w-full bg-surface text-ink border border-line-strong rounded-control shadow-elevation-1 px-3 py-2.5 text-sm transition-colors duration-micro focus:border-focus-ring focus:ring-2 focus:ring-focus-ring/40 focus:ring-offset-0 focus:outline-none">
            <option value="">Seleccionar...</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
          <InputError :message="form.errors.subject_id" class="mt-1" />
        </div>

        <!-- Código de profesor -->
        <div v-if="!offer">
          <InputLabel value="Código del profesor (opcional)" />
          <p class="text-xs text-ink-subtle mb-1.5 mt-0.5">
            Si un profesor te dio su código, pégalo aquí y la solicitud le llegará
            directo a él, sin pasar por otros profesores.
          </p>
          <input v-model="referralCodeInput" type="text" maxlength="6" placeholder="Ej: WW5VRD"
            class="w-full bg-surface text-ink placeholder:text-ink-subtle border border-line-strong rounded-control shadow-elevation-1 px-3 py-2.5 text-sm uppercase tracking-widest font-mono transition-colors duration-micro focus:border-focus-ring focus:ring-2 focus:ring-focus-ring/40 focus:ring-offset-0 focus:outline-none" />
          <p v-if="codeLookup.status === 'checking'" class="text-xs text-ink-subtle mt-1.5">Buscando...</p>
          <p v-else-if="codeLookup.status === 'found'" class="text-xs text-success-text font-medium mt-1.5 flex items-center gap-1">
            <Icon name="check" :size="14" /> Se enviará a: {{ codeLookup.name }}
          </p>
          <p v-else-if="codeLookup.status === 'not-found'" class="text-xs text-danger-text mt-1.5">
            Código de profesor no encontrado.
          </p>
          <InputError :message="form.errors.teacher_referral_code" class="mt-1" />

          <p v-if="codeLookup.status === 'idle' && !referralCodeInput" class="text-xs text-ink-muted mt-2 bg-canvas rounded-control px-3 py-2 flex items-start gap-2">
            <Icon name="info" :size="14" class="text-ink-subtle flex-shrink-0 mt-0.5" />
            <span>Sin código: tu solicitud quedará visible para todos los profesores de{{ subjectName ? ' ' + subjectName : ' la materia elegida' }}. El primero disponible la aceptará.</span>
          </p>
        </div>

        <!-- Modalidad: dos experiencias de negocio distintas (elegibilidad de
             profesor y cupos, no solo un checkbox decorativo), presentadas
             como una elección real, no un extra opcional escondido. -->
        <div v-if="!offer">
          <InputLabel value="Modalidad" />
          <div class="mt-1.5 grid sm:grid-cols-2 gap-2.5">
            <button type="button" @click="form.is_mentorship = false"
              :aria-pressed="!form.is_mentorship"
              :class="['text-left rounded-control border p-3.5 transition-colors duration-micro',
                !form.is_mentorship ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-line-strong bg-surface hover:border-brand-300']">
              <p class="text-sm font-semibold text-ink">Clase puntual</p>
              <p class="text-xs text-ink-muted mt-0.5">Una clase para resolver algo concreto ahora.</p>
            </button>
            <button type="button" @click="form.is_mentorship = true"
              :aria-pressed="form.is_mentorship"
              :class="['text-left rounded-control border p-3.5 transition-colors duration-micro',
                form.is_mentorship ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-line-strong bg-surface hover:border-brand-300']">
              <p class="text-sm font-semibold text-ink">Acompañamiento continuo</p>
              <p class="text-xs text-ink-muted mt-0.5">Seguimiento regular con el mismo profesor, no solo una clase suelta.</p>
            </button>
          </div>
          <p v-if="form.is_mentorship" class="text-xs text-ink-subtle mt-2">
            No todos los profesores ofrecen acompañamiento continuo — solo quienes tengan cupo disponible podrán aceptar tu solicitud.
          </p>
          <InputError :message="form.errors.is_mentorship" class="mt-1" />
        </div>

        <!-- Qué necesita -->
        <div>
          <InputLabel value="¿En qué necesita ayuda?" />
          <textarea v-model="form.help_needed" rows="4" required
            class="mt-1.5 w-full bg-surface text-ink placeholder:text-ink-subtle border border-line-strong rounded-control shadow-elevation-1 px-3 py-2.5 text-sm transition-colors duration-micro focus:border-focus-ring focus:ring-2 focus:ring-focus-ring/40 focus:ring-offset-0 focus:outline-none"
            placeholder="Ej: Necesita repasar fracciones antes de su examen del jueves."></textarea>
          <InputError :message="form.errors.help_needed" class="mt-1" />
        </div>

        <!-- Preferencia horaria: deliberadamente NO se llama "horario" — es
             una preferencia orientativa (mañana/tarde/noche/flexible), no un
             slot reservado. El horario real se coordina después, con el
             profesor. -->
        <div>
          <InputLabel value="¿Cuándo sueles estar disponible?" />
          <p class="text-xs text-ink-subtle mb-2 mt-0.5">
            Es una preferencia orientativa — el horario exacto se coordina con el profesor después de que acepte tu solicitud.
          </p>
          <TimeSlotPicker v-model="form.preferred_times" />
        </div>

        <!-- Resumen antes de enviar: deliberadamente SIN precio, créditos ni
             duración — ninguno de esos datos existe todavía en este punto
             del flujo (créditos, en particular, es un concepto exclusivo del
             profesor, nunca del padre). Ver el mapa de datos en
             docs/MOVA_DESIGN_AUDIT_FINAL.md antes de agregar cualquier campo
             aquí. -->
        <div class="bg-canvas rounded-control border border-line p-4 space-y-2">
          <p class="text-xs font-semibold text-ink uppercase tracking-wide">Antes de enviar</p>
          <dl class="text-sm space-y-1.5">
            <div class="flex justify-between gap-3">
              <dt class="text-ink-muted">Alumno</dt>
              <dd class="text-ink font-medium text-right">{{ selectedStudentName ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-3">
              <dt class="text-ink-muted">Para</dt>
              <dd class="text-ink font-medium text-right">{{ recipientSummary }}</dd>
            </div>
            <div class="flex justify-between gap-3">
              <dt class="text-ink-muted">Modalidad</dt>
              <dd class="text-ink font-medium text-right">{{ form.is_mentorship ? 'Acompañamiento continuo' : 'Clase puntual' }}</dd>
            </div>
            <div v-if="form.preferred_times.length" class="flex justify-between gap-3">
              <dt class="text-ink-muted">Disponibilidad</dt>
              <dd class="text-ink font-medium text-right">{{ preferredTimesSummary }}</dd>
            </div>
          </dl>
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
          <PrimaryButton type="submit" :loading="form.processing" :disabled="!canSubmit" class="sm:min-w-[200px]">
            <Icon v-if="!form.processing" name="send" :size="16" />
            {{ form.processing ? 'Enviando...' : 'Enviar solicitud' }}
          </PrimaryButton>
          <Link :href="route('marketplace')" class="px-5 py-2.5 text-sm font-semibold text-ink-muted hover:text-ink transition-colors duration-micro text-center">Cancelar</Link>
        </div>
        <p class="text-xs text-ink-subtle flex items-start gap-1.5">
          <Icon name="info" :size="14" class="flex-shrink-0 mt-0.5" />
          <span>Después de enviar, tu solicitud queda abierta a espera de que un profesor la acepte — no se confirma ninguna clase ni se cobra nada todavía.</span>
        </p>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import TimeSlotPicker from '@/Components/TimeSlotPicker.vue'
import Icon from '@/Components/Icon.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { timeSlotLabel } from '@/utils/timeSlots'

const props = defineProps({
  subjects: Array,
  students: Array,
  offer: Object,
  isMentorship: Boolean,
  prefillReferralCode: { type: String, default: null },
  prefillSubjectId: { type: Number, default: null },
})

const form = useForm({
  student_id: '',
  subject_id: props.offer?.subject_id ?? props.prefillSubjectId ?? '',
  class_offer_id: props.offer?.id ?? null,
  teacher_referral_code: '',
  is_mentorship: props.isMentorship ?? false,
  help_needed: '',
  preferred_times: [],
})

const referenceRate = computed(() => parseFloat(props.offer?.specific_rate ?? props.offer?.teacher_profile?.hourly_rate ?? 0).toFixed(0))
const subjectName = computed(() => props.subjects?.find(s => String(s.id) === String(form.subject_id))?.name ?? null)
const selectedStudentName = computed(() => props.students?.find(s => String(s.id) === String(form.student_id))?.full_name ?? null)

const recipientSummary = computed(() => {
  if (props.offer) return props.offer.teacher_profile?.user?.name ?? '—'
  if (codeLookup.value.status === 'found') return codeLookup.value.name
  return `El primer profesor disponible de${subjectName.value ? ' ' + subjectName.value : ' la materia elegida'}`
})

const preferredTimesSummary = computed(() => form.preferred_times.map(timeSlotLabel).join(', '))

// El envío se deshabilita mientras falten los datos mínimos — evita un
// primer intento fallido por campos vacíos que el propio formulario ya
// sabe que van a fallar. No sustituye la validación real del backend,
// solo evita fricción innecesaria.
const canSubmit = computed(() => {
  if (form.processing) return false
  if (!form.student_id || !form.help_needed.trim()) return false
  if (!props.offer && !form.subject_id) return false

  return true
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
