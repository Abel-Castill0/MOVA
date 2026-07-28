<template>
  <Head>
    <meta name="description" :content="`Clases particulares con ${teacher.name} en MOVA` + (teacher.subjects?.length ? `: ${teacher.subjects.map(s => s.name).join(', ')}.` : '.') + ' Profesor verificado, clases en vivo por videollamada.'" />
  </Head>
  <component :is="layout" :title="teacher.name + ' — MOVA'">
    <div class="max-w-3xl mx-auto space-y-6">

      <!-- Back -->
      <Link :href="route('marketplace')"
        class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-600 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        Volver al marketplace
      </Link>

      <!-- Profile header -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 sm:p-8 flex flex-col sm:flex-row gap-5 sm:gap-8">
        <!-- Avatar -->
        <div class="flex-shrink-0 flex flex-col items-center gap-2">
          <div class="w-20 h-20 bg-gradient-to-br from-brand-500 to-brand-700 rounded-2xl flex items-center justify-center text-white font-black text-3xl shadow-lg shadow-brand-500/25">
            {{ teacher.name?.charAt(0)?.toUpperCase() }}
          </div>
          <span v-if="teacher.is_verified" class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 text-emerald-700 text-xs font-semibold rounded-lg border border-emerald-200">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            Verificado por MOVA
          </span>
        </div>

        <!-- Info -->
        <div class="flex-1 min-w-0">
          <h1 class="text-2xl font-black text-slate-900">{{ teacher.name }}</h1>

          <!-- Subjects -->
          <div v-if="teacher.subjects?.length" class="flex flex-wrap gap-2 mt-2">
            <span v-for="s in teacher.subjects" :key="s.id"
              class="px-2.5 py-1 bg-brand-50 text-brand-700 text-xs font-semibold rounded-lg">
              {{ s.name }}
            </span>
          </div>

          <!-- Stats row -->
          <div class="flex flex-wrap gap-4 mt-4">
            <div class="text-center">
              <p class="text-xl font-black text-slate-900">S/ {{ parseFloat(teacher.hourly_rate ?? 0).toFixed(0) }}</p>
              <p class="text-xs text-slate-400">por hora</p>
            </div>
            <div v-if="teacher.avg_rating" class="text-center">
              <p class="text-xl font-black text-amber-500">{{ teacher.avg_rating }} ★</p>
              <p class="text-xs text-slate-400">{{ teacher.review_count }} {{ teacher.review_count === 1 ? 'reseña' : 'reseñas' }}</p>
            </div>
            <div v-if="teacher.classes_completed > 0" class="text-center">
              <p class="text-xl font-black text-slate-900">{{ teacher.classes_completed }}</p>
              <p class="text-xs text-slate-400">clases dictadas</p>
            </div>
            <div class="text-center">
              <p class="text-xl font-black text-slate-900">{{ teacher.offers?.length ?? 0 }}</p>
              <p class="text-xs text-slate-400">{{ teacher.offers?.length === 1 ? 'oferta activa' : 'ofertas activas' }}</p>
            </div>
          </div>

          <!-- Bio -->
          <p v-if="teacher.bio" class="text-sm text-slate-600 mt-4 leading-relaxed">{{ teacher.bio }}</p>
          <p v-else class="text-sm text-slate-400 mt-4 italic">Este profesor aún no ha completado su presentación.</p>

          <!-- Modality -->
          <div class="flex items-center gap-1.5 mt-4 text-xs text-slate-500">
            <svg class="w-4 h-4 text-brand-500" fill="currentColor" viewBox="0 0 20 20">
              <path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
              <path d="M14 6a2 2 0 012-2h2a2 2 0 012 2v8a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z"/>
            </svg>
            Clases online por videollamada — link generado automáticamente al confirmar
          </div>
        </div>
      </div>

      <!-- Offers -->
      <div>
        <h2 class="text-lg font-black text-slate-900 mb-4">Ofertas disponibles</h2>

        <!-- Empty offers -->
        <div v-if="!teacher.offers?.length" class="bg-white rounded-2xl border border-gray-100 p-10 text-center text-slate-400">
          <div class="text-4xl mb-3">📚</div>
          <p class="text-sm font-medium">Este profesor no tiene ofertas activas en este momento.</p>
          <Link :href="route('marketplace')" class="inline-block mt-4 text-sm text-brand-600 hover:underline font-medium">
            Ver otros profesores →
          </Link>
        </div>

        <!-- Offer cards -->
        <div v-else class="space-y-4">
          <div v-for="offer in teacher.offers" :key="offer.id"
            class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:border-brand-200 hover:shadow-md transition-all p-5 flex flex-col sm:flex-row sm:items-start gap-4">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-1">
                <span class="px-2 py-0.5 bg-brand-50 text-brand-700 text-xs font-semibold rounded-lg">
                  {{ offer.subject?.name }}
                </span>
              </div>
              <p class="font-bold text-slate-900">{{ offer.title }}</p>
              <p v-if="offer.description" class="text-sm text-slate-500 mt-1 line-clamp-3">{{ offer.description }}</p>
            </div>
            <div class="flex flex-col items-start sm:items-end gap-3 flex-shrink-0">
              <div class="text-right">
                <p class="text-xl font-black text-slate-900">S/ {{ parseFloat(offer.specific_rate ?? teacher.hourly_rate ?? 0).toFixed(0) }}</p>
                <p class="text-xs text-slate-400">/hora</p>
              </div>
              <template v-if="authUser">
                <template v-if="isParent">
                  <Link :href="route('class-requests.create', { offer_id: offer.id })"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 transition-colors shadow-sm shadow-brand-600/20 whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Solicitar clase
                  </Link>
                </template>
                <template v-else>
                  <span class="px-5 py-2.5 bg-slate-100 text-slate-400 text-sm font-medium rounded-xl cursor-default whitespace-nowrap">
                    Solo padres pueden solicitar
                  </span>
                </template>
              </template>
              <template v-else>
                <Link :href="route('login')"
                  class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 transition-colors whitespace-nowrap">
                  Iniciar sesión para solicitar
                </Link>
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- Reviews section -->
      <div>
        <h2 class="text-lg font-black text-slate-900 mb-4">Reseñas verificadas</h2>

        <div v-if="!teacher.reviews?.length" class="bg-white rounded-2xl border border-gray-100 p-8 text-center">
          <p class="text-2xl mb-2">⭐</p>
          <p class="text-sm font-semibold text-slate-700">Profesor nuevo en MOVA</p>
          <p class="text-xs text-slate-400 mt-1">Aún no tiene reseñas. ¡Sé el primero en calificarlo!</p>
        </div>

        <div v-else class="space-y-3">
          <div v-if="teacher.avg_rating" class="bg-amber-50 border border-amber-100 rounded-2xl p-4 flex items-center gap-4">
            <p class="text-4xl font-black text-amber-500">{{ teacher.avg_rating }}</p>
            <div>
              <div class="flex gap-0.5">
                <span v-for="n in 5" :key="n" :class="['text-xl', n <= Math.round(teacher.avg_rating) ? 'text-amber-400' : 'text-gray-200']">★</span>
              </div>
              <p class="text-xs text-slate-500 mt-0.5">Basado en {{ teacher.review_count }} {{ teacher.review_count === 1 ? 'reseña verificada' : 'reseñas verificadas' }}</p>
            </div>
          </div>

          <div v-for="r in teacher.reviews" :key="r.id"
            class="bg-white rounded-xl border border-gray-100 p-4">
            <div class="flex items-center gap-1 mb-1">
              <span v-for="n in 5" :key="n" :class="['text-sm', n <= r.rating ? 'text-amber-400' : 'text-gray-200']">★</span>
              <span class="text-xs text-slate-400 ml-2">{{ fmtDate(r.created_at) }}</span>
            </div>
            <p v-if="r.comment" class="text-sm text-slate-700 leading-relaxed">{{ r.comment }}</p>
            <p v-else class="text-xs text-slate-400 italic">Sin comentario adicional.</p>
          </div>
        </div>
      </div>

      <!-- Trust section -->
      <div class="bg-slate-50 rounded-2xl border border-gray-100 p-5 sm:p-6">
        <h3 class="font-bold text-slate-900 mb-4">¿Por qué confiar en MOVA?</h3>
        <div class="grid sm:grid-cols-3 gap-4">
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center text-emerald-600 flex-shrink-0 text-sm">✓</div>
            <div>
              <p class="text-sm font-semibold text-slate-900">Profesores verificados</p>
              <p class="text-xs text-slate-500 mt-0.5">Cada profesor es revisado por el equipo MOVA antes de aparecer aquí.</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 bg-brand-100 rounded-lg flex items-center justify-center text-brand-600 flex-shrink-0 text-sm">📹</div>
            <div>
              <p class="text-sm font-semibold text-slate-900">Videollamada automática</p>
              <p class="text-xs text-slate-500 mt-0.5">El enlace de videollamada se genera solo al confirmar la clase.</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 bg-brand-100 rounded-lg flex items-center justify-center text-brand-600 flex-shrink-0 text-sm">🔔</div>
            <div>
              <p class="text-sm font-semibold text-slate-900">Recordatorios incluidos</p>
              <p class="text-xs text-slate-500 mt-0.5">Te avisamos por correo electrónico antes de cada clase.</p>
            </div>
          </div>
        </div>
      </div>

    </div>
  </component>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'

defineProps({ teacher: Object })

const authUser = computed(() => usePage().props.auth?.user ?? null)
const isParent = computed(() => {
  const roles = usePage().props.auth?.user?.roles
  if (!roles) return false
  return Array.isArray(roles) ? roles.includes('parent') : Object.values(roles).includes('parent')
})
const layout = computed(() => authUser.value ? AppLayout : GuestLayout)

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>
