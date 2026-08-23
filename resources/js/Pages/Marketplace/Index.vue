<template>
  <Head>
    <meta name="description" content="Conoce a los profesores particulares verificados por MOVA: sus materias, calificación y experiencia. Envía tu solicitud y el primer profesor disponible te contactará." />
  </Head>
  <PublicPageLayout title="Nuestros profesores">
    <div class="space-y-6">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
          <h2 class="text-2xl font-black text-slate-900">Conoce a nuestros profesores</h2>
          <p class="text-sm text-slate-500 mt-1 max-w-xl">
            Profesores verificados por MOVA. Envía tu solicitud sin elegir a nadie — el primer
            profesor disponible de la materia te contactará. Después de tu primera clase, pídele
            su código para volver a elegirlo directamente la próxima vez.
          </p>
        </div>
        <Link v-if="authUser && isParent" :href="route('class-requests.create')"
          class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-sm shadow-brand-600/20 whitespace-nowrap">
          Solicitar una clase →
        </Link>
        <Link v-else-if="!authUser" :href="route('login')"
          class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 transition-colors whitespace-nowrap">
          Iniciar sesión para solicitar
        </Link>
      </div>

      <!-- Estadísticas: refuerzan confianza (principio de diseño #1 de
           PRODUCT.md), no son un filtro — nada aquí es clicable. -->
      <div class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
        <p class="text-slate-500"><span class="font-black text-slate-900">{{ stats.teachers }}</span> profesor{{ stats.teachers === 1 ? '' : 'es' }} verificado{{ stats.teachers === 1 ? '' : 's' }}</p>
        <p class="text-slate-500"><span class="font-black text-slate-900">{{ stats.completed }}</span> clase{{ stats.completed === 1 ? '' : 's' }} impartida{{ stats.completed === 1 ? '' : 's' }}</p>
      </div>

      <!-- Diagnostic banner: recomendación por IA, no búsqueda manual — se mantiene aparte. -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-3">
        <p class="text-sm text-slate-500 flex-1">💡 <strong>¿No sabes qué necesitas?</strong> Responde 5 preguntas y te orientamos.</p>
        <Link v-if="authUser" :href="route('diagnostics.create')"
          class="inline-block px-4 py-2 bg-brand-50 text-brand-700 font-bold rounded-xl text-xs hover:bg-brand-100 transition-colors whitespace-nowrap">
          Diagnóstico rápido →
        </Link>
      </div>

      <!-- Empty state -->
      <div v-if="!teachers.data?.length" class="bg-white rounded-2xl border border-gray-100 py-16 px-6 text-center">
        <div class="text-5xl mb-4">👩‍🏫</div>
        <p class="text-lg font-bold text-slate-900 mb-1">Aún no hay profesores verificados</p>
        <p class="text-sm text-slate-500">Vuelve pronto — estamos verificando a los primeros profesores de MOVA.</p>
      </div>

      <!-- Grid of teachers -->
      <div v-else class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <Link
          v-for="t in teachers.data"
          :key="t.id"
          :href="route('teachers.show', t.id)"
          class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-brand-200 transition-all p-5 flex flex-col"
        >
          <div class="flex items-center gap-3 mb-3">
            <img v-if="t.user?.avatar_url" :src="t.user.avatar_url" :alt="t.user?.name"
              class="w-12 h-12 rounded-xl object-cover flex-shrink-0" />
            <div v-else class="w-12 h-12 bg-gradient-to-br from-brand-500 to-indigo-600 rounded-xl flex items-center justify-center text-white font-black text-base flex-shrink-0">
              {{ t.user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
            </div>
            <div class="min-w-0">
              <p class="font-bold text-slate-900 text-sm truncate">{{ t.user?.name }}</p>
              <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                Verificado por MOVA
              </span>
            </div>
          </div>

          <div v-if="t.review_count" class="flex items-center gap-1 mb-3">
            <span class="text-amber-400 text-sm">★</span>
            <span class="text-xs font-semibold text-slate-700">{{ Number(t.avg_rating).toFixed(1) }}</span>
            <span class="text-xs text-slate-400">({{ t.review_count }} {{ t.review_count === 1 ? 'reseña' : 'reseñas' }})</span>
          </div>
          <p v-else class="text-xs text-slate-400 mb-3">Profesor nuevo en MOVA</p>

          <div v-if="t.subjects?.length" class="flex flex-wrap gap-1.5 mb-4">
            <span v-for="s in t.subjects" :key="s.id"
              class="inline-block px-2 py-0.5 bg-brand-50 text-brand-700 text-xs font-semibold rounded-lg">
              {{ s.name }}
            </span>
          </div>

          <p v-if="t.bio" class="text-xs text-slate-500 line-clamp-2 mb-4">{{ t.bio }}</p>

          <span class="mt-auto text-center px-4 py-2 border border-brand-200 text-brand-700 text-sm font-semibold rounded-xl">
            Ver perfil →
          </span>
        </Link>
      </div>

      <!-- Pagination -->
      <div v-if="teachers.last_page > 1" class="flex gap-1.5 flex-wrap justify-center">
        <component
          v-for="link in teachers.links"
          :key="link.label"
          :is="link.url ? Link : 'span'"
          :href="link.url"
          v-html="link.label"
          :class="[
            'px-3.5 py-2 rounded-xl text-sm font-medium transition-colors',
            link.active
              ? 'bg-brand-600 text-white shadow-sm shadow-brand-600/20'
              : 'bg-white border border-gray-200 text-gray-600 hover:border-brand-300 hover:text-brand-700',
            !link.url && 'opacity-40 pointer-events-none'
          ]"
        />
      </div>

    </div>
  </PublicPageLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import PublicPageLayout from '@/Layouts/PublicPageLayout.vue'

defineProps({ teachers: Object, stats: Object })

const authUser = computed(() => usePage().props.auth?.user ?? null)
const isParent = computed(() => {
  const roles = usePage().props.auth?.user?.roles
  if (!roles) return false
  return Array.isArray(roles) ? roles.includes('parent') : Object.values(roles).includes('parent')
})
</script>
