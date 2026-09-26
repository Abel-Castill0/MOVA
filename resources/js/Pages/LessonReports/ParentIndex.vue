<template>
  <AppLayout title="Reportes de aprendizaje" :sidebar-compressed="isQuizModalOpen">
    <div class="space-y-6 max-w-5xl mx-auto">

      <!-- Header de la sección -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h2 class="text-2xl font-black text-slate-900 tracking-tight">Reportes de aprendizaje</h2>
          <p class="text-sm text-slate-500 mt-1">
            Consulta el progreso, temas trabajados y valida el porcentaje de mejora de tus hijos tras cada clase.
          </p>
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="!reports.length" class="text-center py-16 bg-white rounded-3xl border border-gray-100 shadow-sm">
        <div class="text-5xl mb-3">📋</div>
        <p class="font-bold text-slate-800 text-base">Aún no hay reportes</p>
        <p class="text-slate-400 text-sm mt-1">Los reportes aparecerán aquí después de cada clase.</p>
        <Link :href="route('class-requests.create')" class="inline-block mt-4 px-6 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-2xl hover:bg-brand-700 shadow-sm shadow-brand-600/20 transition-all">
          Solicitar una clase →
        </Link>
      </div>

      <!-- Lista de reportes -->
      <div v-else class="space-y-6">
        <div
          v-for="r in enhancedReports"
          :key="r.id"
          class="bg-white rounded-3xl border border-slate-200/80 hover:border-brand-200 hover:shadow-lg transition-all duration-300 overflow-hidden"
        >
          <!-- Barra superior de color por materia -->
          <div :class="['h-1.5 w-full bg-gradient-to-r', subjectTheme(r.subject).stripeGradient]"></div>

          <div class="p-5 sm:p-7 space-y-6">

            <!-- Encabezado del reporte: Curso, Alumno, Profesor y Fecha -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
              <div class="space-y-1.5">
                <div class="flex flex-wrap items-center gap-2">
                  <!-- Badge del Curso -->
                  <span :class="['inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold border shadow-2xs', subjectTheme(r.subject).badge]">
                    <span>{{ subjectTheme(r.subject).icon }}</span>
                    <span>{{ r.subject }}</span>
                  </span>

                  <!-- Fecha de la clase -->
                  <span class="text-xs font-medium text-slate-400 flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    {{ fmtDate(r.start_time) }}
                  </span>
                </div>

                <!-- Alumno y Profesor -->
                <h3 class="text-lg font-black text-slate-900 flex flex-wrap items-center gap-x-2">
                  <span>{{ r.student_name }}</span>
                  <span class="text-slate-300 font-normal">·</span>
                  <span class="text-sm font-semibold text-slate-500">Prof. {{ r.teacher_name }}</span>
                </h3>
              </div>
            </div>

            <!-- ── SECCIÓN DESTACADA: Indicador de % de mejora y Cuestionario ── -->
            <div
              :class="[
                'rounded-2xl p-4 sm:p-5 border transition-all duration-300',
                r.quiz.completed
                  ? 'bg-gradient-to-br from-emerald-50/80 via-teal-50/40 to-white border-emerald-200/90 shadow-sm'
                  : 'bg-gradient-to-br from-blue-50/80 via-indigo-50/40 to-white border-blue-200/90 shadow-sm'
              ]"
            >
              <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                
                <!-- Indicador visual de porcentaje de mejora -->
                <div class="space-y-2 flex-1">
                  <div class="flex items-center gap-2">
                    <span
                      :class="[
                        'px-2.5 py-0.5 rounded-full text-[11px] font-extrabold uppercase tracking-wider inline-flex items-center gap-1',
                        r.quiz.completed
                          ? 'bg-emerald-100 text-emerald-800'
                          : 'bg-blue-100 text-blue-800'
                      ]"
                    >
                      <span v-if="r.quiz.completed">✓ Cuestionario realizado</span>
                      <span v-else>Evaluación de clase</span>
                    </span>

                    <span v-if="r.quiz.completed" class="text-xs text-emerald-700 font-bold">
                      {{ r.quiz.score }}/{{ r.quiz.total }} aciertos ({{ Math.round((r.quiz.score / r.quiz.total) * 100) }}%)
                    </span>
                  </div>

                  <div class="flex flex-wrap items-baseline gap-2 sm:gap-3">
                    <!-- Porcentaje de mejora destacado -->
                    <div
                      :class="[
                        'text-2xl sm:text-3xl font-black tracking-tight flex items-center gap-1.5',
                        r.quiz.completed ? 'text-emerald-700' : 'text-blue-700'
                      ]"
                    >
                      <svg class="w-6 h-6 stroke-[3]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                      </svg>
                      <span>+{{ r.quiz.completed ? r.quiz.validatedImprovement : r.quiz.initialImprovement }}%</span>
                    </div>

                    <span class="text-sm font-bold text-slate-800">
                      {{ r.quiz.completed ? 'Mejora consolidada tras cuestionario' : 'Mejora observada en la última clase' }}
                    </span>
                  </div>

                  <!-- Mensaje explicativo según estado -->
                  <p class="text-xs text-slate-600 max-w-xl">
                    <template v-if="r.quiz.completed">
                      ¡Excelente resultado! Al completar el cuestionario de comprobación, el alumno demostró retención del tema y elevó su porcentaje de mejora del <span class="font-bold text-slate-700">+{{ r.quiz.initialImprovement }}%</span> inicial al <span class="font-bold text-emerald-700">+{{ r.quiz.validatedImprovement }}%</span> validado.
                    </template>
                    <template v-else>
                      El profesor registró un <span class="font-semibold text-slate-700">+{{ r.quiz.initialImprovement }}%</span> de avance en la sesión. Resuelve el cuestionario de comprobación de 3 preguntas para validar el dominio del tema y registrar la mejora definitiva.
                    </template>
                  </p>

                  <!-- Barra de progreso visual -->
                  <div class="w-full bg-slate-200/70 h-2 rounded-full overflow-hidden mt-2 max-w-md">
                    <div
                      :class="[
                        'h-full rounded-full transition-all duration-700 ease-out',
                        r.quiz.completed
                          ? 'bg-gradient-to-r from-emerald-500 to-teal-500'
                          : 'bg-gradient-to-r from-brand-500 to-blue-500'
                      ]"
                      :style="{ width: r.quiz.completed ? '92%' : '65%' }"
                    ></div>
                  </div>
                </div>

                <!-- Botón de acción para el cuestionario -->
                <div class="flex flex-col sm:flex-row lg:flex-col items-stretch sm:items-center lg:items-end gap-2 shrink-0">
                  <button
                    v-if="!r.quiz.completed"
                    type="button"
                    @click="startQuiz(r)"
                    class="inline-flex items-center justify-center gap-2 px-5 py-3 bg-gradient-to-r from-brand-600 to-blue-600 hover:from-brand-700 hover:to-blue-700 text-white text-sm font-bold rounded-2xl shadow-md shadow-brand-600/25 hover:shadow-lg active:scale-95 transition-all duration-200 cursor-pointer"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    <span>Resolver cuestionario</span>
                    <span class="bg-white/20 text-white text-[10px] px-2 py-0.5 rounded-full font-black ml-1">3 preguntas</span>
                  </button>

                  <div v-else class="flex flex-col items-end gap-2 w-full sm:w-auto">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-100/90 text-emerald-800 text-xs font-bold rounded-xl border border-emerald-200/80">
                      <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                      </svg>
                      Cuestionario completado
                    </span>
                    <button
                      type="button"
                      @click="reviewQuiz(r)"
                      class="text-xs font-bold text-brand-600 hover:text-brand-800 hover:underline px-2 py-1 transition-colors cursor-pointer"
                    >
                      Ver respuestas y detalles →
                    </button>
                  </div>
                </div>

              </div>
            </div>

            <!-- Detalles del reporte pedagógico -->
            <div class="grid sm:grid-cols-2 gap-3 pt-2">
              <div class="bg-slate-50/80 border border-slate-100 rounded-2xl p-4 transition-colors hover:bg-slate-50">
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>📚</span>
                  <span>Tema trabajado</span>
                </p>
                <p class="text-sm font-medium text-slate-800">{{ r.topic_covered }}</p>
              </div>

              <div class="bg-slate-50/80 border border-slate-100 rounded-2xl p-4 transition-colors hover:bg-slate-50">
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>⭐</span>
                  <span>Desempeño en clase</span>
                </p>
                <p class="text-sm font-medium text-slate-800">{{ r.student_performance }}</p>
              </div>

              <div v-if="r.difficulties_detected" class="bg-orange-50/70 border border-orange-100 rounded-2xl p-4">
                <p class="text-xs font-bold text-orange-600 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>⚠️</span>
                  <span>Puntos a reforzar</span>
                </p>
                <p class="text-sm text-slate-800">{{ r.difficulties_detected }}</p>
              </div>

              <div v-if="r.homework_assigned" class="bg-amber-50/70 border border-amber-100 rounded-2xl p-4">
                <p class="text-xs font-bold text-amber-700 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>📝</span>
                  <span>Tarea asignada</span>
                </p>
                <p class="text-sm text-slate-800">{{ r.homework_assigned }}</p>
              </div>

              <div v-if="r.teacher_recommendation" class="sm:col-span-2 bg-blue-50/70 border border-blue-100 rounded-2xl p-4">
                <p class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>💬</span>
                  <span>Mensaje y recomendación del profesor</span>
                </p>
                <p class="text-sm text-slate-800 leading-relaxed">{{ r.teacher_recommendation }}</p>
              </div>

              <div v-if="r.next_step" class="sm:col-span-2 bg-emerald-50/70 border border-emerald-100 rounded-2xl p-4">
                <p class="text-xs font-bold text-emerald-700 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                  <span>🎯</span>
                  <span>Próximo paso</span>
                </p>
                <p class="text-sm text-slate-800">{{ r.next_step }}</p>
              </div>
            </div>

          </div>
        </div>
      </div>

    </div>

    <!-- ── MODAL INTERACTIVO DE CUESTIONARIO (CON COMPRESIÓN DEL SIDEBAR) ── -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="isQuizModalOpen"
          class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-md flex items-center justify-center p-4 sm:p-6"
          @click.self="requestCloseQuiz"
        >
          <!-- Contenedor del Modal -->
          <div
            class="relative w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-slate-100 overflow-hidden transform transition-all duration-300 animate-in fade-in zoom-in-95"
          >
            <!-- Cabecera del Cuestionario -->
            <div class="px-6 py-5 bg-gradient-to-r from-slate-900 via-brand-900 to-slate-900 text-white flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-brand-500/30 border border-brand-400/30 flex items-center justify-center text-xl">
                  {{ activeSubjectInfo.icon }}
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <span class="text-xs uppercase font-extrabold tracking-wider text-brand-300">{{ activeQuizReport?.subject }}</span>
                    <span class="text-white/40">·</span>
                    <span class="text-xs text-white/80 font-medium">{{ activeQuizReport?.student_name }}</span>
                  </div>
                  <h4 class="text-base font-black text-white leading-tight">
                    Cuestionario de comprobación
                  </h4>
                </div>
              </div>

              <!-- Botón cerrar (con interceptor de confirmación) -->
              <button
                type="button"
                @click="requestCloseQuiz"
                aria-label="Cerrar cuestionario"
                class="w-9 h-9 rounded-xl bg-white/10 hover:bg-white/20 active:bg-white/30 text-white flex items-center justify-center transition-colors cursor-pointer"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <!-- Barra de progreso de preguntas (si está respondiendo) -->
            <div v-if="!quizFinished" class="bg-slate-100 px-6 py-3 border-b border-slate-200/80 flex items-center justify-between text-xs">
              <span class="font-bold text-slate-700">
                Pregunta {{ currentQuestionIndex + 1 }} de {{ activeQuestions.length }}
              </span>
              <div class="flex items-center gap-2">
                <div class="w-32 sm:w-44 bg-slate-200 h-2 rounded-full overflow-hidden">
                  <div
                    class="bg-brand-600 h-full rounded-full transition-all duration-300"
                    :style="{ width: `${((currentQuestionIndex + 1) / activeQuestions.length) * 100}%` }"
                  ></div>
                </div>
                <span class="font-bold text-brand-700">{{ Math.round(((currentQuestionIndex + 1) / activeQuestions.length) * 100) }}%</span>
              </div>
            </div>

            <!-- CUERPO 1: Pregunta activa -->
            <div v-if="!quizFinished" class="p-6 sm:p-8 space-y-6">
              <div>
                <p class="text-xs font-bold text-brand-600 uppercase tracking-wider mb-1">
                  Tema: {{ activeQuizReport?.topic_covered }}
                </p>
                <h5 class="text-lg sm:text-xl font-bold text-slate-900 leading-snug">
                  {{ currentQuestion.question }}
                </h5>
              </div>

              <!-- Opciones de respuesta -->
              <div class="space-y-3">
                <button
                  v-for="(opt, oIndex) in currentQuestion.options"
                  :key="opt.id"
                  type="button"
                  @click="selectOption(opt.id)"
                  :class="[
                    'w-full text-left p-4 rounded-2xl border-2 transition-all duration-150 flex items-center gap-3.5 cursor-pointer',
                    selectedAnswers[currentQuestionIndex] === opt.id
                      ? 'border-brand-600 bg-brand-50/70 shadow-sm text-brand-950 font-semibold'
                      : 'border-slate-200 hover:border-brand-300 hover:bg-slate-50 text-slate-800'
                  ]"
                >
                  <div
                    :class="[
                      'w-8 h-8 rounded-xl font-bold flex items-center justify-center text-xs shrink-0 transition-colors',
                      selectedAnswers[currentQuestionIndex] === opt.id
                        ? 'bg-brand-600 text-white shadow-xs'
                        : 'bg-slate-100 text-slate-600'
                    ]"
                  >
                    {{ ['A', 'B', 'C', 'D'][oIndex] }}
                  </div>
                  <span class="text-sm flex-1">{{ opt.text }}</span>
                  <div
                    v-if="selectedAnswers[currentQuestionIndex] === opt.id"
                    class="w-5 h-5 rounded-full bg-brand-600 text-white flex items-center justify-center text-xs shrink-0"
                  >
                    ✓
                  </div>
                </button>
              </div>

              <!-- Footer de navegación del cuestionario -->
              <div class="pt-4 border-t border-slate-100 flex items-center justify-between gap-3">
                <button
                  type="button"
                  @click="requestCloseQuiz"
                  class="px-4 py-2.5 text-xs sm:text-sm font-semibold text-slate-500 hover:text-red-600 transition-colors cursor-pointer"
                >
                  Abandonar cuestionario
                </button>

                <div class="flex items-center gap-2">
                  <button
                    v-if="currentQuestionIndex > 0"
                    type="button"
                    @click="currentQuestionIndex--"
                    class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs sm:text-sm rounded-xl transition-colors cursor-pointer"
                  >
                    ← Anterior
                  </button>

                  <button
                    type="button"
                    :disabled="!selectedAnswers[currentQuestionIndex]"
                    @click="handleNextOrFinish"
                    :class="[
                      'px-6 py-2.5 font-bold text-xs sm:text-sm rounded-xl transition-all cursor-pointer shadow-md',
                      selectedAnswers[currentQuestionIndex]
                        ? 'bg-brand-600 hover:bg-brand-700 text-white shadow-brand-600/25 active:scale-95'
                        : 'bg-slate-200 text-slate-400 cursor-not-allowed shadow-none'
                    ]"
                  >
                    <span v-if="currentQuestionIndex < activeQuestions.length - 1">Siguiente pregunta →</span>
                    <span v-else>Finalizar y calcular mejora ✓</span>
                  </button>
                </div>
              </div>
            </div>

            <!-- CUERPO 2: Pantalla de Resultados y Cálculo de Mejora -->
            <div v-else class="p-6 sm:p-8 space-y-6 text-center">
              <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-3xl mx-auto shadow-inner animate-bounce">
                🎉
              </div>

              <div class="space-y-1">
                <span class="px-3 py-1 bg-emerald-100 text-emerald-800 text-xs font-black rounded-full uppercase tracking-wider">
                  ¡Cuestionario completado con éxito!
                </span>
                <h4 class="text-2xl font-black text-slate-900 mt-2">
                  {{ calculatedScore.correct }} de {{ calculatedScore.total }} respuestas correctas
                </h4>
                <p class="text-sm text-slate-500 max-w-md mx-auto">
                  Has validado la comprensión del alumno sobre <span class="font-bold text-slate-700">{{ activeQuizReport?.topic_covered }}</span>.
                </p>
              </div>

              <!-- Tarjeta de incremento de mejora -->
              <div class="p-5 rounded-2xl bg-gradient-to-br from-emerald-50 via-teal-50 to-emerald-50/50 border border-emerald-200 text-left space-y-3">
                <p class="text-xs font-bold text-emerald-800 uppercase tracking-wider">
                  Impacto en el porcentaje de aprendizaje
                </p>

                <div class="grid grid-cols-2 gap-3">
                  <div class="bg-white/80 p-3.5 rounded-xl border border-emerald-100">
                    <p class="text-[11px] font-semibold text-slate-500">Mejora en clase</p>
                    <p class="text-xl font-bold text-slate-700">+{{ activeQuizReport?.quiz?.initialImprovement }}%</p>
                  </div>

                  <div class="bg-emerald-600 text-white p-3.5 rounded-xl shadow-sm">
                    <p class="text-[11px] font-semibold text-emerald-100">Mejora consolidada</p>
                    <p class="text-xl font-black">+{{ calculatedValidatedImprovement }}% <span class="text-xs font-normal text-emerald-200">(+{{ calculatedValidatedImprovement - activeQuizReport?.quiz?.initialImprovement }}%)</span></p>
                  </div>
                </div>

                <p class="text-xs text-emerald-900 font-medium leading-relaxed">
                  ✓ El reporte ahora reflejará que este cuestionario fue completado y mostrará el porcentaje definitivo de mejora del estudiante.
                </p>
              </div>

              <!-- Botón para guardar y salir -->
              <div class="pt-2 flex flex-col sm:flex-row items-center justify-center gap-3">
                <button
                  type="button"
                  @click="applyQuizResults"
                  class="w-full sm:w-auto px-8 py-3 bg-brand-600 hover:bg-brand-700 text-white font-bold text-sm rounded-2xl shadow-lg shadow-brand-600/30 active:scale-95 transition-all cursor-pointer"
                >
                  Guardar y ver en reportes
                </button>
              </div>
            </div>

            <!-- ── DIÁLOGO DE CONFIRMACIÓN DE ABANDONO ── -->
            <Transition
              enter-active-class="transition duration-200 ease-out"
              enter-from-class="opacity-0 scale-95"
              enter-to-class="opacity-100 scale-100"
              leave-active-class="transition duration-150 ease-in"
              leave-from-class="opacity-100 scale-100"
              leave-to-class="opacity-0 scale-95"
            >
              <div
                v-if="showExitConfirm"
                class="absolute inset-0 bg-slate-950/80 backdrop-blur-xs flex items-center justify-center p-6 z-20"
              >
                <div class="bg-white rounded-3xl p-6 sm:p-7 max-w-md w-full shadow-2xl border border-slate-100 text-center space-y-4">
                  <div class="w-12 h-12 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center text-2xl mx-auto">
                    ⚠️
                  </div>

                  <div class="space-y-1.5">
                    <h5 class="text-lg font-black text-slate-900">
                      ¿Deseas salir del cuestionario?
                    </h5>
                    <p class="text-xs text-slate-600 leading-relaxed">
                      Tu progreso en este cuestionario <span class="font-bold text-slate-900">no será guardado</span> y se mantendrá el porcentaje de mejora actual de la clase sin validar.
                    </p>
                  </div>

                  <div class="pt-2 flex flex-col sm:flex-row items-center justify-center gap-2.5">
                    <button
                      type="button"
                      @click="showExitConfirm = false"
                      class="w-full sm:w-auto px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs sm:text-sm font-bold rounded-xl transition-colors cursor-pointer"
                    >
                      Continuar cuestionario
                    </button>
                    <button
                      type="button"
                      @click="confirmExitQuiz"
                      class="w-full sm:w-auto px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-xs sm:text-sm font-bold rounded-xl shadow-sm shadow-red-600/30 transition-colors cursor-pointer"
                    >
                      Sí, salir sin guardar
                    </button>
                  </div>
                </div>
              </div>
            </Transition>

          </div>
        </div>
      </Transition>
    </Teleport>

  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ reports: { type: Array, default: () => [] } })

