<template>
  <AppLayout title="Diagnóstico rápido">
    <div class="max-w-lg mx-auto">

      <!-- Progress bar -->
      <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Paso {{ step }} de 5</span>
          <button v-if="step > 1" @click="back"
            class="text-xs text-brand-600 font-medium hover:underline flex items-center gap-1">
            ← Atrás
          </button>
        </div>
        <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full bg-brand-600 rounded-full transition-all duration-300"
            :style="{ width: (step / 5 * 100) + '%' }" />
        </div>
        <div class="flex justify-between mt-2">
          <span v-for="i in 5" :key="i"
            :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold transition-all',
              i < step ? 'bg-brand-600 text-white' :
              i === step ? 'bg-brand-600 text-white ring-2 ring-brand-200' :
              'bg-slate-100 text-slate-400']">
            {{ i < step ? '✓' : i }}
          </span>
        </div>
      </div>

      <!-- Step 1: ¿Para cuál hijo? -->
      <div v-if="step === 1">
        <h1 class="text-xl font-black text-slate-900 mb-1">¿Para cuál hijo es el diagnóstico?</h1>
        <p class="text-sm text-slate-500 mb-5">Selecciona el alumno que necesita ayuda</p>

        <div v-if="students.length === 0"
          class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-center">
          <div class="text-4xl mb-3">🎒</div>
          <p class="font-semibold text-amber-900 mb-1">Aún no tienes hijos registrados</p>
          <p class="text-sm text-amber-700 mb-4">Agrega a tu hijo primero para continuar</p>
          <Link :href="route('students.create')"
            class="inline-block px-5 py-2.5 bg-brand-600 text-white font-bold rounded-xl text-sm hover:bg-brand-700 transition-colors">
            Agregar hijo →
          </Link>
        </div>

        <div v-else class="space-y-3">
          <button v-for="s in students" :key="s.id"
            @click="form.student_id = s.id; next()"
            :class="['w-full flex items-center gap-4 p-4 rounded-2xl border-2 text-left transition-all hover:border-brand-400 hover:shadow-md',
              form.student_id === s.id ? 'border-brand-600 bg-brand-50' : 'border-gray-100 bg-white']">
            <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-teal-500 rounded-xl flex items-center justify-center text-white font-black text-lg flex-shrink-0">
              {{ s.first_name?.charAt(0) }}
            </div>
            <div>
              <p class="font-bold text-slate-900">{{ s.first_name }} {{ s.last_name }}</p>
              <p class="text-sm text-slate-500 capitalize">{{ gradeLevelLabel(s.grade_level) }}</p>
            </div>
            <div v-if="form.student_id === s.id" class="ml-auto text-brand-600 text-xl">✓</div>
          </button>
        </div>
      </div>

      <!-- Step 2: ¿Qué materia? -->
      <div v-if="step === 2">
        <h1 class="text-xl font-black text-slate-900 mb-1">¿Qué materia necesita reforzar?</h1>
        <p class="text-sm text-slate-500 mb-5">Puedes saltarlo si no estás seguro</p>

        <div class="flex flex-wrap gap-2 mb-4">
          <button v-for="s in filteredSubjects" :key="s.id"
            @click="form.subject_id = form.subject_id === s.id ? null : s.id"
            :class="['px-4 py-2 rounded-xl border-2 text-sm font-semibold transition-all',
              form.subject_id === s.id
                ? 'border-brand-600 bg-brand-600 text-white'
                : 'border-gray-200 bg-white text-slate-700 hover:border-brand-400']">
            {{ s.name }}
          </button>
        </div>

        <p class="text-xs text-slate-400 mb-5">Mostrando materias para {{ selectedStudentLevel }}</p>

        <button @click="form.subject_id = null; next()"
          class="w-full py-3 border-2 border-dashed border-gray-200 rounded-xl text-sm text-slate-500 hover:border-slate-300 transition-colors">
          No estoy seguro de la materia →
        </button>

        <button @click="next" :disabled="false"
          class="mt-3 w-full py-3.5 bg-brand-600 text-white font-bold rounded-xl text-sm hover:bg-brand-700 transition-colors shadow-sm">
          Continuar →
        </button>
      </div>

      <!-- Step 3: Cuéntanos el problema -->
      <div v-if="step === 3">
        <h1 class="text-xl font-black text-slate-900 mb-1">Cuéntanos el problema</h1>
        <p class="text-sm text-slate-500 mb-5">¿Qué dificultad tiene tu hijo?</p>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1.5">
              ¿Qué está pasando? <span class="text-red-500">*</span>
            </label>
            <textarea
              v-model="form.difficulty_text"
              rows="4"
              maxlength="500"
              placeholder="Ejemplo: Mi hijo no entiende fracciones y se bloquea en los exámenes de matemáticas."
              class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none transition"
            />
            <p class="text-xs text-slate-400 mt-1 text-right">{{ form.difficulty_text.length }}/500</p>
            <p v-if="errors.difficulty_text" class="text-xs text-red-500 mt-1">{{ errors.difficulty_text }}</p>
          </div>

          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1.5">
              ¿Qué dijo el profesor del colegio? <span class="text-slate-400">(opcional)</span>
            </label>
            <textarea
              v-model="form.school_feedback"
              rows="2"
              maxlength="500"
              placeholder="Ejemplo: El profesor dijo que tiene dificultades para seguir el ritmo de la clase."
              class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none transition"
            />
          </div>
        </div>

        <button @click="validateStep3"
          class="mt-5 w-full py-3.5 bg-brand-600 text-white font-bold rounded-xl text-sm hover:bg-brand-700 transition-colors shadow-sm">
          Continuar →
        </button>
      </div>

      <!-- Step 4: ¿Qué quieres lograr? -->
      <div v-if="step === 4">
        <h1 class="text-xl font-black text-slate-900 mb-1">¿Qué quieres lograr?</h1>
        <p class="text-sm text-slate-500 mb-5">Elige el objetivo principal</p>

        <div class="space-y-3">
          <button v-for="g in goalOptions" :key="g.value"
            @click="form.goal = g.value; next()"
            :class="['w-full flex items-center gap-4 p-4 rounded-2xl border-2 text-left transition-all hover:border-brand-400 hover:shadow-md',
              form.goal === g.value ? 'border-brand-600 bg-brand-50' : 'border-gray-100 bg-white']">
            <span class="text-2xl flex-shrink-0">{{ g.icon }}</span>
            <div>
              <p class="font-bold text-slate-900 text-sm">{{ g.label }}</p>
              <p class="text-xs text-slate-500">{{ g.desc }}</p>
            </div>
            <div v-if="form.goal === g.value" class="ml-auto text-brand-600 text-xl">✓</div>
          </button>
        </div>
      </div>

      <!-- Step 5: ¿Con qué urgencia? -->
      <div v-if="step === 5">
        <h1 class="text-xl font-black text-slate-900 mb-1">¿Con qué urgencia lo necesitas?</h1>
        <p class="text-sm text-slate-500 mb-5">Esto nos ayuda a encontrar el profesor disponible</p>

        <div class="space-y-3">
          <button v-for="u in urgencyOptions" :key="u.value"
            @click="form.urgency = u.value"
            :class="['w-full flex items-center gap-4 p-4 rounded-2xl border-2 text-left transition-all hover:border-brand-400 hover:shadow-md',
              form.urgency === u.value ? 'border-brand-600 bg-brand-50' : 'border-gray-100 bg-white']">
            <span class="text-2xl flex-shrink-0">{{ u.icon }}</span>
            <div>
              <p class="font-bold text-slate-900 text-sm">{{ u.label }}</p>
              <p class="text-xs text-slate-500">{{ u.desc }}</p>
            </div>
            <div v-if="form.urgency === u.value" class="ml-auto text-brand-600 text-xl">✓</div>
          </button>
        </div>

        <button @click="submit" :disabled="!form.urgency || loading"
          :class="['mt-5 w-full py-3.5 font-bold rounded-xl text-sm transition-all shadow-sm',
            form.urgency && !loading
              ? 'bg-brand-600 text-white hover:bg-brand-700'
              : 'bg-gray-100 text-gray-400 cursor-not-allowed']">
          <span v-if="loading" class="flex items-center justify-center gap-2">
            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            Buscando profesores...
          </span>
          <span v-else>🔍 Ver profesores recomendados →</span>
        </button>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  students: Array,
  subjects: Array,
});

