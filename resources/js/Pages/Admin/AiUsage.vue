<template>
  <AppLayout title="IA — Uso y límites">
    <div class="space-y-6">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-900">Uso de IA</h2>
        <span :class="['px-3 py-1 text-xs font-bold rounded-lg border',
          limits.enabled
            ? 'bg-amber-50 text-amber-700 border-amber-200'
            : 'bg-slate-100 text-slate-500 border-slate-200']">
          {{ limits.enabled ? 'IA ACTIVA' : 'IA DESACTIVADA' }}
        </span>
      </div>

      <!-- Limits info -->
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-slate-400 mb-1">Límite diario</p>
          <p class="text-2xl font-black text-slate-800">
            {{ todaySuccess }} <span class="text-slate-400 text-lg font-normal">/ {{ limits.daily }}</span>
          </p>
          <p class="text-xs text-slate-400 mt-1">llamadas exitosas hoy</p>
          <div class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div :class="['h-full rounded-full transition-all', dailyPct > 90 ? 'bg-red-500' : dailyPct > 70 ? 'bg-amber-400' : 'bg-emerald-500']"
              :style="{ width: dailyPct + '%' }" />
          </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-slate-400 mb-1">Límite mensual</p>
          <p class="text-2xl font-black text-slate-800">
            {{ monthSuccess }} <span class="text-slate-400 text-lg font-normal">/ {{ limits.monthly }}</span>
          </p>
          <p class="text-xs text-slate-400 mt-1">llamadas exitosas este mes</p>
          <div class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div :class="['h-full rounded-full transition-all', monthlyPct > 90 ? 'bg-red-500' : monthlyPct > 70 ? 'bg-amber-400' : 'bg-emerald-500']"
              :style="{ width: monthlyPct + '%' }" />
          </div>
        </div>
      </div>

      <!-- Stats today -->
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <h3 class="text-sm font-bold text-slate-700 mb-3">Hoy</h3>
        <div class="flex flex-wrap gap-4">
          <div v-for="s in statuses" :key="s.key" class="text-center">
            <p :class="['text-xl font-black', s.color]">{{ statsToday[s.key] ?? 0 }}</p>
            <p class="text-xs text-slate-400">{{ s.label }}</p>
          </div>
        </div>
      </div>

      <!-- Stats month -->
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <h3 class="text-sm font-bold text-slate-700 mb-3">Este mes</h3>
        <div class="flex flex-wrap gap-4">
          <div v-for="s in statuses" :key="s.key" class="text-center">
            <p :class="['text-xl font-black', s.color]">{{ statsMonth[s.key] ?? 0 }}</p>
            <p class="text-xs text-slate-400">{{ s.label }}</p>
          </div>
        </div>
      </div>

      <!-- Recent logs -->
      <div>
        <h3 class="text-sm font-bold text-slate-700 mb-2">Últimos registros</h3>
        <div v-if="!recent.length" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-slate-400 text-sm">
          Sin registros aún.
        </div>
        <div v-else class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
          <div v-for="r in recent" :key="r.id" class="flex items-center gap-3 px-4 py-2.5 text-sm">
            <span :class="['w-16 text-center px-2 py-0.5 text-xs font-semibold rounded', statusBadge(r.status)]">
              {{ r.status }}
            </span>
            <span class="text-slate-600 flex-1">{{ r.provider }}/{{ r.model }}</span>
            <span v-if="r.total_tokens" class="text-xs text-slate-400">{{ r.total_tokens }} tok</span>
            <span v-if="r.error_type" class="text-xs text-orange-500">{{ r.error_type }}</span>
            <span class="text-xs text-slate-300 ml-auto">{{ fmtDate(r.created_at) }}</span>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  stats_today: { type: Object, default: () => ({}) },
  stats_month: { type: Object, default: () => ({}) },
  recent:      { type: Array,  default: () => [] },
  limits:      { type: Object, default: () => ({ daily: 50, monthly: 500, enabled: false }) },
})

const statuses = [
  { key: 'success',  label: 'Exitosas',  color: 'text-emerald-600' },
  { key: 'fallback', label: 'Fallback',  color: 'text-amber-500' },
  { key: 'error',    label: 'Error',     color: 'text-red-500' },
  { key: 'skipped',  label: 'Omitidas',  color: 'text-slate-400' },
]

const todaySuccess  = computed(() => props.stats_today?.success  ?? 0)
const monthSuccess  = computed(() => props.stats_month?.success  ?? 0)
const dailyPct      = computed(() => Math.min(100, Math.round((todaySuccess.value / (props.limits?.daily || 1)) * 100)))
const monthlyPct    = computed(() => Math.min(100, Math.round((monthSuccess.value / (props.limits?.monthly || 1)) * 100)))

function statusBadge(s) {
  return {
    success:  'bg-emerald-50 text-emerald-700',
    fallback: 'bg-amber-50 text-amber-700',
    error:    'bg-red-50 text-red-700',
    skipped:  'bg-slate-100 text-slate-500',
  }[s] ?? 'bg-gray-100 text-gray-500'
}

function fmtDate(d) {
  return new Date(d).toLocaleString('es-PE', {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
  })
}
</script>