// Estado reactivo para las evaluaciones / cuestionarios por ID de reporte
// Permite que la vista refleje inmediatamente si el cuestionario fue completado
// y calcule el porcentaje de mejora dinámicamente.
const quizState = reactive({})

// Inicializamos el estado para cada reporte
props.reports.forEach((r, idx) => {
  if (!quizState[r.id]) {
    // Para demostración interactiva: el primer reporte (Inglés) se inicia pendiente,
    // o el usuario puede resolver cualquiera.
    quizState[r.id] = {
      completed: false,
      score: 0,
      total: 3,
      initialImprovement: 15 + (idx * 3), // +15%, +18%
      validatedImprovement: 28 + (idx * 4), // +28%, +32%
      completedAt: null
    }
  }
})

// Lista enriquecida con el estado de cuestionario y mejora
const enhancedReports = computed(() => {
  return props.reports.map((r, idx) => {
    const q = quizState[r.id] || {
      completed: false,
      score: 0,
      total: 3,
      initialImprovement: 15 + (idx * 3),
      validatedImprovement: 28 + (idx * 4),
    }
    return {
      ...r,
      quiz: q
    }
  })
})

// Control del modal de cuestionario
const isQuizModalOpen = ref(false)
const showExitConfirm = ref(false)
const activeQuizReport = ref(null)
const currentQuestionIndex = ref(0)
const selectedAnswers = reactive({})
const quizFinished = ref(false)

