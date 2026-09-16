<template>
  <AppLayout title="Solicitar clase">
    <div class="max-w-2xl space-y-6">

      <!-- ── Cabecera con barra de progreso ──────────────────────────────── -->
      <div>
        <div class="flex items-center justify-between mb-1">
          <h2 class="text-2xl font-black text-ink">Solicitar una clase</h2>
          <span class="text-sm font-semibold text-ink-muted">{{ currentStep }} / {{ TOTAL_STEPS }}</span>
        </div>
        <p class="text-sm text-ink-muted mb-4">
          Cuéntanos qué necesita tu hijo/a — el proceso toma menos de un minuto.
        </p>

        <!-- Barra de progreso brand-600 (#1F5AA6) -->
        <div class="w-full h-1.5 bg-canvas rounded-full overflow-hidden">
          <div
            class="h-full bg-brand-600 rounded-full transition-all duration-300 ease-out"
            :style="{ width: ((currentStep / TOTAL_STEPS) * 100) + '%' }"
          />
        </div>

        <!-- Indicadores de paso numéricos -->
        <div class="flex justify-between mt-2">
          <span v-for="n in TOTAL_STEPS" :key="n"
            :class="['text-xs font-semibold transition-colors duration-150',
              n < currentStep  ? 'text-brand-600' :
              n === currentStep ? 'text-brand-700 font-bold' :
              'text-ink-subtle']">
            {{ STEP_LABELS[n - 1] }}
          </span>
        </div>
      </div>

      <!-- ── Contexto del profesor (solo si se llegó con offer) ──────────── -->
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

      <!-- ── Pasos con transición ─────────────────────────────────────────── -->
      <div class="relative overflow-hidden">
        <Transition :name="transitionName">

          <!-- PASO 1: Hijo y Materia ─────────────────────────────────────── -->
          <div v-if="currentStep === 1" key="step1" class="bg-surface rounded-elevated border border-line shadow-elevation-1 p-6 space-y-5">
            <div>
              <h3 class="text-base font-bold text-ink mb-1">¿Para quién es la clase?</h3>
              <p class="text-xs text-ink-muted">Elige el alumno y la materia.</p>
            </div>

            <!-- Hijo/a -->
            <div>
              <InputLabel value="Hijo/a" />
              <div class="mt-1.5 grid sm:grid-cols-2 gap-2.5">
                <button
                  v-for="s in students" :key="s.id"
                  type="button"
                  @click="form.student_id = s.id"
                  :aria-pressed="form.student_id === s.id"
                  :class="['text-left rounded-control border p-3.5 transition-colors duration-micro',
                    form.student_id === s.id
                      ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500'
                      : 'border-line-strong bg-surface hover:border-brand-300']">
                  <p class="font-semibold text-sm text-ink">{{ s.full_name }}</p>
                </button>
              </div>
              <InputError :message="form.errors.student_id" class="mt-1" />
            </div>

            <!-- Materia (si no hay offer) -->
            <div v-if="!offer">
              <InputLabel value="Materia" />
              <div class="mt-1.5 grid sm:grid-cols-2 gap-2">
                <button
                  v-for="s in subjects" :key="s.id"
                  type="button"
                  @click="form.subject_id = s.id"
                  :aria-pressed="form.subject_id === s.id"
                  :class="['text-left rounded-control border p-3 transition-colors duration-micro text-sm',
                    form.subject_id === s.id
                      ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500 font-semibold text-brand-700'
                      : 'border-line-strong bg-surface hover:border-brand-300 text-ink']">
                  {{ s.name }}
                </button>
              </div>
              <InputError :message="form.errors.subject_id" class="mt-1" />
            </div>

            <div class="flex justify-end pt-1">
              <button type="button" @click="goNext"
                :disabled="!step1Valid"
                class="px-6 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-control hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors duration-micro">
                Siguiente →
              </button>
            </div>
          </div>

          <!-- PASO 2: Código de profesor ───────────────────────────────────── -->
          <div v-else-if="currentStep === 2" key="step2" class="bg-surface rounded-elevated border border-line shadow-elevation-1 p-6 space-y-5">
            <div>
              <h3 class="text-base font-bold text-ink mb-1">¿Tienes el código de un profesor?</h3>
              <p class="text-xs text-ink-muted">Si un profesor te dio su código, tu solicitud le llegará directo a él.</p>
            </div>

            <div>
              <InputLabel value="Código del profesor (opcional)" />
              <input v-model="referralCodeInput" type="text" maxlength="6" placeholder="Ej: WW5VRD"
                class="mt-1.5 w-full bg-surface text-ink placeholder:text-ink-subtle border border-line-strong rounded-control shadow-elevation-1 px-3 py-2.5 text-sm uppercase tracking-widest font-mono transition-colors duration-micro focus:border-focus-ring focus:ring-2 focus:ring-focus-ring/40 focus:ring-offset-0 focus:outline-none" />
              <p v-if="codeLookup.status === 'checking'" class="text-xs text-ink-subtle mt-1.5">Buscando...</p>
              <p v-else-if="codeLookup.status === 'found'" class="text-xs text-success-text font-medium mt-1.5 flex items-center gap-1">
                <Icon name="check" :size="14" /> Se enviará a: {{ codeLookup.name }}
              </p>
              <p v-else-if="codeLookup.status === 'not-found'" class="text-xs text-danger-text mt-1.5">
                Código de profesor no encontrado.
              </p>
              <InputError :message="form.errors.teacher_referral_code" class="mt-1" />

              <p v-if="codeLookup.status === 'idle' && !referralCodeInput" class="text-xs text-ink-muted mt-3 bg-canvas rounded-control px-3 py-2 flex items-start gap-2">
                <Icon name="info" :size="14" class="text-ink-subtle flex-shrink-0 mt-0.5" />
                <span>Sin código: tu solicitud quedará visible para todos los profesores de{{ subjectName ? ' ' + subjectName : ' la materia elegida' }}.</span>
              </p>
            </div>

            <div class="flex items-center justify-between pt-1">
              <button type="button" @click="goPrev" class="text-sm text-ink-muted hover:text-ink transition-colors duration-micro">
                ← Volver
              </button>
              <div class="flex gap-2">
                <button type="button" @click="skipCode"
                  class="px-4 py-2.5 text-sm text-ink-muted border border-line rounded-control hover:border-line-strong transition-colors duration-micro">
                  Omitir
                </button>
                <button type="button" @click="goNext"
                  :disabled="codeLookup.status === 'checking'"
                  class="px-6 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-control hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors duration-micro">
                  Siguiente →
                </button>
              </div>
            </div>
          </div>

          <!-- PASO 3: Modalidad ─────────────────────────────────────────────── -->
          <div v-else-if="currentStep === 3" key="step3" class="bg-surface rounded-elevated border border-line shadow-elevation-1 p-6 space-y-5">
            <div>
              <h3 class="text-base font-bold text-ink mb-1">¿Qué tipo de apoyo necesita?</h3>
              <p class="text-xs text-ink-muted">Elige una opción para continuar automáticamente.</p>
            </div>

            <div class="grid sm:grid-cols-2 gap-3">
              <button type="button" @click="selectModality(false)"
                :aria-pressed="!form.is_mentorship"
                :class="['text-left rounded-control border p-4 transition-colors duration-micro',
                  !form.is_mentorship ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-line-strong bg-surface hover:border-brand-300']">
                <p class="font-semibold text-ink">Clase puntual</p>
                <p class="text-xs text-ink-muted mt-1">Una clase para resolver algo concreto ahora.</p>
              </button>
              <button type="button" @click="selectModality(true)"
                :aria-pressed="form.is_mentorship"
                :class="['text-left rounded-control border p-4 transition-colors duration-micro',
                  form.is_mentorship ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-line-strong bg-surface hover:border-brand-300']">
                <p class="font-semibold text-ink">Acompañamiento continuo</p>
                <p class="text-xs text-ink-muted mt-1">Seguimiento regular con el mismo profesor.</p>
              </button>
            </div>

            <div class="flex items-center justify-between pt-1">
              <button type="button" @click="goPrev" class="text-sm text-ink-muted hover:text-ink transition-colors duration-micro">
                ← Volver
              </button>
            </div>
          </div>

          <!-- PASO 4: Descripción y disponibilidad ─────────────────────────── -->
          <div v-else-if="currentStep === 4" key="step4" class="bg-surface rounded-elevated border border-line shadow-elevation-1 p-6 space-y-5">
            <div>
              <h3 class="text-base font-bold text-ink mb-1">Cuéntanos un poco más</h3>
              <p class="text-xs text-ink-muted">Describe la situación y cuándo suele estar disponible.</p>
            </div>

            <!-- Qué necesita -->
            <div>
              <InputLabel value="¿En qué necesita ayuda?" />
              <textarea v-model="form.help_needed" rows="4" required
                class="mt-1.5 w-full bg-surface text-ink placeholder:text-ink-subtle border border-line-strong rounded-control shadow-elevation-1 px-3 py-2.5 text-sm transition-colors duration-micro focus:border-focus-ring focus:ring-2 focus:ring-focus-ring/40 focus:ring-offset-0 focus:outline-none"
                placeholder="Ej: Necesita repasar fracciones antes de su examen del jueves."></textarea>
              <InputError :message="form.errors.help_needed" class="mt-1" />
            </div>

            <!-- Preferencia horaria -->
            <div>
              <InputLabel value="¿Cuándo suele estar disponible?" />
              <p class="text-xs text-ink-subtle mb-2 mt-0.5">
                Preferencia orientativa — el horario exacto se coordina después con el profesor.
              </p>
              <TimeSlotPicker v-model="form.preferred_times" />
            </div>

            <div class="flex items-center justify-between pt-1">
              <button type="button" @click="goPrev" class="text-sm text-ink-muted hover:text-ink transition-colors duration-micro">
                ← Volver
              </button>
              <button type="button" @click="goNext"
                :disabled="!step4Valid"
                class="px-6 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-control hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors duration-micro">
                Ver resumen →
              </button>
            </div>
          </div>

          <!-- PASO 5: Resumen + enviar ────────────────────────────────────── -->
          <div v-else-if="currentStep === 5" key="step5" class="bg-surface rounded-elevated border border-line shadow-elevation-1 p-6 space-y-5">
            <div>
              <h3 class="text-base font-bold text-ink mb-1">Revisa y envía</h3>
              <p class="text-xs text-ink-muted">Confirma que todo esté correcto antes de enviar.</p>
            </div>

            <!-- Resumen — sin precio, créditos ni duración (no existen aún) -->
            <dl class="divide-y divide-line text-sm">
              <div class="flex justify-between gap-3 py-2.5">
                <dt class="text-ink-muted">Alumno</dt>
                <dd class="text-ink font-semibold text-right">{{ selectedStudentName ?? '—' }}</dd>
              </div>
              <div class="flex justify-between gap-3 py-2.5">
                <dt class="text-ink-muted">Materia</dt>
                <dd class="text-ink font-semibold text-right">{{ offer?.subject?.name ?? subjectName ?? '—' }}</dd>
              </div>
              <div class="flex justify-between gap-3 py-2.5">
                <dt class="text-ink-muted">Profesor</dt>
                <dd class="text-ink font-semibold text-right">{{ recipientSummary }}</dd>
              </div>
              <div class="flex justify-between gap-3 py-2.5">
                <dt class="text-ink-muted">Modalidad</dt>
                <dd class="text-ink font-semibold text-right">{{ form.is_mentorship ? 'Acompañamiento continuo' : 'Clase puntual' }}</dd>
              </div>
              <div v-if="form.preferred_times.length" class="flex justify-between gap-3 py-2.5">
                <dt class="text-ink-muted">Disponibilidad</dt>
                <dd class="text-ink font-semibold text-right max-w-[60%]">{{ preferredTimesSummary }}</dd>
              </div>
              <div class="py-2.5">
                <dt class="text-ink-muted mb-1">Descripción</dt>
                <dd class="text-ink text-sm line-clamp-3">{{ form.help_needed }}</dd>
              </div>
            </dl>

            <p class="text-xs text-ink-subtle flex items-start gap-1.5 bg-canvas rounded-control px-3 py-2">
              <Icon name="info" :size="14" class="flex-shrink-0 mt-0.5" />
              <span>Al enviar, la solicitud queda abierta. No se confirma ninguna clase ni se cobra nada todavía.</span>
            </p>

            <div class="flex flex-col sm:flex-row gap-3 pt-1">
              <!-- Botón naranja (#F59E0B = accent-500) para la acción principal final -->
              <button type="button" @click="submit"
                :disabled="form.processing"
                class="flex items-center justify-center gap-2 px-6 py-3 bg-accent-500 text-white text-sm font-bold rounded-control hover:bg-accent-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-micro shadow-elevation-1 sm:min-w-[200px]">
                <Icon v-if="!form.processing" name="send" :size="16" />
                {{ form.processing ? 'Enviando...' : 'Enviar solicitud' }}
              </button>
              <button type="button" @click="goPrev"
                class="px-5 py-2.5 text-sm font-semibold text-ink-muted hover:text-ink transition-colors duration-micro text-center">
                ← Volver
              </button>
            </div>
          </div>

        </Transition>
      </div>

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
import { timeSlotLabel } from '@/utils/timeSlots'

// ── Props ───────────────────────────────────────────────────────────────────
const props = defineProps({
  subjects:          Array,
  students:          Array,
  offer:             Object,
  isMentorship:      Boolean,
  prefillReferralCode: { type: String, default: null },
  prefillSubjectId:    { type: Number, default: null },
})

// ── Wizard ─────────────────────────────────────────────────────────────────
const TOTAL_STEPS = 5
const STEP_LABELS = ['Alumno', 'Profesor', 'Modalidad', 'Detalles', 'Resumen']
const currentStep = ref(1)
// 'forward' = animación hacia la izquierda, 'back' = hacia la derecha
const direction   = ref('forward')

const transitionName = computed(() =>
  direction.value === 'forward' ? 'slide-step' : 'slide-step-back'
)

function goNext() {
  if (currentStep.value < TOTAL_STEPS) {
    direction.value = 'forward'
    currentStep.value++
  }
}

function goPrev() {
  if (currentStep.value > 1) {
    direction.value = 'back'
    currentStep.value--
  }
}

// Paso 3: seleccionar modalidad avanza automáticamente
function selectModality(isMentorship) {
  form.is_mentorship = isMentorship
  direction.value = 'forward'
  currentStep.value = 4
}

// Paso 2: omitir el código limpia el campo y avanza
function skipCode() {
  referralCodeInput.value = ''
  form.teacher_referral_code = ''
  codeLookup.value = { status: 'idle', name: null }
  goNext()
}

// ── Validaciones por paso ──────────────────────────────────────────────────
const step1Valid = computed(() => {
  if (!form.student_id) return false
  if (!props.offer && !form.subject_id) return false
  return true
})

const step4Valid = computed(() => form.help_needed.trim().length >= 10)

// ── Form (mismo useForm que antes — el backend no cambia) ─────────────────
const form = useForm({
  student_id:          '',
  subject_id:          props.offer?.subject_id ?? props.prefillSubjectId ?? '',
  class_offer_id:      props.offer?.id ?? null,
  teacher_referral_code: '',
  is_mentorship:       props.isMentorship ?? false,
  help_needed:         '',
  preferred_times:     [],
})

// ── Computed ───────────────────────────────────────────────────────────────
const referenceRate      = computed(() => parseFloat(props.offer?.specific_rate ?? props.offer?.teacher_profile?.hourly_rate ?? 0).toFixed(0))
const subjectName        = computed(() => props.subjects?.find(s => String(s.id) === String(form.subject_id))?.name ?? null)
const selectedStudentName = computed(() => props.students?.find(s => String(s.id) === String(form.student_id))?.full_name ?? null)

const recipientSummary = computed(() => {
  if (props.offer) return props.offer.teacher_profile?.user?.name ?? '—'
  if (codeLookup.value.status === 'found') return codeLookup.value.name
  return `El primer profesor disponible de${subjectName.value ? ' ' + subjectName.value : ' la materia elegida'}`
})

const preferredTimesSummary = computed(() => form.preferred_times.map(timeSlotLabel).join(', '))

// ── Lookup de código (igual que antes) ────────────────────────────────────
const referralCodeInput = ref(props.prefillReferralCode ?? '')
const codeLookup        = ref({ status: 'idle', name: null })
let lookupTimer         = null

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
  if (referralCodeInput.value) {
    form.teacher_referral_code = referralCodeInput.value
    lookupCode(referralCodeInput.value)
  }
})

// ── Submit (igual que antes) ───────────────────────────────────────────────
function submit() {
  form.post(route('class-requests.store'))
}
</script>

<style scoped>
/* Transición hacia adelante: nuevo panel entra desde la derecha */
.slide-step-enter-active,
.slide-step-leave-active {
  transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
  position: absolute;
  width: 100%;
}
.slide-step-enter-from { opacity: 0; transform: translateX(40px); }
.slide-step-enter-to   { opacity: 1; transform: translateX(0); }
.slide-step-leave-from { opacity: 1; transform: translateX(0); }
.slide-step-leave-to   { opacity: 0; transform: translateX(-40px); }

/* Transición hacia atrás: nuevo panel entra desde la izquierda */
.slide-step-back-enter-active,
.slide-step-back-leave-active {
  transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
  position: absolute;
  width: 100%;
}
.slide-step-back-enter-from { opacity: 0; transform: translateX(-40px); }
.slide-step-back-enter-to   { opacity: 1; transform: translateX(0); }
.slide-step-back-leave-from { opacity: 1; transform: translateX(0); }
.slide-step-back-leave-to   { opacity: 0; transform: translateX(40px); }
</style>
