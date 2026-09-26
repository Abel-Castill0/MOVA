<template>
  <AppLayout title="Solicitudes" :compact="true">
    <div class="space-y-3.5">

      <!-- ══════════════════════════════════════════════
           BARRA DE BÚSQUEDA FLOTANTE (Móvil: aparece al subir, se oculta al bajar)
           ══════════════════════════════════════════════ -->
      <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="-translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition-all duration-250 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="-translate-y-full opacity-0"
      >
        <div
          v-if="showFloatingSearch && isMobile"
          class="fixed top-[60px] left-0 right-0 z-20 px-4 py-2.5 bg-[#EBF2FA]/95 backdrop-blur-md border-b border-blue-200/50 shadow-sm"
        >
          <div class="relative w-full max-w-md mx-auto">
            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </span>
            <input
              v-model="searchQuery"
              @focus="isFloatingSearchFocused = true"
              @blur="onFloatingBlur"
              type="text"
              placeholder="Buscar por alumno o materia..."
              class="w-full pl-10 pr-8 py-2.5 bg-white border border-gray-200/90 rounded-2xl text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 shadow-xs transition-all"
            />
            <button
              v-if="searchQuery"
              @click="searchQuery = ''"
              type="button"
              aria-label="Limpiar búsqueda"
              class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 cursor-pointer"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>
      </Transition>

      <!-- Flash de éxito (contraoferta enviada, etc.) -->
      <div
        v-if="$page.props.flash?.success"
        class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-2xl px-4 py-3 flex items-center gap-2"
      >
        <Icon name="check" :size="16" class="flex-shrink-0 text-green-600" />
        {{ $page.props.flash.success }}
      </div>

      <!-- ══════════════════════════════════════════════
           CABECERA: Título, Contador, Buscador y Filtro
           ══════════════════════════════════════════════ -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-2 flex-wrap">
          <div v-if="counterofdRequests.length || rejectedRequests.length" class="flex items-center gap-1 bg-slate-100/90 p-1 rounded-2xl border border-slate-200/60 shadow-2xs">
            <button
              @click="statusTab = 'open'"
              type="button"
              :class="['px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer', statusTab === 'open' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-800']"
            >
              Abiertas ({{ requests.length }})
            </button>
            <button
              v-if="counterofdRequests.length"
              @click="statusTab = 'counteroffered'"
              type="button"
              :class="['px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer', statusTab === 'counteroffered' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-800']"
            >
              Con contraoferta ({{ counterofdRequests.length }})
            </button>
            <button
              v-if="rejectedRequests.length"
              @click="statusTab = 'rejected'"
              type="button"
              :class="['px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer', statusTab === 'rejected' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-800']"
            >
              Rechazadas ({{ rejectedRequests.length }})
            </button>
          </div>

          <span v-else class="text-xs font-bold text-slate-600 bg-white/90 px-3 py-1.5 rounded-xl border border-slate-200/80 shadow-2xs">
            {{ filteredRequests.length }} {{ filteredRequests.length === 1 ? 'pendiente' : 'pendientes' }}
          </span>
        </div>

        <!-- Controles de búsqueda y ordenamiento -->
        <div class="flex items-center gap-3 flex-wrap sm:flex-nowrap">
          <!-- Buscador en vivo por alumno o materia -->
          <div :class="['relative w-full sm:w-72', isMobile && showFloatingSearch ? 'hidden' : '']">
            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </span>
            <input
              v-model="searchQuery"
              @focus="isStaticSearchFocused = true"
              @blur="isStaticSearchFocused = false"
              type="text"
              placeholder="Buscar por alumno o materia..."
              class="w-full pl-10 pr-8 py-2.5 bg-white border border-gray-200/90 rounded-2xl text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 shadow-2xs transition-all"
            />
            <button
              v-if="searchQuery"
              @click="searchQuery = ''"
              type="button"
              aria-label="Limpiar búsqueda"
              class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 cursor-pointer"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- Selector de Ordenamiento -->
          <div class="relative">
            <select
              v-model="sortBy"
              class="appearance-none bg-white border border-gray-200/90 pl-3.5 pr-8 py-2.5 rounded-2xl text-xs sm:text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 shadow-2xs cursor-pointer transition-all"
            >
              <option value="recent">≡ Ordenar</option>
              <option value="recent">Más recientes</option>
              <option value="urgent">Mayor urgencia</option>
              <option value="student">Alumno (A-Z)</option>
              <option value="subject">Materia (A-Z)</option>
            </select>
            <span class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-slate-400">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </span>
          </div>
        </div>
      </div>


      <!-- ══════════════════════════════════════════════
           TAB 1: SOLICITUDES ABIERTAS (Master - Detail)
           ══════════════════════════════════════════════ -->
      <div v-if="statusTab === 'open'" class="flex items-start gap-6 relative">
        
        <!-- COLUMNA IZQUIERDA: REJILLA DE TARJETAS -->
        <div class="flex-1 min-w-0">
          <div v-if="!filteredRequests.length" class="text-center py-16 bg-white rounded-3xl border border-gray-100 shadow-sm">
            <div class="text-4xl mb-2.5">🔍</div>
            <p class="font-bold text-slate-800 text-base">No se encontraron solicitudes</p>
            <p class="text-slate-400 text-xs sm:text-sm mt-1">Prueba cambiando los términos de búsqueda o filtros.</p>
          </div>

          <div
            v-else
            :class="[
              'grid gap-5',
              selectedRequest
                ? 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3'
                : 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4'
            ]"
          >
            <div
              v-for="r in filteredRequests"
              :key="r.id"
              @click="selectRequest(r)"
              :class="[
                'rounded-3xl p-5 flex flex-col justify-between transition-all duration-200 cursor-pointer select-none group',
                selectedRequest?.id === r.id
                  ? 'bg-white border-2 border-blue-600 ring-4 ring-blue-500/15 shadow-lg shadow-blue-500/10 -translate-y-0.5'
                  : 'bg-white border border-slate-200/90 hover:border-blue-400 hover:shadow-xl hover:shadow-blue-500/10 hover:-translate-y-1 active:scale-[0.985] active:translate-y-0 shadow-2xs'
              ]"
            >
              <div>
                <!-- Badge Materia + 0% comisión + Indicador de selección -->
                <div class="flex items-center justify-between mb-3">
                  <div class="flex items-center gap-2 flex-wrap">
                    <span :class="['text-xs font-bold px-3 py-1 rounded-full border shadow-2xs', subjectBadgeTheme(r.subject?.name).badge]">
                      {{ r.subject?.name || 'Materia' }}
                    </span>
                    <span
                      v-if="r.is_zero_commission"
                      class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"
                    >
                      0% comisión
                    </span>
                  </div>
                </div>

                <!-- Nombre del Alumno -->
                <h3 :class="['text-base sm:text-lg font-black leading-snug transition-colors', selectedRequest?.id === r.id ? 'text-blue-950' : 'text-slate-900 group-hover:text-blue-900']">
                  {{ studentFullName(r) }}
                </h3>

                <!-- Descripción / Necesidad -->
                <p class="text-xs text-slate-500 line-clamp-2 leading-relaxed mt-2 font-normal">
                  {{ requestGoal(r) }}
                </p>

                <!-- Badges Row: Duración, Créditos, Urgencia -->
                <div class="flex items-center gap-1.5 flex-wrap mt-4">
                  <!-- Duración -->
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-slate-50 border border-slate-200/70 text-slate-600 text-[11px] font-semibold">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 1118 0z"/>
                    </svg>
                    <span>1 hora</span>
                  </span>

                  <!-- Créditos -->
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-slate-50 border border-slate-200/70 text-slate-600 text-[11px] font-semibold">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    <span>1 crédito</span>
                  </span>

                  <!-- Urgencia -->
                  <span :class="['inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border text-[11px] font-bold', urgencyLevel(r).badgeClass]">
                    <span>{{ urgencyLevel(r).icon }}</span>
                    <span>{{ urgencyLevel(r).label }}</span>
                  </span>
                </div>
              </div>

              <!-- Pie de tarjeta: Ver detalle -->
              <div
                :class="[
                  'mt-5 pt-3 border-t flex items-center justify-between font-bold text-xs transition-colors',
                  selectedRequest?.id === r.id
                    ? 'border-blue-100 text-blue-700'
                    : 'border-slate-100 text-blue-600 group-hover:text-blue-700'
                ]"
              >
                <span class="flex items-center gap-1.5">
                  <span v-if="selectedRequest?.id === r.id" class="w-2 h-2 rounded-full bg-blue-600 animate-pulse"></span>
                  <span>{{ selectedRequest?.id === r.id ? 'Viendo detalle' : 'Ver detalle' }}</span>
                </span>
                <svg
                  :class="[
                    'w-3.5 h-3.5 stroke-[2.5] transition-transform duration-200',
                    selectedRequest?.id === r.id
                      ? 'translate-x-1 text-blue-700'
                      : 'group-hover:translate-x-1.5'
                  ]"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════
             COLUMNA DERECHA: PESTAÑA / PANEL DE DETALLE (Desktop)
             ══════════════════════════════════════════════ -->
        <aside
          v-if="selectedRequest"
          class="hidden lg:flex flex-col justify-between w-[390px] xl:w-[430px] shrink-0 bg-white rounded-3xl border border-slate-200/90 shadow-sm p-4 xl:p-5 select-none h-[calc(100vh-125px)] min-h-[500px] sticky top-3"
        >
          <!-- 1. Encabezado Alumno / Materia / Código -->
          <div class="shrink-0 pb-3 border-b border-slate-100/90">
            <div class="flex items-center gap-3">
              <!-- Avatar inicial o foto -->
              <img
                v-if="selectedRequest.student?.avatar_url"
                :src="selectedRequest.student.avatar_url"
                :alt="studentFullName(selectedRequest)"
                class="w-12 h-12 rounded-2xl object-cover ring-2 ring-blue-500/20 shadow-xs shrink-0"
              />
              <div
                v-else
                class="w-12 h-12 rounded-2xl bg-gradient-to-br from-[#0D409A] via-[#1F5AA6] to-indigo-600 text-white font-black text-lg flex items-center justify-center shadow-md shadow-blue-600/20 ring-2 ring-blue-100 shrink-0"
              >
                {{ studentFullName(selectedRequest).charAt(0).toUpperCase() }}
              </div>

              <!-- Info principal del alumno -->
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5 flex-wrap">
                  <span :class="['text-[11px] font-bold px-2.5 py-0.5 rounded-full border shadow-2xs', subjectBadgeTheme(selectedRequest.subject?.name).badge]">
                    {{ selectedRequest.subject?.name || 'Materia' }}
                  </span>
                  <span
                    v-if="selectedRequest.is_zero_commission"
                    class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"
                  >
                    0% comisión
                  </span>
                </div>
                <h2 class="text-base xl:text-lg font-black text-slate-900 tracking-tight leading-snug mt-0.5 truncate">
                  {{ studentFullName(selectedRequest) }}
                </h2>
                <p class="text-[11px] font-medium text-slate-400 truncate">
                  {{ selectedRequest.student?.grade_level || 'Estudiante MOVA' }} · Solicitud individual
                </p>
              </div>

              <!-- Código y Botón cerrar -->
              <div class="flex items-center gap-1.5 shrink-0 self-start">
                <span class="text-[10px] font-mono font-bold text-slate-500 bg-slate-100/90 px-2 py-1 rounded-lg border border-slate-200/80">
                  {{ requestCode(selectedRequest) }}
                </span>
                <button
                  @click="selectedRequest = null"
                  type="button"
                  aria-label="Cerrar detalle"
                  class="w-7 h-7 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 flex items-center justify-center transition-colors cursor-pointer"
                >
                  <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <!-- 2. Contenido distribuido responsivamente -->
          <div class="flex-1 flex flex-col justify-between min-h-0 py-2.5 gap-y-2 xl:gap-y-2.5">
            <!-- Objetivo de aprendizaje -->
            <div class="rounded-2xl bg-gradient-to-br from-blue-50/90 via-blue-50/50 to-indigo-50/40 border border-blue-200/90 p-3 xl:p-3.5 shadow-2xs">
              <div class="flex items-center gap-2 mb-1.5">
                <div class="w-6 h-6 rounded-lg bg-blue-600 text-white flex items-center justify-center text-xs shadow-xs shrink-0">
                  🎯
                </div>
                <h4 class="text-xs font-bold text-blue-950 uppercase tracking-wider">
                  Objetivo de aprendizaje
                </h4>
              </div>
              <p class="text-xs sm:text-[13px] text-slate-800 leading-relaxed font-semibold line-clamp-3">
                {{ requestGoal(selectedRequest) }}
              </p>
            </div>

            <!-- Duración y Descuento -->
            <div class="grid grid-cols-2 gap-2 xl:gap-2.5">
              <div class="bg-white border border-slate-200/90 rounded-2xl p-2.5 xl:p-3 flex items-center gap-2.5 shadow-2xs">
                <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-700 flex items-center justify-center shrink-0 border border-blue-100 shadow-2xs">
                  <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 1118 0z" />
                  </svg>
                </div>
                <div class="min-w-0">
                  <p class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider leading-none">Duración</p>
                  <p class="text-xs sm:text-sm font-black text-slate-900 mt-1 truncate">
                    1 hora <span class="text-[10px] font-semibold text-slate-400">/ clase</span>
                  </p>
                </div>
              </div>

              <div class="bg-white border border-slate-200/90 rounded-2xl p-2.5 xl:p-3 flex items-center gap-2.5 shadow-2xs">
                <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center shrink-0 border border-amber-200/70 shadow-2xs">
                  <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                  </svg>
                </div>
                <div class="min-w-0">
                  <p class="text-[10px] font-extrabold text-amber-600 uppercase tracking-wider leading-none">Descuento</p>
                  <p class="text-xs sm:text-sm font-black text-amber-950 mt-1 truncate">1 crédito</p>
                </div>
              </div>
            </div>

            <!-- Disponibilidad / Urgencia -->
            <div :class="['rounded-2xl p-2.5 xl:p-3 border flex items-center gap-2.5 xl:gap-3 shadow-2xs transition-colors', urgencyLevel(selectedRequest).boxClass]">
              <div :class="['w-9 h-9 rounded-xl flex items-center justify-center shrink-0 text-sm font-black shadow-2xs border', urgencyLevel(selectedRequest).iconBoxClass]">
                {{ urgencyLevel(selectedRequest).icon }}
              </div>
              <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-1">
                  <span :class="['text-[10px] font-extrabold uppercase tracking-wider leading-none', urgencyLevel(selectedRequest).titleClass]">
                    Disponibilidad / Urgencia
                  </span>
                  <span :class="['text-[10px] font-extrabold px-2 py-0.5 rounded-full border shadow-2xs', urgencyLevel(selectedRequest).badgeClass]">
                    {{ urgencyLevel(selectedRequest).label }}
                  </span>
                </div>
                <p :class="['text-xs sm:text-sm font-extrabold mt-1 truncate', urgencyLevel(selectedRequest).textClass]">
                  {{ urgencyLevel(selectedRequest).timeText }}
                </p>
              </div>
            </div>

            <!-- Mensaje del alumno -->
            <div class="rounded-2xl bg-slate-50/90 border border-slate-200/90 p-3 xl:p-3.5 shadow-2xs">
              <div class="flex items-center gap-2 mb-1.5">
                <div class="w-6 h-6 rounded-lg bg-slate-200/80 text-slate-700 flex items-center justify-center text-xs shadow-2xs shrink-0">
                  💬
                </div>
                <h4 class="text-xs font-bold text-slate-800 uppercase tracking-wider">
                  Mensaje del alumno
                </h4>
              </div>
              <p class="text-xs sm:text-[13px] text-slate-700 leading-relaxed font-medium pl-3 border-l-2 border-indigo-400 italic line-clamp-3">
                "{{ requestStudentMessage(selectedRequest) }}"
              </p>
            </div>

            <!-- Información adicional -->
            <div class="rounded-2xl bg-sky-50/60 border border-sky-200/80 p-3 xl:p-3.5 shadow-2xs">
              <div class="flex items-center gap-2 mb-1.5">
                <div class="w-6 h-6 rounded-lg bg-sky-600 text-white flex items-center justify-center text-xs font-black shadow-xs shrink-0">
                  i
                </div>
                <h4 class="text-xs font-bold text-sky-950 uppercase tracking-wider">
                  Información adicional
                </h4>
              </div>
              <p class="text-xs sm:text-[13px] text-slate-700 leading-relaxed font-medium line-clamp-3">
                {{ requestAdditionalInfo(selectedRequest) }}
              </p>
            </div>
          </div>

          <!-- 3. Botones de Acción fijados abajo -->
          <div class="pt-2.5 border-t border-slate-100 flex flex-col gap-1.5 mt-auto shrink-0">
            <Link
              :href="route('teacher.requests.accept', selectedRequest.id)"
              class="w-full py-2.5 xl:py-3 px-4 bg-gradient-to-r from-[#0D409A] to-[#1F5AA6] hover:from-[#0B3580] hover:to-[#17469E] active:scale-[0.99] text-white font-extrabold text-xs sm:text-sm rounded-xl xl:rounded-2xl shadow-md shadow-blue-900/20 transition-all text-center flex items-center justify-center gap-2 cursor-pointer"
            >
              <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
              </svg>
              <span>Aceptar solicitud</span>
            </Link>

            <button
              type="button"
              @click="openCounterofferModal(selectedRequest)"
              class="w-full py-2 xl:py-2.5 px-4 bg-white border-2 border-[#EA580C] text-[#EA580C] hover:bg-orange-50/80 active:scale-[0.99] font-extrabold text-xs rounded-xl xl:rounded-2xl transition-all text-center flex items-center justify-center gap-2 cursor-pointer shadow-2xs"
            >
              <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 1118 0z" />
              </svg>
              <span>Proponer otra hora</span>
            </button>

            <button
              type="button"
              @click="openRejectModal(selectedRequest)"
              class="text-center text-[11px] font-semibold text-slate-400 hover:text-red-600 transition-colors py-1 cursor-pointer"
            >
              Rechazar solicitud
            </button>
          </div>
        </aside>

        <!-- DRAWER FLOTANTE PARA MÓVIL (Slide-over) -->
        <Teleport to="body">
          <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
          >
            <div
              v-if="selectedRequest && isMobile"
              class="fixed inset-0 bg-black/40 backdrop-blur-xs z-50 lg:hidden"
              @click="selectedRequest = null"
            />
          </Transition>

          <Transition
            enter-active-class="transition-transform duration-250 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
          >
            <aside
              v-if="selectedRequest && isMobile"
              class="fixed inset-y-0 right-0 z-50 w-full max-w-sm sm:max-w-md bg-white shadow-2xl flex flex-col justify-between p-4 sm:p-5 lg:hidden overflow-hidden"
            >
              <!-- 1. Encabezado Fijo Arriba -->
              <div class="shrink-0 pb-3 border-b border-slate-100">
                <div class="flex items-center gap-3">
                  <!-- Avatar -->
                  <img
                    v-if="selectedRequest.student?.avatar_url"
                    :src="selectedRequest.student.avatar_url"
                    :alt="studentFullName(selectedRequest)"
                    class="w-12 h-12 rounded-2xl object-cover ring-2 ring-blue-500/20 shadow-xs shrink-0"
                  />
                  <div
                    v-else
                    class="w-12 h-12 rounded-2xl bg-gradient-to-br from-[#0D409A] via-[#1F5AA6] to-indigo-600 text-white font-black text-base flex items-center justify-center shadow-md shadow-blue-600/20 ring-2 ring-blue-100 shrink-0"
                  >
                    {{ studentFullName(selectedRequest).charAt(0).toUpperCase() }}
                  </div>

                  <!-- Info del alumno -->
                  <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5 flex-wrap">
                      <span :class="['text-[11px] font-bold px-2.5 py-0.5 rounded-full border shadow-2xs', subjectBadgeTheme(selectedRequest.subject?.name).badge]">
                        {{ selectedRequest.subject?.name || 'Materia' }}
                      </span>
                      <span
                        v-if="selectedRequest.is_zero_commission"
                        class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"
                      >
                        0% comisión
                      </span>
                    </div>
                    <h2 class="text-base font-black text-slate-900 tracking-tight leading-snug mt-0.5 truncate">
                      {{ studentFullName(selectedRequest) }}
                    </h2>
                    <p class="text-[11px] font-medium text-slate-400 truncate">
                      {{ selectedRequest.student?.grade_level || 'Estudiante MOVA' }} · Solicitud individual
                    </p>
                  </div>

                  <!-- Código y Botón cerrar -->
                  <div class="flex items-center gap-1.5 shrink-0 self-start">
                    <span class="text-[10px] font-mono font-bold text-slate-500 bg-slate-100/90 px-2 py-1 rounded-lg border border-slate-200/80">
                      {{ requestCode(selectedRequest) }}
                    </span>
                    <button
                      @click="selectedRequest = null"
                      type="button"
                      aria-label="Cerrar detalle"
                      class="w-8 h-8 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 flex items-center justify-center transition-colors cursor-pointer"
                    >
                      <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </div>
                </div>
              </div>

              <!-- 2. Contenido Scrollable que encaja en el recuadro -->
              <div class="flex-1 min-h-0 overflow-y-auto py-3 space-y-3 pr-0.5">
                <!-- Objetivo de aprendizaje -->
                <div class="rounded-2xl bg-gradient-to-br from-blue-50/90 via-blue-50/50 to-indigo-50/40 border border-blue-200/90 p-3.5 shadow-2xs">
                  <div class="flex items-center gap-2 mb-1.5">
                    <div class="w-6 h-6 rounded-lg bg-blue-600 text-white flex items-center justify-center text-xs shadow-xs shrink-0">
                      🎯
                    </div>
                    <h4 class="text-xs font-bold text-blue-950 uppercase tracking-wider">
                      Objetivo de aprendizaje
                    </h4>
                  </div>
                  <p class="text-xs sm:text-[13px] text-slate-800 leading-relaxed font-semibold">
                    {{ requestGoal(selectedRequest) }}
                  </p>
                </div>

                <!-- Duración y Descuento -->
                <div class="grid grid-cols-2 gap-2.5">
                  <div class="bg-white border border-slate-200/90 rounded-2xl p-3 flex items-center gap-2.5 shadow-2xs">
                    <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-700 flex items-center justify-center shrink-0 border border-blue-100 shadow-2xs">
                      <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 1118 0z" />
                      </svg>
                    </div>
                    <div class="min-w-0">
                      <p class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider leading-none">Duración</p>
                      <p class="text-xs sm:text-sm font-black text-slate-900 mt-1 truncate">
                        1 hora <span class="text-[10px] font-semibold text-slate-400">/ clase</span>
                      </p>
                    </div>
                  </div>

                  <div class="bg-white border border-slate-200/90 rounded-2xl p-3 flex items-center gap-2.5 shadow-2xs">
                    <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center shrink-0 border border-amber-200/70 shadow-2xs">
                      <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                      </svg>
                    </div>
                    <div class="min-w-0">
                      <p class="text-[10px] font-extrabold text-amber-600 uppercase tracking-wider leading-none">Descuento</p>
                      <p class="text-xs sm:text-sm font-black text-amber-950 mt-1 truncate">1 crédito</p>
                    </div>
                  </div>
                </div>

                <!-- Disponibilidad / Urgencia Box -->
                <div :class="['rounded-2xl p-3 border flex items-center gap-3 shadow-2xs transition-colors', urgencyLevel(selectedRequest).boxClass]">
                  <div :class="['w-9 h-9 rounded-xl flex items-center justify-center shrink-0 text-sm font-black shadow-2xs border', urgencyLevel(selectedRequest).iconBoxClass]">
                    {{ urgencyLevel(selectedRequest).icon }}
                  </div>
                  <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-1">
                      <span :class="['text-[10px] font-extrabold uppercase tracking-wider leading-none', urgencyLevel(selectedRequest).titleClass]">
                        Disponibilidad / Urgencia
                      </span>
                      <span :class="['text-[10px] font-extrabold px-2 py-0.5 rounded-full border shadow-2xs', urgencyLevel(selectedRequest).badgeClass]">
                        {{ urgencyLevel(selectedRequest).label }}
                      </span>
                    </div>
                    <p :class="['text-xs sm:text-sm font-extrabold mt-1 truncate', urgencyLevel(selectedRequest).textClass]">
                      {{ urgencyLevel(selectedRequest).timeText }}
                    </p>
                  </div>
                </div>

                <!-- Mensaje del alumno -->
                <div class="rounded-2xl bg-slate-50/90 border border-slate-200/90 p-3.5 shadow-2xs">
                  <div class="flex items-center gap-2 mb-1.5">
                    <div class="w-6 h-6 rounded-lg bg-slate-200/80 text-slate-700 flex items-center justify-center text-xs shadow-2xs shrink-0">
                      💬
                    </div>
                    <h4 class="text-xs font-bold text-slate-800 uppercase tracking-wider">
                      Mensaje del alumno
                    </h4>
                  </div>
                  <p class="text-xs sm:text-[13px] text-slate-700 leading-relaxed font-medium pl-3 border-l-2 border-indigo-400 italic">
                    "{{ requestStudentMessage(selectedRequest) }}"
                  </p>
                </div>

                <!-- Información adicional -->
                <div class="rounded-2xl bg-sky-50/60 border border-sky-200/80 p-3.5 shadow-2xs">
                  <div class="flex items-center gap-2 mb-1.5">
                    <div class="w-6 h-6 rounded-lg bg-sky-600 text-white flex items-center justify-center text-xs font-black shadow-xs shrink-0">
                      i
                    </div>
                    <h4 class="text-xs font-bold text-sky-950 uppercase tracking-wider">
                      Información adicional
                    </h4>
                  </div>
                  <p class="text-xs sm:text-[13px] text-slate-700 leading-relaxed font-medium">
                    {{ requestAdditionalInfo(selectedRequest) }}
                  </p>
                </div>
              </div>

              <!-- 3. Botones fijados abajo con fondo blanco -->
              <div class="pt-3 border-t border-slate-100 flex flex-col gap-2 shrink-0 bg-white">
                <Link
                  :href="route('teacher.requests.accept', selectedRequest.id)"
                  class="w-full py-3 px-4 bg-gradient-to-r from-[#0D409A] to-[#1F5AA6] hover:from-[#0B3580] hover:to-[#17469E] active:scale-[0.99] text-white font-extrabold text-sm rounded-2xl shadow-md shadow-blue-900/20 transition-all text-center flex items-center justify-center gap-2 cursor-pointer"
                >
                  <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                  </svg>
                  <span>Aceptar solicitud</span>
                </Link>

                <button
                  type="button"
                  @click="openCounterofferModal(selectedRequest)"
                  class="w-full py-2.5 px-4 bg-white border-2 border-[#EA580C] text-[#EA580C] hover:bg-orange-50/80 active:scale-[0.99] font-extrabold text-sm rounded-2xl transition-all text-center flex items-center justify-center gap-2 cursor-pointer shadow-2xs"
                >
                  <svg class="w-4 h-4 stroke-[2.5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 1118 0z" />
                  </svg>
                  <span>Proponer otra hora</span>
                </button>

                <button
                  type="button"
                  @click="openRejectModal(selectedRequest)"
                  class="w-full text-center text-xs font-semibold text-slate-400 hover:text-red-600 transition-colors py-1 cursor-pointer"
                >
                  Rechazar solicitud
                </button>
              </div>
            </aside>
          </Transition>
        </Teleport>

      </div>

      <!-- ══════════════════════════════════════════════
           TAB 2: SOLICITUDES CON CONTRAOFERTA PENDIENTE
           ══════════════════════════════════════════════ -->
      <div v-else-if="statusTab === 'counteroffered'" class="space-y-3">
        <div
          v-for="r in counterofdRequests"
          :key="r.id"
          class="bg-amber-50/80 rounded-2xl border border-amber-200/90 p-5 flex items-start justify-between gap-4"
        >
          <div class="flex-1 min-w-0">
            <span :class="['text-xs font-bold px-3 py-1 rounded-full border mb-2 inline-block', subjectBadgeTheme(r.subject?.name).badge]">
              {{ r.subject?.name || 'Materia' }}
            </span>
            <h3 class="text-base font-black text-slate-900 mt-1">{{ studentFullName(r) }}</h3>
            <p class="text-xs text-amber-800 font-semibold mt-1">
              Propusiste: {{ fmtDateTime(r.counteroffer_time) }} — esperando confirmación del padre.
            </p>
          </div>
          <span class="text-xs bg-amber-100 text-amber-800 px-3 py-1 rounded-full font-bold flex-shrink-0">
            Esperando respuesta
          </span>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════
           TAB 3: SOLICITUDES RECHAZADAS
           ══════════════════════════════════════════════ -->
      <div v-else-if="statusTab === 'rejected'" class="space-y-3">
        <div
          v-for="r in rejectedRequests"
          :key="r.id"
          class="bg-white rounded-2xl border border-gray-200 p-5 flex items-start justify-between gap-4"
        >
          <div class="flex-1 min-w-0">
            <span :class="['text-xs font-bold px-3 py-1 rounded-full border mb-2 inline-block', subjectBadgeTheme(r.subject?.name).badge]">
              {{ r.subject?.name || 'Materia' }}
            </span>
            <h3 class="text-base font-black text-slate-900 mt-1">{{ studentFullName(r) }}</h3>
            <p class="text-xs text-slate-500 mt-1 font-normal">
              Motivo: <span class="font-medium text-slate-700">{{ r.teacher_rejection_reason }}</span>
            </p>
          </div>
          <span class="text-xs text-slate-400 font-medium flex-shrink-0">
            {{ fmtDate(r.teacher_rejected_at) }}
          </span>
        </div>
      </div>

    </div>

    <!-- ── Modal de CONTRAOFERTA (Proponer otra hora) ────────────────────────── -->
    <div v-if="counterofferTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 shadow-xl p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Proponer otra hora</h3>
        <p class="text-sm text-gray-500 mb-4">
          Clase de <strong>{{ counterofferTarget.subject?.name }}</strong> con
          {{ counterofferTarget.student?.first_name }}.
          El padre recibirá la propuesta y podrá aceptar o rechazar.
        </p>

        <label class="block text-xs font-semibold text-gray-700 mb-1">Hora que propones</label>
        <input
          v-model="counterofferForm.counteroffer_time"
          type="datetime-local"
          :min="minDatetime"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 mb-1"
        />
        <p v-if="counterofferForm.errors.counteroffer_time" class="text-xs text-red-500 mb-2">
          {{ counterofferForm.errors.counteroffer_time }}
        </p>

        <div class="flex gap-2 mt-4">
          <button
            @click="closeCounterofferModal"
            :disabled="counterofferForm.processing"
            type="button"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-50 cursor-pointer"
          >
            Cancelar
          </button>
          <button
            @click="submitCounteroffer"
            :disabled="counterofferForm.processing || !counterofferForm.counteroffer_time"
            type="button"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-accent-500 hover:bg-accent-600 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors cursor-pointer"
          >
            {{ counterofferForm.processing ? 'Enviando…' : 'Enviar propuesta' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Modal de RECHAZO ────────────────────────────────────────────────── -->
    <div v-if="rejectTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Rechazar solicitud</h3>
        <p class="text-sm text-gray-500 mb-4">
          Solicitud de <strong>{{ rejectTarget.subject?.name }}</strong> de
          {{ rejectTarget.student?.first_name }}.
          El padre recibirá una notificación.
        </p>
        <textarea
          v-model="rejectReason"
          rows="3"
          placeholder="Motivo del rechazo (obligatorio, mínimo 10 caracteres)"
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 mb-1"
        />
        <p v-if="rejectError" class="text-xs text-red-500 mb-2">{{ rejectError }}</p>
        <div class="flex gap-2 mt-3">
          <button
            @click="closeRejectModal"
            type="button"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer"
          >
            Cancelar
          </button>
          <button
            @click="submitReject"
            :disabled="rejecting"
            type="button"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
          >
            {{ rejecting ? 'Rechazando…' : 'Confirmar rechazo' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'

const props = defineProps({
  requests:           { type: Array, default: () => [] },
  rejectedRequests:   { type: Array, default: () => [] },
  counterofdRequests: { type: Array, default: () => [] },
})

const selectedRequest = ref(null)
const searchQuery = ref('')
const sortBy = ref('recent')
const statusTab = ref('open')
const isMobile = ref(false)
const showFloatingSearch = ref(false)
const isStaticSearchFocused = ref(false)
const isFloatingSearchFocused = ref(false)
let lastScrollY = 0

function onFloatingBlur() {
  isFloatingSearchFocused.value = false
  const winScroll = typeof window !== 'undefined' ? (window.scrollY || document.documentElement?.scrollTop || 0) : 0
  if (winScroll <= 80) {
    showFloatingSearch.value = false
  }
}

function handleScroll(e) {
  if (!isMobile.value) {
    if (showFloatingSearch.value) showFloatingSearch.value = false
    return
  }

  // Si el usuario está interactuando con el buscador estático, no activar el flotante
  if (isStaticSearchFocused.value) {
    showFloatingSearch.value = false
    return
  }

  const winScroll = typeof window !== 'undefined' ? (window.scrollY || document.documentElement?.scrollTop || 0) : 0
  const targetScroll = (e?.target && typeof e.target.scrollTop === 'number') ? e.target.scrollTop : 0
  const currentScrollY = Math.max(winScroll, targetScroll)
  const delta = currentScrollY - lastScrollY

  // Si el usuario está escribiendo en el buscador flotante, mantenerlo visible durante micro-scrolls del teclado
  if (isFloatingSearchFocused.value) {
    lastScrollY = currentScrollY
    return
  }

  // Si estamos en la zona superior (<= 80px), la barra estática ya es visible en el flujo
  if (currentScrollY <= 80) {
    showFloatingSearch.value = false
    lastScrollY = currentScrollY
    return
  }

  // Scroll hacia abajo: ocultar
  if (delta > 6) {
    showFloatingSearch.value = false
  }
  // Scroll hacia arriba: mostrar con animación fluida
  else if (delta < -6) {
    showFloatingSearch.value = true
  }

  lastScrollY = currentScrollY
}

function checkMobile() {
  if (typeof window !== 'undefined') {
    isMobile.value = window.innerWidth < 1024
    if (!isMobile.value) {
      showFloatingSearch.value = false
    }
  }
}

onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  window.addEventListener('scroll', handleScroll, { passive: true, capture: true })
  // En desktop preseleccionar la primera solicitud para ver la pestaña derecha idéntica a la maqueta
  if (window.innerWidth >= 1024 && props.requests.length > 0) {
    selectedRequest.value = props.requests[0]
  }
})

onUnmounted(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('resize', checkMobile)
    window.removeEventListener('scroll', handleScroll, { capture: true })
  }
})

