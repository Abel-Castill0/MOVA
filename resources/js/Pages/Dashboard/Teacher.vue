<template>
  <AppLayout title="Mi panel de profesor">
    <div class="space-y-5 sm:space-y-6">

      <!-- Welcome banner -->
      <div class="bg-gradient-to-r from-brand-800 to-brand-600 rounded-2xl p-5 sm:p-6 text-white shadow-lg shadow-brand-800/20 flex items-center justify-between">
        <div>
          <p class="text-white/70 text-sm font-medium">Hola, {{ user?.name?.split(' ')[0] }}</p>
          <h2 class="text-xl sm:text-2xl font-black mt-0.5">Tu panel de clases</h2>
          <p class="text-white/60 text-sm mt-1">{{ today }}</p>
        </div>
        <div class="text-5xl sm:text-6xl hidden sm:block opacity-80">👨‍🏫</div>
      </div>

      <!-- Banner post-clase: recién salió de la videollamada -->
      <div v-if="postClassLessonId && postClassEnded" class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="w-10 h-10 bg-indigo-100 rounded-xl flex items-center justify-center text-xl flex-shrink-0">📝</div>
        <div class="flex-1">
          <p class="font-semibold text-indigo-900">La clase ha terminado.</p>
          <p class="text-sm text-indigo-700 mt-0.5">Escribe el reporte pedagógico.</p>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 self-start sm:self-auto">
          <Link :href="route('lesson-reports.create', postClassLessonId)"
            class="px-4 py-2 bg-indigo-600 text-white text-sm font-bold rounded-xl hover:bg-indigo-700 transition-colors">
            Escribir reporte
          </Link>
          <button @click="postClassLessonId = null" type="button" aria-label="Cerrar aviso"
            class="px-2 py-2 text-indigo-500 hover:text-indigo-700 transition-colors">✕</button>
        </div>
      </div>
      <div v-else-if="postClassLessonId" class="bg-slate-50 border border-slate-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-xl flex-shrink-0">🕐</div>
        <div class="flex-1">
          <p class="font-semibold text-slate-800">La clase está en curso.</p>
          <p class="text-sm text-slate-500 mt-0.5">Las acciones (pago/reporte) estarán disponibles cuando finalice el horario programado.</p>
        </div>
        <button @click="postClassLessonId = null" type="button" aria-label="Cerrar aviso"
          class="flex-shrink-0 self-start sm:self-auto px-2 py-2 text-slate-400 hover:text-slate-600 transition-colors">✕</button>
      </div>

      <!-- Phone verification incentive banner -->
      <div v-if="!user?.phone_verified" class="bg-blue-50 border border-blue-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center text-xl flex-shrink-0">🎁</div>
        <div class="flex-1">
          <p class="font-semibold text-blue-900">¡Verifica tu número de celular para desbloquear tus 5 créditos gratis y empezar a aceptar solicitudes de clases!</p>
        </div>
        <Link :href="route('phone.verification.notice')"
          class="flex-shrink-0 px-4 py-2 bg-blue-600 text-white text-sm font-bold rounded-xl hover:bg-blue-700 transition-colors self-start sm:self-auto">
          Verificar celular
        </Link>
      </div>

      <!-- Stats — 1 col mobile, 3 desktop -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
          <div class="text-2xl mb-3">📅</div>
          <p class="text-3xl font-black text-brand-600">{{ upcoming.length }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Clases próximas</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
          <div class="text-2xl mb-3">📬</div>
          <p class="text-3xl font-black text-orange-500">{{ pending_requests }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Solicitudes abiertas</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 p-5 hover:shadow-md transition-shadow">
          <div class="text-2xl mb-3">📝</div>
          <p class="text-3xl font-black" :class="pending_reports > 0 ? 'text-red-500' : 'text-green-600'">{{ pending_reports }}</p>
          <p class="text-sm text-slate-500 mt-0.5">Reportes pendientes</p>
        </div>
      </div>

      <!-- Pending reports alert -->
      <div v-if="pending_reports > 0" class="bg-red-50 border border-red-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center text-xl flex-shrink-0">📝</div>
        <div class="flex-1">
          <p class="font-semibold text-red-900">{{ pending_reports }} clase(s) completada(s) sin reporte</p>
          <p class="text-sm text-red-600 mt-0.5">Los padres esperan el reporte de aprendizaje de sus hijos.</p>
        </div>
        <Link :href="route('teacher.lessons')"
          class="flex-shrink-0 px-4 py-2 bg-red-500 text-white text-sm font-bold rounded-xl hover:bg-red-600 transition-colors self-start sm:self-auto">
          Completar reportes
        </Link>
      </div>

      <!-- Upcoming classes -->
      <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <h3 class="font-bold text-slate-900">Próximas clases</h3>
          <Link :href="route('teacher.lessons')" class="text-sm text-brand-600 font-medium hover:underline">Ver todas →</Link>
        </div>
        <template v-if="upcoming.length">
          <div class="px-5 sm:px-6 pt-4">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wide">📅 Esta semana</p>
          </div>
          <div v-if="upcomingThisWeek.length" class="divide-y divide-gray-50">
            <div v-for="l in upcomingThisWeek" :key="l.id" class="px-5 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div class="flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 bg-brand-50 rounded-xl flex items-center justify-center flex-shrink-0 text-brand-600 font-bold text-sm">
                  {{ l.student?.first_name?.charAt(0) }}
                </div>
                <div class="min-w-0">
                  <p class="font-semibold text-slate-900 truncate">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                  <p class="text-xs text-slate-400">{{ l.student?.first_name }} {{ l.student?.last_name }} · {{ fmtDate(l.start_time) }}</p>
                </div>
              </div>
              <Link v-if="l.status === 'paid'" :href="route('lesson-reports.create', l.id)"
                class="flex-shrink-0 inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 text-white text-xs font-bold rounded-xl hover:bg-brand-700 transition-colors shadow-sm self-start sm:self-auto">
                📝 Escribir reporte
              </Link>
            </div>
          </div>
          <p v-else class="px-5 sm:px-6 pb-4 pt-2 text-sm text-slate-400">No tienes clases esta semana.</p>

          <template v-if="upcomingPast.length">
            <div class="px-5 sm:px-6 pt-4 border-t border-gray-50">
              <p class="text-xs font-bold text-slate-400 uppercase tracking-wide">📚 Pasadas</p>
            </div>
            <div class="divide-y divide-gray-50">
              <div v-for="l in upcomingPast" :key="l.id" class="px-5 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-10 h-10 bg-brand-50 rounded-xl flex items-center justify-center flex-shrink-0 text-brand-600 font-bold text-sm">
                    {{ l.student?.first_name?.charAt(0) }}
                  </div>
                  <div class="min-w-0">
                    <p class="font-semibold text-slate-900 truncate">{{ l.class_request?.subject?.name ?? 'Clase' }}</p>
                    <p class="text-xs text-slate-400">{{ l.student?.first_name }} {{ l.student?.last_name }} · {{ fmtDate(l.start_time) }}</p>
                  </div>
                </div>
                <Link v-if="l.status === 'paid'" :href="route('lesson-reports.create', l.id)"
                  class="flex-shrink-0 inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 text-white text-xs font-bold rounded-xl hover:bg-brand-700 transition-colors shadow-sm self-start sm:self-auto">
                  📝 Escribir reporte
                </Link>
              </div>
            </div>
          </template>
        </template>
        <div v-else class="px-6 py-10 text-center text-slate-400">
          <div class="text-4xl mb-2">📭</div>
          <p class="text-sm">No tienes clases próximas</p>
        </div>
      </div>

      <!-- Profile completeness checklist -->
      <div v-if="profile_score < 100" class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <div class="flex-1">
            <h3 class="font-bold text-slate-900">Tu perfil está {{ profile_score }}% completo</h3>
            <div class="mt-2 h-2 bg-gray-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full transition-all duration-500"
                :style="{ width: profile_score + '%' }"
                :class="profile_score >= 70 ? 'bg-green-500' : profile_score >= 40 ? 'bg-yellow-400' : 'bg-red-400'">
              </div>
            </div>
          </div>
        </div>
        <div class="px-5 sm:px-6 py-4 grid grid-cols-1 sm:grid-cols-2 gap-2">
          <div v-for="(done, key) in profile_checklist" :key="key" class="flex items-center gap-2.5">
            <div class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold"
              :class="done ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-400'">
              {{ done ? '✓' : '○' }}
            </div>
            <span class="text-sm" :class="done ? 'text-slate-700' : 'text-slate-400'">{{ checklistLabel(key) }}</span>
          </div>
        </div>
        <div class="px-5 sm:px-6 py-4 border-t border-gray-50">
          <Link :href="route('teacher.profile')" class="text-sm text-brand-600 font-semibold hover:underline">
            Completar perfil →
          </Link>
        </div>
      </div>

      <!-- Quick actions — 1 col mobile, 2 tablet, 4 desktop -->
      <div>
        <h3 class="text-base font-bold text-slate-900 mb-3">Acciones rápidas</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <Link :href="route('teacher.requests')"
            class="group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
            <div class="w-11 h-11 bg-orange-50 group-hover:bg-orange-100 rounded-xl flex items-center justify-center text-xl mb-3 transition-colors">📋</div>
            <p class="font-bold text-slate-900">Solicitudes</p>
            <p class="text-sm text-slate-400 mt-0.5">{{ pending_requests }} abiertas</p>
          </Link>
          <Link :href="route('class-offers.index')"
            class="group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
            <div class="w-11 h-11 bg-brand-50 group-hover:bg-brand-100 rounded-xl flex items-center justify-center text-xl mb-3 transition-colors">📚</div>
            <p class="font-bold text-slate-900">Mis ofertas</p>
            <p class="text-sm text-slate-400 mt-0.5">Gestiona tus anuncios</p>
          </Link>
          <Link :href="route('teacher.credits.index')"
            class="group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
            <div class="w-11 h-11 bg-emerald-50 group-hover:bg-emerald-100 rounded-xl flex items-center justify-center text-xl mb-3 transition-colors">💳</div>
            <p class="font-bold text-slate-900">Recargar créditos</p>
            <p class="text-sm text-slate-400 mt-0.5">Yape / Plin</p>
          </Link>
          <Link :href="route('teacher.profile')"
            class="group bg-white border border-gray-100 rounded-2xl p-5 hover:border-brand-300 hover:shadow-lg transition-all">
            <div class="w-11 h-11 bg-purple-50 group-hover:bg-purple-100 rounded-xl flex items-center justify-center text-xl mb-3 transition-colors">👤</div>
            <p class="font-bold text-slate-900">Mi perfil</p>
            <p class="text-sm text-slate-400 mt-0.5">Actualiza tu información</p>
          </Link>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { splitByWeek } from '@/utils/weekGrouping'

const props = defineProps({
  upcoming: { type: Array, default: () => [] },
  pending_requests: Number,
  pending_reports: Number,
  profile_score: { type: Number, default: 0 },
  profile_checklist: { type: Object, default: () => ({}) },
})

const user  = computed(() => usePage().props.auth?.user)
const today = computed(() => new Date().toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }))

// `upcoming` ya viene ordenado start_time asc desde DashboardController —
// ambos baldes conservan ese orden tal cual.
const upcomingGrouped  = computed(() => splitByWeek(props.upcoming))
const upcomingThisWeek = computed(() => upcomingGrouped.value.thisWeek)
const upcomingPast     = computed(() => upcomingGrouped.value.past)

const postClassLessonId = ref(null)
const postClassEnded = ref(true)

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