// Banco de preguntas contextuales por materia
const questionsBySubject = {
  Matemáticas: [
    {
      id: 'm1',
      question: '¿Cuáles son las soluciones para la ecuación cuadrática x² - 9 = 0?',
      options: [
        { id: 'a', text: 'x = 3 y x = -3' },
        { id: 'b', text: 'x = 9 y x = 0' },
        { id: 'c', text: 'x = 3 únicamente' },
        { id: 'd', text: 'x = -9 y x = 9' }
      ],
      correct: 'a'
    },
    {
      id: 'm2',
      question: '¿Cuál es la factorización correcta del trinomio x² + 5x + 6?',
      options: [
        { id: 'a', text: '(x + 2)(x + 3)' },
        { id: 'b', text: '(x + 1)(x + 6)' },
        { id: 'c', text: '(x - 2)(x - 3)' },
        { id: 'd', text: '(x + 5)(x + 1)' }
      ],
      correct: 'a'
    },
    {
      id: 'm3',
      question: 'En la fórmula cuadrática general, ¿cómo se llama el término (b² - 4ac)?',
      options: [
        { id: 'a', text: 'Discriminante' },
        { id: 'b', text: 'Vértice' },
        { id: 'c', text: 'Coeficiente simétrico' },
        { id: 'd', text: 'Hipotenusa' }
      ],
      correct: 'a'
    }
  ],
  Inglés: [
    {
      id: 'i1',
      question: "Choose the correct sentence in Present Simple for the third person singular (she):",
      options: [
        { id: 'a', text: 'She plays tennis every Saturday.' },
        { id: 'b', text: 'She play tennis every Saturday.' },
        { id: 'c', text: 'She is play tennis every Saturday.' },
        { id: 'd', text: 'She playing tennis every Saturday.' }
      ],
      correct: 'a'
    },
    {
      id: 'i2',
      question: "Complete the sentence: 'They _____ to school by bus every morning.'",
      options: [
        { id: 'a', text: 'go' },
        { id: 'b', text: 'goes' },
        { id: 'c', text: 'are go' },
        { id: 'd', text: 'gone' }
      ],
      correct: 'a'
    },
    {
      id: 'i3',
      question: "Which of the following is the correct negative form of 'He likes coffee'?",
      options: [
        { id: 'a', text: "He doesn't like coffee." },
        { id: 'b', text: "He don't like coffee." },
        { id: 'c', text: "He not likes coffee." },
        { id: 'd', text: "He isn't like coffee." }
      ],
      correct: 'a'
    }
  ]
}

