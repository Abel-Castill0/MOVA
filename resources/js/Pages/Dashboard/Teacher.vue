<template>
  <AppLayout title="Mi panel de profesor">
    <div class="space-y-6">

      <!-- ══════════════════════════════════════════════
           HERO — identidad naranja MOVA
           ══════════════════════════════════════════════ -->
      <section class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-[#B43403] via-[#EA580C] to-[#F97316] text-white shadow-lg shadow-orange-900/15 px-6 py-6 sm:px-8 sm:py-7">
        <!-- Decoración de fondo naranja (asset oficial) -->
        <img src="/images/brand/teacher-hero-decoration.png" alt="" aria-hidden="true"
          class="absolute inset-0 w-full h-full object-cover object-right pointer-events-none select-none opacity-30 mix-blend-screen" />

        <div class="relative z-10 flex items-center justify-between gap-6">
          <div class="min-w-0 py-1">
            <p class="text-orange-100 text-sm font-medium mb-1 flex items-center gap-1.5">
              ¡Hola, {{ firstName }}! <span class="inline-block text-base">👋</span>
            </p>
            <h2 class="text-2xl sm:text-3xl lg:text-4xl font-black tracking-tight leading-tight">Tu panel de clases</h2>
            <p class="text-orange-100/90 text-sm mt-1.5 capitalize">{{ today }}</p>
            <p class="text-white/80 text-sm mt-2 max-w-sm leading-relaxed hidden sm:block">Sigamos creando oportunidades a través de la educación.</p>
          </div>

          <!-- Lado derecho: Composición educativa con ilustración oficial -->
          <div class="hidden sm:flex items-end gap-3 lg:gap-5 flex-shrink-0">
            <div class="hidden lg:flex flex-col items-end justify-center text-white/90 text-right select-none pr-1">
              <span class="text-sm font-medium italic tracking-wide font-serif leading-tight">Grandes profesores,</span>
              <span class="text-sm font-medium italic tracking-wide font-serif leading-tight">mejores futuros</span>
              <!-- Flecha curva hacia la ilustración -->
              <svg class="w-12 h-6 text-white/80 mt-1 mr-2" viewBox="0 0 50 25" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M 5 5 Q 30 5 38 18 M 32 18 L 38 18 L 38 12" />
              </svg>
            </div>
            <img src="/images/brand/teacher-hero-education.png" alt="Libros y herramientas educativas"
              class="h-28 sm:h-32 md:h-36 lg:h-40 max-h-full w-auto object-contain select-none pointer-events-none drop-shadow-md self-end" />
          </div>
        </div>
      </section>

      <!-- ══════════════════════════════════════════════
           BANNER POST-CLASE (lógica sin cambios)
           ══════════════════════════════════════════════ -->
      <div v-if="postClassLessonId && postClassEnded" class="bg-blue-50 border border-blue-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center text-blue-600 flex-shrink-0">
          <Icon name="my-reports" :size="20" />
        </div>
        <div class="flex-1">
          <p class="font-semibold text-blue-900">La clase ha terminado.</p>
          <p class="text-sm text-blue-700 mt-0.5">Escribe el reporte pedagógico para los padres.</p>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 self-start sm:self-auto">
          <Link :href="route('lesson-reports.create', postClassLessonId)"
            class="px-4 py-2 bg-blue-600 text-white text-sm font-bold rounded-xl hover:bg-blue-700 transition-colors">
            Escribir reporte
          </Link>
          <button @click="postClassLessonId = null" type="button" aria-label="Cerrar aviso"
            class="px-2 py-2 text-blue-500 hover:text-blue-700 transition-colors">
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
           KPI CARDS — 3 métricas clave
           ══════════════════════════════════════════════ -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4" role="list" aria-label="Métricas del panel">
        <!-- Clases próximas -->
        <div class="group bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
          <div class="flex items-start justify-between gap-3 mb-3">
            <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center text-blue-600 flex-shrink-0">
              <Icon name="classes" :size="20" />
            </div>
            <Link :href="route('teacher.lessons')" aria-label="Ver todas mis clases"
              class="w-8 h-8 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-blue-600 group-hover:bg-blue-50 transition-colors">
              <Icon name="arrow-right" :size="14" />
            </Link>
          </div>
          <p class="text-3xl font-black text-slate-900 tabular-nums">{{ upcoming.length }}</p>
          <p class="text-sm font-semibold text-slate-700 mt-0.5">Clases próximas</p>
          <p class="text-xs text-slate-400 mt-1 leading-snug">
            <template v-if="upcoming.length === 0">Tienes 0 clases programadas</template>
            <template v-else-if="upcoming.length === 1">Tienes 1 clase agendada</template>
            <template v-else>Tienes {{ upcoming.length }} clases agendadas</template>
          </p>
        </div>

        <!-- Solicitudes abiertas -->
        <div class="group bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
          <div class="flex items-start justify-between gap-3 mb-3">
            <div class="w-10 h-10 bg-orange-50 rounded-xl flex items-center justify-center text-orange-600 flex-shrink-0">
              <Icon name="requests" :size="20" />
            </div>
            <Link :href="route('teacher.requests')" aria-label="Ver solicitudes"
              class="w-8 h-8 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-orange-600 group-hover:bg-orange-50 transition-colors">
              <Icon name="arrow-right" :size="14" />
            </Link>
          </div>
          <p class="text-3xl font-black text-orange-600 tabular-nums">{{ pending_requests }}</p>
          <p class="text-sm font-semibold text-slate-700 mt-0.5">Solicitudes abiertas</p>
          <p class="text-xs text-slate-400 mt-1 leading-snug">
            <template v-if="pending_requests === 0">Tienes 0 solicitudes pendientes</template>
            <template v-else-if="pending_requests === 1">Tienes 1 solicitud pendiente</template>
            <template v-else>Tienes {{ pending_requests }} solicitudes pendientes</template>
          </p>
        </div>

        <!-- Reportes pendientes -->
        <div class="group bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" role="listitem">
          <div class="flex items-start justify-between gap-3 mb-3">
            <div :class="['w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0',
              pending_reports > 0 ? 'bg-red-50 text-red-500' : 'bg-green-50 text-green-600']">
              <Icon name="my-reports" :size="20" />
            </div>
            <Link :href="route('teacher.lessons')" aria-label="Ver reportes"
              class="w-8 h-8 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 group-hover:text-emerald-600 group-hover:bg-green-50 transition-colors">
              <Icon name="arrow-right" :size="14" />
            </Link>
          </div>
          <p class="text-3xl font-black tabular-nums" :class="pending_reports > 0 ? 'text-red-500' : 'text-slate-900'">{{ pending_reports }}</p>
          <p class="text-sm font-semibold text-slate-700 mt-0.5">Reportes pendientes</p>
          <p class="text-xs text-slate-400 mt-1 leading-snug">
            <template v-if="pending_reports === 0">No tienes reportes pendientes</template>
            <template v-else-if="pending_reports === 1">Tienes 1 reporte por completar</template>
            <template v-else>Tienes {{ pending_reports }} reportes por completar</template>
          </p>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════
           ALERT — Verificación de celular (naranja MOVA)
           ══════════════════════════════════════════════ -->
      <div v-if="!user?.phone_verified && showPhoneBanner" class="bg-orange-50/70 border border-orange-200/80 rounded-2xl p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3.5 min-w-0">
          <div class="w-11 h-11 bg-orange-500 rounded-2xl flex items-center justify-center text-white flex-shrink-0 shadow-sm shadow-orange-500/20">
            <Icon name="incentive" :size="22" />
          </div>
          <div class="min-w-0">
            <p class="font-bold text-slate-900 text-sm sm:text-base">¡Verifica tu número de celular!</p>
            <p class="text-xs sm:text-sm text-slate-600 mt-0.5 leading-relaxed">Desbloquea tus 5 créditos gratis y empieza a aceptar solicitudes de clases.</p>
          </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 self-start sm:self-auto">
          <Link :href="route('phone.verification.notice')"
            class="inline-flex items-center gap-2 px-5 py-2.5 bg-orange-600 text-white text-sm font-bold rounded-xl hover:bg-orange-700 active:scale-95 transition-all shadow-sm shadow-orange-600/20">
            Verificar celular
            <Icon name="arrow-right" :size="14" />
          </Link>
          <button @click="showPhoneBanner = false" type="button" aria-label="Cerrar aviso"
            class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-orange-100/50 transition-colors">
            <Icon name="close" :size="16" />
          </button>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════
           ALERT — Reportes atrasados
           ══════════════════════════════════════════════ -->
      <div v-if="pending_reports > 0" class="bg-red-50 border border-red-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center text-red-600 flex-shrink-0">
          <Icon name="report-due" :size="20" />
        </div>
        <div class="flex-1">
          <p class="font-semibold text-red-900">{{ pending_reports }} clase(s) completada(s) sin reporte</p>
          <p class="text-sm text-red-600 mt-0.5">Los padres esperan el reporte de aprendizaje de sus hijos.</p>
        </div>
        <Link :href="route('teacher.lessons')"
          class="flex-shrink-0 px-4 py-2 bg-red-500 text-white text-sm font-bold rounded-xl hover:bg-red-600 transition-colors self-start sm:self-auto">
          Completar reportes
        </Link>
      </div>

      <!-- ══════════════════════════════════════════════
           GRID 2 COLUMNAS DESKTOP: Clases + Perfil
           ══════════════════════════════════════════════ -->
      <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">

        <!-- Próximas clases — col 3/5 -->
        <section class="lg:col-span-3 bg-white rounded-2xl border border-gray-100 overflow-hidden" aria-labelledby="upcoming-title">
          <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
              <Icon name="classes" :size="18" class="text-orange-500" />
              <h3 id="upcoming-title" class="font-bold text-slate-900">Próximas clases</h3>
            </div>
            <Link :href="route('teacher.lessons')" class="text-sm text-blue-600 font-semibold hover:text-blue-700 transition-colors flex items-center gap-1">
              Ver todas <Icon name="arrow-right" :size="14" />
            </Link>
          </div>

          <!-- Con clases -->
          <template v-if="upcoming.length">
            <!-- Esta semana -->
            <div v-if="upcomingThisWeek.length" class="px-5 sm:px-6 pt-4 pb-1">
              <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                <Icon name="classes" :size="12" /> Esta semana
              </p>
            </div>
            <div v-if="upcomingThisWeek.length" class="divide-y divide-gray-50">
              <div v-for="l in upcomingThisWeek" :key="l.id"
                class="px-5 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/60 transition-colors">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-9 h-9 bg-orange-50 rounded-xl flex items-center justify-center flex-shrink-0 text-orange-700 font-bold text-sm">
                    {{ l.student?.first_name?.charAt(0) ?? '?' }}
                  </div>
                  <div class="min-w-0">
                    <p class="font-semibold text-slate-900 truncate text-sm">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                    <p class="text-xs text-slate-400 mt-0.5">{{ l.student?.first_name }} {{ l.student?.last_name }} · {{ fmtDate(l.start_time) }}</p>
                  </div>
                </div>
                <Link v-if="l.status === 'paid'" :href="route('lesson-reports.create', l.id)"
                  class="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 bg-orange-500 text-white text-xs font-bold rounded-xl hover:bg-orange-600 transition-colors shadow-sm self-start sm:self-auto">
                  <Icon name="my-reports" :size="13" /> Escribir reporte
                </Link>
              </div>
            </div>
            <p v-else class="px-5 sm:px-6 py-3 text-sm text-slate-400">No tienes clases esta semana.</p>

            <!-- Pasadas -->
            <template v-if="upcomingPast.length">
              <div class="px-5 sm:px-6 pt-4 pb-1 border-t border-gray-50">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                  <Icon name="topic" :size="12" /> Pasadas
                </p>
              </div>
              <div class="divide-y divide-gray-50">
                <div v-for="l in upcomingPast" :key="l.id"
                  class="px-5 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/60 transition-colors">
                  <div class="flex items-center gap-3 min-w-0">
                    <div class="w-9 h-9 bg-slate-100 rounded-xl flex items-center justify-center flex-shrink-0 text-slate-500 font-bold text-sm">
                      {{ l.student?.first_name?.charAt(0) ?? '?' }}
                    </div>
                    <div class="min-w-0">
                      <p class="font-semibold text-slate-700 truncate text-sm">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                      <p class="text-xs text-slate-400 mt-0.5">{{ l.student?.first_name }} {{ l.student?.last_name }} · {{ fmtDate(l.start_time) }}</p>
                    </div>
                  </div>
                  <Link v-if="l.status === 'paid'" :href="route('lesson-reports.create', l.id)"
                    class="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-200 text-slate-700 text-xs font-bold rounded-xl hover:bg-slate-300 transition-colors self-start sm:self-auto">
                    <Icon name="my-reports" :size="13" /> Escribir reporte
                  </Link>
                </div>
              </div>
            </template>
          </template>

          <!-- Empty state -->
          <div v-else class="px-6 py-12 text-center">
            <img src="/images/brand/dashboard-empty-calendar.png" alt="Calendario"
              class="w-24 h-24 sm:w-28 sm:h-28 object-contain mx-auto mb-3 drop-shadow-sm select-none" />
            <p class="font-bold text-slate-900 text-sm sm:text-base">No tienes clases próximas</p>
            <p class="text-xs sm:text-sm text-slate-400 mt-1 mb-5 leading-relaxed">Cuando tengas clases agendadas, aparecerán aquí.</p>
            <Link :href="route('teacher.requests')"
              class="inline-flex items-center gap-2 px-5 py-2.5 bg-white text-orange-600 text-sm font-bold rounded-xl hover:bg-orange-50/80 transition-colors border border-orange-200 shadow-sm">
              <Icon name="requests" :size="15" /> Explorar solicitudes
            </Link>
          </div>
        </section>

        <!-- Columna derecha: Perfil + Acciones rápidas -->
        <div class="lg:col-span-2 space-y-5">

          <!-- Progreso del perfil -->
          <section v-if="profile_score < 100" class="bg-white rounded-2xl border border-gray-100 overflow-hidden" aria-labelledby="profile-title">
            <div class="px-5 py-4 border-b border-gray-100">
              <div class="flex items-center gap-2.5 mb-1">
                <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                  <Icon name="profile" :size="18" />
                </div>
                <h3 id="profile-title" class="font-bold text-slate-900 text-sm sm:text-base">Tu perfil está {{ profile_score }}% completo</h3>
              </div>
              <p class="text-xs text-slate-500 mt-1">Completa tu perfil para ganar más visibilidad y recibir más solicitudes.</p>
              <!-- Progress bar con porcentaje al lado -->
              <div class="mt-3.5 flex items-center gap-3">
                <div class="flex-1 h-2.5 bg-gray-100 rounded-full overflow-hidden" role="progressbar" :aria-valuenow="profile_score" aria-valuemin="0" aria-valuemax="100">
                  <div class="h-full rounded-full transition-all duration-700"
                    :style="{ width: profile_score + '%' }"
                    :class="profile_score >= 70 ? 'bg-emerald-500' : profile_score >= 40 ? 'bg-amber-400' : 'bg-red-400'">
                  </div>
                </div>
                <span class="text-xs font-bold text-emerald-600 flex-shrink-0">{{ profile_score }}%</span>
              </div>
            </div>
            <!-- Checklist en 2 columnas -->
            <div class="px-5 py-4 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
              <div v-for="(done, key) in profile_checklist" :key="key" class="flex items-center gap-2">
                <div class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0"
                  :class="done ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400'">
                  <Icon v-if="done" name="check" :size="10" :stroke-width="3" />
                  <span v-else class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                </div>
                <span class="text-xs" :class="done ? 'text-slate-700' : 'text-slate-400'">{{ checklistLabel(key) }}</span>
              </div>
            </div>
            <div class="p-3 border-t border-gray-50">
              <Link :href="route('teacher.profile')"
                class="w-full py-2.5 px-4 bg-orange-50/80 hover:bg-orange-100 text-orange-700 font-bold text-xs sm:text-sm rounded-xl flex items-center justify-center gap-1.5 transition-colors">
                Completar perfil <Icon name="arrow-right" :size="14" />
              </Link>
            </div>
          </section>

          <!-- Acciones rápidas -->
          <section aria-labelledby="actions-title">
            <h3 id="actions-title" class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-3 px-0.5">Acciones rápidas</h3>
            <div class="space-y-2.5">
              <Link :href="route('teacher.requests')"
                class="group flex items-center gap-3.5 bg-white border border-gray-100 rounded-2xl px-4 py-3.5 hover:border-orange-200 hover:shadow-sm transition-all duration-150">
                <div class="w-9 h-9 bg-orange-50 group-hover:bg-orange-100 rounded-xl flex items-center justify-center text-orange-600 flex-shrink-0 transition-colors">
                  <Icon name="requests" :size="18" />
                </div>
                <div class="min-w-0 flex-1">
                  <p class="font-semibold text-slate-900 text-sm">Solicitudes</p>
                  <p class="text-xs text-slate-400">{{ pending_requests }} abiertas</p>
                </div>
                <Icon name="arrow-right" :size="16" class="text-slate-300 group-hover:text-orange-500 flex-shrink-0 transition-colors" />
              </Link>

              <Link v-if="hasOffers" :href="route('class-offers.index')"
                class="group flex items-center gap-3.5 bg-white border border-gray-100 rounded-2xl px-4 py-3.5 hover:border-brand-200 hover:shadow-sm transition-all duration-150">
                <div class="w-9 h-9 bg-brand-50 group-hover:bg-brand-100 rounded-xl flex items-center justify-center text-brand-600 flex-shrink-0 transition-colors">
                  <Icon name="past-offers" :size="18" />
                </div>
                <div class="min-w-0 flex-1">
                  <p class="font-semibold text-slate-900 text-sm">Mis ofertas anteriores</p>
                  <p class="text-xs text-slate-400">Gestiona tarifa y cupos</p>
                </div>
                <Icon name="arrow-right" :size="16" class="text-slate-300 group-hover:text-brand-500 flex-shrink-0 transition-colors" />
              </Link>

              <Link :href="route('teacher.credits.index')"
                class="group flex items-center gap-3.5 bg-white border border-gray-100 rounded-2xl px-4 py-3.5 hover:border-emerald-200 hover:shadow-sm transition-all duration-150">
                <div class="w-9 h-9 bg-emerald-50 group-hover:bg-emerald-100 rounded-xl flex items-center justify-center text-emerald-600 flex-shrink-0 transition-colors">
                  <Icon name="credits" :size="18" />
                </div>
                <div class="min-w-0 flex-1">
                  <p class="font-semibold text-slate-900 text-sm">Recargar créditos</p>
                  <p class="text-xs text-slate-400">Yape / Plin</p>
                </div>
                <Icon name="arrow-right" :size="16" class="text-slate-300 group-hover:text-emerald-500 flex-shrink-0 transition-colors" />
              </Link>

              <Link :href="route('teacher.profile')"
                class="group flex items-center gap-3.5 bg-white border border-gray-100 rounded-2xl px-4 py-3.5 hover:border-purple-200 hover:shadow-sm transition-all duration-150">
                <div class="w-9 h-9 bg-purple-50 group-hover:bg-purple-100 rounded-xl flex items-center justify-center text-purple-600 flex-shrink-0 transition-colors">
                  <Icon name="profile" :size="18" />
                </div>
                <div class="min-w-0 flex-1">
                  <p class="font-semibold text-slate-900 text-sm">Mi perfil</p>
                  <p class="text-xs text-slate-400">Actualiza tu información</p>
                </div>
                <Icon name="arrow-right" :size="16" class="text-slate-300 group-hover:text-purple-500 flex-shrink-0 transition-colors" />
              </Link>
            </div>
          </section>

        </div><!-- /col derecha -->
      </div><!-- /grid 2 columnas -->

    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icon from '@/Components/Icon.vue'
