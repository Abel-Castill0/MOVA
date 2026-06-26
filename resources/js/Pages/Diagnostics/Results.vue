<template>
  <AppLayout title="Profesores recomendados">
    <div class="max-w-2xl mx-auto space-y-5">

      <!-- Header -->
      <div class="bg-gradient-to-r from-brand-800 to-brand-600 rounded-2xl p-5 sm:p-6 text-white">
        <p class="text-white/70 text-sm mb-1">Diagnóstico para {{ diagnostic.student }}</p>
        <h1 class="text-xl sm:text-2xl font-black leading-tight">
          {{ recommendations.length > 0
            ? `MOVA encontró ${recommendations.length} profesor${recommendations.length > 1 ? 'es' : ''} para ti`
            : 'Sin resultados por ahora' }}
        </h1>
        <div class="flex flex-wrap gap-2 mt-3">
          <span class="px-2.5 py-1 bg-white/20 rounded-lg text-xs font-medium">{{ goalLabel }}</span>
          <span class="px-2.5 py-1 bg-white/20 rounded-lg text-xs font-medium">{{ urgencyLabel }}</span>
          <span v-if="diagnostic.subject" class="px-2.5 py-1 bg-white/20 rounded-lg text-xs font-medium">{{ diagnostic.subject }}</span>
        </div>
      </div>

      <!-- No results state -->
      <div v-if="recommendations.length === 0"
        class="bg-white rounded-2xl border border-gray-100 p-8 text-center">
        <div class="text-5xl mb-4">🔍</div>
        <h2 class="font-black text-slate-900 text-lg mb-2">No encontramos profesores disponibles</h2>
        <p class="text-sm text-slate-500 mb-5 max-w-sm mx-auto">
          No hay profesores verificados disponibles para estos criterios en este momento.
        </p>
        <div class="flex flex-col sm:flex-row gap-3 justify-center">
          <Link :href="route('marketplace')"
            class="px-5 py-2.5 bg-brand-600 text-white font-bold rounded-xl text-sm hover:bg-brand-700 transition-colors">
            Ver todos los profesores
          </Link>
          <Link :href="route('diagnostics.create')"
            class="px-5 py-2.5 border border-gray-200 text-slate-700 font-semibold rounded-xl text-sm hover:bg-slate-50 transition-colors">
            Intentar con otra materia
          </Link>
        </div>
      </div>

      <!-- Recommendation cards -->
      <div v-for="rec in recommendations" :key="rec.id"
        class="bg-white rounded-2xl border border-gray-100 overflow-hidden hover:shadow-md transition-shadow">

        <!-- Teacher header -->
        <div class="p-5 sm:p-6">
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 bg-gradient-to-br from-brand-400 to-brand-700 rounded-xl flex items-center justify-center text-white font-black text-lg flex-shrink-0">
              {{ rec.offer.teacher.name?.charAt(0) }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-0.5">
                <p class="font-bold text-slate-900">{{ rec.offer.teacher.name }}</p>
                <span class="px-2 py-0.5 bg-green-50 text-green-700 text-xs font-bold rounded-lg border border-green-200">
                  ✓ Verificado
                </span>
                <span class="px-2 py-0.5 bg-slate-50 text-slate-500 text-xs font-medium rounded-lg">
                  #{{ rec.rank }} mejor match
                </span>
              </div>
              <p class="text-sm text-slate-500">{{ rec.offer.subject }} · S/ {{ formatRate(rec.offer.teacher.hourly_rate, rec.offer.specific_rate) }}/hora</p>
            </div>
          </div>

          <!-- Offer title -->
          <div class="mt-3 p-3 bg-slate-50 rounded-xl">
            <p class="text-sm font-semibold text-slate-800">{{ rec.offer.title }}</p>
            <p v-if="rec.offer.description" class="text-xs text-slate-500 mt-1 line-clamp-2">{{ rec.offer.description }}</p>
          </div>

          <!-- Why recommended -->
          <div class="mt-3">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">¿Por qué te lo recomendamos?</p>
            <div class="flex flex-wrap gap-1.5">
              <span v-for="reason in rec.reasons" :key="reason"
                class="flex items-center gap-1 px-2.5 py-1 bg-brand-50 text-brand-700 text-xs font-medium rounded-lg">
                <span class="text-brand-500">✓</span> {{ reason }}
              </span>
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="px-5 pb-5 sm:px-6 sm:pb-6 flex flex-col sm:flex-row gap-2.5">
          <button @click="requestClass(rec)"
            :disabled="requesting === rec.id"
            class="flex-1 py-2.5 bg-brand-600 text-white font-bold rounded-xl text-sm hover:bg-brand-700 transition-colors shadow-sm disabled:opacity-60 flex items-center justify-center gap-2">
            <svg v-if="requesting === rec.id" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <span>{{ requesting === rec.id ? 'Enviando...' : '📩 Solicitar clase' }}</span>
          </button>
          <a :href="rec.offer.teacher.public_url" target="_blank"
            class="flex-1 py-2.5 border border-gray-200 text-slate-700 font-semibold rounded-xl text-sm hover:bg-slate-50 transition-colors text-center">
            Ver perfil completo
          </a>
        </div>
      </div>

      <!-- Footer note -->
      <p class="text-center text-xs text-slate-400 pb-4">
        Solo profesores verificados por MOVA · Sin compromiso hasta confirmar
      </p>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  diagnostic:      Object,
  recommendations: Array,
  subjects:        Array,
});

const requesting = ref(null);

const goalLabels = {
  reinforce_topic:    'Reforzar tema',
  prepare_exam:       'Preparar examen',
  recover_grades:     'Recuperar notas',
  solve_homework:     'Resolver tarea',
  continuous_support: 'Acompañamiento continuo',
};

const urgencyLabels = {
  today_or_tomorrow: 'Hoy o mañana',
  this_week:         'Esta semana',
  flexible:          'Sin prisa',
};

const goalLabel    = computed(() => goalLabels[props.diagnostic.goal] ?? props.diagnostic.goal);
const urgencyLabel = computed(() => urgencyLabels[props.diagnostic.urgency] ?? props.diagnostic.urgency);

function formatRate(hourly, specific) {
  const rate = specific ?? hourly;
  return rate ? parseFloat(rate).toFixed(0) : '?';
}

function requestClass(rec) {
  if (requesting.value) return;
  requesting.value = rec.id;
  router.post(
    route('diagnostics.request', { diagnostic: props.diagnostic.id, classOffer: rec.offer.id }),
    {},
    {
      onError: () => { requesting.value = null; },
      onFinish: () => { requesting.value = null; },
    }
  );
}
</script>
