<template>
  <AppLayout title="Mi panel">
    <div class="space-y-6">

      <!-- ══════════════════════════════════════════════
           HERO — identidad azul MOVA
           ══════════════════════════════════════════════ -->
      <section class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-[#17469E] via-[#1F5AA6] to-[#2563EB] text-white shadow-lg shadow-brand-800/15 px-6 py-6 sm:px-8 sm:py-7">
        <!-- Decoración de fondo azul (asset oficial) -->
        <img src="/images/brand/parent-hero-decoration.png" alt="" aria-hidden="true"
          class="absolute inset-0 w-full h-full object-cover object-right pointer-events-none select-none opacity-35 mix-blend-screen" />

        <div class="relative z-10 flex items-center justify-between gap-6">
          <div class="min-w-0 py-1">
            <p class="text-blue-100 text-sm font-medium mb-1">¡Bienvenido de vuelta!</p>
            <h2 class="text-2xl sm:text-3xl lg:text-4xl font-black tracking-tight leading-tight">{{ firstName }}</h2>
            <p class="text-blue-100/90 text-sm mt-1.5 capitalize">{{ today }}</p>
            <p class="text-white/80 text-sm mt-2 max-w-sm leading-relaxed hidden sm:block">Acompaña el aprendizaje de tus hijos con los mejores profesores.</p>
          </div>

          <!-- Lado derecho: Composición con ilustración oficial del niño con laptop y texto destacado -->
          <div class="hidden sm:flex items-end gap-3 lg:gap-5 flex-shrink-0">
            <img src="/images/brand/parent-hero-child.png" alt="Estudiante MOVA con laptop"
              class="h-28 sm:h-32 md:h-36 lg:h-40 max-h-full w-auto object-contain select-none pointer-events-none drop-shadow-md self-end" />
            <div class="hidden lg:flex flex-col text-right select-none pr-1 max-w-[170px]">
              <span class="text-sm font-semibold text-white/95 leading-snug">Grandes aprendizajes también empiezan</span>
              <span class="text-sm font-black text-white underline decoration-sky-300 decoration-2 underline-offset-4 mt-0.5">en casa</span>
            </div>
          </div>
        </div>
      </section>

      <!-- ══════════════════════════════════════════════
           BANNER POST-CLASE (lógica sin cambios)
           ══════════════════════════════════════════════ -->
      <div v-if="postClassLessonId && postClassEnded" class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center text-emerald-600 flex-shrink-0">
          <Icon name="flash-success" :size="20" />
        </div>
        <div class="flex-1">
          <p class="font-semibold text-emerald-900">La clase ha terminado.</p>
          <p class="text-sm text-emerald-700 mt-0.5">Confirma tu pago para continuar.</p>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 self-start sm:self-auto">
          <Link :href="route('parent.lessons')"
            class="px-4 py-2 bg-emerald-600 text-white text-sm font-bold rounded-xl hover:bg-emerald-700 transition-colors">
            Ir a la clase
          </Link>
          <button @click="postClassLessonId = null" type="button" aria-label="Cerrar aviso"
            class="px-2 py-2 text-emerald-500 hover:text-emerald-700 transition-colors">
            <Icon name="close" :size="16" />
          </button>
        </div>
      </div>
      <div v-else-if="postClassLessonId" class="bg-slate-50 border border-slate-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-500 flex-shrink-0">
          <Icon name="in-progress" :size="20" />
        </div>
        <div class="flex-1">
          <p class="font-semibold text-slate-800">La clase está en curso.</p>
          <p class="text-sm text-slate-500 mt-0.5">Las acciones (pago/reporte) estarán disponibles cuando finalice el horario programado.</p>
        </div>
        <button @click="postClassLessonId = null" type="button" aria-label="Cerrar aviso"
          class="flex-shrink-0 self-start sm:self-auto px-2 py-2 text-slate-400 hover:text-slate-600 transition-colors">
          <Icon name="close" :size="16" />
        </button>
      </div>

      <!-- ══════════════════════════════════════════════
           EMPTY STATE: sin hijos registrados
           ══════════════════════════════════════════════ -->
      <div v-if="!students.length" class="bg-white rounded-2xl border border-gray-100 px-6 py-16 sm:py-20 text-center max-w-xl mx-auto">
        <div class="w-16 h-16 bg-brand-50 rounded-2xl flex items-center justify-center text-brand-600 mx-auto mb-5">
          <Icon name="my-students" :size="32" :stroke-width="1.5" />
        </div>
        <h3 class="text-xl font-black text-slate-900">Registra a tu primer hijo/a</h3>
        <p class="text-slate-500 mt-2 leading-relaxed text-sm">
          Para solicitar clases, seguir su progreso y calificar profesores, primero necesitamos
          saber para quién estás buscando tutorías.
        </p>
        <Link :href="route('students.create')"
          class="inline-flex items-center gap-2 mt-6 px-6 py-3 bg-brand-600 text-white font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-lg shadow-brand-600/20">
          + Añadir hijo/a
        </Link>
      </div>

      <template v-else>

        <!-- ALERT aprobación pendiente -->
        <div v-if="pending_approval > 0" class="bg-orange-50 border border-orange-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
          <div class="w-10 h-10 bg-orange-100 rounded-xl flex items-center justify-center text-orange-600 flex-shrink-0">
            <Icon name="warning" :size="20" />
          </div>
          <div class="flex-1">
            <p class="font-semibold text-orange-900">{{ pending_approval }} solicitud(es) esperan tu aprobación</p>
            <p class="text-sm text-orange-600 mt-0.5">Revisa y aprueba las clases de tus hijos</p>
          </div>
          <Link :href="route('class-requests.index')"
            class="flex-shrink-0 px-4 py-2 bg-orange-500 text-white text-sm font-bold rounded-xl hover:bg-orange-600 transition-colors self-start sm:self-auto">
            Revisar
          </Link>
        </div>

        <!-- ══════════════════════════════════════════════
             KPI CARDS — 4 métricas
             ══════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4" role="list" aria-label="Métricas del panel">
          <!-- Clases solicitadas -->
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 p-4 sm:p-5 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
            <div class="flex items-start justify-between gap-2 mb-3">
              <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center text-brand-600 group-hover:bg-blue-100 transition-colors flex-shrink-0">
                <Icon name="requests" :size="20" />
              </div>
              <Link :href="route('class-requests.index')" aria-label="Ver solicitudes"
                class="w-7 h-7 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-brand-600 group-hover:bg-blue-50 transition-colors">
                <Icon name="arrow-right" :size="13" />
              </Link>
            </div>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums">{{ stats.class_requests_total }}</p>
            <p class="text-xs sm:text-sm font-semibold text-slate-700 mt-0.5">Clases solicitadas</p>
            <p class="text-xs text-slate-400 mt-1 leading-snug hidden sm:block">Sigue el estado de tus solicitudes</p>
          </div>

          <!-- Clases completadas -->
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 p-4 sm:p-5 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
            <div class="flex items-start justify-between gap-2 mb-3">
              <div class="w-10 h-10 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600 group-hover:bg-emerald-100 transition-colors flex-shrink-0">
                <Icon name="flash-success" :size="20" />
              </div>
              <Link :href="route('parent.lessons')" aria-label="Ver clases completadas"
                class="w-7 h-7 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-emerald-600 group-hover:bg-emerald-50 transition-colors">
                <Icon name="arrow-right" :size="13" />
              </Link>
            </div>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums">{{ stats.classes_completed }}</p>
            <p class="text-xs sm:text-sm font-semibold text-slate-700 mt-0.5">Clases completadas</p>
            <p class="text-xs text-slate-400 mt-1 leading-snug hidden sm:block">Celebra cada logro de tus hijos</p>
          </div>

          <!-- Próxima clase -->
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 p-4 sm:p-5 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
            <div class="flex items-start justify-between gap-2 mb-3">
              <div class="w-10 h-10 bg-sky-50 rounded-xl flex items-center justify-center text-sky-600 group-hover:bg-sky-100 transition-colors flex-shrink-0">
                <Icon name="classes" :size="20" />
              </div>
              <Link :href="route('parent.lessons')" aria-label="Ver próximas clases"
                class="w-7 h-7 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-sky-600 group-hover:bg-sky-50 transition-colors">
                <Icon name="arrow-right" :size="13" />
              </Link>
            </div>
            <template v-if="next_lesson">
              <p class="text-sm font-black text-slate-900 leading-snug line-clamp-1">{{ next_lesson.class_request?.subject?.name ?? 'Clase' }}</p>
              <p class="text-xs text-slate-500 mt-0.5">{{ fmtDateShort(next_lesson.start_time) }}</p>
            </template>
            <template v-else>
              <p class="text-2xl sm:text-3xl font-black text-slate-300">—</p>
              <p class="text-xs sm:text-sm font-semibold text-slate-700 mt-0.5">Próxima clase</p>
              <p class="text-xs text-slate-400 mt-1 leading-snug hidden sm:block">Aún no hay clases programadas</p>
            </template>
          </div>

          <!-- Calificación promedio -->
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 p-4 sm:p-5 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
            <div class="flex items-start justify-between gap-2 mb-3">
              <div class="w-10 h-10 bg-amber-50 rounded-xl flex items-center justify-center text-amber-500 group-hover:bg-amber-100 transition-colors flex-shrink-0">
                <Icon name="reviews" :size="20" />
              </div>
              <Link :href="route('parent.reports')" aria-label="Ver calificaciones"
                class="w-7 h-7 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-amber-500 group-hover:bg-amber-50 transition-colors">
                <Icon name="arrow-right" :size="13" />
              </Link>
            </div>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums">{{ stats.avg_teacher_rating ?? '—' }}</p>
            <p class="text-xs sm:text-sm font-semibold text-slate-700 mt-0.5">Calificación promedio</p>
            <p class="text-xs text-slate-400 mt-1 leading-snug hidden sm:block">Las opiniones de los profesores</p>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════
             DIAGNÓSTICO CTA — con ilustración oficial
             ══════════════════════════════════════════════ -->
        <div class="reveal-item bg-[#F0F5FF] border border-blue-100/90 rounded-2xl p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-5 relative overflow-hidden">
          <div class="flex items-center gap-3.5 min-w-0 z-10">
            <div class="w-12 h-12 bg-blue-100/80 rounded-2xl flex items-center justify-center text-brand-600 flex-shrink-0">
              <Icon name="target" :size="24" />
            </div>
            <div class="min-w-0">
              <p class="font-bold text-slate-900 text-base sm:text-lg">¿No sabes qué profesor elegir?</p>
              <p class="text-xs sm:text-sm text-slate-600 mt-0.5 leading-relaxed max-w-xl">Responde 5 preguntas y MOVA te recomienda los profesores ideales para cada uno de tus hijos.</p>
            </div>
          </div>
          <div class="flex items-center gap-4 flex-shrink-0 z-10 self-start sm:self-auto">
            <Link :href="route('diagnostics.create')"
              class="inline-flex items-center gap-2 px-6 py-2.5 bg-brand-600 text-white font-bold rounded-xl text-sm hover:bg-brand-700 active:scale-95 transition-all shadow-sm shadow-brand-600/20">
              Hacer diagnóstico
              <Icon name="arrow-right" :size="14" />
            </Link>
            <img src="/images/brand/parent-diagnostic-checklist.png" alt="Diagnóstico ilustrado"
              class="h-16 sm:h-20 md:h-24 w-auto object-contain select-none pointer-events-none drop-shadow-sm flex-shrink-0" />
          </div>
        </div>

        <!-- ══════════════════════════════════════════════
             GRID 2 COLUMNAS DESKTOP: Clases + Hijos
             ══════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">

          <!-- Próximas clases — 3/5 -->
          <section class="lg:col-span-3 bg-white rounded-2xl border border-gray-100 overflow-hidden" aria-labelledby="parent-upcoming-title">
            <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
              <div class="flex items-center gap-2">
                <Icon name="classes" :size="18" class="text-brand-600" />
                <h3 id="parent-upcoming-title" class="font-bold text-slate-900">Próximas clases</h3>
              </div>
              <Link :href="route('parent.lessons')" class="text-sm text-brand-600 font-semibold hover:text-brand-700 transition-colors flex items-center gap-1">
                Ver todas <Icon name="arrow-right" :size="14" />
              </Link>
            </div>

            <template v-if="upcoming.length">
              <!-- Esta semana -->
              <div v-if="upcomingThisWeek.length" class="px-5 sm:px-6 pt-4 pb-1">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                  <Icon name="classes" :size="12" /> Esta semana
                </p>
              </div>
              <ol v-if="upcomingThisWeek.length" class="divide-y divide-gray-50">
                <li v-for="(l, i) in upcomingThisWeek" :key="l.id"
                  class="reveal-item px-5 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/60 transition-colors">
                  <div class="flex items-center gap-3 min-w-0">
                    <!-- Status indicator -->
                    <span class="w-3 h-3 rounded-full flex-shrink-0 mt-0.5 ring-2 ring-white ring-offset-1"
                      :class="dotColor(l.status)"></span>
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold text-slate-900 text-sm truncate">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                        <StatusBadge :status="l.status" />
                      </div>
                      <p class="text-xs text-slate-500 mt-0.5">
                        {{ l.student?.first_name }} · Prof. {{ l.teacher_profile?.user?.name }}
                      </p>
                      <p class="text-xs text-slate-400 mt-0.5 flex items-center gap-1">
                        <Icon name="classes" :size="11" /> {{ fmtDate(l.start_time) }}
                      </p>
                    </div>
                  </div>
                  <div class="flex-shrink-0 self-start sm:self-auto">
                    <ConfirmPaymentAction v-if="l.status === 'scheduled'" :lesson="l" :paying-id="payingId"
                      :payment-error-id="paymentErrorId" :payment-error="paymentError" @pay="confirmPayment" />
                    <button v-else-if="l.status === 'paid' && canJoinJitsi(l)" @click="openJitsi(l)"
                      class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 text-white text-xs font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-sm shadow-brand-600/25">
                      <Icon name="join-room" :size="14" /> Unirse a la sala
                    </button>
                    <p v-else-if="l.status === 'paid'" class="text-xs text-slate-400 text-right max-w-[10rem]">La sala ya no está disponible.</p>
                    <Link v-else-if="l.status === 'pending_parent_confirmation'" :href="route('reviews.create', l.id)"
                      class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-500 text-white text-xs font-bold rounded-xl hover:bg-yellow-600 active:scale-95 transition-all shadow-sm">
                      <Icon name="reviews" :size="14" /> Calificar
                    </Link>
                  </div>
                </li>
              </ol>
              <p v-else class="px-5 sm:px-6 py-3 text-sm text-slate-400">No tienes clases esta semana.</p>

              <!-- Pasadas -->
              <template v-if="upcomingPast.length">
                <div class="px-5 sm:px-6 pt-4 pb-1 border-t border-gray-50">
                  <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                    <Icon name="topic" :size="12" /> Pasadas
                  </p>
                </div>
                <ol class="divide-y divide-gray-50">
                  <li v-for="l in upcomingPast" :key="l.id"
                    class="reveal-item px-5 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/60 transition-colors">
                    <div class="flex items-center gap-3 min-w-0">
                      <span class="w-3 h-3 rounded-full flex-shrink-0 mt-0.5 ring-2 ring-white ring-offset-1"
                        :class="dotColor(l.status)"></span>
                      <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                          <p class="font-semibold text-slate-700 text-sm truncate">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                          <StatusBadge :status="l.status" />
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">
                          {{ l.student?.first_name }} · Prof. {{ l.teacher_profile?.user?.name }}
                        </p>
                        <p class="text-xs text-slate-400 mt-0.5 flex items-center gap-1">
                          <Icon name="classes" :size="11" /> {{ fmtDate(l.start_time) }}
                        </p>
                      </div>
                    </div>
                    <div class="flex-shrink-0 self-start sm:self-auto">
                      <ConfirmPaymentAction v-if="l.status === 'scheduled'" :lesson="l" :paying-id="payingId"
                        :payment-error-id="paymentErrorId" :payment-error="paymentError" @pay="confirmPayment" />
                      <button v-else-if="l.status === 'paid' && canJoinJitsi(l)" @click="openJitsi(l)"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 text-white text-xs font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-sm shadow-brand-600/25">
                        <Icon name="join-room" :size="14" /> Unirse a la sala
                      </button>
                      <Link v-else-if="l.status === 'pending_parent_confirmation'" :href="route('reviews.create', l.id)"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-500 text-white text-xs font-bold rounded-xl hover:bg-yellow-600 active:scale-95 transition-all shadow-sm">
                        <Icon name="reviews" :size="14" /> Calificar
                      </Link>
                    </div>
                  </li>
                </ol>
              </template>
            </template>

            <!-- Empty state diseñado según mockup -->
            <div v-else class="relative overflow-hidden px-6 py-12 sm:py-16 text-center">
              <!-- Trayectoria punteada de vuelo decorativa de fondo -->
              <img src="/images/brand/parent-flight-path.png" alt="" aria-hidden="true"
                class="absolute inset-0 w-full h-full object-contain pointer-events-none select-none opacity-25 z-0" />
              
              <!-- Avión de papel en vuelo en el cuadrante derecho -->
              <img src="/images/brand/parent-paper-plane.png" alt="" aria-hidden="true"
                class="absolute right-4 sm:right-10 lg:right-14 top-6 sm:top-8 w-16 sm:w-24 md:w-28 object-contain pointer-events-none select-none drop-shadow-sm z-0" />

              <!-- Contenido central -->
              <div class="relative z-10">
                <img src="/images/brand/dashboard-empty-calendar.png" alt="Calendario"
                  class="w-20 h-20 sm:w-24 sm:h-24 object-contain mx-auto mb-3 drop-shadow-sm select-none" />
                <h4 class="font-bold text-slate-900 text-base sm:text-lg">No hay clases próximas</h4>
                <p class="text-xs sm:text-sm text-slate-500 mt-1 mb-5 max-w-sm mx-auto leading-relaxed">Cuando tengas clases agendadas, las verás aquí.</p>
                <Link :href="route('marketplace')"
                  class="inline-flex items-center gap-2 px-6 py-2.5 bg-brand-600 text-white font-bold text-sm rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-sm shadow-brand-600/20">
                  Buscar un profesor <Icon name="arrow-right" :size="14" />
                </Link>
              </div>
            </div>
          </section>

          <!-- Columna derecha: Mis hijos + Acciones -->
          <div class="lg:col-span-2 space-y-5">

            <!-- Mis hijos -->
            <section class="bg-white rounded-2xl border border-gray-100 overflow-hidden" aria-labelledby="students-title">
              <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <Icon name="my-students" :size="18" class="text-brand-600" />
                  <h3 id="students-title" class="font-bold text-slate-900">Mis hijos</h3>
                </div>
                <Link :href="route('students.index')" class="text-sm text-brand-600 font-semibold hover:text-brand-700 transition-colors flex items-center gap-1">
                  Ver todos <Icon name="arrow-right" :size="14" />
                </Link>
              </div>
              <ul class="divide-y divide-gray-50">
                <li v-for="s in students" :key="s.id"
                  class="reveal-item px-5 sm:px-6 py-3.5 flex items-center gap-3 hover:bg-slate-50/60 transition-colors">
                  <!-- Avatar inicial con color verde uniforme -->
                  <div class="w-9 h-9 bg-emerald-600 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                    {{ s.first_name?.charAt(0)?.toUpperCase() }}
                  </div>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-slate-900 truncate">{{ s.first_name }} {{ s.last_name }}</p>
                    <p class="text-xs text-slate-400 capitalize">{{ s.grade_level ?? 'Sin nivel asignado' }}</p>
                  </div>
                  <Link :href="route('students.index')"
                    class="flex-shrink-0 px-3 py-1.5 bg-brand-50 text-brand-700 text-xs font-bold rounded-lg hover:bg-brand-100 transition-colors border border-brand-100 flex items-center gap-1">
                    Ver detalle <Icon name="arrow-right" :size="12" />
                  </Link>
                </li>
              </ul>
            </section>

            <!-- Acciones rápidas -->
            <section aria-labelledby="parent-actions-title">
              <h3 id="parent-actions-title" class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-3 px-0.5">Acciones rápidas</h3>
              <div class="space-y-2.5">
                <Link :href="route('class-requests.create')"
                  class="reveal-item flex items-center gap-3.5 bg-brand-600 rounded-2xl px-4 py-3.5 hover:bg-brand-700 transition-all shadow-md shadow-brand-600/20">
                  <div class="w-9 h-9 bg-white/15 rounded-xl flex items-center justify-center flex-shrink-0">
                    <Icon name="new-request" :size="18" class="text-white" />
                  </div>
                  <div class="min-w-0 flex-1">
                    <p class="font-bold text-white text-sm">Solicitar una clase</p>
                    <p class="text-xs text-brand-200 mt-0.5">Te contactará el primer profesor disponible</p>
                  </div>
                  <Icon name="arrow-right" :size="16" class="text-white/50 flex-shrink-0" />
                </Link>

                <Link :href="route('students.index')"
                  class="reveal-item group flex items-center gap-3.5 bg-white border border-gray-100 rounded-2xl px-4 py-3.5 hover:border-brand-200 hover:shadow-sm transition-all duration-150">
                  <div class="w-9 h-9 bg-slate-50 group-hover:bg-brand-50 rounded-xl flex items-center justify-center text-slate-500 group-hover:text-brand-600 flex-shrink-0 transition-colors">
                    <Icon name="my-students" :size="18" />
                  </div>
                  <div class="min-w-0 flex-1">
                    <p class="font-semibold text-slate-900 text-sm">Gestionar hijos</p>
                    <p class="text-xs text-slate-400">Añade o edita sus datos</p>
                  </div>
                  <Icon name="arrow-right" :size="16" class="text-slate-300 group-hover:text-brand-500 flex-shrink-0 transition-colors" />
                </Link>

                <Link :href="route('parent.reports')"
                  class="reveal-item group flex items-center gap-3.5 bg-white border border-gray-100 rounded-2xl px-4 py-3.5 hover:border-brand-200 hover:shadow-sm transition-all duration-150">
                  <div class="w-9 h-9 bg-slate-50 group-hover:bg-brand-50 rounded-xl flex items-center justify-center text-slate-500 group-hover:text-brand-600 flex-shrink-0 transition-colors">
                    <Icon name="my-reports" :size="18" />
                  </div>
                  <div class="min-w-0 flex-1">
                    <p class="font-semibold text-slate-900 text-sm">Reportes</p>
                    <p class="text-xs text-slate-400">Historial de aprendizaje</p>
                  </div>
                  <Icon name="arrow-right" :size="16" class="text-slate-300 group-hover:text-brand-500 flex-shrink-0 transition-colors" />
                </Link>
              </div>
            </section>

          </div>
        </div><!-- /grid -->

        <!-- ══════════════════════════════════════════════
             HISTORIAL RECIENTE (si existe)
             ══════════════════════════════════════════════ -->
        <div v-if="recent_history.length">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-base font-bold text-slate-900">Historial reciente</h3>
            <Link :href="route('parent.lessons')" class="text-sm text-brand-600 font-semibold hover:text-brand-700 transition-colors flex items-center gap-1">
              Ver todo <Icon name="arrow-right" :size="14" />
            </Link>
          </div>
          <div class="reveal-group grid gap-3 sm:grid-cols-2">
            <div v-for="l in recent_history" :key="l.id"
              class="reveal-item bg-white rounded-2xl border border-gray-100 p-4 hover:shadow-md transition-shadow">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="font-semibold text-slate-900 truncate text-sm">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                  <p class="text-xs text-slate-400 mt-0.5">{{ l.student?.first_name }} · Prof. {{ l.teacher_profile?.user?.name }}</p>
                  <p class="text-xs text-slate-400">{{ fmtDateShort(l.start_time) }}</p>
                </div>
                <div v-if="l.teacher_review" class="flex-shrink-0 flex items-center gap-0.5">
                  <Icon v-for="n in 5" :key="n" name="reviews" :size="13"
                    :class="n <= l.teacher_review.rating ? 'text-amber-400' : 'text-gray-200'"
                    :fill="n <= l.teacher_review.rating ? 'currentColor' : 'none'" />
                </div>
                <p v-else class="flex-shrink-0 text-xs text-slate-300 italic">Sin calificar</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Último reporte de aprendizaje -->
        <div v-if="last_report" class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
          <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-slate-900">Último reporte de aprendizaje</h3>
            <Link :href="route('parent.reports')" class="text-sm text-brand-600 font-semibold hover:text-brand-700 transition-colors flex items-center gap-1">
              Ver todos <Icon name="arrow-right" :size="14" />
            </Link>
          </div>
          <div class="p-5 sm:p-6 space-y-3">
            <div class="flex flex-wrap items-center gap-2 mb-1">
              <span class="px-2.5 py-0.5 bg-brand-50 text-brand-700 text-xs font-semibold rounded-lg">{{ last_report.subject }}</span>
              <span class="text-xs text-slate-400">{{ last_report.student_name }} · Prof. {{ last_report.teacher_name }}</span>
            </div>
            <div class="grid sm:grid-cols-2 gap-3">
              <div class="bg-slate-50 rounded-xl p-3">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1 flex items-center gap-1"><Icon name="topic" :size="12" /> Tema</p>
                <p class="text-sm text-slate-800 line-clamp-2">{{ last_report.topic_covered }}</p>
              </div>
              <div class="bg-slate-50 rounded-xl p-3">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1 flex items-center gap-1"><Icon name="reviews" :size="12" /> Desempeño</p>
                <p class="text-sm text-slate-800 line-clamp-2">{{ last_report.student_performance }}</p>
              </div>
              <div v-if="last_report.next_step" class="sm:col-span-2 bg-green-50 rounded-xl p-3">
                <p class="text-xs font-semibold text-green-600 uppercase tracking-wide mb-1 flex items-center gap-1"><Icon name="target" :size="12" /> Próximo paso</p>
                <p class="text-sm text-slate-800">{{ last_report.next_step }}</p>
              </div>
            </div>
          </div>
        </div>

      </template><!-- /v-else students -->
    </div>

    <!-- Modal Sala Virtual (Jitsi) — sin cambios -->
    <JitsiModal :show="showingJitsiModal" :lesson="activeLesson" :error="joinError" :connecting="connecting" @close="closeJitsi" />
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import JitsiModal from '@/Components/JitsiModal.vue'
import ConfirmPaymentAction from '@/Components/Lessons/ConfirmPaymentAction.vue'
import Icon from '@/Components/Icon.vue'
import { useJitsiMeet } from '@/Composables/useJitsiMeet'
import { splitByWeek } from '@/utils/weekGrouping'
import { canJoinJitsi } from '@/utils/lessonJoin'
import { statusStyle } from '@/utils/statusColors'
import gsap from 'gsap'

const props = defineProps({
  students: { type: Array, default: () => [] },
  upcoming: { type: Array, default: () => [] },
  next_lesson: { type: Object, default: null },
  recent_history: { type: Array, default: () => [] },
  stats: {
    type: Object,
    default: () => ({ class_requests_total: 0, classes_completed: 0, avg_teacher_rating: null }),
  },
  pending_approval: { type: Number, default: 0 },
  last_report: { type: Object, default: null },
})

const user      = computed(() => usePage().props.auth?.user)
const firstName = computed(() => user.value?.name?.split(' ')[0] ?? '')
const today     = computed(() => new Date().toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }))

