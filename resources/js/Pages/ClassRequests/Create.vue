<template>
  <AppLayout title="Solicitar clase" :seamless="true">
    <!-- Símbolos Matemáticos Flotantes tipo Filigrana por toda la pantalla (Elegantes y con Parallax) -->
    <div ref="wizardCanvasRef" class="fixed inset-0 overflow-hidden pointer-events-none select-none z-0">
      <span class="math-sym math-sym-1" data-depth="0.04" aria-hidden="true">+</span>
      <span class="math-sym math-sym-2" data-depth="0.07" aria-hidden="true">−</span>
      <span class="math-sym math-sym-3" data-depth="0.05" aria-hidden="true">÷</span>
      <span class="math-sym math-sym-4" data-depth="0.09" aria-hidden="true">×</span>
      <span class="math-sym math-sym-5" data-depth="0.03" aria-hidden="true">√</span>
      <span class="math-sym math-sym-6" data-depth="0.06" aria-hidden="true">∑</span>
      <span class="math-sym math-sym-7" data-depth="0.08" aria-hidden="true">π</span>
      <span class="math-sym math-sym-8" data-depth="0.05" aria-hidden="true">∞</span>
      <span class="math-sym math-sym-9" data-depth="0.10" aria-hidden="true">∫</span>
      <span class="math-sym math-sym-10" data-depth="0.04" aria-hidden="true">Δ</span>
      <span class="math-sym math-sym-11" data-depth="0.06" aria-hidden="true">%</span>
    </div>

    <!-- ── Contenedor Central del Wizard (Full Screen Responsive en Móviles) ── -->
    <div class="relative z-10 max-w-2xl mx-auto w-full flex-1 flex flex-col min-h-0 space-y-2.5 sm:space-y-6 pt-0 sm:pt-2 pb-2 sm:pb-8">

        <!-- ── Cabecera Desktop (Completa con títulos y etiquetas) ────────── -->
        <div class="hidden sm:block space-y-4">
          <!-- Badge interactivo refinado -->
          <div class="inline-flex items-center gap-2 px-3.5 py-1.5 bg-brand-50/80 border border-brand-200/70 rounded-full text-xs font-semibold text-brand-700 shadow-xs backdrop-blur-sm">
            <span class="relative flex h-2 w-2">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
            </span>
            <span>Reserva guiada · Profesores verificados</span>
          </div>

          <!-- Título y Contador -->
          <div class="flex sm:items-end justify-between gap-2">
            <div>
              <h2 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                Solicitar una <span class="text-brand-600">clase</span>
              </h2>
              <p class="text-xs sm:text-sm text-slate-500 mt-1">
                Cuéntanos qué necesita tu hijo/a — el proceso toma menos de un minuto.
              </p>
            </div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-white border border-slate-200/90 rounded-full text-xs font-semibold text-slate-600 shadow-xs">
              <span class="text-slate-400">Paso</span>
              <span class="text-slate-900 font-bold">{{ currentStep }}</span>
              <span class="text-slate-300">/</span>
              <span class="text-slate-500">{{ TOTAL_STEPS }}</span>
            </div>
          </div>

          <!-- Stepper Estilo Apple Segmented Control (Desktop) -->
          <div class="bg-slate-200/50 backdrop-blur-sm p-1 rounded-2xl flex gap-1 border border-slate-200/70">
            <div
              v-for="(label, idx) in STEP_LABELS"
              :key="idx"
              :class="[
                'flex-1 flex items-center justify-center gap-1.5 py-2 px-1 rounded-xl text-xs transition-all duration-200 select-none',
                (idx + 1) === currentStep
                  ? 'bg-white text-slate-900 font-bold shadow-sm ring-1 ring-black/5'
                  : (idx + 1) < currentStep
                    ? 'text-brand-700 font-medium hover:bg-white/40'
                    : 'text-slate-400 font-medium'
              ]"
            >
              <div
                :class="[
                  'w-4 h-4 rounded-full flex items-center justify-center text-[10px] font-bold flex-shrink-0 transition-all',
                  (idx + 1) === currentStep
                    ? 'bg-brand-600 text-white'
                    : (idx + 1) < currentStep
                      ? 'bg-emerald-100 text-emerald-700'
                      : 'bg-slate-300/60 text-slate-500'
                ]"
              >
                <svg v-if="(idx + 1) < currentStep" class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                  <polyline points="20 6 9 17 4 12" />
                </svg>
                <span v-else>{{ idx + 1 }}</span>
              </div>
              <span class="hidden sm:inline truncate">{{ label }}</span>
            </div>
          </div>

          <!-- Barra de Progreso Fina -->
          <div class="w-full h-1 bg-slate-200/60 rounded-full overflow-hidden">
            <div
              class="h-full bg-brand-600 rounded-full transition-all duration-300 ease-out"
              :style="{ width: ((currentStep / TOTAL_STEPS) * 100) + '%' }"
            />
          </div>
        </div>

        <!-- ── Cabecera Mobile Compacta (Optimizada para pantalla completa) ── -->
        <div class="sm:hidden space-y-2">
          <div class="flex items-center justify-between">
            <div class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-brand-50/90 border border-brand-200/70 rounded-full text-[11px] font-semibold text-brand-700 shadow-2xs backdrop-blur-sm">
              <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
              </span>
              <span>Reserva guiada</span>
            </div>

            <div class="inline-flex items-center gap-1 px-2.5 py-0.5 bg-white border border-slate-200/90 rounded-full text-[11px] font-semibold text-slate-600 shadow-2xs">
              <span class="text-slate-400">Paso</span>
              <span class="text-slate-900 font-bold">{{ currentStep }}</span>
              <span class="text-slate-300">/</span>
              <span class="text-slate-500">{{ TOTAL_STEPS }}</span>
            </div>
          </div>

          <!-- Stepper Mobile Compacto -->
          <div class="bg-slate-200/50 backdrop-blur-sm p-0.5 rounded-xl flex gap-1 border border-slate-200/60">
            <div
              v-for="(label, idx) in STEP_LABELS"
              :key="idx"
              :class="[
                'flex-1 flex items-center justify-center py-1 rounded-lg transition-all duration-200 select-none',
                (idx + 1) === currentStep
                  ? 'bg-white shadow-xs'
                  : (idx + 1) < currentStep
                    ? 'text-emerald-700'
                    : 'text-slate-400'
              ]"
            >
              <div
                :class="[
                  'w-4 h-4 rounded-full flex items-center justify-center text-[9px] font-bold flex-shrink-0 transition-all',
                  (idx + 1) === currentStep
                    ? 'bg-brand-600 text-white'
                    : (idx + 1) < currentStep
                      ? 'bg-emerald-100 text-emerald-700'
                      : 'bg-slate-300/60 text-slate-500'
                ]"
              >
                <svg v-if="(idx + 1) < currentStep" class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                  <polyline points="20 6 9 17 4 12" />
                </svg>
                <span v-else>{{ idx + 1 }}</span>
              </div>
            </div>
          </div>

          <!-- Barra de Progreso Fina -->
          <div class="w-full h-1 bg-slate-200/60 rounded-full overflow-hidden">
            <div
              class="h-full bg-brand-600 rounded-full transition-all duration-300 ease-out"
              :style="{ width: ((currentStep / TOTAL_STEPS) * 100) + '%' }"
            />
          </div>
        </div>

        <!-- ── Contexto del profesor (solo si se llegó con offer) ────────── -->
        <div v-if="offer" class="bg-white/90 backdrop-blur-xl rounded-xl sm:rounded-2xl border border-slate-200/80 p-3 sm:p-5 flex items-start gap-3 sm:gap-4 shadow-xs">
          <div class="w-9 h-9 sm:w-12 sm:h-12 bg-gradient-to-br from-brand-600 to-brand-700 rounded-xl flex items-center justify-center text-white font-black text-sm sm:text-base flex-shrink-0 shadow-xs">
            {{ offer.teacher_profile?.user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
          </div>
          <div class="min-w-0 flex-1">
            <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-brand-50 border border-brand-200 text-[10px] sm:text-[11px] font-bold text-brand-700 uppercase tracking-wider mb-0.5">
              <span class="w-1.5 h-1.5 rounded-full bg-brand-600"></span> Solicitud dirigida a
            </div>
            <p class="font-bold text-slate-900 text-sm sm:text-base truncate">{{ offer.teacher_profile?.user?.name }}</p>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5 flex flex-wrap items-center gap-1.5 sm:gap-2">
              <span class="text-slate-800 font-semibold">{{ offer.subject?.name }}</span>
              <span class="text-slate-300">·</span>
              <span class="text-brand-700 font-bold">S/ {{ referenceRate }}/hora</span>
              <span class="text-[11px] text-slate-400">(referencial)</span>
            </p>
          </div>
        </div>

        <!-- ── Tarjeta Principal del Wizard (Llena la pantalla verticalmente) ─ -->
        <div class="relative flex-1 flex flex-col justify-between bg-white/95 backdrop-blur-xl rounded-2xl sm:rounded-3xl border border-slate-200/80 p-4 sm:p-7 shadow-[0_8px_30px_rgba(15,23,42,0.05)]">

          <Transition :name="transitionName">

            <!-- PASO 1: Hijo y Materia ───────────────────────────────────── -->
            <div v-if="currentStep === 1" key="step1" class="flex-1 flex flex-col justify-between space-y-4">
              <div class="space-y-4">
                <div>
                  <h3 class="text-lg sm:text-xl font-black text-slate-900 tracking-tight">¿Para quién es la clase?</h3>
                  <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Elige el estudiante y la materia que necesita reforzar.</p>
                </div>

                <!-- Hijo/a -->
                <div>
                  <label class="block text-[11px] sm:text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">
                    HIJO/A
                  </label>
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <button
                      v-for="s in students" :key="s.id"
                      type="button"
                      @click="form.student_id = s.id"
                      :aria-pressed="form.student_id === s.id"
                      :class="[
                        'group text-left rounded-2xl border-2 p-3 sm:p-3.5 transition-all duration-200 flex items-center justify-between',
                        form.student_id === s.id
                          ? 'border-brand-600 bg-brand-50/80 text-slate-900 shadow-sm ring-2 ring-brand-500/20'
                          : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/60 text-slate-700 shadow-2xs'
                      ]"
                    >
                      <div class="flex items-center gap-3 min-w-0">
                        <div :class="[
                          'w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center font-bold text-sm sm:text-base flex-shrink-0 transition-all shadow-xs',
                          form.student_id === s.id
                            ? 'bg-brand-600 text-white shadow-brand-500/25'
                            : 'bg-slate-100 text-slate-600 group-hover:bg-slate-200'
                        ]">
                          {{ s.full_name?.charAt(0)?.toUpperCase() ?? 'A' }}
                        </div>
                        <div class="min-w-0">
                          <p class="font-bold text-sm sm:text-base text-slate-900 truncate">{{ s.full_name }}</p>
                          <p class="text-[11px] text-slate-400 font-medium">Estudiante</p>
                        </div>
                      </div>

                      <!-- Indicador Check Circular Grande -->
                      <div :class="[
                        'w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 transition-all border-2',
                        form.student_id === s.id
                          ? 'bg-brand-600 border-brand-600 text-white scale-100 shadow-xs'
                          : 'border-slate-300 bg-white text-transparent scale-95 group-hover:border-slate-400'
                      ]">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                          <polyline points="20 6 9 17 4 12" />
                        </svg>
                      </div>
                    </button>
                  </div>
                  <InputError :message="form.errors.student_id" class="mt-1 text-rose-500 text-xs" />
                </div>

                <!-- Materia (si no hay offer) -->
                <div v-if="!offer">
                  <label class="block text-[11px] sm:text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">
                    MATERIA
                  </label>
                  <div class="grid grid-cols-2 gap-2 sm:gap-2.5">
                    <button
                      v-for="s in subjects" :key="s.id"
                      type="button"
                      @click="form.subject_id = s.id"
                      :aria-pressed="form.subject_id === s.id"
                      :class="[
                        'group text-left rounded-2xl border-2 px-3.5 py-3 sm:py-3.5 transition-all duration-200 flex items-center justify-between',
                        form.subject_id === s.id
                          ? 'border-brand-600 bg-brand-50/80 font-bold text-brand-900 shadow-sm ring-2 ring-brand-500/20'
                          : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/60 text-slate-800 shadow-2xs'
                      ]"
                    >
                      <div class="flex items-center gap-2.5 min-w-0">
                        <span class="text-lg sm:text-xl flex-shrink-0 leading-none" aria-hidden="true">{{ subjectEmoji(s.name) }}</span>
                        <span class="text-xs sm:text-sm font-bold truncate leading-tight">{{ s.name }}</span>
                      </div>
                      <div :class="[
                        'w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 ml-1 transition-all border',
                        form.subject_id === s.id
                          ? 'border-brand-600 bg-brand-600 text-white'
                          : 'border-slate-200 bg-transparent text-transparent'
                      ]">
                        <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                          <polyline points="20 6 9 17 4 12" />
                        </svg>
                      </div>
                    </button>
                  </div>
                  <InputError :message="form.errors.subject_id" class="mt-1 text-rose-500 text-xs" />
                </div>
              </div>

              <!-- Botón Siguiente Prominente abajo -->
              <div class="mt-auto pt-4 sm:pt-6">
                <button
                  type="button"
                  @click="goNext"
                  :disabled="!step1Valid"
                  class="w-full py-3.5 sm:py-4 px-6 bg-brand-600 hover:bg-brand-700 active:bg-brand-800 text-white text-sm sm:text-base font-bold rounded-2xl shadow-lg shadow-brand-600/25 active:scale-[0.99] disabled:opacity-40 disabled:cursor-not-allowed disabled:shadow-none transition-all flex items-center justify-center gap-2"
                >
                  <span>Siguiente</span>
                  <span class="text-base sm:text-lg font-black" aria-hidden="true">→</span>
                </button>
              </div>
            </div>

            <!-- PASO 2: Código de profesor ───────────────────────────────── -->
            <div v-else-if="currentStep === 2" key="step2" class="flex-1 flex flex-col justify-between space-y-4">
              <div class="space-y-4">
                <div>
                  <h3 class="text-lg sm:text-xl font-black text-slate-900 tracking-tight">¿Tienes el código de un profesor?</h3>
                  <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Si un profesor te facilitó su código personal, tu solicitud le llegará directo a él.</p>
                </div>

                <div>
                  <label class="block text-[11px] sm:text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">
                    Código del profesor (opcional)
                  </label>
                  <div class="relative">
                    <input
                      v-model="referralCodeInput"
                      type="text"
                      maxlength="6"
                      placeholder="EJ: WW5VRD"
                      class="w-full bg-slate-50 border-2 border-slate-200 rounded-2xl px-4 py-3.5 sm:py-4 text-center text-lg sm:text-2xl uppercase tracking-widest font-mono text-slate-900 placeholder:text-slate-400 focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 focus:outline-none transition-all shadow-inner"
                    />
                    <div v-if="codeLookup.status === 'checking'" class="absolute right-4 top-1/2 -translate-y-1/2 flex items-center gap-2 text-xs text-brand-600">
                      <svg class="animate-spin h-5 w-5 text-brand-600" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                      </svg>
                      <span class="font-bold">Buscando...</span>
                    </div>
                  </div>

                  <!-- Feedback del código -->
                  <div v-if="codeLookup.status === 'found'" class="mt-3 p-3.5 rounded-2xl bg-emerald-50 border-2 border-emerald-200 text-emerald-800 text-xs sm:text-sm font-semibold flex items-center gap-2.5">
                    <div class="w-6 h-6 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold flex-shrink-0">✓</div>
                    <span>Código verificado · Se enviará directamente a: <strong class="text-slate-900 font-bold">{{ codeLookup.name }}</strong></span>
                  </div>
                  <div v-else-if="codeLookup.status === 'not-found'" class="mt-3 p-3.5 rounded-2xl bg-rose-50 border-2 border-rose-200 text-rose-800 text-xs sm:text-sm font-semibold flex items-center gap-2.5">
                    <div class="w-6 h-6 rounded-full bg-rose-600 text-white flex items-center justify-center font-bold flex-shrink-0">✕</div>
                    <span>Código no encontrado. Verifica las 6 letras o continúa sin código.</span>
                  </div>
                  <InputError :message="form.errors.teacher_referral_code" class="mt-1 text-rose-500 text-xs" />

                  <!-- Nota informativa sin código -->
                  <div v-if="codeLookup.status === 'idle' && !referralCodeInput" class="mt-3 sm:mt-4 bg-slate-50/80 border border-slate-200/80 rounded-2xl p-3.5 sm:p-4 text-xs text-slate-600 flex items-start gap-2.5">
                    <Icon name="info" :size="18" class="text-brand-600 flex-shrink-0 mt-0.5" />
                    <span>Sin código: tu solicitud se publicará en la red y quedará disponible para todos los profesores verificados de <strong class="text-slate-900">{{ subjectName ? subjectName : 'la materia elegida' }}</strong>.</span>
                  </div>
                </div>
              </div>

              <!-- Botones Step 2 -->
              <div class="mt-auto pt-4 sm:pt-6 space-y-2.5">
                <div class="flex items-center gap-2.5">
                  <button
                    type="button"
                    @click="skipCode"
                    class="flex-1 py-3.5 sm:py-4 px-4 text-xs sm:text-sm font-bold text-slate-600 hover:text-slate-900 border-2 border-slate-200 rounded-2xl hover:bg-slate-100/80 active:scale-[0.99] transition-all text-center"
                  >
                    Omitir código
                  </button>
                  <button
                    type="button"
                    @click="goNext"
                    :disabled="codeLookup.status === 'checking'"
                    class="flex-[1.5] py-3.5 sm:py-4 px-6 bg-brand-600 hover:bg-brand-700 active:bg-brand-800 text-white text-xs sm:text-base font-bold rounded-2xl shadow-lg shadow-brand-600/25 active:scale-[0.99] disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center justify-center gap-2 text-center"
                  >
                    <span>Siguiente</span>
                    <span class="text-base sm:text-lg font-black" aria-hidden="true">→</span>
                  </button>
                </div>
                <button
                  type="button"
                  @click="goPrev"
                  class="w-full text-center text-xs sm:text-sm font-semibold text-slate-400 hover:text-slate-700 py-1 transition-colors"
                >
                  ← Volver al paso anterior
                </button>
              </div>
            </div>

            <!-- PASO 3: Modalidad ─────────────────────────────────────────── -->
            <div v-else-if="currentStep === 3" key="step3" class="flex-1 flex flex-col justify-between space-y-4">
              <div class="space-y-4">
                <div>
                  <h3 class="text-lg sm:text-xl font-black text-slate-900 tracking-tight">¿Qué tipo de apoyo necesita?</h3>
                  <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Elige la modalidad que mejor se adapte para avanzar automáticamente.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                  <!-- Tarjeta 1: Clase puntual -->
                  <button
                    type="button"
                    @click="selectModality(false)"
                    :aria-pressed="!form.is_mentorship"
                    :class="[
                      'group text-left rounded-2xl border-2 p-4 sm:p-5 transition-all duration-200 relative overflow-hidden flex flex-col justify-between active:scale-[0.99]',
                      !form.is_mentorship
                        ? 'border-brand-600 bg-brand-50/80 text-slate-900 shadow-sm ring-2 ring-brand-500/20'
                        : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/50 shadow-2xs'
                    ]"
                  >
                    <div>
                      <div class="flex items-center justify-between mb-3">
                        <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-2xl bg-amber-50 border border-amber-200 flex items-center justify-center text-2xl shadow-xs">
                          🎯
                        </div>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                          Puntual
                        </span>
                      </div>
                      <h4 class="text-base sm:text-lg font-black text-slate-900 mb-1">Clase puntual</h4>
                      <p class="text-xs sm:text-sm text-slate-500 leading-relaxed">
                        Para resolver dudas urgentes, preparar un examen cercano o resolver una tarea específica.
                      </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs sm:text-sm font-bold text-brand-600 group-hover:text-brand-700 transition-colors">
                      <span>Elegir esta opción</span>
                      <span class="transition-transform group-hover:translate-x-1 font-black text-base">→</span>
                    </div>
                  </button>

                  <!-- Tarjeta 2: Acompañamiento continuo -->
                  <button
                    type="button"
                    @click="selectModality(true)"
                    :aria-pressed="form.is_mentorship"
                    :class="[
                      'group text-left rounded-2xl border-2 p-4 sm:p-5 transition-all duration-200 relative overflow-hidden flex flex-col justify-between active:scale-[0.99]',
                      form.is_mentorship
                        ? 'border-brand-600 bg-brand-50/80 text-slate-900 shadow-sm ring-2 ring-brand-500/20'
                        : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/50 shadow-2xs'
                    ]"
                  >
                    <div>
                      <div class="flex items-center justify-between mb-3">
                        <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-2xl bg-blue-50 border border-brand-200 flex items-center justify-center text-2xl shadow-xs">
                          ⭐
                        </div>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                          Recomendado
                        </span>
                      </div>
                      <h4 class="text-base sm:text-lg font-black text-slate-900 mb-1">Acompañamiento continuo</h4>
                      <p class="text-xs sm:text-sm text-slate-500 leading-relaxed">
                        Seguimiento clase a clase con el mismo profesor para consolidar hábitos y excelencia académica.
                      </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs sm:text-sm font-bold text-brand-600 group-hover:text-brand-700 transition-colors">
                      <span>Elegir esta opción</span>
                      <span class="transition-transform group-hover:translate-x-1 font-black text-base">→</span>
                    </div>
                  </button>
                </div>
              </div>

              <!-- Botón volver Step 3 -->
              <div class="mt-auto pt-4 sm:pt-6">
                <button
                  type="button"
                  @click="goPrev"
                  class="w-full text-center text-xs sm:text-sm font-semibold text-slate-400 hover:text-slate-700 py-1 transition-colors"
                >
                  ← Volver al paso anterior
                </button>
              </div>
            </div>

            <!-- PASO 4: Descripción y disponibilidad ─────────────────────── -->
            <div v-else-if="currentStep === 4" key="step4" class="flex-1 flex flex-col justify-between space-y-4">
              <div class="space-y-4">
                <div>
                  <h3 class="text-lg sm:text-xl font-black text-slate-900 tracking-tight">Cuéntanos un poco más</h3>
                  <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Describe la necesidad específica y cuándo suele estar disponible tu hijo/a.</p>
                </div>

                <!-- Qué necesita -->
                <div>
                  <label class="block text-[11px] sm:text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5 sm:mb-2">
                    ¿En qué necesita ayuda?
                  </label>
                  <textarea
                    v-model="form.help_needed"
                    rows="3"
                    required
                    class="w-full bg-slate-50 border-2 border-slate-200 rounded-2xl p-3.5 sm:p-4 text-xs sm:text-sm text-slate-900 placeholder:text-slate-400 focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 focus:outline-none transition-all shadow-inner leading-relaxed"
                    placeholder="Ej: Necesita repasar fracciones antes de su examen del jueves y resolver dudas de trigonometría."
                  ></textarea>
                  <div class="flex justify-between items-center mt-1 text-[11px] sm:text-xs">
                    <span :class="form.help_needed.trim().length >= 10 ? 'text-emerald-600 font-semibold' : 'text-slate-400'">
                      {{ form.help_needed.trim().length }} / mín. 10 caracteres
                    </span>
                    <InputError :message="form.errors.help_needed" class="text-rose-500" />
                  </div>
                </div>

                <!-- Preferencia horaria -->
                <div>
                  <label class="block text-[11px] sm:text-xs font-bold uppercase tracking-wider text-slate-500 mb-0.5">
                    ¿Cuándo suele estar disponible?
                  </label>
                  <p class="text-[11px] sm:text-xs text-slate-400 mb-2">
                    Preferencia orientativa — el horario se coordina después con el profesor.
                  </p>
                  <TimeSlotPicker v-model="form.preferred_times" />
                </div>
              </div>

              <!-- Botones Step 4 -->
              <div class="mt-auto pt-4 sm:pt-6 space-y-2">
                <button
                  type="button"
                  @click="goNext"
                  :disabled="!step4Valid"
                  class="w-full py-3.5 sm:py-4 px-6 bg-brand-600 hover:bg-brand-700 active:bg-brand-800 text-white text-sm sm:text-base font-bold rounded-2xl shadow-lg shadow-brand-600/25 active:scale-[0.99] disabled:opacity-40 disabled:cursor-not-allowed disabled:shadow-none transition-all flex items-center justify-center gap-2"
                >
                  <span>Ver resumen</span>
                  <span class="text-base sm:text-lg font-black" aria-hidden="true">→</span>
                </button>
                <button
                  type="button"
                  @click="goPrev"
                  class="w-full text-center text-xs sm:text-sm font-semibold text-slate-400 hover:text-slate-700 py-1 transition-colors"
                >
                  ← Volver al paso anterior
                </button>
              </div>
            </div>

            <!-- PASO 5: Resumen + enviar ────────────────────────────────── -->
            <div v-else-if="currentStep === 5" key="step5" class="flex-1 flex flex-col justify-between space-y-4">
              <div class="space-y-4">
                <div>
                  <h3 class="text-lg sm:text-xl font-black text-slate-900 tracking-tight">Revisa y envía</h3>
                  <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Confirma que todo esté correcto antes de enviar tu solicitud a los profesores.</p>
                </div>

                <!-- Resumen compacto -->
                <div class="bg-slate-50/80 border-2 border-slate-200/80 rounded-2xl p-3.5 sm:p-5 divide-y divide-slate-200/70 text-xs sm:text-sm">
                  <div class="flex justify-between items-center py-2 sm:py-2.5">
                    <span class="text-slate-500 font-medium">Alumno</span>
                    <span class="text-slate-900 font-bold text-right">{{ selectedStudentName ?? '—' }}</span>
                  </div>
                  <div class="flex justify-between items-center py-2 sm:py-2.5">
                    <span class="text-slate-500 font-medium">Materia</span>
                    <span class="text-slate-900 font-bold text-right">{{ offer?.subject?.name ?? subjectName ?? '—' }}</span>
                  </div>
                  <div class="flex justify-between items-center py-2 sm:py-2.5">
                    <span class="text-slate-500 font-medium">Profesor</span>
                    <span class="text-slate-900 font-bold text-right max-w-[60%] truncate">{{ recipientSummary }}</span>
                  </div>
                  <div class="flex justify-between items-center py-2 sm:py-2.5">
                    <span class="text-slate-500 font-medium">Modalidad</span>
                    <span class="text-slate-900 font-bold text-right">
                      <span
                        class="px-2.5 py-0.5 rounded-full text-[11px] sm:text-xs font-bold"
                        :class="form.is_mentorship ? 'bg-brand-50 text-brand-700 border border-brand-200' : 'bg-amber-50 text-amber-700 border border-amber-200'"
                      >
                        {{ form.is_mentorship ? 'Acompañamiento continuo' : 'Clase puntual' }}
                      </span>
                    </span>
                  </div>
                  <div v-if="form.preferred_times.length" class="flex justify-between items-center py-2 sm:py-2.5">
                    <span class="text-slate-500 font-medium">Disponibilidad</span>
                    <span class="text-slate-900 font-bold text-right max-w-[60%] truncate">{{ preferredTimesSummary }}</span>
                  </div>
                  <div class="pt-2 sm:pt-2.5">
                    <span class="text-slate-500 font-medium block mb-1">Descripción de ayuda</span>
                    <p class="text-slate-700 text-xs sm:text-sm italic bg-white/90 p-2.5 sm:p-3.5 rounded-xl border border-slate-200/80 line-clamp-3">
                      "{{ form.help_needed }}"
                    </p>
                  </div>
                </div>

                <!-- Banner de tranquilidad sin cobro oculto -->
                <div class="bg-brand-50/80 border border-brand-200/80 rounded-2xl p-3 sm:p-4 text-xs text-brand-900 flex items-start gap-2.5 shadow-2xs">
                  <Icon name="info" :size="18" class="text-brand-600 flex-shrink-0 mt-0.5" />
                  <span><strong>Garantía MOVA:</strong> Solicitud abierta. No se cobra nada hasta que aceptes la propuesta que más te guste.</span>
                </div>
              </div>

              <!-- Botones Step 5 -->
              <div class="mt-auto pt-4 sm:pt-6 space-y-2.5">
                <button
                  type="button"
                  @click="submit"
                  :disabled="form.processing"
                  class="btn-pulse-green w-full py-4 px-6 bg-gradient-to-r from-emerald-600 via-emerald-500 to-green-600 hover:from-emerald-500 hover:to-green-500 active:from-emerald-700 active:to-green-700 text-white text-base font-black rounded-2xl shadow-[0_8px_25px_rgba(16,185,129,0.35)] hover:shadow-[0_10px_30px_rgba(16,185,129,0.5)] active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center justify-center gap-2.5"
                >
                  <Icon v-if="!form.processing" name="send" :size="20" class="text-white drop-shadow-sm" />
                  <span>{{ form.processing ? 'Enviando solicitud...' : 'Enviar solicitud' }}</span>
                  <span v-if="!form.processing" class="text-lg" aria-hidden="true">🚀</span>
                </button>

                <button
                  type="button"
                  @click="goPrev"
                  class="w-full text-center text-xs sm:text-sm font-semibold text-slate-400 hover:text-slate-700 py-1 transition-colors"
                >
                  ← Volver a editar datos
                </button>
              </div>
            </div>

          </Transition>
        </div>

      </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import TimeSlotPicker from '@/Components/TimeSlotPicker.vue'
