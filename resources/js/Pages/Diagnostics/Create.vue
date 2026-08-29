<template>
  <AppLayout title="Diagnóstico rápido">
    <div class="mx-auto max-w-lg">
      <div class="mb-6">
        <div class="mb-2 flex items-center justify-between">
          <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Paso {{ step }} de 5</span>
          <button v-if="step > 1" @click="back" class="text-xs font-medium text-brand-600 hover:underline">
            Atrás
          </button>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
          <div class="h-full rounded-full bg-brand-600 transition-all duration-300" :style="{ width: `${(step / 5) * 100}%` }" />
        </div>
      </div>

      <section v-if="step === 1" class="space-y-5">
        <div>
          <h1 class="text-xl font-black text-slate-900">¿Para cuál hijo es el diagnóstico?</h1>
          <p class="text-sm text-slate-500">Selecciona el alumno que necesita ayuda.</p>
        </div>

        <div v-if="students.length === 0" class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-center">
          <p class="font-semibold text-amber-900">Aún no tienes hijos registrados</p>
          <Link :href="route('students.create')" class="mt-4 inline-block rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-brand-700">
            Agregar hijo
          </Link>
        </div>

        <div v-else class="space-y-3">
          <button
            v-for="student in students"
            :key="student.id"
            @click="form.student_id = student.id; next()"
            :class="optionClass(form.student_id === student.id)"
          >
            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-100 font-black text-brand-700">
              {{ student.first_name?.charAt(0) }}
            </span>
            <span>
              <span class="block font-bold text-slate-900">{{ student.first_name }} {{ student.last_name }}</span>
              <span class="block text-sm text-slate-500">{{ gradeLevelLabel(student.grade_level) }}</span>
            </span>
          </button>
        </div>
      </section>

      <section v-if="step === 2" class="space-y-5">
        <div>
          <h1 class="text-xl font-black text-slate-900">¿Qué materia necesita reforzar?</h1>
          <p class="text-sm text-slate-500">Elige una materia para enviar la solicitud a profesores relevantes.</p>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            v-for="subject in filteredSubjects"
            :key="subject.id"
            @click="form.subject_id = subject.id"
            :class="[
              'rounded-xl border-2 px-4 py-2 text-sm font-semibold transition-all',
              form.subject_id === subject.id
                ? 'border-brand-600 bg-brand-600 text-white'
                : 'border-gray-200 bg-white text-slate-700 hover:border-brand-400',
            ]"
          >
            {{ subject.name }}
          </button>
        </div>

        <button
          @click="next"
          :disabled="!form.subject_id"
          :class="primaryClass(Boolean(form.subject_id))"
        >
          Continuar
        </button>
      </section>

      <section v-if="step === 3" class="space-y-5">
        <div>
          <h1 class="text-xl font-black text-slate-900">Cuéntanos el problema</h1>
          <p class="text-sm text-slate-500">Describe la dificultad académica con claridad.</p>
        </div>

        <textarea
          v-model="form.difficulty_text"
          rows="5"
          maxlength="500"
          placeholder="Ejemplo: Mi hijo necesita ayuda con ecuaciones y se bloquea en los ejercicios."
          class="w-full resize-none rounded-xl border border-gray-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        />
        <p v-if="errors.difficulty_text" class="text-xs text-red-500">{{ errors.difficulty_text }}</p>

        <textarea
          v-model="form.school_feedback"
          rows="3"
          maxlength="500"
          placeholder="Comentario del colegio o profesor, si lo tienes."
          class="w-full resize-none rounded-xl border border-gray-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        />

        <button @click="validateStep3" class="w-full rounded-xl bg-brand-600 py-3.5 text-sm font-bold text-white hover:bg-brand-700">
          Continuar
        </button>
      </section>

      <section v-if="step === 4" class="space-y-5">
        <div>
          <h1 class="text-xl font-black text-slate-900">¿Qué necesitas lograr?</h1>
          <p class="text-sm text-slate-500">Elige el objetivo principal de esta solicitud.</p>
        </div>

        <button v-for="goal in goalOptions" :key="goal.value" @click="form.goal = goal.value; next()" :class="optionClass(form.goal === goal.value)">
          <span class="text-2xl">{{ goal.icon }}</span>
          <span>
            <span class="block font-bold text-slate-900">{{ goal.label }}</span>
            <span class="block text-sm text-slate-500">{{ goal.desc }}</span>
          </span>
        </button>
      </section>

      <section v-if="step === 5" class="space-y-5">
        <div>
          <h1 class="text-xl font-black text-slate-900">¿Con qué urgencia lo necesitas?</h1>
          <p class="text-sm text-slate-500">La solicitud se enviará a profesores verificados de la materia.</p>
        </div>

        <button v-for="urgency in urgencyOptions" :key="urgency.value" @click="form.urgency = urgency.value" :class="optionClass(form.urgency === urgency.value)">
          <span class="text-2xl">{{ urgency.icon }}</span>
          <span>
            <span class="block font-bold text-slate-900">{{ urgency.label }}</span>
            <span class="block text-sm text-slate-500">{{ urgency.desc }}</span>
          </span>
        </button>

        <button @click="submit" :disabled="!form.urgency || loading" :class="primaryClass(Boolean(form.urgency) && !loading)">
          {{ loading ? 'Enviando solicitud...' : 'Completar diagnóstico y solicitar clase' }}
        </button>
        <!-- Encontrado auditando Diagnostics: onError guardaba el error bag
             completo, pero solo `errors.difficulty_text` se renderizaba en
             algún lugar (paso 3) — un error de validación sobre cualquier
             otro campo (student_id/subject_id/goal/urgency) llegaba
             correctamente del backend y desaparecía en silencio, sin
             feedback al padre. Poco probable en el flujo normal (los
             botones ya restringen a valores válidos), pero real si un
             subject/alumno se elimina entre cargar la página y enviar. -->
        <p v-if="topLevelError" role="alert" class="text-xs text-red-500 text-center">{{ topLevelError }}</p>
      </section>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  students: Array,
  subjects: Array,
})

