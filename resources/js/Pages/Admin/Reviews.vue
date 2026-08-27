<template>
  <AppLayout title="Reseñas — Admin">
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-900">Moderación de reseñas</h2>
        <div class="flex flex-wrap gap-2">
          <button v-for="f in visibilityFilters" :key="f.value ?? 'all'"
            @click="setFilter(f.value)"
            :class="['px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors',
              visibility === f.value
                ? 'bg-brand-600 text-white border-brand-600'
                : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50']">
            {{ f.label }}
          </button>
        </div>
      </div>

      <div v-if="!reviews.data.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <p class="text-gray-400 text-sm">No hay reseñas con ese filtro</p>
      </div>

      <div v-else class="space-y-3">
        <div v-for="r in reviews.data" :key="r.id"
          :class="['bg-white rounded-xl border p-4', r.is_visible ? 'border-gray-200' : 'border-orange-200 bg-orange-50/30']">

          <div class="flex flex-col sm:flex-row sm:items-start gap-3 justify-between">
            <div class="flex-1 min-w-0 space-y-1">
              <!-- Rating stars -->
              <div class="flex items-center gap-1.5">
                <span v-for="n in 5" :key="n" :class="['text-base', n <= r.rating ? 'text-amber-400' : 'text-gray-200']">★</span>
                <span class="text-sm font-bold text-slate-800 ml-1">{{ r.rating }}/5</span>
                <span v-if="!r.is_visible" class="ml-2 px-2 py-0.5 text-xs font-semibold bg-orange-100 text-orange-700 rounded">OCULTA</span>
              </div>

              <!-- Comment -->
              <p v-if="r.comment" class="text-sm text-slate-700 leading-relaxed">{{ r.comment }}</p>
              <p v-else class="text-xs text-slate-400 italic">Sin comentario</p>

              <!-- Meta -->
              <div class="flex flex-wrap gap-3 text-xs text-slate-400 pt-1">
                <span>Profesor: <strong class="text-slate-600">{{ r.teacher_profile?.user?.name ?? '—' }}</strong></span>
                <span>Padre: <strong class="text-slate-600">{{ r.parent?.name ?? '—' }}</strong></span>
                <span>{{ fmtDate(r.created_at) }}</span>
              </div>

              <div v-if="r.moderation_reason" class="text-xs text-orange-600 bg-orange-50 rounded px-2 py-1 mt-1">
                Motivo de ocultación: {{ r.moderation_reason }}
              </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-col gap-2 flex-shrink-0">
              <button v-if="r.is_visible"
                @click="openHideModal(r)"
                class="px-3 py-1.5 text-xs font-semibold text-orange-600 border border-orange-200 rounded-lg hover:bg-orange-50 transition-colors whitespace-nowrap">
                Ocultar
              </button>
              <button v-else
                @click="restoreReview(r)" :disabled="moderating"
                class="px-3 py-1.5 text-xs font-semibold text-green-600 border border-green-200 rounded-lg hover:bg-green-50 transition-colors whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed">
                Mostrar
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="reviews.last_page > 1" class="flex items-center justify-between text-sm text-gray-500">
        <span>{{ reviews.from }}–{{ reviews.to }} de {{ reviews.total }}</span>
        <div class="flex gap-1">
          <Link v-if="reviews.prev_page_url" :href="reviews.prev_page_url" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50">←</Link>
          <Link v-if="reviews.next_page_url" :href="reviews.next_page_url" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50">→</Link>
        </div>
      </div>
    </div>

    <!-- Hide modal -->
    <div v-if="hideTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl border border-gray-200 p-6 w-full max-w-sm">
        <h3 class="font-bold text-gray-900 mb-1">Ocultar reseña</h3>
        <p class="text-sm text-gray-500 mb-3">Motivo de moderación (opcional)</p>
        <textarea v-model="hideReason" rows="3" maxlength="500"
          placeholder="Ej: Contenido inapropiado, spam..."
          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400 mb-3 resize-none" />
        <div class="flex gap-2">
          <button @click="hideTarget = null; hideReason = ''"
            class="flex-1 px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">
            Cancelar
          </button>
          <p v-if="moderationError" class="mb-3 text-sm font-semibold text-red-600">{{ moderationError }}</p>
          <button @click="submitHide" :disabled="moderating"
            class="flex-1 px-4 py-2 text-sm font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed">
            {{ moderating ? 'Ocultando…' : 'Confirmar' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  reviews:    { type: Object, required: true },
  visibility: { type: String, default: null },
})

const visibilityFilters = [
  { value: null,      label: 'Todas' },
  { value: 'visible', label: 'Visibles' },
  { value: 'hidden',  label: 'Ocultas' },
]

const hideTarget = ref(null)
const hideReason = ref('')
const moderating = ref(false)
const moderationError = ref('')

function setFilter(v) {
  router.get(route('admin.reviews'), v ? { visibility: v } : {}, { preserveScroll: true })
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' })
}

function openHideModal(r) {
  hideTarget.value = r
  hideReason.value = ''
}

// F-12 (segunda ronda): moderación de reseñas, también sin guard en la
// primera pasada. El backend es idempotente aquí, pero un doble clic dejaba
// al admin viendo un parpadeo sin saber si la acción se aplicó.
function submitHide() {
  if (moderating.value) return
  moderating.value = true
  router.post(
    route('admin.reviews.hide', hideTarget.value.id),
    { reason: hideReason.value.trim() || null },
    {
      preserveScroll: true,
      onSuccess: () => { hideTarget.value = null; hideReason.value = '' },
      // F-19: sin esto, un fallo de moderación era invisible para el admin.
      onError: (errors) => { moderationError.value = errors.reason ?? 'No se pudo ocultar la reseña.' },
      onFinish: () => { moderating.value = false },
    }
  )
}

function restoreReview(r) {
  if (moderating.value) return
  moderating.value = true
  router.post(route('admin.reviews.show', r.id), {}, {
    preserveScroll: true,
    onError: () => { moderationError.value = 'No se pudo mostrar la reseña.' },
    onFinish: () => { moderating.value = false },
  })
}
</script>
