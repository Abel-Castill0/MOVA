<template>
  <AppLayout title="Calificar profesor">
    <div class="max-w-lg mx-auto">
      <div class="bg-white rounded-2xl border border-gray-100 p-6 space-y-5">
        <div>
          <h2 class="text-xl font-black text-slate-900">Calificar al profesor</h2>
          <p class="text-sm text-slate-500 mt-1">
            {{ lesson.subject }} · {{ fmtDate(lesson.date) }}
          </p>
          <p v-if="lesson.teacher" class="text-sm text-slate-600 font-medium mt-0.5">
            Prof. {{ lesson.teacher }}
          </p>
        </div>

        <!-- Star rating -->
        <div>
          <p class="text-sm font-semibold text-slate-700 mb-2">Calificación <span class="text-red-500">*</span></p>
          <div class="flex gap-2">
            <button v-for="n in 5" :key="n"
              type="button"
              @click="form.rating = n"
              :class="['text-3xl transition-transform hover:scale-110 focus:outline-none',
                n <= form.rating ? 'text-amber-400' : 'text-gray-200']">
              ★
            </button>
          </div>
          <p class="text-xs text-slate-400 mt-1">{{ ratingLabel }}</p>
          <p v-if="errors.rating" class="text-xs text-red-500 mt-1">{{ errors.rating }}</p>
        </div>

        <!-- Comment -->
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">
            Comentario <span class="text-slate-400 font-normal">(opcional)</span>
          </label>
          <textarea v-model="form.comment" rows="4" maxlength="1000"
            placeholder="¿Cómo fue la clase? ¿El profesor explicó bien? ¿Lo recomendarías?"
            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 resize-none" />
          <p class="text-xs text-slate-400 text-right mt-0.5">{{ form.comment.length }}/1000</p>
        </div>

        <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 text-xs text-blue-700">
          Tu reseña es anónima. No se mostrará tu nombre ni datos del alumno.
        </div>

        <div class="flex gap-3 pt-1">
          <Link :href="route('parent.lessons')"
            class="flex-1 text-center px-4 py-2.5 text-sm text-slate-600 border border-gray-200 rounded-xl hover:bg-gray-50">
            Cancelar
          </Link>
          <button @click="submit" :disabled="!form.rating || submitting"
            :class="['flex-1 px-4 py-2.5 text-sm font-semibold rounded-xl transition-colors',
              form.rating && !submitting
                ? 'bg-brand-600 text-white hover:bg-brand-700'
                : 'bg-gray-100 text-gray-400 cursor-not-allowed']">
            {{ submitting ? 'Enviando...' : 'Enviar reseña' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  lesson: { type: Object, required: true },
})

const form      = ref({ rating: 0, comment: '' })
const errors    = ref({})
const submitting = ref(false)

const ratingLabel = computed(() => {
  return ['', 'Muy malo', 'Malo', 'Regular', 'Bueno', 'Excelente'][form.value.rating] ?? ''
})

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric'
  })
}

function submit() {
  if (!form.value.rating) {
    errors.value.rating = 'Selecciona una calificación.'
    return
  }
  submitting.value = true
  errors.value = {}
  router.post(
    route('reviews.store', props.lesson.id),
    { rating: form.value.rating, comment: form.value.comment || null },
    {
      onError: (e) => { errors.value = e; submitting.value = false },
      onFinish: () => { submitting.value = false },
    }
  )
}
</script>
