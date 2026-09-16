<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Icon from '@/Components/Icon.vue'
import InputError from '@/Components/InputError.vue'
import Modal from '@/Components/Modal.vue'
import MovaLogo from '@/Components/MovaLogo.vue'
import RegisterVisualPanel from '@/Components/RegisterVisualPanel.vue'

const props = defineProps({
  lockedRole: { type: String, default: null },
})

const year = new Date().getFullYear()
const showGoogleModal = ref(false)
const showPassword = ref(false)
const showConfirmPassword = ref(false)
const subjectDraft = ref('')

const roleLocked = computed(() => props.lockedRole === 'parent' || props.lockedRole === 'teacher')

const form = useForm({
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  role: props.lockedRole ?? 'parent',
  teacher_subject_names: [],
  accepted_terms: false,
})

const totalSteps = computed(() => form.role === 'teacher' ? 6 : 5)
const passwordStep = computed(() => form.role === 'teacher' ? 5 : 4)
const step = ref(1)

let bodyObserver = null
onMounted(() => {
  if (window.innerWidth >= 1024) {
    document.body.style.paddingBottom = '0px'
    bodyObserver = new MutationObserver(() => {
      if (document.body.style.paddingBottom && document.body.style.paddingBottom !== '0px') {
        document.body.style.paddingBottom = '0px'
      }
    })
    bodyObserver.observe(document.body, { attributes: true, attributeFilter: ['style'] })
  }
})

onUnmounted(() => {
  if (bodyObserver) bodyObserver.disconnect()
  document.body.style.paddingBottom = ''
})

const isCurrentStepValid = computed(() => {
  if (step.value === 1) return form.role === 'parent' || form.role === 'teacher'
  if (step.value === 2) return form.name.trim().length > 0
  if (step.value === 3) return /^\S+@\S+\.\S+$/.test(form.email.trim())
  if (step.value === 4 && form.role === 'teacher') {
    return form.teacher_subject_names.length > 0 || subjectDraft.value.trim().length > 0
  }
  if (step.value === passwordStep.value) {
    return form.password.length >= 8 && form.password === form.password_confirmation
  }
  if (step.value === totalSteps.value) return form.accepted_terms
  return true
})

function addSubject() {
  const value = subjectDraft.value.trim()
  if (!value) return
  if (!form.teacher_subject_names.some((s) => s.toLowerCase() === value.toLowerCase())) {
    form.teacher_subject_names.push(value)
  }
  subjectDraft.value = ''
}

function removeSubject(subject) {
  form.teacher_subject_names = form.teacher_subject_names.filter((s) => s !== subject)
}

function next() {
  if (step.value === 4 && form.role === 'teacher') addSubject()
  if (step.value < totalSteps.value) step.value += 1
}

function back() {
  if (step.value > 1) step.value -= 1
}

function stepForField(field) {
  if (field === 'role') return 1
  if (field === 'name') return 2
  if (field === 'email' || field === 'phone') return 3
  if (field === 'teacher_subject_names' || field.startsWith('teacher_subject_names.')) return 4
  if (field === 'password' || field === 'password_confirmation') return passwordStep.value
  if (field === 'accepted_terms') return totalSteps.value
  return step.value
}

function submit() {
  form.post(route('register'), {
    onError: (errors) => {
      const fields = Object.keys(errors)
      if (fields.length === 0) return
      step.value = Math.min(...fields.map(stepForField))
    },
  })
}
</script>