// Preguntas genéricas de respaldo si la materia no está en el diccionario específico
const genericQuestions = [
  {
    id: 'g1',
    question: '¿Cuál de los siguientes conceptos fue el eje principal desarrollado en la sesión?',
    options: [
      { id: 'a', text: 'Comprensión de conceptos clave y aplicación de ejemplos prácticos.' },
      { id: 'b', text: 'Solo lectura pasiva sin ejercicios guiados.' },
      { id: 'c', text: 'Evaluación teórica previa sin interacción.' },
      { id: 'd', text: 'Ninguna de las anteriores.' }
    ],
    correct: 'a'
  },
  {
    id: 'g2',
    question: 'Al resolver los ejercicios propuestos por el profesor, ¿qué estrategia demostró mayor efectividad?',
    options: [
      { id: 'a', text: 'Identificar los datos iniciales y aplicar la regla paso a paso.' },
      { id: 'b', text: 'Intentar adivinar la respuesta al azar.' },
      { id: 'c', text: 'Saltar a la conclusión sin verificar.' },
      { id: 'd', text: 'Omitir las observaciones del profesor.' }
    ],
    correct: 'a'
  },
  {
    id: 'g3',
    question: '¿Qué paso se recomienda para consolidar el aprendizaje antes de la próxima clase?',
    options: [
      { id: 'a', text: 'Realizar la tarea asignada y repasar las dudas anotadas.' },
      { id: 'b', text: 'No revisar el tema hasta el próximo mes.' },
      { id: 'c', text: 'Olvidar las fórmulas explicadas.' },
      { id: 'd', text: 'No tomar notas de clase.' }
    ],
    correct: 'a'
  }
]