const payingId = ref(null)
const paymentError = ref('')
const paymentErrorId = ref(null)
const postClassLessonId = ref(null)
const postClassEnded = ref(true)
const { showingJitsiModal, joinError, connecting, activeLesson, openJitsi, closeJitsi } = useJitsiMeet()

// `upcoming` ya viene ordenado start_time asc (próxima primero) desde DashboardController
const upcomingGrouped  = computed(() => splitByWeek(props.upcoming))
const upcomingThisWeek = computed(() => upcomingGrouped.value.thisWeek)
const upcomingPast     = computed(() => upcomingGrouped.value.past)

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function fmtDateShort(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

// Deduplicado: usa statusStyle() compartido (utils/statusColors.js)
function dotColor(status) {
  return statusStyle(status).dot
}

// F-19: incluye onError y paymentErrorId por lección para evitar estado global
function confirmPayment(l) {
  if (payingId.value) return
  payingId.value = l.id
  paymentErrorId.value = null
  router.post(route('lessons.confirm-payment', l.id), {}, {
    preserveScroll: true,
    onError: (e) => {
      paymentErrorId.value = l.id
      paymentError.value = e.confirmPayment || 'No se pudo confirmar el pago. Intenta nuevamente.'
    },
    onFinish: () => { payingId.value = null },
  })
}

onMounted(() => {
  // Limpia ?post_class de la URL tras cerrar el modal Jitsi
  const params = new URLSearchParams(window.location.search)
  const postClass = params.get('post_class')
  if (postClass) {
    postClassLessonId.value = postClass
    const endsAt = params.get('post_class_ends_at')
    postClassEnded.value = endsAt ? Date.now() >= new Date(endsAt).getTime() : true
    window.history.replaceState({}, '', window.location.pathname)
  }

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (prefersReducedMotion) return

  // Animación de entrada por sección — con safeguard para tabs en background
  // (ver comentario original en Parent.vue sobre el bug de Chrome con opacity:0)
  document.querySelectorAll('.reveal-group').forEach((group, groupIndex) => {
    const items = group.querySelectorAll('.reveal-item')
    if (!items.length) return

    gsap.from(items, {
      opacity: 0,
      y: 16,
      duration: 0.5,
      ease: 'power2.out',
      delay: groupIndex * 0.06,
      stagger: { amount: Math.min(items.length * 0.06, 0.4) },
      clearProps: 'opacity,transform',
    })
    setTimeout(() => gsap.set(items, { clearProps: 'all' }), 1500)
  })
})
</script>
