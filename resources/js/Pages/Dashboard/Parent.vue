<template>
  <AppLayout title="Mi panel">
    <div class="space-y-4 sm:space-y-4.5">

      <!-- ══════════════════════════════════════════════
           HERO — identidad azul MOVA (intacto)
           ══════════════════════════════════════════════ -->
      <section class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-[#17469E] via-[#1F5AA6] to-[#2563EB] text-white shadow-lg shadow-brand-800/15 px-6 py-6 sm:px-8 sm:py-7">
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
      <div v-if="postClassLessonId && postClassEnded" class="bg-emerald-50 border border-emerald-200 rounded-2xl sm:rounded-3xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
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
      <div v-else-if="postClassLessonId" class="bg-slate-50 border border-slate-200 rounded-2xl sm:rounded-3xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
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
      <div v-if="!students.length" class="bg-white rounded-3xl border border-gray-100 px-6 py-12 sm:py-16 text-center max-w-xl mx-auto shadow-sm">
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
        <div v-if="pending_approval > 0" class="bg-orange-50 border border-orange-200 rounded-2xl sm:rounded-3xl p-3.5 flex flex-col sm:flex-row sm:items-center gap-3">
          <div class="w-9 h-9 bg-orange-100 rounded-xl flex items-center justify-center text-orange-600 flex-shrink-0">
            <Icon name="warning" :size="18" />
          </div>
          <div class="flex-1">
            <p class="font-semibold text-orange-900 text-sm">{{ pending_approval }} solicitud(es) esperan tu aprobación</p>
            <p class="text-xs text-orange-600 mt-0.5">Revisa y aprueba las clases de tus hijos</p>
          </div>
          <Link :href="route('class-requests.index')"
            class="flex-shrink-0 px-3.5 py-1.5 bg-orange-500 text-white text-xs font-bold rounded-xl hover:bg-orange-600 transition-colors self-start sm:self-auto">
            Revisar
          </Link>
        </div>

        <!-- ══════════════════════════════════════════════
             KPI CARDS — Slim UI (4 métricas compactas)
             ══════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4" role="list" aria-label="Métricas del panel">
          <!-- Clases solicitadas -->
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 py-3 px-4 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <div class="w-7 h-7 sm:w-8 sm:h-8 bg-blue-50 rounded-lg flex items-center justify-center text-brand-600 group-hover:bg-blue-100 transition-colors flex-shrink-0">
                <Icon name="requests" :size="16" />
              </div>
              <Link :href="route('class-requests.index')" aria-label="Ver solicitudes"
                class="w-6 h-6 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-brand-600 group-hover:bg-blue-50 transition-colors">
                <Icon name="arrow-right" :size="11" />
              </Link>
            </div>
            <p class="text-xl sm:text-2xl font-black text-slate-900 tabular-nums leading-tight">{{ stats.class_requests_total }}</p>
            <p class="text-xs sm:text-sm font-semibold text-slate-700 mt-0.5">Clases solicitadas</p>
          </div>

          <!-- Clases completadas -->
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 py-3 px-4 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <div class="w-7 h-7 sm:w-8 sm:h-8 bg-emerald-50 rounded-lg flex items-center justify-center text-emerald-600 group-hover:bg-emerald-100 transition-colors flex-shrink-0">
                <Icon name="flash-success" :size="16" />
              </div>
              <Link :href="route('parent.lessons')" aria-label="Ver clases completadas"
                class="w-6 h-6 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-emerald-600 group-hover:bg-emerald-50 transition-colors">
                <Icon name="arrow-right" :size="11" />
              </Link>
            </div>
            <p class="text-xl sm:text-2xl font-black text-slate-900 tabular-nums leading-tight">{{ stats.classes_completed }}</p>
            <p class="text-xs sm:text-sm font-semibold text-slate-700 mt-0.5">Clases completadas</p>
          </div>

          <!-- Próxima clase -->
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 py-3 px-4 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <div class="w-7 h-7 sm:w-8 sm:h-8 bg-sky-50 rounded-lg flex items-center justify-center text-sky-600 group-hover:bg-sky-100 transition-colors flex-shrink-0">
                <Icon name="classes" :size="16" />
              </div>
              <Link :href="route('parent.lessons')" aria-label="Ver próximas clases"
                class="w-6 h-6 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-sky-600 group-hover:bg-sky-50 transition-colors">
                <Icon name="arrow-right" :size="11" />
              </Link>
            </div>
            <template v-if="next_lesson">
              <p class="text-sm font-black text-slate-900 leading-tight line-clamp-1">{{ next_lesson.class_request?.subject?.name ?? 'Clase' }}</p>
              <p class="text-xs text-slate-500 mt-0.5 truncate">{{ fmtDateShort(next_lesson.start_time) }}</p>
            </template>
            <template v-else>
              <p class="text-xl sm:text-2xl font-black text-slate-300 leading-tight">—</p>
              <p class="text-xs sm:text-sm font-semibold text-slate-700 mt-0.5">Próxima clase</p>
            </template>
          </div>

          <!-- Calificación promedio -->
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 py-3 px-4 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <div class="w-7 h-7 sm:w-8 sm:h-8 bg-amber-50 rounded-lg flex items-center justify-center text-amber-500 group-hover:bg-amber-100 transition-colors flex-shrink-0">
                <Icon name="reviews" :size="16" />
              </div>
              <Link :href="route('parent.reports')" aria-label="Ver calificaciones"
                class="w-6 h-6 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-amber-500 group-hover:bg-amber-50 transition-colors">
                <Icon name="arrow-right" :size="11" />
              </Link>
            </div>
            <p class="text-xl sm:text-2xl font-black text-slate-900 tabular-nums leading-tight">{{ stats.avg_teacher_rating ?? '—' }}</p>
            <p class="text-xs sm:text-sm font-semibold text-slate-700 mt-0.5">Calificación promedio</p>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════
             MAIN CONTENT GRID — 3 Columnas
             ══════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 lg:gap-6 items-stretch">

          <!-- ── COLUMNA 1: Próximas clases ───────────────────────────── -->
          <section class="bg-white rounded-2xl sm:rounded-3xl border border-gray-100 p-5 shadow-sm flex flex-col justify-between" aria-labelledby="parent-upcoming-title">
            <div>
              <div class="flex items-center justify-between pb-3.5 mb-3.5 border-b border-gray-100">
                <div class="flex items-center gap-2">
                  <Icon name="classes" :size="18" class="text-brand-600" />
                  <h3 id="parent-upcoming-title" class="font-bold text-slate-900 text-sm sm:text-base">Próximas clases</h3>
                </div>
                <Link :href="route('parent.lessons')" class="text-xs text-brand-600 font-semibold hover:text-brand-700 transition-colors flex items-center gap-1">
                  Ver todas <Icon name="arrow-right" :size="12" />
                </Link>
              </div>

              <!-- Lista compacta de máximo 2 clases -->
              <template v-if="upcoming.length">
                <ol class="divide-y divide-gray-50">
                  <li v-for="l in upcoming.slice(0, 2)" :key="l.id"
                    class="reveal-item py-3 first:pt-0 last:pb-0 flex flex-col gap-2">
                    <div class="flex items-start justify-between gap-2 min-w-0">
                      <div class="flex items-start gap-2.5 min-w-0">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 mt-1 ring-2 ring-white ring-offset-1"
                          :class="dotColor(l.status)"></span>
                        <div class="min-w-0">
                          <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-bold text-slate-900 text-sm truncate">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                            <StatusBadge :status="l.status" />
                          </div>
                          <p class="text-xs text-slate-500 mt-0.5 truncate">
                            {{ l.student?.first_name }} · Prof. {{ l.teacher_profile?.user?.name }}
                          </p>
                          <p class="text-[11px] text-slate-400 mt-0.5 flex items-center gap-1">
                            <Icon name="classes" :size="11" /> {{ fmtDate(l.start_time) }}
                          </p>
                        </div>
                      </div>

                      <!-- Acciones compactas y sutiles -->
                      <div class="flex-shrink-0">
                        <ConfirmPaymentAction v-if="l.status === 'scheduled'" :lesson="l" :paying-id="payingId"
                          :payment-error-id="paymentErrorId" :payment-error="paymentError" @pay="confirmPayment" />
                        <button v-else-if="l.status === 'paid' && canJoinJitsi(l)" @click="openJitsi(l)"
                          class="inline-flex items-center gap-1 px-3 py-1.5 bg-brand-600 text-white text-xs font-bold rounded-lg hover:bg-brand-700 active:scale-95 transition-all shadow-xs">
                          <Icon name="join-room" :size="12" /> Unirse
                        </button>
                        <p v-else-if="l.status === 'paid'" class="text-[11px] text-slate-400 text-right">Sala cerrada</p>
                        <Link v-else-if="l.status === 'pending_parent_confirmation'" :href="route('reviews.create', l.id)"
                          class="inline-flex items-center gap-1 px-3 py-1.5 bg-yellow-500 text-white text-xs font-bold rounded-lg hover:bg-yellow-600 active:scale-95 transition-all shadow-xs">
                          <Icon name="reviews" :size="12" /> Calificar
                        </Link>
                      </div>
                    </div>
                  </li>
                </ol>
              </template>

              <!-- Empty state compacto -->
              <div v-else class="py-6 text-center">
                <div class="w-12 h-12 bg-slate-50 rounded-xl flex items-center justify-center text-slate-400 mx-auto mb-2">
                  <Icon name="classes" :size="22" />
                </div>
                <h4 class="font-bold text-slate-900 text-sm">No hay clases próximas</h4>
                <p class="text-xs text-slate-500 mt-0.5 mb-3.5 max-w-xs mx-auto">Cuando tengas clases agendadas, las verás aquí.</p>
                <Link :href="route('marketplace')"
                  class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 text-white font-bold text-xs rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-xs">
                  Buscar un profesor <Icon name="arrow-right" :size="12" />
                </Link>
              </div>
            </div>
          </section>

          <!-- ── COLUMNA 2: Último reporte de aprendizaje ─────────────── -->
          <section class="border-t-4 border-[#1f5aa6] bg-white rounded-2xl sm:rounded-3xl border-x border-b border-gray-100 p-5 shadow-sm flex flex-col justify-between" aria-labelledby="last-report-title">
            <div>
              <div class="flex items-center justify-between pb-3.5 mb-3.5 border-b border-gray-100">
                <div class="flex items-center gap-2">
                  <Icon name="my-reports" :size="18" class="text-brand-600" />
                  <h3 id="last-report-title" class="font-bold text-slate-900 text-sm sm:text-base">Último reporte</h3>
                </div>
                <Link :href="route('parent.reports')" class="text-xs text-brand-600 font-semibold hover:text-brand-700 transition-colors flex items-center gap-1">
                  Ver todos <Icon name="arrow-right" :size="12" />
                </Link>
              </div>

              <template v-if="last_report">
                <div class="space-y-3">
                  <!-- Header del reporte: Chip de materia e info de estudiante/profesor -->
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="px-2.5 py-0.5 bg-brand-50 text-brand-700 text-xs font-semibold rounded-lg">{{ last_report.subject }}</span>
                    <span class="text-xs text-slate-500 font-medium truncate">{{ last_report.student_name }} · Prof. {{ last_report.teacher_name }}</span>
                  </div>

                  <!-- Bloque Tema -->
                  <div class="bg-slate-50 rounded-xl p-3 border border-slate-100/80">
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center gap-1">
                      <Icon name="topic" :size="12" /> Tema
                    </p>
                    <p class="text-sm text-slate-800 line-clamp-2 leading-snug">{{ last_report.topic_covered }}</p>
                  </div>

                  <!-- Bloque Desempeño -->
                  <div class="bg-slate-50 rounded-xl p-3 border border-slate-100/80">
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center gap-1">
                      <Icon name="reviews" :size="12" /> Desempeño
                    </p>
                    <p class="text-sm text-slate-800 line-clamp-2 leading-snug">{{ last_report.student_performance }}</p>
                  </div>

                  <!-- Bloque Próximo paso (Resaltado con fondo suave) -->
                  <div v-if="last_report.next_step" class="bg-green-50 text-green-700 rounded-xl p-3 border border-green-100">
                    <p class="text-[11px] font-bold text-green-700 uppercase tracking-wider mb-1 flex items-center gap-1">
                      <Icon name="target" :size="12" /> Próximo paso
                    </p>
                    <p class="text-sm text-green-900 leading-snug">{{ last_report.next_step }}</p>
                  </div>
                </div>
              </template>

              <!-- Empty state si no hay reportes -->
              <div v-else class="py-6 text-center">
                <div class="w-12 h-12 bg-slate-50 rounded-xl flex items-center justify-center text-slate-400 mx-auto mb-2">
                  <Icon name="my-reports" :size="22" />
                </div>
                <h4 class="font-bold text-slate-900 text-sm">Sin reportes aún</h4>
                <p class="text-xs text-slate-500 mt-0.5 max-w-xs mx-auto">Cuando tus hijos completen clases, aquí verás el reporte de su aprendizaje.</p>
              </div>
            </div>
          </section>

          <!-- ── COLUMNA 3: Mis hijos + Acciones Rápidas ──────────────── -->
          <div class="flex flex-col gap-4 sm:gap-5 justify-between">

            <!-- Arriba: Mis Hijos -->
            <section class="bg-white rounded-2xl sm:rounded-3xl border border-gray-100 p-4 sm:p-5 shadow-sm" aria-labelledby="students-title">
              <div class="flex items-center justify-between pb-2.5 mb-2.5 border-b border-gray-100">
                <div class="flex items-center gap-2">
                  <Icon name="my-students" :size="18" class="text-brand-600" />
                  <h3 id="students-title" class="font-bold text-slate-900 text-sm sm:text-base">Mis hijos</h3>
                </div>
                <Link :href="route('students.index')" class="text-xs text-brand-600 font-semibold hover:text-brand-700 transition-colors flex items-center gap-1">
                  Ver todos <Icon name="arrow-right" :size="12" />
                </Link>
              </div>

              <!-- Lista muy comprimida -->
              <ul class="divide-y divide-gray-50">
                <li v-for="s in students.slice(0, 2)" :key="s.id"
                  class="reveal-item py-2 first:pt-1 last:pb-1 flex items-center justify-between gap-3">
                  <div class="flex items-center gap-2.5 min-w-0">
                    <!-- Avatar redondo inicial -->
                    <div class="w-8 h-8 rounded-full bg-emerald-600 flex items-center justify-center text-white font-bold text-xs flex-shrink-0">
                      {{ s.first_name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div class="min-w-0">
                      <p class="text-xs sm:text-sm font-semibold text-slate-900 truncate">{{ s.first_name }} {{ s.last_name }}</p>
                      <p class="text-[11px] text-slate-400 capitalize truncate">{{ s.grade_level ?? 'Sin nivel' }}</p>
                    </div>
                  </div>
                  <!-- Botón Ver detalle minimalista -->
                  <Link :href="route('students.index')"
                    class="text-xs font-semibold text-brand-600 hover:text-brand-700 flex items-center gap-0.5 whitespace-nowrap transition-colors">
                    Ver detalle <Icon name="arrow-right" :size="11" />
                  </Link>
                </li>
              </ul>
            </section>

            <!-- Abajo: Acciones Rápidas -->
            <section class="bg-white rounded-2xl sm:rounded-3xl border border-gray-100 p-4 sm:p-5 shadow-sm" aria-labelledby="parent-actions-title">
              <h3 id="parent-actions-title" class="font-bold text-slate-900 text-sm mb-3">Acciones rápidas</h3>
              <div class="space-y-2.5">
                <!-- Botón verde magnético con animación ultra fluida en reposo y hover -->
                <div class="relative group block w-full select-none">
                  <!-- Capa 1: Resplandor ambiental verde en reposo y hover (difuso y suave) -->
                  <div class="cta-glow-ambient absolute -inset-0.5 rounded-2xl bg-gradient-to-r from-emerald-500 via-green-400 to-teal-500 opacity-60 blur-md transition-all duration-700 ease-out group-hover:opacity-100 group-hover:blur-lg group-hover:-inset-1"></div>

                  <!-- Capa 2: Botón principal -->
                  <Link :href="route('class-requests.create')"
                    class="cta-main-btn relative overflow-hidden w-full bg-gradient-to-r from-emerald-600 via-emerald-500 to-green-600 text-white font-extrabold text-sm rounded-xl py-3 px-4 flex items-center justify-between border border-emerald-300/40 shadow-md shadow-emerald-900/10">

                    <!-- Capa 3: Overlay de gradiente brillante en hover que se desvanece suavemente con opacity (sin saltos bruscos) -->
                    <div class="absolute inset-0 bg-gradient-to-r from-emerald-500 via-green-400 to-teal-500 opacity-0 group-hover:opacity-100 transition-opacity duration-700 ease-out pointer-events-none"></div>

                    <!-- Capa 4: Shimmer ray continuo -->
                    <div class="cta-shimmer-sweep pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/30 to-transparent"></div>

                    <!-- Contenido interactivo -->
                    <div class="relative z-10 flex items-center gap-2.5">
                      <span class="flex items-center justify-center w-7 h-7 rounded-lg bg-white/20 backdrop-blur-xs text-white shadow-2xs transition-transform duration-500 ease-out group-hover:scale-110 group-hover:rotate-6">
                        <Icon name="new-request" :size="16" class="text-white drop-shadow-xs" />
                      </span>
                      <span class="tracking-wide font-black text-sm drop-shadow-xs">Solicitar una clase</span>
                    </div>

                    <!-- Flecha animada suavemente al hacer hover -->
                    <div class="relative z-10 flex items-center justify-center w-6 h-6 rounded-full bg-emerald-800/30 text-white transition-all duration-500 ease-out group-hover:bg-white/25 group-hover:translate-x-1.5">
                      <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                      </svg>
                    </div>
                  </Link>
                </div>

                <!-- Botón gris secundario -->
                <Link :href="route('students.index')"
                  class="w-full bg-slate-100 hover:bg-slate-200/80 text-slate-700 font-semibold text-sm rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 active:scale-[0.99] transition-all">
                  <Icon name="my-students" :size="16" class="text-slate-500" />
                  <span>Reportes / Gestionar hijos</span>
                </Link>
              </div>
            </section>

          </div>
        </div><!-- /grid -->

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

<style scoped>
/* Respiración sutil y fluida del resplandor ambiental en reposo */
@keyframes ambient-glow-breathe {
  0%, 100% {
    opacity: 0.55;
    transform: scale(0.99);
  }
  50% {
    opacity: 0.85;
    transform: scale(1.02);
  }
}

.cta-glow-ambient {
  animation: ambient-glow-breathe 3.5s ease-in-out infinite;
}

/* Transición ultra-fluida al interactuar con el botón principal */
.cta-main-btn {
  transform: translateY(0) scale(1);
  transition: transform 0.55s cubic-bezier(0.16, 1, 0.3, 1),
              box-shadow 0.55s cubic-bezier(0.16, 1, 0.3, 1),
              border-color 0.55s cubic-bezier(0.16, 1, 0.3, 1);
}

.group:hover .cta-main-btn {
  transform: translateY(-2.5px) scale(1.015);
  box-shadow: 0 12px 28px -4px rgba(16, 185, 129, 0.55), 0 0 0 1px rgba(167, 243, 208, 0.4);
  border-color: rgba(167, 243, 208, 0.7);
}

.group:active .cta-main-btn {
  transform: translateY(0) scale(0.98);
  transition: transform 0.15s ease-out;
}

/* Destello continuo suave que cruza el botón */
@keyframes shimmer-sweep-anim {
  0% {
    transform: translateX(-150%) skewX(-20deg);
  }
  35%, 100% {
    transform: translateX(250%) skewX(-20deg);
  }
}

.cta-shimmer-sweep {
  animation: shimmer-sweep-anim 4.2s cubic-bezier(0.4, 0, 0.2, 1) infinite;
}

.group:hover .cta-shimmer-sweep {
  animation: shimmer-sweep-anim 2s cubic-bezier(0.4, 0, 0.2, 1) infinite;
}
</style>