function selectRequest(r) {
  selectedRequest.value = r
}

function studentFullName(r) {
  if (!r?.student) return 'Alumno'
  return [r.student.first_name, r.student.last_name].filter(Boolean).join(' ') || 'Alumno'
}

function requestCode(r) {
  if (!r?.id) return '#SOL-00001'
  return `#SOL-${String(r.id).padStart(5, '0')}`
}

function subjectBadgeTheme(subjectName) {
  const n = (subjectName || '').toLowerCase()
  if (n.includes('inglés') || n.includes('lengua') || n.includes('idioma')) {
    return {
      badge: 'bg-blue-100 text-blue-700 border-blue-200/70',
    }
  }
  if (n.includes('mate') || n.includes('álgebra') || n.includes('cálculo') || n.includes('física')) {
    return {
      badge: 'bg-amber-100 text-amber-800 border-amber-200/70',
    }
  }
  if (n.includes('química') || n.includes('bio') || n.includes('ciencia')) {
    return {
      badge: 'bg-emerald-100 text-emerald-800 border-emerald-200/70',
    }
  }
  return {
    badge: 'bg-sky-100 text-sky-800 border-sky-200/70',
  }
}

function urgencyLevel(r) {
  if (!r) {
    return {
      label: 'Baja',
      timeText: 'Flexible',
      icon: '📅',
      badgeClass: 'bg-emerald-100 text-emerald-800 border-emerald-300 font-extrabold',
      boxClass: 'bg-emerald-50/90 border-emerald-200/90',
      iconBoxClass: 'bg-emerald-100 text-emerald-700 border-emerald-200',
      titleClass: 'text-emerald-700',
      textClass: 'text-emerald-950',
    }
  }

  const text = (r.help_needed || '').toLowerCase()

  // 1. Detección explícita de Urgencia en el diagnóstico/solicitud
  const match = text.match(/urgencia:\s*([^\n\r]+)/i)
  if (match && match[1]) {
    const val = match[1].toLowerCase().trim()
    if (val.includes('hoy') || val.includes('inmediat') || val.includes('urgent') || val.includes('esta semana') || val.includes('alta')) {
      return {
        label: 'Alta',
        timeText: 'Esta semana',
        icon: '⚡',
        badgeClass: 'bg-red-100 text-red-700 border-red-300 font-extrabold',
        boxClass: 'bg-red-50/90 border-red-200/90',
        iconBoxClass: 'bg-red-100 text-red-600 border-red-200',
        titleClass: 'text-red-600',
        textClass: 'text-red-950',
      }
    }
    if (val.includes('próxima semana') || val.includes('proxima semana') || val.includes('media') || val.includes('moderada')) {
      return {
        label: 'Media',
        timeText: 'Próxima semana',
        icon: '📅',
        badgeClass: 'bg-amber-100 text-amber-800 border-amber-300 font-extrabold',
        boxClass: 'bg-amber-50/90 border-amber-200/90',
        iconBoxClass: 'bg-amber-100 text-amber-700 border-amber-200',
        titleClass: 'text-amber-700',
        textClass: 'text-amber-950',
      }
    }
    if (val.includes('flexible') || val.includes('sin prisa') || val.includes('baja') || val.includes('mes')) {
      return {
        label: 'Baja',
        timeText: 'Flexible',
        icon: '📅',
        badgeClass: 'bg-emerald-100 text-emerald-800 border-emerald-300 font-extrabold',
        boxClass: 'bg-emerald-50/90 border-emerald-200/90',
        iconBoxClass: 'bg-emerald-100 text-emerald-700 border-emerald-200',
        titleClass: 'text-emerald-700',
        textClass: 'text-emerald-950',
      }
    }
  }

  // 2. Si es mentoría o menciona explícitamente urgente/esta semana/examen
  if (text.includes('urgent') || text.includes('esta semana') || text.includes('examen') || r.is_mentorship) {
    return {
      label: 'Alta',
      timeText: 'Esta semana',
      icon: '⚡',
      badgeClass: 'bg-red-100 text-red-700 border-red-300 font-extrabold',
      boxClass: 'bg-red-50/90 border-red-200/90',
      iconBoxClass: 'bg-red-100 text-red-600 border-red-200',
      titleClass: 'text-red-600',
      textClass: 'text-red-950',
    }
  }

  // 3. Próxima semana o pocos horarios preferidos
  if (text.includes('próxima semana') || text.includes('proxima semana') || (r.preferred_times && r.preferred_times.length <= 2)) {
    return {
      label: 'Media',
      timeText: 'Próxima semana',
      icon: '📅',
      badgeClass: 'bg-amber-100 text-amber-800 border-amber-300 font-extrabold',
      boxClass: 'bg-amber-50/90 border-amber-200/90',
      iconBoxClass: 'bg-amber-100 text-amber-700 border-amber-200',
      titleClass: 'text-amber-700',
      textClass: 'text-amber-950',
    }
  }

  // 4. Default: Baja / Flexible
  return {
    label: 'Baja',
    timeText: 'Flexible',
    icon: '📅',
    badgeClass: 'bg-emerald-100 text-emerald-800 border-emerald-300 font-extrabold',
    boxClass: 'bg-emerald-50/90 border-emerald-200/90',
    iconBoxClass: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    titleClass: 'text-emerald-700',
    textClass: 'text-emerald-950',
  }
}

