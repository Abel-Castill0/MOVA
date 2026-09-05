<template>
  <GuestLayout max-width="max-w-xl" :role="form.role">
    <Head title="Crear cuenta – MOVA" />

    <div class="mb-6">
      <h1 class="text-2xl font-black text-slate-900">Crear cuenta</h1>
      <p class="text-sm text-slate-500 mt-1">Únete a MOVA en unos minutos.</p>
      <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div class="h-full rounded-full bg-brand-600 transition-all duration-300" :style="{ width: `${(step / totalSteps) * 100}%` }" />
      </div>
      <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Paso {{ step }} de {{ totalSteps }}</p>
    </div>

      <template v-if="step === 1">
        <button type="button" @click="showGoogleModal = true"
          class="flex w-full items-center justify-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition-all hover:border-brand-300 hover:shadow-md active:scale-[0.98]">
          <svg class="h-5 w-5" viewBox="0 0 48 48">
            <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/>
            <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/>
            <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/>
            <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z"/>
          </svg>
          Continuar con Google
        </button>
        <p class="mt-2 text-center text-xs text-slate-400">Te preguntaremos si eres familia o profesor justo después.</p>
        <div class="my-5 flex items-center gap-3">
          <div class="h-px flex-1 bg-gray-200"></div>
          <span class="text-xs font-medium text-gray-400">o completa el formulario</span>
          <div class="h-px flex-1 bg-gray-200"></div>
        </div>
      </template>

      <Modal :show="showGoogleModal" max-width="sm" @close="showGoogleModal = false">
        <div class="p-6 text-center">
          <div class="w-12 h-12 mx-auto rounded-full bg-amber-50 flex items-center justify-center text-amber-600 mb-3">
            <Icon name="pending" :size="24" />
          </div>
          <h3 class="text-lg font-bold text-slate-900">Opción temporalmente no disponible</h3>
          <p class="text-sm text-slate-500 mt-2">Estamos trabajando para ofrecerte esta opción. Por ahora, regístrate con tu correo electrónico.</p>
          <button type="button" @click="showGoogleModal = false"
            class="mt-5 w-full px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 transition-colors">
            Entendido
          </button>
        </div>
      </Modal>

      <form @submit.prevent="submit">
        <section v-if="step === 1" class="space-y-5">
          <div>
            <h2 class="text-xl font-black text-slate-900">¡Hola! ¿Cómo usarás MOVA?</h2>
            <p class="text-sm text-slate-500">Esto personaliza tu experiencia desde el inicio.</p>
          </div>
          <!-- Si llegó con ?role=... desde la landing ("Soy profesor"), el
               rol queda fijo — no se ofrece la posibilidad de cambiarlo por
               error, solo un aviso de dónde ajustarlo si se equivocó de link. -->
          <div v-if="roleLocked" class="rounded-2xl border-2 border-brand-600 bg-brand-50 p-4 flex items-center gap-3">
            <Icon :name="form.role === 'teacher' ? 'role-teacher' : 'role-parent'" :size="24" class="flex-shrink-0 text-brand-700" />
            <div>
              <span class="block font-bold text-brand-700">{{ form.role === 'teacher' ? 'Registro de profesor' : 'Registro de padre/madre' }}</span>
              <span class="block text-xs text-slate-500">¿Te equivocaste? <Link :href="route('register')" class="text-brand-600 hover:underline">Elige de nuevo</Link>.</span>
            </div>
          </div>
          <div v-else class="grid gap-3 sm:grid-cols-2">
            <button type="button" @click="form.role = 'parent'" :class="roleClass(form.role === 'parent')">
              <Icon name="role-parent" :size="24" />
              <span class="block font-bold">Soy padre</span>
              <span class="block text-xs text-slate-500">Busco apoyo para mi hijo.</span>
            </button>
            <button type="button" @click="form.role = 'teacher'" :class="roleClass(form.role === 'teacher')">
              <Icon name="role-teacher" :size="24" />
              <span class="block font-bold">Soy profesor</span>
              <span class="block text-xs text-slate-500">Quiero enseñar en MOVA.</span>
            </button>
          </div>
          <InputError :message="form.errors.role" />
        </section>

        <section v-if="step === 2" class="space-y-5">
          <div>
            <h2 class="text-xl font-black text-slate-900">¿Cómo te llamas?</h2>
            <p class="text-sm text-slate-500">Usaremos tu nombre en la plataforma y las comunicaciones.</p>
          </div>
          <TextInput v-model="form.name" type="text" class="block w-full" placeholder="Nombre completo" autofocus />
          <InputError :message="form.errors.name" />
        </section>

        <section v-if="step === 3" class="space-y-5">
          <div>
            <h2 class="text-xl font-black text-slate-900">¿Cuál es tu correo y teléfono?</h2>
            <p class="text-sm text-slate-500">Tu correo recibirá la verificación de cuenta.</p>
          </div>
          <TextInput v-model="form.email" type="email" class="block w-full" placeholder="correo@ejemplo.com" />
          <InputError :message="form.errors.email" />
          <TextInput v-model="form.phone" type="tel" class="block w-full" placeholder="987654321 o +51987654321" />
          <InputError :message="form.errors.phone" />
        </section>

        <section v-if="step === 4 && form.role === 'teacher'" class="space-y-5">
          <div>
            <h2 class="text-xl font-black text-slate-900">¿Qué materias te apasiona enseñar?</h2>
            <p class="text-sm text-slate-500">Escribe materias o cursos especializados. Si no existen, MOVA los creará.</p>
          </div>
          <div class="flex gap-2">
            <TextInput v-model="subjectDraft" type="text" class="block flex-1" placeholder="Ej: Robótica, Álgebra, Python" @keydown.enter.prevent="addSubject" />
            <button type="button" class="rounded-lg bg-brand-600 px-4 text-sm font-bold text-white hover:bg-brand-700" @click="addSubject">
              Agregar
            </button>
          </div>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="subject in form.teacher_subject_names"
              :key="subject"
              type="button"
              class="rounded-full bg-brand-50 px-3 py-1 text-sm font-semibold text-brand-700 hover:bg-brand-100"
              @click="removeSubject(subject)"
            >
              {{ subject }} ×
            </button>
          </div>
          <InputError :message="form.errors.teacher_subject_names || form.errors['teacher_subject_names.0']" />
        </section>

        <section v-if="step === passwordStep" class="space-y-5">
          <div>
            <h2 class="text-xl font-black text-slate-900">Crea una contraseña segura</h2>
            <p class="text-sm text-slate-500">Debe coincidir en ambos campos para continuar.</p>
          </div>
          <TextInput v-model="form.password" type="password" class="block w-full" placeholder="Contraseña" />
          <InputError :message="form.errors.password" />
          <TextInput v-model="form.password_confirmation" type="password" class="block w-full" placeholder="Confirmar contraseña" />
        </section>

        <section v-if="step === totalSteps" class="space-y-5">
          <div>
            <h2 class="text-xl font-black text-slate-900">Último paso</h2>
            <p class="text-sm text-slate-500">Acepta los términos para crear tu cuenta.</p>
          </div>
          <label class="flex cursor-pointer items-start gap-2.5">
            <input v-model="form.accepted_terms" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
            <span class="text-xs leading-snug text-gray-600">
              He leído y acepto los
              <a :href="route('legal.terms')" target="_blank" class="text-brand-600 hover:underline">Términos y Condiciones</a>
              y la
              <a :href="route('legal.privacy')" target="_blank" class="text-brand-600 hover:underline">Política de Privacidad</a>
              de MOVA.
            </span>
          </label>
          <InputError :message="form.errors.accepted_terms" />
        </section>

        <div class="mt-6 flex gap-3">
          <button v-if="step > 1" type="button" class="flex-1 rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50" @click="back">
            Atrás
          </button>
          <button v-if="step < totalSteps" type="button"
            class="flex-1 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-40 disabled:hover:bg-brand-600"
            :disabled="!isCurrentStepValid" @click="next">
            Continuar
          </button>
          <button v-else type="submit" :disabled="form.processing || !form.accepted_terms" class="flex-1 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
            Crear cuenta
          </button>
        </div>
      </form>

    <p class="mt-5 text-center text-sm text-gray-500">
      ¿Ya tienes cuenta?
      <Link :href="route('login')" class="text-brand-600 hover:underline">Inicia sesión</Link>
    </p>
  </GuestLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import Modal from '@/Components/Modal.vue'
