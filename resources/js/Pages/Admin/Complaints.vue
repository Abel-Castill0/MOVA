<template>
  <AppLayout title="Reclamaciones — Admin">
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-900">Libro de Reclamaciones</h2>
        <div class="flex flex-wrap gap-2">
          <button v-for="f in filters" :key="f.value ?? 'all'" type="button" @click="setFilter(f.value)"
            :class="['px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors',
              status === f.value ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50']">
            {{ f.label }}
          </button>
        </div>
      </div>

      <div v-if="!complaints.data.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <p class="text-gray-400 text-sm">No hay hojas con ese filtro</p>
      </div>

      <article v-for="c in complaints.data" :key="c.id" class="bg-white rounded-xl border border-gray-200 p-4 space-y-3">
        <header class="flex flex-wrap items-center gap-2 justify-between">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-sm font-bold text-slate-900">{{ c.code }}</span>
            <span class="px-2 py-0.5 text-xs font-semibold rounded bg-slate-100 text-slate-700 uppercase">{{ c.type }}</span>
            <span :class="['px-2 py-0.5 text-xs font-semibold rounded', c.status === 'open' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800']">
              {{ c.status === 'open' ? `Abierta · vence ${dueDate(c.created_at)}` : 'Respondida' }}
            </span>
          </div>
          <time class="text-xs text-slate-400" :datetime="c.created_at">{{ fmt(c.created_at) }}</time>
        </header>

        <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
          <div><dt class="inline text-slate-400">Consumidor: </dt><dd class="inline text-slate-800">{{ c.consumer_name }} ({{ c.document_type }} {{ c.document_number }})</dd></div>
          <div><dt class="inline text-slate-400">Contacto: </dt><dd class="inline text-slate-800 break-all">{{ c.email }}<template v-if="c.phone"> · {{ c.phone }}</template></dd></div>
          <div v-if="c.is_minor"><dt class="inline text-slate-400">Apoderado: </dt><dd class="inline text-slate-800">{{ c.guardian_name }}</dd></div>
          <div><dt class="inline text-slate-400">Bien: </dt><dd class="inline text-slate-800">{{ c.good_type }} — {{ c.good_description }}<template v-if="c.amount"> (S/ {{ c.amount }})</template></dd></div>
        </dl>
        <p class="text-sm text-slate-700 whitespace-pre-line"><strong>Detalle:</strong> {{ c.detail }}</p>
        <p class="text-sm text-slate-700 whitespace-pre-line"><strong>Pedido:</strong> {{ c.consumer_request }}</p>

        <div v-if="c.status === 'answered'" class="rounded-lg bg-green-50 p-3 text-sm text-green-900 whitespace-pre-line">
          <strong>Respuesta ({{ fmt(c.responded_at) }}<template v-if="c.responder"> · {{ c.responder.name }}</template>):</strong> {{ c.response }}
        </div>
        <form v-else class="space-y-2" @submit.prevent="respond(c)">
          <label :for="`resp-${c.id}`" class="block text-sm font-medium text-slate-700">Respuesta al consumidor</label>
          <textarea :id="`resp-${c.id}`" v-model="drafts[c.id]" rows="3" maxlength="5000" class="block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500" />
          <p v-if="errors[c.id]" class="text-sm text-red-600">{{ errors[c.id] }}</p>
          <button type="submit" :disabled="busy === c.id"
            class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50">
            {{ busy === c.id ? 'Enviando…' : 'Registrar respuesta y notificar' }}
          </button>
        </form>
      </article>

      <nav v-if="complaints.links?.length > 3" class="flex flex-wrap gap-1" aria-label="Paginación">
        <Link v-for="l in complaints.links" :key="l.label" :href="l.url ?? ''" v-html="l.label" preserve-scroll
          :class="['px-3 py-1.5 text-xs rounded border', l.active ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-gray-200', !l.url && 'pointer-events-none opacity-40']" />
      </nav>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  complaints:   { type: Object, required: true },
  status:       { type: String, default: null },
  responseDays: { type: Number, required: true },
})

const filters = [
  { value: null, label: 'Todas' },
  { value: 'open', label: 'Abiertas' },
  { value: 'answered', label: 'Respondidas' },
]

const drafts = reactive({})
const errors = reactive({})
const busy = ref(null)

function setFilter(v) {
  router.get(route('admin.complaints'), v ? { status: v } : {}, { preserveScroll: true })
}

const fmt = (d) => d ? new Date(d).toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' }) : '—'

// Vencimiento orientativo: suma días hábiles (lun–vie), sin feriados.
function dueDate(created) {
  const d = new Date(created)
  let left = props.responseDays
  while (left > 0) {
    d.setDate(d.getDate() + 1)
    if (d.getDay() !== 0 && d.getDay() !== 6) left--
  }
  return d.toLocaleDateString('es-PE', { dateStyle: 'medium' })
}

function respond(c) {
  if (busy.value) return
  busy.value = c.id
  errors[c.id] = null
  router.post(route('admin.complaints.respond', c.id), { response: (drafts[c.id] ?? '').trim() }, {
    preserveScroll: true,
    onError: (e) => { errors[c.id] = e.response ?? 'No se pudo registrar la respuesta.' },
    onFinish: () => { busy.value = null },
  })
}
</script>