function requestGoal(r) {
  if (!r?.help_needed) return 'Prepararse para un examen o reforzar temas de la materia.'
  const text = r.help_needed
  const match = text.match(/Objetivo:\s*([^\n\r]+)/i)
  if (match && match[1]) {
    const rawGoal = match[1].trim()
    const cleaned = rawGoal.split(/Urgencia:/i)[0].replace(/\[.*?\]/g, '').trim()
    return cleaned.endsWith('.') ? cleaned : `${cleaned}.`
  }
  const clean = text.replace(/\[.*?\]/g, '').replace(/Urgencia:\s*[^\n\r]+/gi, '').replace(/Objetivo:\s*/gi, '').trim()
  if (clean) {
    const firstSentence = clean.split('.')[0]
    const res = firstSentence ? firstSentence.trim() : clean.slice(0, 90)
    return res.endsWith('.') ? res : `${res}.`
  }
  return 'Prepararse para un examen o reforzar temas de la materia.'
}

function requestStudentMessage(r) {
  if (!r?.help_needed) return 'Hola profesor, me gustaría coordinar los temas más importantes para la clase.'
  const text = r.help_needed
  if (text.includes('Objetivo:') || text.includes('Urgencia:')) {
    const cleanWithoutBrackets = text.replace(/\[.*?\]/g, '')
    const lines = cleanWithoutBrackets.split('\n').map(l => l.trim()).filter(Boolean)
    const customLines = lines.filter(l => !l.startsWith('Objetivo:') && !l.startsWith('Urgencia:'))
    if (customLines.length > 0) {
      return customLines.join(' ')
    }
    const goal = requestGoal(r).replace(/\.$/, '')
    return `Hola profesor, busco apoyo enfocado en ${goal.toLowerCase()}. ¡Muchas gracias!`
  }
  const clean = text.replace(/\[.*?\]/g, '').trim()
  return clean || 'Hola profesor, me gustaría coordinar los temas más importantes para la clase.'
}

