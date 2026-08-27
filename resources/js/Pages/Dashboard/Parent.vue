<template>
  <AppLayout title="Mi panel">
    <div class="space-y-6 sm:space-y-8">

      <!-- Welcome banner -->
      <div class="bg-gradient-to-r from-brand-800 to-brand-600 rounded-2xl p-5 sm:p-6 text-white shadow-lg shadow-brand-800/20 flex items-center justify-between">
        <div>
          <p class="text-white/70 text-sm font-medium">Bienvenido/a de vuelta</p>
          <h2 class="text-xl sm:text-2xl font-black mt-0.5">{{ user?.name?.split(' ')[0] }}</h2>
          <p class="text-white/60 text-sm mt-1">{{ today }}</p>
        </div>
        <Icon name="role-parent" :size="56" :stroke-width="1.25" class="hidden sm:block opacity-30 flex-shrink-0" />
      </div>

      <!-- Banner post-clase: recién salió de la videollamada -->
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

      <!-- Empty state: sin hijos registrados -->
      <div v-if="!students.length" class="reveal-group">
        <div class="reveal-item bg-white rounded-2xl border border-gray-100 px-6 py-16 sm:py-20 text-center max-w-xl mx-auto">
          <div class="w-16 h-16 bg-brand-50 rounded-2xl flex items-center justify-center text-brand-600 mx-auto mb-5">
            <Icon name="my-students" :size="32" :stroke-width="1.5" />
          </div>
          <h3 class="text-xl font-black text-slate-900">Registra a tu primer hijo/a</h3>
          <p class="text-slate-500 mt-2 leading-relaxed">
            Para solicitar clases, seguir su progreso y calificar profesores, primero necesitamos
            saber para quién estás buscando tutorías.
          </p>
          <Link :href="route('students.create')"
            class="inline-flex items-center gap-2 mt-6 px-6 py-3 bg-brand-600 text-white font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-lg shadow-brand-600/20 hover:shadow-brand-600/30">
            + Añadir hijo/a
          </Link>
        </div>
      </div>

      <template v-else>

        <!-- Pending approval alert -->
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

        <!-- Metric cards — Spatial UI: superficie elevada, badge de icono, profundidad sutil -->
        <div class="reveal-group grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 p-4 sm:p-5 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300">
            <div class="w-10 h-10 bg-brand-50 rounded-xl flex items-center justify-center text-brand-600 mb-3 group-hover:bg-brand-100 transition-colors">
              <Icon name="requests" :size="20" />
            </div>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums">{{ stats.class_requests_total }}</p>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Clases solicitadas</p>
          </div>
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 p-4 sm:p-5 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300">
            <div class="w-10 h-10 bg-green-50 rounded-xl flex items-center justify-center text-green-600 mb-3 group-hover:bg-green-100 transition-colors">
              <Icon name="flash-success" :size="20" />
            </div>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums">{{ stats.classes_completed }}</p>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Clases completadas</p>
          </div>
          <!-- sky, no indigo: cuarto color de la fila de métricas, distinto del
               violeta ya reservado para el estado "pagada" (utils/statusColors.js)
               para no mezclar el color de una métrica con el de un estado. -->
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 p-4 sm:p-5 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300">
            <div class="w-10 h-10 bg-sky-50 rounded-xl flex items-center justify-center text-sky-600 mb-3 group-hover:bg-sky-100 transition-colors">
              <Icon name="classes" :size="20" />
            </div>
            <template v-if="next_lesson">
              <p class="text-sm sm:text-base font-black text-slate-900 leading-snug line-clamp-1">{{ next_lesson.class_request?.subject?.name ?? 'Clase' }}</p>
              <p class="text-xs sm:text-sm text-slate-500 mt-0.5">{{ fmtDateShort(next_lesson.start_time) }}</p>
            </template>
            <template v-else>
              <p class="text-2xl sm:text-3xl font-black text-slate-300">—</p>
              <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Próxima clase</p>
            </template>
          </div>
          <div class="reveal-item group bg-white rounded-2xl border border-gray-100 p-4 sm:p-5 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300">
            <div class="w-10 h-10 bg-amber-50 rounded-xl flex items-center justify-center text-amber-600 mb-3 group-hover:bg-amber-100 transition-colors">
              <Icon name="reviews" :size="20" />
            </div>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums">{{ stats.avg_teacher_rating ?? '—' }}</p>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Calificación promedio</p>
          </div>
        </div>

        <!-- Diagnostic CTA — antes era un gradiente indigo-50→brand-50: ni el
             indigo era de marca, ni un gradiente decorativo encaja con el
             principio de PRODUCT.md de evitarlos. Tarjeta plana, tono brand
             único, igual que el resto de tarjetas de esta página. -->
        <div class="bg-brand-50 border border-brand-100 rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center gap-4">
          <div class="text-brand-600 flex-shrink-0">
            <Icon name="target" :size="28" />
          </div>
          <div class="flex-1">
            <p class="font-bold text-slate-900">¿No sabes qué profesor elegir?</p>
            <p class="text-sm text-slate-500 mt-0.5">Responde 5 preguntas y MOVA te recomienda profesores ideales para tu hijo</p>
          </div>
          <Link :href="route('diagnostics.create')"
            class="flex-shrink-0 px-5 py-2.5 bg-brand-600 text-white font-bold rounded-xl text-sm hover:bg-brand-700 transition-colors shadow-sm self-start sm:self-auto">
            Hacer diagnóstico →
          </Link>
        </div>

        <!-- Próximas clases — timeline -->
        <div>
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-base font-bold text-slate-900">Próximas clases</h3>
            <Link :href="route('parent.lessons')" class="text-sm text-brand-600 font-medium hover:underline">Ver todas →</Link>
          </div>

          <div v-if="upcoming.length" class="reveal-group bg-white rounded-2xl border border-gray-100 p-5 sm:p-6 space-y-6">
            <div>
              <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                <Icon name="classes" :size="14" /> Esta semana
              </p>
              <ol v-if="upcomingThisWeek.length" class="relative">
                <li v-for="(l, i) in upcomingThisWeek" :key="l.id"
                  class="reveal-item relative pl-9 pb-6 last:pb-0">
                  <!-- Connecting line -->
                  <span v-if="i < upcomingThisWeek.length - 1" class="absolute left-[7px] top-4 bottom-0 w-px bg-gray-100"></span>
                  <!-- Status dot -->
                  <span class="absolute left-0 top-1 w-4 h-4 rounded-full border-2 border-white shadow-sm ring-1 ring-gray-100"
                    :class="dotColor(l.status)"></span>

                  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <p class="font-bold text-slate-900">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                        <StatusBadge :status="l.status" />
                      </div>
                      <p class="text-sm text-slate-500 mt-0.5">
                        {{ l.student?.first_name }} · Prof. {{ l.teacher_profile?.user?.name }}
                      </p>
                      <p class="text-xs text-slate-400 mt-0.5 flex items-center gap-1">
                        <Icon name="classes" :size="12" /> {{ fmtDate(l.start_time) }}
                      </p>
                    </div>

                    <div class="flex-shrink-0">
                      <button v-if="l.status === 'scheduled' && hasClassEnded(l)" @click="confirmPayment(l)" :disabled="payingId === l.id"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-sm font-bold rounded-xl hover:bg-emerald-700 active:scale-95 transition-all shadow-sm disabled:opacity-50">
                        <Icon v-if="payingId !== l.id" name="check" :size="16" />
                        {{ payingId === l.id ? 'Confirmando...' : 'Ya pagué' }}
                      </button>
                      <p v-else-if="l.status === 'scheduled'" class="text-xs text-slate-400 text-right max-w-[10rem]">Podrás confirmar el pago cuando la clase finalice</p>
                      <button v-else-if="l.status === 'paid' && canJoinJitsi(l)" @click="openJitsi(l)"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-sm shadow-brand-600/25">
                        <Icon name="join-room" :size="16" /> Unirse a la sala
                      </button>
                      <!-- F-11: rama inalcanzable — canJoinJitsi() devuelve true para todo lo que esté en 'paid', así que este texto no llega a mostrarse. Se conserva como red por si la regla de acceso cambia. -->
                      <p v-else-if="l.status === 'paid'" class="text-xs text-slate-400 text-right max-w-[10rem]">La sala de esta clase ya no está disponible.</p>
                      <p v-if="paymentError && payingId === null" class="mt-1 text-xs font-semibold text-red-600 text-right">{{ paymentError }}</p>
                      <Link v-else-if="l.status === 'pending_parent_confirmation'" :href="route('reviews.create', l.id)"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-500 text-white text-sm font-bold rounded-xl hover:bg-yellow-600 active:scale-95 transition-all shadow-sm">
                        <Icon name="reviews" :size="16" /> Calificar
                      </Link>
                    </div>
                  </div>
                </li>
              </ol>
              <p v-else class="text-sm text-slate-400">No tienes clases esta semana.</p>
            </div>

            <div v-if="upcomingPast.length" class="pt-5 border-t border-gray-50">
              <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-1.5">
                <Icon name="topic" :size="14" /> Pasadas
              </p>
              <ol class="relative">
                <li v-for="(l, i) in upcomingPast" :key="l.id"
                  class="reveal-item relative pl-9 pb-6 last:pb-0">
                  <span v-if="i < upcomingPast.length - 1" class="absolute left-[7px] top-4 bottom-0 w-px bg-gray-100"></span>
                  <span class="absolute left-0 top-1 w-4 h-4 rounded-full border-2 border-white shadow-sm ring-1 ring-gray-100"
                    :class="dotColor(l.status)"></span>

                  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <p class="font-bold text-slate-900">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                        <StatusBadge :status="l.status" />
                      </div>
                      <p class="text-sm text-slate-500 mt-0.5">
                        {{ l.student?.first_name }} · Prof. {{ l.teacher_profile?.user?.name }}
                      </p>
                      <p class="text-xs text-slate-400 mt-0.5 flex items-center gap-1">
                        <Icon name="classes" :size="12" /> {{ fmtDate(l.start_time) }}
                      </p>
                    </div>

                    <div class="flex-shrink-0">
                      <button v-if="l.status === 'scheduled' && hasClassEnded(l)" @click="confirmPayment(l)" :disabled="payingId === l.id"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-sm font-bold rounded-xl hover:bg-emerald-700 active:scale-95 transition-all shadow-sm disabled:opacity-50">
                        <Icon v-if="payingId !== l.id" name="check" :size="16" />
                        {{ payingId === l.id ? 'Confirmando...' : 'Ya pagué' }}
                      </button>
                      <button v-else-if="l.status === 'paid' && canJoinJitsi(l)" @click="openJitsi(l)"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-sm shadow-brand-600/25">
                        <Icon name="join-room" :size="16" /> Unirse a la sala
                      </button>
                      <Link v-else-if="l.status === 'pending_parent_confirmation'" :href="route('reviews.create', l.id)"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-500 text-white text-sm font-bold rounded-xl hover:bg-yellow-600 active:scale-95 transition-all shadow-sm">
                        <Icon name="reviews" :size="16" /> Calificar
                      </Link>
                    </div>
                  </div>
                </li>
              </ol>
            </div>
          </div>

          <div v-else class="bg-white rounded-2xl border border-gray-100 px-6 py-10 text-center text-slate-400">
            <div class="mb-2 flex justify-center">
              <Icon name="no-classes" :size="32" :stroke-width="1.5" />
            </div>
            <p class="text-sm">No hay clases próximas</p>
            <Link :href="route('marketplace')" class="inline-block mt-3 text-sm text-brand-600 hover:underline font-medium">
              Buscar un profesor →
            </Link>
          </div>
        </div>

        <!-- Historial reciente -->
        <div v-if="recent_history.length">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-base font-bold text-slate-900">Historial reciente</h3>
            <Link :href="route('parent.lessons')" class="text-sm text-brand-600 font-medium hover:underline">Ver todo →</Link>
          </div>
          <div class="reveal-group grid gap-3 sm:grid-cols-2">
            <div v-for="l in recent_history" :key="l.id"
              class="reveal-item bg-white rounded-2xl border border-gray-100 p-4 hover:shadow-md transition-shadow">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="font-semibold text-slate-900 truncate">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                  <p class="text-xs text-slate-400 mt-0.5">{{ l.student?.first_name }} · Prof. {{ l.teacher_profile?.user?.name }}</p>
                  <p class="text-xs text-slate-400">{{ fmtDateShort(l.start_time) }}</p>
                </div>
                <div v-if="l.teacher_review" class="flex-shrink-0 flex items-center gap-0.5">
                  <Icon v-for="n in 5" :key="n" name="reviews" :size="14"
                    :class="n <= l.teacher_review.rating ? 'text-amber-400' : 'text-gray-200'"
                    :fill="n <= l.teacher_review.rating ? 'currentColor' : 'none'" />
                </div>
                <p v-else class="flex-shrink-0 text-xs text-slate-300 italic">Sin calificar</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Last learning report -->
        <div v-if="last_report" class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
          <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-slate-900">Último reporte de aprendizaje</h3>
            <Link :href="route('parent.reports')" class="text-sm text-brand-600 font-medium hover:underline">Ver todos →</Link>
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

        <!-- Students list -->
        <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
          <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-slate-900">Mis hijos</h3>
            <Link :href="route('students.index')" class="text-sm text-brand-600 font-medium hover:underline">Ver todos →</Link>
          </div>
          <div class="divide-y divide-gray-50">
            <div v-for="s in students" :key="s.id" class="px-5 sm:px-6 py-3.5 flex items-center gap-3 hover:bg-slate-50 transition-colors">
              <div class="w-9 h-9 bg-gradient-to-br from-green-400 to-teal-500 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                {{ s.first_name?.charAt(0) }}
              </div>
              <div>
                <p class="text-sm font-semibold text-slate-900">{{ s.first_name }} {{ s.last_name }}</p>
                <p class="text-xs text-slate-400 capitalize">{{ s.grade_level ?? 'Sin nivel asignado' }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick actions -->
        <div>
          <h3 class="text-base font-bold text-slate-900 mb-3">Acciones rápidas</h3>
          <div class="reveal-group grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Link :href="route('class-requests.create')"
              class="reveal-item group bg-brand-600 rounded-2xl p-5 hover:bg-brand-700 transition-all shadow-lg shadow-brand-600/25">
              <Icon name="new-request" :size="28" class="text-white/90 mb-3" />
              <p class="font-bold text-white">Solicitar una clase</p>
              <p class="text-sm text-brand-200 mt-0.5">Sin elegir profesor — te contactará el primero disponible</p>
            </Link>
            <Link :href="route('students.index')"
              class="reveal-item group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
              <Icon name="my-students" :size="28" class="text-brand-600 mb-3" />
              <p class="font-bold text-slate-900">Gestionar hijos</p>
              <p class="text-sm text-slate-400 mt-0.5">Añade o edita sus datos</p>
            </Link>
            <Link :href="route('parent.reports')"
              class="reveal-item group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
              <Icon name="requests" :size="28" class="text-brand-600 mb-3" />
              <p class="font-bold text-slate-900">Reportes</p>
              <p class="text-sm text-slate-400 mt-0.5">Historial de aprendizaje</p>
            </Link>
          </div>
        </div>

      </template>
    </div>

    <!-- Modal Sala Virtual (Jitsi) -->
    <JitsiModal :show="showingJitsiModal" :lesson="activeLesson" :error="joinError" @close="closeJitsi" />
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import JitsiModal from '@/Components/JitsiModal.vue'
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

const user  = computed(() => usePage().props.auth?.user)
const today = computed(() => new Date().toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }))