import TextInput from '@/Components/TextInput.vue'
import Icon from '@/Components/Icon.vue'

const props = defineProps({
  lockedRole: { type: String, default: null }, // 'parent' | 'teacher' | null — desde ?role= en la landing
})

const showGoogleModal = ref(false)
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

// Validación por paso: el botón "Continuar" se deshabilita hasta que el paso
// actual esté completo — nunca se avanza con campos vacíos. El backend sigue
// siendo la autoridad real (RegisteredUserController::store ya validaba
// todo esto); esto es solo feedback inmediato en el frontend.
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
  return true
})

function roleClass(active) {
  return [
    'rounded-2xl border-2 p-4 text-left transition-all hover:border-brand-400',
    active ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-gray-200 text-slate-700',
  ]
}

function addSubject() {
  const value = subjectDraft.value.trim()
  if (!value) return
  if (!form.teacher_subject_names.some((subject) => subject.toLowerCase() === value.toLowerCase())) {
    form.teacher_subject_names.push(value)
  }
  subjectDraft.value = ''
}

function removeSubject(subject) {
  form.teacher_subject_names = form.teacher_subject_names.filter((item) => item !== subject)
}

function next() {
  if (step.value === 4 && form.role === 'teacher') addSubject()
  if (step.value < totalSteps.value) step.value += 1
}