// Preguntas activas según el reporte seleccionado
const activeQuestions = computed(() => {
  if (!activeQuizReport.value) return genericQuestions
  const sub = activeQuizReport.value.subject
  if (sub && questionsBySubject[sub]) {
    return questionsBySubject[sub]
  }
  return genericQuestions
})

const currentQuestion = computed(() => {
  return activeQuestions.value[currentQuestionIndex.value] || activeQuestions.value[0]
})

// Temas y colores por materia
function subjectTheme(subject) {
  const s = (subject || '').toLowerCase()
  if (s.includes('mat')) {
    return {
      icon: '📐',
      badge: 'bg-blue-50 text-blue-700 border-blue-200',
      stripeGradient: 'from-blue-600 via-indigo-600 to-brand-600'
    }
  }
  if (s.includes('ing') || s.includes('leng') || s.includes('idiom')) {
    return {
      icon: '🇬🇧',
      badge: 'bg-purple-50 text-purple-700 border-purple-200',
      stripeGradient: 'from-purple-600 via-indigo-600 to-brand-600'
    }
  }
  if (s.includes('cien') || s.includes('fís') || s.includes('quím') || s.includes('bio')) {
    return {
      icon: '🔬',
      badge: 'bg-emerald-50 text-emerald-700 border-emerald-200',
      stripeGradient: 'from-emerald-600 via-teal-600 to-brand-600'
    }
  }
  return {
    icon: '📚',
    badge: 'bg-brand-50 text-brand-700 border-brand-200',
    stripeGradient: 'from-brand-600 via-blue-600 to-indigo-600'
  }
}

