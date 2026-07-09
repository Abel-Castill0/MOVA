<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-50 p-4">
    <div class="w-full max-w-xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
      <div class="mb-6">
        <p class="text-sm font-semibold text-brand-600">MOVA</p>
        <h1 class="text-2xl font-black text-slate-900">Crear cuenta</h1>
        <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-100">
          <div class="h-full rounded-full bg-brand-600 transition-all duration-300" :style="{ width: `${(step / totalSteps) * 100}%` }" />
        </div>
        <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Paso {{ step }} de {{ totalSteps }}</p>
      </div>

      <form @submit.prevent="submit">
        <section v-if="step === 1" class="space-y-5">
          <div>
            <h2 class="text-xl font-black text-slate-900">¡Hola! ¿Cómo usarás MOVA?</h2>
            <p class="text-sm text-slate-500">Esto personaliza tu experiencia desde el inicio.</p>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <button type="button" @click="form.role = 'parent'" :class="roleClass(form.role === 'parent')">
              <span class="text-2xl">👪</span>
              <span class="block font-bold">Soy padre</span>
              <span class="block text-xs text-slate-500">Busco apoyo para mi hijo.</span>
            </button>
            <button type="button" @click="form.role = 'teacher'" :class="roleClass(form.role === 'teacher')">
              <span class="text-2xl">🎓</span>
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
          <button v-if="step < totalSteps" type="button" class="flex-1 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700" @click="next">
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
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'
import TextInput from '@/Components/TextInput.vue'

const subjectDraft = ref('')

const form = useForm({
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  role: 'parent',
  teacher_subject_names: [],
  accepted_terms: false,
})

const totalSteps = computed(() => form.role === 'teacher' ? 6 : 5)
const passwordStep = computed(() => form.role === 'teacher' ? 5 : 4)
const step = ref(1)

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

function submit() {
  form.post(route('register'))
}
</script>