import Icon from '@/Components/Icon.vue'
import InputError from '@/Components/InputError.vue'
import { timeSlotLabel } from '@/utils/timeSlots'

// ── Props ───────────────────────────────────────────────────────────────────
const props = defineProps({
  subjects:            Array,
  students:            Array,
  offer:               Object,
  isMentorship:        Boolean,
  prefillReferralCode: { type: String, default: null },
  prefillSubjectId:    { type: Number, default: null },
})

// ── Wizard ─────────────────────────────────────────────────────────────────
const TOTAL_STEPS = 5
const STEP_LABELS = ['Alumno', 'Profesor', 'Modalidad', 'Detalles', 'Resumen']
const currentStep = ref(1)
const direction   = ref('forward')

const transitionName = computed(() =>
  direction.value === 'forward' ? 'slide-step' : 'slide-step-back'
)

function goNext() {
  if (currentStep.value < TOTAL_STEPS) {
    direction.value = 'forward'
    currentStep.value++
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

function goPrev() {
  if (currentStep.value > 1) {
    direction.value = 'back'
    currentStep.value--
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

// Paso 3: seleccionar modalidad avanza automáticamente
function selectModality(isMentorship) {
  form.is_mentorship = isMentorship
  direction.value = 'forward'
  currentStep.value = 4
  window.scrollTo({ top: 0, behavior: 'smooth' })
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

// ── Form ───────────────────────────────────────────────────────────────────
const form = useForm({
  student_id:            props.students?.length === 1 ? props.students[0].id : '',
  subject_id:            props.offer?.subject_id ?? props.prefillSubjectId ?? '',
  class_offer_id:        props.offer?.id ?? null,
  teacher_referral_code: '',
  is_mentorship:         props.isMentorship ?? false,
  help_needed:           '',
  preferred_times:       [],
})

// ── Computed ───────────────────────────────────────────────────────────────
const referenceRate       = computed(() => parseFloat(props.offer?.specific_rate ?? props.offer?.teacher_profile?.hourly_rate ?? 0).toFixed(0))
const subjectName         = computed(() => props.subjects?.find(s => String(s.id) === String(form.subject_id))?.name ?? null)
const selectedStudentName = computed(() => props.students?.find(s => String(s.id) === String(form.student_id))?.full_name ?? null)

const recipientSummary = computed(() => {
  if (props.offer) return props.offer.teacher_profile?.user?.name ?? '—'
  if (codeLookup.value.status === 'found') return codeLookup.value.name
  return `El primer profesor disponible de${subjectName.value ? ' ' + subjectName.value : ' la materia elegida'}`
})

const preferredTimesSummary = computed(() => form.preferred_times.map(timeSlotLabel).join(', '))

function subjectEmoji(name) {
  if (!name) return '📚'
  const lower = name.toLowerCase()
  if (lower.includes('matemática') || lower.includes('matematica') || lower.includes('algebra') || lower.includes('geometr')) return '📐'
  if (lower.includes('física') || lower.includes('fisica')) return '⚡'
  if (lower.includes('química') || lower.includes('quimica')) return '🧪'
  if (lower.includes('natural') || lower.includes('ciencia') || lower.includes('biolog')) return '🍃'
  if (lower.includes('inglés') || lower.includes('ingles') || lower.includes('english')) return '🇬🇧'
  if (lower.includes('lengua') || lower.includes('comunic') || lower.includes('literat') || lower.includes('español')) return '📖'
  if (lower.includes('program') || lower.includes('comput') || lower.includes('sistem')) return '💻'
  if (lower.includes('histor') || lower.includes('social')) return '🏛️'
  if (lower.includes('filoso') || lower.includes('psicol')) return '🧠'
  if (lower.includes('arte') || lower.includes('músic') || lower.includes('music')) return '🎨'
  return '📚'
}

// ── Lookup de código ───────────────────────────────────────────────────────
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

// ── Parallax Sutil del Fondo Matemático ────────────────────────────────────
const wizardCanvasRef = ref(null)
let rafId = null

onMounted(() => {
  if (referralCodeInput.value) {
    form.teacher_referral_code = referralCodeInput.value
    lookupCode(referralCodeInput.value)
  }

  const mathSymbols = wizardCanvasRef.value?.querySelectorAll('.math-sym') ?? []
  let mouseX = 0
  let mouseY = 0
  let targetX = 0
  let targetY = 0

  function onMouseMove(e) {
    mouseX = (e.clientX / window.innerWidth - 0.5) * 2
    mouseY = (e.clientY / window.innerHeight - 0.5) * 2
  }

  function animateSymbols() {
    targetX += (mouseX - targetX) * 0.08
    targetY += (mouseY - targetY) * 0.08

    mathSymbols.forEach((sym) => {
      const depth = parseFloat(sym.getAttribute('data-depth')) || 0.05
      const tx = (targetX * depth * 280).toFixed(1)
      const ty = (targetY * depth * 280).toFixed(1)
      sym.style.setProperty('--mx', `${tx}px`)
      sym.style.setProperty('--my', `${ty}px`)
    })

    rafId = requestAnimationFrame(animateSymbols)
  }

  window.addEventListener('mousemove', onMouseMove, { passive: true })
  rafId = requestAnimationFrame(animateSymbols)

  onBeforeUnmount(() => {
    window.removeEventListener('mousemove', onMouseMove)
    if (rafId) cancelAnimationFrame(rafId)
  })
})

// ── Submit ─────────────────────────────────────────────────────────────────
function submit() {
  form.post(route('class-requests.store'))
}
</script>

<style scoped>
/* ── Símbolos Matemáticos Tipo Marca de Agua (Apple Elegance) ─────────────── */
.math-sym {
  position: absolute;
  pointer-events: none;
  z-index: 0;
  will-change: transform;
  font-family: 'Georgia', 'Times New Roman', serif;
  font-weight: 700;
  line-height: 1;
  user-select: none;
  --mx: 0px;
  --my: 0px;
  color: rgba(31, 90, 166, 0.055);
}

.math-sym-1  { top: 8%;   left: 3%;    font-size: 3.8rem; animation: mathFloat1  8s ease-in-out infinite 0s;   }
.math-sym-2  { top: 38%;  left: 2%;    font-size: 2.8rem; animation: mathFloat2 10s ease-in-out infinite 1.2s; }
.math-sym-3  { top: 22%;  left: 18%;   font-size: 3rem;   animation: mathFloat3  9s ease-in-out infinite 0.5s; }
.math-sym-4  { top: 56%;  left: 6%;    font-size: 3.5rem; animation: mathFloat1 11s ease-in-out infinite 2s;   }
.math-sym-5  { top: 10%;  right: 8%;   font-size: 3.4rem; animation: mathFloat2  7s ease-in-out infinite 0.8s; }
.math-sym-6  { top: 30%;  right: 3%;   font-size: 3.2rem; animation: mathFloat3 12s ease-in-out infinite 1.5s; }
.math-sym-7  { top: 68%;  right: 11%;  font-size: 3rem;   animation: mathFloat1  9s ease-in-out infinite 3s;   }
.math-sym-8  { top: 84%;  right: 15%;  font-size: 3.3rem; animation: mathFloat2 13s ease-in-out infinite 0.3s; }
.math-sym-9  { top: 48%;  right: 5%;   font-size: 4.2rem; animation: mathFloat3  8s ease-in-out infinite 1.8s; }
.math-sym-10 { top: 78%;  left: 3%;    font-size: 3rem;   animation: mathFloat1 10s ease-in-out infinite 0.6s; }
.math-sym-11 { top: 66%;  left: 16%;   font-size: 2.8rem; animation: mathFloat2 11s ease-in-out infinite 2.4s; }

@keyframes mathFloat1 {
  0%, 100% { transform: translate(var(--mx), calc(var(--my) + 0px))   rotate(0deg);  }
  33%       { transform: translate(var(--mx), calc(var(--my) - 16px)) rotate(3deg);  }
  66%       { transform: translate(var(--mx), calc(var(--my) - 8px))  rotate(-2deg); }
}
@keyframes mathFloat2 {
  0%, 100% { transform: translate(var(--mx), calc(var(--my) + 0px))   rotate(0deg);  }
  40%       { transform: translate(var(--mx), calc(var(--my) - 14px)) rotate(-3deg); }
  70%       { transform: translate(var(--mx), calc(var(--my) - 20px)) rotate(2deg);  }
}
@keyframes mathFloat3 {
  0%, 100% { transform: translate(var(--mx), calc(var(--my) + 0px))   scale(1);      }
  50%       { transform: translate(var(--mx), calc(var(--my) - 18px)) scale(1.04);   }
}

/* Transiciones de Pasos Suaves */
.slide-step-enter-active,
.slide-step-leave-active {
  transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}
.slide-step-enter-from { opacity: 0; transform: translateX(25px); }
.slide-step-enter-to   { opacity: 1; transform: translateX(0); }
.slide-step-leave-from { opacity: 1; transform: translateX(0); }
.slide-step-leave-to   { opacity: 0; transform: translateX(-25px); }

.slide-step-back-enter-active,
.slide-step-back-leave-active {
  transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}
.slide-step-back-enter-from { opacity: 0; transform: translateX(-25px); }
.slide-step-back-enter-to   { opacity: 1; transform: translateX(0); }
.slide-step-back-leave-from { opacity: 1; transform: translateX(0); }
.slide-step-back-leave-to   { opacity: 0; transform: translateX(25px); }

/* ── Botón Verde Palpitante (CTA de alta conversión) ─────────────────────── */
@keyframes pulseGreenCta {
  0%, 100% {
    transform: scale(1);
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.4), 0 0 0 0 rgba(16, 185, 129, 0.5);
  }
  50% {
    transform: scale(1.025);
    box-shadow: 0 16px 36px rgba(16, 185, 129, 0.65), 0 0 0 10px rgba(16, 185, 129, 0.15);
  }
}

.btn-pulse-green:not(:disabled) {
  animation: pulseGreenCta 2s cubic-bezier(0.4, 0, 0.2, 1) infinite;
}

.btn-pulse-green:hover:not(:disabled) {
  animation-play-state: paused;
  transform: scale(1.025);
}

.btn-pulse-green:active:not(:disabled) {
  animation: none;
  transform: scale(0.98);
}
</style>