const step = ref(1)
const loading = ref(false)
const errors = ref({})

const form = ref({
  student_id: null,
  subject_id: null,
  difficulty_text: '',
  school_feedback: '',
  goal: null,
  urgency: null,
})

// Cualquier error que NO sea difficulty_text (el único que ya tenía su
// propio <p> en el paso 3) se muestra aquí, en el paso final — nunca se
// pierde en silencio un error real del backend.
const topLevelError = computed(() => {
  const { difficulty_text, ...rest } = errors.value
  return Object.values(rest)[0] ?? null
})

const selectedStudent = computed(() => props.students.find((student) => student.id === form.value.student_id))
const filteredSubjects = computed(() => {
  const level = selectedStudent.value?.grade_level
  if (!level) return props.subjects
  return props.subjects.filter((subject) => subject.level === level || subject.level === 'todos')
})

const goalOptions = [
  { value: 'prepare_exam', icon: '📝', label: 'Preparar un examen', desc: 'Hay una evaluación próxima y necesita práctica guiada.' },
  { value: 'solve_homework', icon: '✏️', label: 'Resolver una tarea', desc: 'Necesita ayuda puntual para avanzar con seguridad.' },
  { value: 'continuous_support', icon: '🤝', label: 'Acompañamiento continuo', desc: 'Busca clases regulares para sostener el progreso.' },
]

const urgencyOptions = [
  { value: 'today_or_tomorrow', icon: '⚡', label: 'Hoy o mañana', desc: 'Necesita apoyo lo antes posible.' },
  { value: 'this_week', icon: '📅', label: 'Esta semana', desc: 'Puede coordinar dentro de los próximos días.' },
  { value: 'flexible', icon: '🕊️', label: 'Sin prisa', desc: 'Prefiere encontrar el mejor horario disponible.' },
]

function gradeLevelLabel(level) {
  return { primaria: 'Primaria', secundaria: 'Secundaria', universidad: 'Universidad' }[level] ?? level
}

function optionClass(active) {
  return [
    'flex w-full items-center gap-4 rounded-2xl border-2 bg-white p-4 text-left transition-all hover:border-brand-400 hover:shadow-md',
    active ? 'border-brand-600 bg-brand-50' : 'border-gray-100',
  ]
}

function primaryClass(enabled) {
  return [
    'w-full rounded-xl py-3.5 text-sm font-bold transition-colors',
    enabled ? 'bg-brand-600 text-white hover:bg-brand-700' : 'cursor-not-allowed bg-gray-100 text-gray-400',
  ]
}

function next() {
  if (step.value < 5) step.value += 1
}

function back() {
  if (step.value > 1) step.value -= 1
}

function validateStep3() {
  errors.value = {}
  if (form.value.difficulty_text.trim().length < 10) {
    errors.value.difficulty_text = 'Describe el problema con al menos 10 caracteres.'
    return
  }
  next()
}

function submit() {
  if (!form.value.urgency || loading.value) return
  loading.value = true
  router.post(route('diagnostics.store'), form.value, {
    onError: (errorBag) => { errors.value = errorBag },
    onFinish: () => { loading.value = false },
  })
}
</script>