const step    = ref(1);
const loading = ref(false);
const errors  = ref({});

const form = ref({
  student_id:       null,
  subject_id:       null,
  difficulty_text:  '',
  school_feedback:  '',
  goal:             null,
  urgency:          null,
});

const selectedStudent = computed(() => props.students.find(s => s.id === form.value.student_id));
const selectedStudentLevel = computed(() => {
  const lvl = selectedStudent.value?.grade_level;
  return lvl ? gradeLevelLabel(lvl) : 'todos los niveles';
});

const filteredSubjects = computed(() => {
  const lvl = selectedStudent.value?.grade_level;
  if (!lvl) return props.subjects;
  return props.subjects.filter(s => s.level === lvl || s.level === 'todos');
});

function gradeLevelLabel(lvl) {
  return { primaria: 'Primaria', secundaria: 'Secundaria', universidad: 'Universidad' }[lvl] ?? lvl;
}

const goalOptions = [
  { value: 'reinforce_topic',    icon: '📚', label: 'Reforzar un tema', desc: 'El alumno necesita repasar o practicar más un contenido' },
  { value: 'prepare_exam',       icon: '📝', label: 'Preparar un examen', desc: 'Hay un examen próximo que necesita aprobar' },
  { value: 'recover_grades',     icon: '📈', label: 'Recuperar notas', desc: 'Las calificaciones han bajado y necesita mejorarlas' },
  { value: 'solve_homework',     icon: '✏️', label: 'Resolver una tarea', desc: 'Necesita ayuda puntual con una tarea específica' },
  { value: 'continuous_support', icon: '🤝', label: 'Acompañamiento continuo', desc: 'Clases regulares para avanzar semana a semana' },
];

const urgencyOptions = [
  { value: 'today_or_tomorrow', icon: '⚡', label: 'Hoy o mañana', desc: 'Necesito un profesor lo antes posible' },
  { value: 'this_week',         icon: '📅', label: 'Esta semana', desc: 'Tengo tiempo hasta el fin de semana' },
  { value: 'flexible',          icon: '😌', label: 'Sin prisa, a mi ritmo', desc: 'No hay urgencia, busco la mejor opción' },
];

function next() {
  if (step.value < 5) step.value++;
}

function back() {
  if (step.value > 1) step.value--;
}

function validateStep3() {
  errors.value = {};
  if (!form.value.difficulty_text || form.value.difficulty_text.trim().length < 10) {
    errors.value.difficulty_text = 'Por favor describe el problema (mínimo 10 caracteres).';
    return;
  }
  next();
}

function submit() {
  if (!form.value.urgency || loading.value) return;
  loading.value = true;
  router.post(route('diagnostics.store'), form.value, {
    onError: (e) => { errors.value = e; loading.value = false; },
    onFinish: () => { loading.value = false; },
  });
}
</script>