function requestAdditionalInfo(r) {
  const text = r?.help_needed || ''
  const bracketMatch = text.match(/\[(.*?)\]/)
  if (bracketMatch && bracketMatch[1]) {
    return bracketMatch[1].trim()
  }
  return 'El padre completó un diagnóstico inicial. El detalle del problema estará disponible tras aceptar la solicitud.'
}

const filteredRequests = computed(() => {
  let list = [...props.requests]

  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase().trim()
    list = list.filter((r) => {
      const student = studentFullName(r).toLowerCase()
      const subject = (r.subject?.name || '').toLowerCase()
      const help = (r.help_needed || '').toLowerCase()
      return student.includes(q) || subject.includes(q) || help.includes(q)
    })
  }

  if (sortBy.value === 'urgent') {
    const score = (r) => {
      const u = urgencyLevel(r).label
      if (u === 'Alta') return 3
      if (u === 'Media') return 2
      return 1
    }
    list.sort((a, b) => score(b) - score(a))
  } else if (sortBy.value === 'student') {
    list.sort((a, b) => studentFullName(a).localeCompare(studentFullName(b)))
  } else if (sortBy.value === 'subject') {
    list.sort((a, b) => (a.subject?.name || '').localeCompare(b.subject?.name || ''))
  } else {
    // 'recent'
    list.sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0))
  }

  return list
})