const payingId = ref(null)
const paymentError = ref('')
const postClassLessonId = ref(null)
const postClassEnded = ref(true)
const { showingJitsiModal, joinError, activeLesson, openJitsi, closeJitsi } = useJitsiMeet()

// `upcoming` ya viene ordenado start_time asc (próxima primero) desde
// DashboardController — ambos baldes conservan ese orden tal cual.
const upcomingGrouped  = computed(() => splitByWeek(props.upcoming))
const upcomingThisWeek = computed(() => upcomingGrouped.value.thisWeek)
const upcomingPast     = computed(() => upcomingGrouped.value.past)

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function fmtDateShort(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

// Deduplicado: antes era un cuarto mapeo de color independiente (junto a
// utils/statusColors.js, TeacherLessonCard.vue y ParentLessonCard.vue, cada
// uno con su propia copia). Ahora los cuatro leen del mismo `statusStyle()`.
function dotColor(status) {
  return statusStyle(status).dot
}

// F-06: era una CUARTA copia divergente de la misma regla (las otras tres ya
// se habían unificado en utils/lessonJoin.js). Además tenía el mismo hueco
// que la versión compartida anterior —sin límite inferior— y restringía a
// 'paid' cuando el backend permite más estados. Se usa la función compartida.

function hasClassEnded(l) {
  return Date.now() >= new Date(l.end_time).getTime()
}

// F-19: esta acción no tenía onError, aunque la MISMA acción en
// Lessons/ParentIndex.vue sí lo tiene. Un 422 (por ejemplo, la clase todavía
// no ha terminado) dejaba al padre pulsando un botón que no hacía nada.
function confirmPayment(l) {
  if (payingId.value) return
  payingId.value = l.id
  paymentError.value = ''
  router.post(route('lessons.confirm-payment', l.id), {}, {
    preserveScroll: true,
    onError: () => { paymentError.value = 'No se pudo confirmar el pago. Recarga la página e inténtalo de nuevo.' },
    onFinish: () => { payingId.value = null },
  })
}

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

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (prefersReducedMotion) return

  // A diferencia de Welcome.vue (landing, scroll largo, ScrollTrigger tiene
  // sentido), el dashboard es una pantalla de una sola vista donde el padre
  // necesita ver acciones pendientes (pagar/calificar) de inmediato. Gatear
  // la opacidad detrás de un ScrollTrigger deja el contenido bajo el pliegue
  // en opacity:0 hasta que alguien haga scroll — inaceptable para un botón
  // de "pagar clase". Se anima todo al montar, en cascada por sección.
  //
  // Bug real reproducido y diagnosticado en navegador: si la pestaña queda
  // en segundo plano justo cuando esto corre (`document.hidden`), Chrome
  // limita requestAnimationFrame a ~1fps — GSAP no se "traba", simplemente
  // avanza ~50x más lento, y una animación de <1s puede tardar 30s+ en
  // terminar. Mientras tanto el contenido queda en opacity:0 — exactamente
  // "aparece y desaparece" que reportaron ("Acciones rápidas" era el caso
  // observado). clearProps ya evita que quede a medio camino cuando SÍ
  // termina; el setTimeout de abajo es la red de seguridad para cuando no
  // termina a tiempo: fuerza opacity:1 igual, sin esperar a que el tween
  // avance — nunca se queda invisible más de lo que tarda este timeout.
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