const activeSubjectInfo = computed(() => {
  return subjectTheme(activeQuizReport.value?.subject)
})

// Iniciar cuestionario
function startQuiz(report) {
  activeQuizReport.value = report
  currentQuestionIndex.value = 0
  // Limpiar respuestas
  Object.keys(selectedAnswers).forEach(k => delete selectedAnswers[k])
  quizFinished.value = false
  showExitConfirm.value = false
  isQuizModalOpen.value = true
}

// Revisar cuestionario ya completado
function reviewQuiz(report) {
  activeQuizReport.value = report
  currentQuestionIndex.value = 0
  // Pre-cargar respuestas correctas
  const qs = activeQuestions.value
  qs.forEach((q, i) => {
    selectedAnswers[i] = q.correct
  })
  quizFinished.value = true
  showExitConfirm.value = false
  isQuizModalOpen.value = true
}

// Seleccionar opción
function selectOption(optionId) {
  selectedAnswers[currentQuestionIndex.value] = optionId
}

// Siguiente o finalizar
function handleNextOrFinish() {
  if (currentQuestionIndex.value < activeQuestions.value.length - 1) {
    currentQuestionIndex.value++
  } else {
    quizFinished.value = true
  }
}

// Cálculos de puntaje y mejora
const calculatedScore = computed(() => {
  const qs = activeQuestions.value
  let correct = 0
  qs.forEach((q, i) => {
    if (selectedAnswers[i] === q.correct) {
      correct++
    }
  })
  return {
    correct,
    total: qs.length
  }
})