import { splitByWeek } from '@/utils/weekGrouping'

const props = defineProps({
  upcoming: { type: Array, default: () => [] },
  pending_requests: Number,
  pending_reports: Number,
  profile_score: { type: Number, default: 0 },
  profile_checklist: { type: Object, default: () => ({}) },
  has_offers: { type: Boolean, default: false },
})

const hasOffers = computed(() => props.has_offers)

const user      = computed(() => usePage().props.auth?.user)
const firstName = computed(() => user.value?.name?.split(' ')[0] ?? '')
const today     = computed(() => new Date().toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }))

// `upcoming` ya viene ordenado start_time asc desde DashboardController
const upcomingGrouped  = computed(() => splitByWeek(props.upcoming))
const upcomingThisWeek = computed(() => upcomingGrouped.value.thisWeek)
const upcomingPast     = computed(() => upcomingGrouped.value.past)

const postClassLessonId = ref(null)
const postClassEnded = ref(true)
const showPhoneBanner = ref(true)

onMounted(() => {
  // Llega aquí justo después de cerrar el modal de Jitsi (ver useJitsiMeet →
  // closeJitsi), que redirige con ?post_class=<id>&post_class_ends_at=<iso>.
  // Se limpia de la URL para que un refresh no vuelva a mostrar el banner.
  const params = new URLSearchParams(window.location.search)
  const postClass = params.get('post_class')
  if (postClass) {
    postClassLessonId.value = postClass
    const endsAt = params.get('post_class_ends_at')
    postClassEnded.value = endsAt ? Date.now() >= new Date(endsAt).getTime() : true
    window.history.replaceState({}, '', window.location.pathname)
  }
})

// PRODUCT AUDIT (hallazgo real, no solo visual): este checklist todavía
// pide "Al menos una oferta activa" como requisito de perfil completo, pero
// el flujo de creación de ofertas ya no existe para profesores nuevos (ver
// el comentario de "Mis ofertas anteriores" más abajo — el profesor acepta
// solicitudes abiertas, no publica anuncios). Si `active_offer` sigue
// viniendo del backend como parte de `profile_checklist`, un profesor nuevo
// puede quedar atascado en <100% sin ninguna acción visible para resolverlo.
// No se toca la lógica de backend en este pase (fuera del alcance de una
// auditoría de diseño) — se deja marcado aquí y en
// docs/MOVA_DESIGN_AUDIT_FINAL.md para que se decida explícitamente.
const checklistLabels = {
  bio:            'Biografía completa',
  subjects:       'Materias asignadas',
  active_offer:   'Al menos una oferta activa',
  phone_verified: 'Teléfono verificado',
  email_verified: 'Email verificado',
  is_verified:    'Verificado por el equipo MOVA',
}
function checklistLabel(key) { return checklistLabels[key] ?? key }

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}
</script>