// ── Modal de contraoferta ─────────────────────────────────────────────────
const counterofferTarget = ref(null)

const minDatetime = computed(() => {
  const d = new Date(Date.now() + 30 * 60 * 1000)
  return d.toISOString().slice(0, 16)
})

const counterofferForm = useForm({
  counteroffer_time: '',
})

function openCounterofferModal(r) {
  counterofferTarget.value = r
  counterofferForm.reset()
  counterofferForm.errors.counteroffer_time = null
}

function closeCounterofferModal() {
  if (counterofferForm.processing) return
  counterofferTarget.value = null
}

function submitCounteroffer() {
  if (counterofferForm.processing) return
  counterofferForm.post(
    route('teacher.requests.counteroffer', counterofferTarget.value.id),
    {
      onSuccess: () => closeCounterofferModal(),
      onError: () => {},
    }
  )
}

// ── Modal de rechazo ──────────────────────────────────────────────────────
const rejectTarget = ref(null)
const rejectReason = ref('')
const rejectError  = ref('')
const rejecting    = ref(false)

function openRejectModal(r) {
  rejectTarget.value = r
  rejectReason.value = ''
  rejectError.value  = ''
}

function closeRejectModal() {
  if (rejecting.value) return
  rejectTarget.value = null
  rejectReason.value = ''
  rejectError.value  = ''
}

function submitReject() {
  if (rejecting.value) return
  if (rejectReason.value.trim().length < 10) {
    rejectError.value = 'El motivo debe tener al menos 10 caracteres.'
    return
  }
  rejecting.value = true
  router.post(
    route('teacher.requests.reject', rejectTarget.value.id),
    { reason: rejectReason.value.trim() },
    {
      onSuccess: () => closeRejectModal(),
      onError: (errors) => { rejectError.value = errors.reason ?? 'No se pudo rechazar la solicitud.' },
      onFinish: () => { rejecting.value = false },
    }
  )
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' })
}

function fmtDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleString('es-ES', {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  })
}
</script>