<template>
  <Head title="Crear cuenta – MOVA" />

  <div class="min-h-screen lg:h-screen flex flex-col lg:flex-row bg-[#F8FAFC] overflow-x-hidden lg:overflow-hidden">
    <!-- PANEL IZQUIERDO: Branding & Ilustración (~47%) -->
    <RegisterVisualPanel :year="year" />

    <!-- PANEL DERECHO: Formulario de Registro Minimalista (~53%) -->
    <div class="flex-1 flex flex-col justify-between p-4 sm:p-6 lg:p-8 xl:p-9 min-h-screen lg:min-h-0 lg:overflow-y-auto relative bg-[#F8FAFC] overflow-x-hidden">
      <!-- Glows decorativos de fondo -->
      <div class="hidden lg:block absolute -top-24 -right-24 w-96 h-96 rounded-full bg-blue-100/40 pointer-events-none blur-3xl z-0" />
      <div class="hidden lg:block absolute -top-20 -left-20 w-80 h-80 rounded-full bg-blue-50/50 pointer-events-none blur-2xl z-0" />

      <!-- Header Móvil: Visible en pantallas pequeñas -->
      <header class="lg:hidden flex items-center justify-between pb-3 pt-1 border-b border-slate-200/70 relative z-10">
        <Link href="/" class="flex items-center">
          <MovaLogo class="h-7 w-auto" />
        </Link>
        <Link :href="route('login')" class="text-xs font-bold text-brand-600 hover:text-brand-700">
          Iniciar sesión
        </Link>
      </header>

      <!-- Contenedor central con la Card de Registro -->
      <main class="flex-1 flex items-center justify-center py-4 sm:py-6 relative z-10">
        <div class="w-full max-w-[490px] xl:max-w-[520px] bg-white rounded-2xl sm:rounded-[22px] border border-slate-100/90 shadow-[0_12px_40px_-10px_rgba(15,23,42,0.06)] p-5 sm:p-7 xl:p-8">
          <!-- Cabecera del formulario -->
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2 class="text-2xl sm:text-[26px] font-black text-slate-900 tracking-tight">Crear cuenta</h2>
              <p class="text-xs sm:text-sm text-slate-500 mt-1 font-normal">Únete a MOVA en unos minutos.</p>
            </div>
            <span class="text-xs font-bold tracking-wider uppercase text-slate-400 mt-1 whitespace-nowrap">
              PASO {{ step }} DE {{ totalSteps }}
            </span>
          </div>

          <!-- Barra de progreso horizontal -->
          <div class="mt-3.5 h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
            <div
              class="h-full bg-[#1B60C4] rounded-full transition-all duration-300"
              :style="{ width: `${(step / totalSteps) * 100}%` }"
            />
          </div>

          <form @submit.prevent="submit">
            <!-- ========================================================= -->
            <!-- PASO 1: Selección de Rol & Registro con Google            -->
            <!-- ========================================================= -->
            <div v-if="step === 1">
              <!-- Botón Google -->
              <button
                type="button"
                @click="showGoogleModal = true"
                class="mt-5 sm:mt-6 w-full h-[48px] sm:h-[50px] flex items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm transition-all duration-150 hover:bg-slate-50 hover:border-slate-300 active:scale-[0.99] focus:outline-none focus:ring-2 focus:ring-brand-500/20"
              >
                <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 48 48">
                  <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/>
                  <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/>
                  <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/>
                  <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z"/>
                </svg>
                <span>Continuar con Google</span>
              </button>

              <!-- Divisor -->
              <div class="my-4 sm:my-5 flex items-center gap-3">
                <div class="h-px flex-1 bg-slate-200" />
                <span class="text-xs font-medium text-slate-400 whitespace-nowrap">o completa el formulario</span>
                <div class="h-px flex-1 bg-slate-200" />
              </div>

              <!-- Selección de Rol -->
              <div>
                <h3 class="text-base sm:text-[17px] font-extrabold text-slate-900 tracking-tight">¡Hola! ¿Cómo usarás MOVA?</h3>
                <p class="text-xs text-slate-500 mt-0.5 font-normal">Esto personaliza tu experiencia desde el inicio.</p>

                <div v-if="roleLocked" class="mt-3 rounded-xl border-2 border-brand-600 bg-brand-50 p-3.5 flex items-center gap-3">
                  <Icon :name="form.role === 'teacher' ? 'role-teacher' : 'role-parent'" :size="22" class="flex-shrink-0 text-brand-700" />
                  <div>
                    <span class="block font-bold text-sm text-brand-700">{{ form.role === 'teacher' ? 'Registro de profesor' : 'Registro de padre/madre' }}</span>
                    <span class="block text-xs text-slate-500">¿Te equivocaste? <Link :href="route('register')" class="text-brand-600 hover:underline font-medium">Elige de nuevo</Link>.</span>
                  </div>
                </div>

                <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3" role="radiogroup" aria-label="Selección de rol">
                  <!-- Tarjeta: Soy padre -->
                  <div
                    role="radio"
                    :aria-checked="form.role === 'parent'"
                    tabindex="0"
                    @click="form.role = 'parent'"
                    @keydown.space.prevent="form.role = 'parent'"
                    @keydown.enter.prevent="form.role = 'parent'"
                    class="relative rounded-xl p-3 sm:p-3.5 text-left transition-all duration-150 flex items-start gap-2.5 cursor-pointer focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    :class="form.role === 'parent' ? 'border-2 border-[#1B60C4] bg-[#EEF4FF] shadow-sm' : 'border border-slate-200 bg-white hover:border-slate-300'"
                  >
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" :class="form.role === 'parent' ? 'text-[#1B60C4]' : 'text-slate-400'">
                      <Icon name="role-parent" :size="20" />
                    </div>
                    <div class="min-w-0 flex-1">
                      <span class="block font-bold text-sm text-slate-900 leading-tight">Soy padre</span>
                      <span class="block text-xs text-slate-500 mt-0.5 leading-tight">Busco apoyo para mis hijos.</span>
                    </div>
                    <div class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5" :class="form.role === 'parent' ? 'border-2 border-[#1B60C4] bg-white' : 'border border-slate-300 bg-white'">
                      <div v-if="form.role === 'parent'" class="w-2 h-2 rounded-full bg-[#1B60C4]" />
                    </div>
                  </div>

                  <!-- Tarjeta: Soy profesor -->
                  <div
                    role="radio"
                    :aria-checked="form.role === 'teacher'"
                    tabindex="0"
                    @click="form.role = 'teacher'"
                    @keydown.space.prevent="form.role = 'teacher'"
                    @keydown.enter.prevent="form.role = 'teacher'"
                    class="relative rounded-xl p-3 sm:p-3.5 text-left transition-all duration-150 flex items-start gap-2.5 cursor-pointer focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                    :class="form.role === 'teacher' ? 'border-2 border-[#1B60C4] bg-[#EEF4FF] shadow-sm' : 'border border-slate-200 bg-white hover:border-slate-300'"
                  >
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" :class="form.role === 'teacher' ? 'text-[#1B60C4]' : 'text-slate-400'">
                      <Icon name="role-teacher" :size="20" />
                    </div>
                    <div class="min-w-0 flex-1">
                      <span class="block font-bold text-sm text-slate-900 leading-tight">Soy profesor</span>
                      <span class="block text-xs text-slate-500 mt-0.5 leading-tight">Quiero enseñar en MOVA.</span>
                    </div>
                    <div class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5" :class="form.role === 'teacher' ? 'border-2 border-[#1B60C4] bg-white' : 'border border-slate-300 bg-white'">
                      <div v-if="form.role === 'teacher'" class="w-2 h-2 rounded-full bg-[#1B60C4]" />
                    </div>
                  </div>
                </div>
                <InputError class="mt-1.5" :message="form.errors.role" />
              </div>

              <!-- Botón Continuar -->
              <div class="pt-5 sm:pt-6">
                <button
                  type="button"
                  @click="next"
                  :disabled="!isCurrentStepValid || form.processing"
                  class="w-full h-[48px] sm:h-[50px] flex items-center justify-center gap-2 rounded-xl bg-[#1B60C4] hover:bg-[#154FA6] active:bg-[#104088] text-white text-base font-semibold shadow-sm transition-all duration-150 active:scale-[0.99] disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-brand-500/40"
                >
                  <span>Continuar</span>
                  <span class="text-lg leading-none">→</span>
                </button>
              </div>

              <!-- Enlace a Iniciar Sesión -->
              <p class="mt-4 sm:mt-5 text-center text-xs sm:text-sm text-slate-600 font-normal">
                ¿Ya tienes cuenta?
                <Link :href="route('login')" class="text-brand-600 hover:text-brand-700 hover:underline font-bold ml-1">
                  Inicia sesión
                </Link>
              </p>
            </div>

            <!-- ========================================================= -->
            <!-- PASO 2: Nombre Completo                                   -->
            <!-- ========================================================= -->
            <div v-else-if="step === 2" class="space-y-4 pt-5">
              <div>
                <h3 class="text-xl font-extrabold text-slate-900 tracking-tight">¿Cómo te llamas?</h3>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">Usaremos tu nombre en la plataforma y las comunicaciones.</p>
              </div>

              <div>
                <label for="name" class="block text-xs sm:text-[13px] font-semibold text-slate-800 mb-1">Nombre completo</label>
                <div class="relative">
                  <div class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                    <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                  </div>
                  <input
                    id="name"
                    type="text"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="Tu nombre completo"
                    class="w-full h-[46px] sm:h-[48px] pl-10 pr-4 rounded-xl bg-white border border-slate-200/90 text-slate-900 text-sm placeholder:text-slate-400 transition-all duration-150 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
                    :class="{ 'border-rose-400 bg-rose-50/30': form.errors.name }"
                  />
                </div>
                <InputError class="mt-1" :message="form.errors.name" />
              </div>

              <div class="pt-4 flex gap-3">
                <button type="button" @click="back" class="flex-1 h-[46px] rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                  Atrás
                </button>
                <button
                  type="button"
                  @click="next"
                  :disabled="!isCurrentStepValid"
                  class="flex-1 h-[46px] rounded-xl bg-[#1B60C4] hover:bg-[#154FA6] disabled:opacity-40 text-white text-sm font-semibold transition-colors flex items-center justify-center gap-1.5"
                >
                  <span>Continuar</span>
                  <span>→</span>
                </button>
              </div>
            </div>

            <!-- ========================================================= -->
            <!-- PASO 3: Correo electrónico y Teléfono                     -->
            <!-- ========================================================= -->
            <div v-else-if="step === 3" class="space-y-4 pt-5">
              <div>
                <h3 class="text-xl font-extrabold text-slate-900 tracking-tight">¿Cuál es tu correo y teléfono?</h3>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">Tu correo recibirá la verificación de cuenta y avisos por WhatsApp.</p>
              </div>

              <div>
                <label for="email" class="block text-xs sm:text-[13px] font-semibold text-slate-800 mb-1">Correo electrónico</label>
                <div class="relative">
                  <div class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                    <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                  </div>
                  <input
                    id="email"
                    type="email"
                    v-model="form.email"
                    required
                    autocomplete="username"
                    placeholder="tu@correo.com"
                    class="w-full h-[46px] sm:h-[48px] pl-10 pr-4 rounded-xl bg-white border border-slate-200/90 text-slate-900 text-sm placeholder:text-slate-400 transition-all duration-150 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
                    :class="{ 'border-rose-400 bg-rose-50/30': form.errors.email }"
                  />
                </div>
                <InputError class="mt-1" :message="form.errors.email" />
              </div>

              <div>
                <label for="phone" class="block text-xs sm:text-[13px] font-semibold text-slate-800 mb-1">Teléfono o WhatsApp <span class="text-slate-400 font-normal">(opcional)</span></label>
                <div class="relative">
                  <div class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                    <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                    </svg>
                  </div>
                  <input
                    id="phone"
                    v-model="form.phone"
                    type="tel"
                    placeholder="987654321 o +51987654321"
                    class="w-full h-[46px] sm:h-[48px] pl-10 pr-4 rounded-xl bg-white border border-slate-200/90 text-slate-900 text-sm placeholder:text-slate-400 transition-all duration-150 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
                    :class="{ 'border-rose-400 bg-rose-50/30': form.errors.phone }"
                  />
                </div>
                <InputError class="mt-1" :message="form.errors.phone" />
              </div>

              <div class="pt-4 flex gap-3">
                <button type="button" @click="back" class="flex-1 h-[46px] rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                  Atrás
                </button>
                <button
                  type="button"
                  @click="next"
                  :disabled="!isCurrentStepValid"
                  class="flex-1 h-[46px] rounded-xl bg-[#1B60C4] hover:bg-[#154FA6] disabled:opacity-40 text-white text-sm font-semibold transition-colors flex items-center justify-center gap-1.5"
                >
                  <span>Continuar</span>
                  <span>→</span>
                </button>
              </div>
            </div>

            <!-- ========================================================= -->
            <!-- PASO 4 (Profesor): Materias que enseña                    -->
            <!-- ========================================================= -->
            <div v-else-if="step === 4 && form.role === 'teacher'" class="space-y-4 pt-5">
              <div>
                <h3 class="text-xl font-extrabold text-slate-900 tracking-tight">¿Qué materias te apasiona enseñar?</h3>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">Escribe materias o cursos especializados. Si no existen, MOVA los creará.</p>
              </div>

              <div class="flex gap-2">
                <input
                  v-model="subjectDraft"
                  type="text"
                  placeholder="Ej: Robótica, Álgebra, Python"
                  class="flex-1 h-[46px] px-3.5 rounded-xl border border-slate-200 text-slate-900 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
                  @keydown.enter.prevent="addSubject"
                />
                <button type="button" @click="addSubject" class="px-4 h-[46px] rounded-xl bg-[#1B60C4] text-white text-sm font-bold hover:bg-[#154FA6] transition-colors">
                  Agregar
                </button>
              </div>

              <div class="flex flex-wrap gap-2 pt-1">
                <button
                  v-for="subject in form.teacher_subject_names"
                  :key="subject"
                  type="button"
                  class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-brand-700 hover:bg-blue-100 transition-colors"
                  @click="removeSubject(subject)"
                >
                  {{ subject }} ×
                </button>
              </div>
              <InputError :message="form.errors.teacher_subject_names || form.errors['teacher_subject_names.0']" />

              <div class="pt-4 flex gap-3">
                <button type="button" @click="back" class="flex-1 h-[46px] rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                  Atrás
                </button>
                <button
                  type="button"
                  @click="next"
                  :disabled="!isCurrentStepValid"
                  class="flex-1 h-[46px] rounded-xl bg-[#1B60C4] hover:bg-[#154FA6] disabled:opacity-40 text-white text-sm font-semibold transition-colors flex items-center justify-center gap-1.5"
                >
                  <span>Continuar</span>
                  <span>→</span>
                </button>
              </div>
            </div>

            <!-- ========================================================= -->
            <!-- PASO CONTRASEÑA: Paso 4 (Padre) o Paso 5 (Profesor)       -->
            <!-- ========================================================= -->
            <div v-else-if="step === passwordStep" class="space-y-4 pt-5">
              <div>
                <h3 class="text-xl font-extrabold text-slate-900 tracking-tight">Crea una contraseña segura</h3>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">Debe tener al menos 8 caracteres y coincidir en ambos campos.</p>
              </div>

              <div>
                <label for="password" class="block text-xs sm:text-[13px] font-semibold text-slate-800 mb-1">Contraseña</label>
                <div class="relative">
                  <div class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                    <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                  </div>
                  <input
                    id="password"
                    :type="showPassword ? 'text' : 'password'"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                    placeholder="Contraseña"
                    class="w-full h-[46px] sm:h-[48px] pl-10 pr-11 rounded-xl bg-white border border-slate-200/90 text-slate-900 text-sm placeholder:text-slate-400 transition-all duration-150 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
                    :class="{ 'border-rose-400 bg-rose-50/30': form.errors.password }"
                  />
                  <button
                    type="button"
                    @click="showPassword = !showPassword"
                    :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    class="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600 focus:outline-none focus:ring-2 focus:ring-brand-500/20 rounded-lg transition-colors"
                  >
                    <svg v-if="showPassword" class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    <svg v-else class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                    </svg>
                  </button>
                </div>
                <InputError class="mt-1" :message="form.errors.password" />
              </div>

              <div>
                <label for="password_confirmation" class="block text-xs sm:text-[13px] font-semibold text-slate-800 mb-1">Confirmar contraseña</label>
                <div class="relative">
                  <div class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                    <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                  </div>
                  <input
                    id="password_confirmation"
                    :type="showConfirmPassword ? 'text' : 'password'"
                    v-model="form.password_confirmation"
                    required
                    placeholder="Repite tu contraseña"
                    class="w-full h-[46px] sm:h-[48px] pl-10 pr-11 rounded-xl bg-white border border-slate-200/90 text-slate-900 text-sm placeholder:text-slate-400 transition-all duration-150 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
                    :class="{ 'border-rose-400 bg-rose-50/30': form.password_confirmation && form.password !== form.password_confirmation }"
                  />
                  <button
                    type="button"
                    @click="showConfirmPassword = !showConfirmPassword"
                    :aria-label="showConfirmPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    class="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600 focus:outline-none focus:ring-2 focus:ring-brand-500/20 rounded-lg transition-colors"
                  >
                    <svg v-if="showConfirmPassword" class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    <svg v-else class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                    </svg>
                  </button>
                </div>
                <p v-if="form.password_confirmation && form.password !== form.password_confirmation" class="text-xs text-rose-500 mt-1">
                  Las contraseñas no coinciden.
                </p>
              </div>

              <div class="pt-4 flex gap-3">
                <button type="button" @click="back" class="flex-1 h-[46px] rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                  Atrás
                </button>
                <button
                  type="button"
                  @click="next"
                  :disabled="!isCurrentStepValid"
                  class="flex-1 h-[46px] rounded-xl bg-[#1B60C4] hover:bg-[#154FA6] disabled:opacity-40 text-white text-sm font-semibold transition-colors flex items-center justify-center gap-1.5"
                >
                  <span>Continuar</span>
                  <span>→</span>
                </button>
              </div>
            </div>

            <!-- ========================================================= -->
            <!-- PASO FINAL: Términos y Creación de Cuenta                 -->
            <!-- ========================================================= -->
            <div v-else-if="step === totalSteps" class="space-y-4 pt-5">
              <div>
                <h3 class="text-xl font-extrabold text-slate-900 tracking-tight">Último paso</h3>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">Acepta los términos para crear tu cuenta en MOVA.</p>
              </div>

              <label class="flex cursor-pointer items-start gap-3 p-3.5 rounded-xl border border-slate-200 bg-slate-50/50 hover:bg-slate-50 transition-colors">
                <input
                  v-model="form.accepted_terms"
                  type="checkbox"
                  class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                />
                <span class="text-xs leading-relaxed text-slate-600">
                  He leído y acepto los
                  <a :href="route('legal.terms')" target="_blank" class="text-brand-600 font-semibold hover:underline">Términos y Condiciones</a>
                  y la
                  <a :href="route('legal.privacy')" target="_blank" class="text-brand-600 font-semibold hover:underline">Política de Privacidad</a>
                  de MOVA.
                </span>
              </label>
              <InputError :message="form.errors.accepted_terms" />

              <div class="pt-4 flex gap-3">
                <button type="button" @click="back" class="flex-1 h-[48px] rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                  Atrás
                </button>
                <button
                  type="submit"
                  :disabled="form.processing || !form.accepted_terms"
                  class="flex-1 h-[48px] rounded-xl bg-[#1B60C4] hover:bg-[#154FA6] active:bg-[#104088] text-white text-sm font-bold shadow-sm transition-all disabled:opacity-50 flex items-center justify-center gap-2"
                >
                  <svg v-if="form.processing" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                  </svg>
                  <span>Crear cuenta</span>
                </button>
              </div>
            </div>
          </form>
        </div>
      </main>

      <!-- Modal Informativo de Google -->
      <Modal :show="showGoogleModal" max-width="sm" @close="showGoogleModal = false">
        <div class="p-6 text-center">
          <div class="w-12 h-12 mx-auto rounded-full bg-amber-50 flex items-center justify-center text-amber-600 mb-3">
            <Icon name="pending" :size="24" />
          </div>
          <h3 class="text-lg font-bold text-slate-900">Opción temporalmente no disponible</h3>
          <p class="text-sm text-slate-500 mt-2">
            Estamos trabajando para ofrecerte esta opción. Por ahora, regístrate con tu correo electrónico.
          </p>
          <button
            type="button"
            @click="showGoogleModal = false"
            class="mt-5 w-full px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 transition-colors"
          >
            Entendido
          </button>
        </div>
      </Modal>

      <!-- Footer inferior del panel derecho -->
      <footer class="pt-3 pb-2 text-center text-xs text-slate-400 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 relative z-10">
        <Link :href="route('legal.terms')" class="hover:text-slate-600 transition-colors">Términos y Condiciones</Link>
        <span class="text-slate-300">|</span>
        <Link :href="route('legal.privacy')" class="hover:text-slate-600 transition-colors">Política de Privacidad</Link>
        <span class="text-slate-300">|</span>
        <a href="mailto:m0v4class@gmail.com" class="hover:text-slate-600 transition-colors">Soporte</a>
        <span class="text-slate-300">|</span>
        <span>© {{ year }} MOVA. Todos los derechos reservados.</span>
      </footer>
    </div>
  </div>
</template>
