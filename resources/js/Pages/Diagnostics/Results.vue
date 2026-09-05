<template>
  <AppLayout title="Diagnóstico completado">
    <div class="mx-auto max-w-2xl space-y-5">
      <div class="rounded-2xl bg-gradient-to-r from-brand-800 to-brand-600 p-6 text-white">
        <p class="mb-1 text-sm text-white/70">Diagnóstico para {{ diagnostic.student }}</p>
        <h1 class="text-2xl font-black">Solicitud enviada a profesores verificados</h1>
        <div class="mt-3 flex flex-wrap gap-2">
          <span class="rounded-lg bg-white/20 px-2.5 py-1 text-xs font-medium">{{ goalLabel }}</span>
          <span class="rounded-lg bg-white/20 px-2.5 py-1 text-xs font-medium">{{ urgencyLabel }}</span>
          <span v-if="diagnostic.subject" class="rounded-lg bg-white/20 px-2.5 py-1 text-xs font-medium">{{ diagnostic.subject }}</span>
        </div>
      </div>

      <div v-if="diagnostic.ai_summary" class="flex items-start gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-5 py-4">
        <span class="text-lg text-indigo-400">✨</span>
        <div>
          <p class="mb-0.5 text-xs font-semibold uppercase tracking-wide text-indigo-600">MOVA entendió que necesitas</p>
          <p class="text-sm leading-snug text-indigo-900">{{ diagnostic.ai_summary }}</p>
        </div>
      </div>

      <div class="rounded-2xl border border-gray-100 bg-white p-6">
        <p class="font-semibold text-slate-900">Tu solicitud quedó registrada.</p>
        <p class="mt-1 text-sm text-slate-500">
          Los profesores verificados que enseñan esta materia podrán verla y responder desde su panel.
        </p>
        <Link
          :href="route('class-requests.index')"
          class="mt-5 inline-block rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-brand-700"
        >
          Ver mis solicitudes
        </Link>
      </div>

      <!--
        H-08: el servicio calculaba y guardaba estas recomendaciones desde
        siempre; la pantalla devolvía una lista vacía fija y ni siquiera
        declaraba la prop.

        Son INFORMATIVAS a propósito: la solicitud ya salió a todos los
        profesores verificados de la materia (arriba). Esto solo le pone cara a
        quién encaja mejor, para que el padre pueda mirar un perfil antes de que
        alguien responda. No dispara una segunda solicitud.
      -->
      <section class="rounded-2xl border border-gray-100 bg-white p-6">
        <header class="mb-1">
          <h2 class="font-semibold text-slate-900">Profesores que encajan con lo que nos contaste</h2>
          <p class="mt-1 text-sm text-slate-500">
            Ordenados por afinidad con la materia, el nivel de tu hijo y su disponibilidad.
          </p>
        </header>

        <ul v-if="recommendations.length" class="mt-4 space-y-3">
          <li
            v-for="teacher in recommendations"
            :key="teacher.id"
            class="rounded-xl border border-gray-100 p-4 transition-colors hover:border-brand-200"
          >
            <div class="flex flex-wrap items-start gap-3 sm:flex-nowrap">
              <img
                v-if="teacher.avatar_url"
                :src="teacher.avatar_url"
                :alt="`Foto de ${teacher.teacher_name}`"
                class="h-11 w-11 flex-shrink-0 rounded-full object-cover"
              />
              <div
                v-else
                class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-bold text-brand-700"
                aria-hidden="true"
              >
                {{ initials(teacher.teacher_name) }}
              </div>

              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                  <p class="font-bold text-slate-900">{{ teacher.teacher_name }}</p>
                  <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">
                    {{ teacher.match_label }}
                  </span>
                </div>

                <p class="mt-0.5 text-sm text-slate-500">
                  <span v-if="teacher.subject">{{ teacher.subject }} · </span>
                  <span>S/ {{ money(teacher.hourly_rate) }}/hora</span>
                  <span v-if="teacher.review_count > 0">
                    · {{ teacher.avg_rating }}★ ({{ teacher.review_count }})
                  </span>
                </p>

                <ul v-if="teacher.reasons.length" class="mt-2 flex flex-wrap gap-1.5">
                  <li
                    v-for="reason in teacher.reasons"
                    :key="reason"
                    class="rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-600"
                  >
                    {{ reason }}
                  </li>
                </ul>
              </div>

              <Link
                :href="route('teachers.show', teacher.teacher_profile_id)"
                class="w-full flex-shrink-0 rounded-lg border border-gray-200 px-3 py-1.5 text-center text-xs font-semibold text-slate-700 transition-colors hover:border-brand-300 hover:text-brand-700 sm:w-auto"
              >
                Ver perfil
              </Link>
            </div>
          </li>
        </ul>

        <!--
          Estado vacío REAL, no un placeholder: hoy las recomendaciones se
          construyen sobre ofertas de clase, y los profesores ya no publican
          ofertas nuevas (la ruta de creación está cerrada). Que no haya
          ninguna es un desenlace normal, no un error, y el padre no pierde
          nada: su solicitud ya salió a todos los profesores de la materia.
        -->
        <div v-else class="mt-4 rounded-xl bg-slate-50 px-4 py-5 text-center">
          <p class="text-sm font-medium text-slate-700">Todavía no podemos sugerirte profesores concretos.</p>
          <p class="mt-1 text-sm text-slate-500">
            Tu solicitud ya está visible para todos los profesores verificados de la materia.
            En cuanto alguno la acepte, te avisamos.
          </p>
          <Link
            :href="route('marketplace')"
            class="mt-4 inline-block text-sm font-semibold text-brand-600 hover:underline"
          >
            Explorar profesores por tu cuenta
          </Link>
        </div>
      </section>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  diagnostic: Object,
  // H-08: esta prop se enviaba desde el controller (siempre vacía) y la vista
  // ni siquiera la declaraba, así que nada la habría mostrado aunque hubiera
  // llegado con datos.
  recommendations: {
    type: Array,
    default: () => [],
  },
})

const goalLabels = {
  prepare_exam: 'Preparar examen',
  solve_homework: 'Resolver tarea',
  continuous_support: 'Acompañamiento continuo',
}

const urgencyLabels = {
  today_or_tomorrow: 'Hoy o mañana',
  this_week: 'Esta semana',
  flexible: 'Sin prisa',
}

const goalLabel = computed(() => goalLabels[props.diagnostic.goal] ?? props.diagnostic.goal)
const urgencyLabel = computed(() => urgencyLabels[props.diagnostic.urgency] ?? props.diagnostic.urgency)

function money(value) {
  return Number(value ?? 0).toFixed(2)
}

function initials(name) {
  return (name ?? '')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
}
</script>