const calculatedValidatedImprovement = computed(() => {
  const base = activeQuizReport.value?.quiz?.initialImprovement || 15
  const score = calculatedScore.value
  // Bono por aciertos: hasta +13% adicional si acertó todas
  const bonus = Math.round((score.correct / score.total) * 13)
  return base + bonus
})

// Aplicar y guardar resultados en el estado reactivo
function applyQuizResults() {
  if (!activeQuizReport.value) return
  const rId = activeQuizReport.value.id
  if (!quizState[rId]) {
    quizState[rId] = {}
  }
  quizState[rId].completed = true
  quizState[rId].score = calculatedScore.value.correct
  quizState[rId].total = calculatedScore.value.total
  quizState[rId].validatedImprovement = calculatedValidatedImprovement.value
  quizState[rId].completedAt = new Date().toISOString()

  // Cerrar modal y descomprimir sidebar
  isQuizModalOpen.value = false
  showExitConfirm.value = false
}

// Manejo de abandono y confirmación
function requestCloseQuiz() {
  // Si ya terminó o solo estaba viendo resultados, cerrar sin confirmación
  if (quizFinished.value) {
    isQuizModalOpen.value = false
    showExitConfirm.value = false
    return
  }
  // Si está a mitad del cuestionario, mostrar alerta de confirmación
  showExitConfirm.value = true
}

function confirmExitQuiz() {
  showExitConfirm.value = false
  isQuizModalOpen.value = false
  activeQuizReport.value = null
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
  })
}
</script>