function back() {
  if (step.value > 1) step.value -= 1
}

// Bug real encontrado en vivo (no en el diff, en el navegador): un envío
// final que falla por una validación que solo el servidor puede conocer
// (el caso real y alcanzable: email ya registrado — `unique:users`, nada
// que el cliente pueda verificar de antemano sin una consulta live-lookup
// que no existe aquí) llenaba `form.errors.email`, pero el wizard se queda
// en `totalSteps` porque nada mueve `step` de vuelta al paso que en
// realidad tiene el problema — la sección con el email (paso 3) ni
// siquiera está montada en el DOM en ese momento (`v-if="step === 3"`).
// El usuario veía un botón "Crear cuenta" habilitado que no hacía nada al
// hacer clic, sin ningún mensaje visible en ningún lado — confirmado
// reproduciendo con un correo ya sembrado (ana@mova.test) y leyendo
// form.errors.email manualmente tras retroceder al paso 3.
function stepForField(field) {
  if (field === 'role') return 1
  if (field === 'name') return 2
  if (field === 'email' || field === 'phone') return 3
  if (field === 'teacher_subject_names' || field.startsWith('teacher_subject_names.')) return 4
  if (field === 'password' || field === 'password_confirmation') return passwordStep.value
  if (field === 'accepted_terms') return totalSteps.value
  return step.value // campo desconocido: no mover al usuario a ciegas
}

function submit() {
  form.post(route('register'), {
    onError: (errors) => {
      const fields = Object.keys(errors)
      if (fields.length === 0) return
      // Si hay errores en más de un paso, se va al más temprano — tiene
      // más sentido arreglar los problemas en el orden en que aparecen en
      // el flujo que saltar a uno arbitrario.
      step.value = Math.min(...fields.map(stepForField))
    },
  })
}
</script>
