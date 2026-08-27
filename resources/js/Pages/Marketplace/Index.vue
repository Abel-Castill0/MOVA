<template>
  <Head>
    <meta name="description" content="Conoce a los profesores particulares verificados por MOVA: sus materias, calificación y experiencia. Envía tu solicitud y el primer profesor disponible te contactará." />
  </Head>
  <PublicPageLayout title="Nuestros profesores">
    <div class="space-y-6">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
          <h2 class="text-2xl font-black text-ink">Conoce a nuestros profesores</h2>
          <p class="text-sm text-ink-muted mt-1 max-w-xl">
            Profesores verificados por MOVA. Envía tu solicitud sin elegir a nadie — el primer
            profesor disponible de la materia te contactará. Después de tu primera clase, pídele
            su código para volver a elegirlo directamente la próxima vez.
          </p>
        </div>
        <Link v-if="authUser && isParent" :href="route('class-requests.create')"
          class="inline-flex items-center justify-center gap-2 min-h-[44px] px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-card shadow-elevation-1 hover:bg-brand-700 active:scale-[0.97] transition-[transform,background-color] duration-micro ease-out-expo focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 whitespace-nowrap">
          Solicitar una clase
          <Icon name="arrow-right" :size="16" />
        </Link>
        <Link v-else-if="!authUser" :href="route('login')"
          class="inline-flex items-center justify-center gap-2 min-h-[44px] px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-card shadow-elevation-1 hover:bg-brand-700 active:scale-[0.97] transition-[transform,background-color] duration-micro ease-out-expo focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 whitespace-nowrap">
          Iniciar sesión para solicitar
        </Link>
      </div>

      <!-- Estadísticas: refuerzan confianza (principio de diseño #1 de
           PRODUCT.md), no son un filtro — nada aquí es clicable. -->
      <div class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
        <p class="text-ink-muted"><span class="font-black text-ink">{{ stats.teachers }}</span> profesor{{ stats.teachers === 1 ? '' : 'es' }} verificado{{ stats.teachers === 1 ? '' : 's' }}</p>
        <p class="text-ink-muted"><span class="font-black text-ink">{{ stats.completed }}</span> clase{{ stats.completed === 1 ? '' : 's' }} impartida{{ stats.completed === 1 ? '' : 's' }}</p>
      </div>

      <!-- Diagnostic banner: recomendación por IA, no búsqueda manual — se mantiene aparte. -->
      <div class="bg-surface rounded-elevated border border-line shadow-elevation-1 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-3">
        <p class="text-sm text-ink-muted flex-1">💡 <strong>¿No sabes qué necesitas?</strong> Responde 5 preguntas y te orientamos.</p>
        <Link v-if="authUser" :href="route('diagnostics.create')"
          class="inline-block px-4 py-2 bg-brand-50 text-brand-700 font-bold rounded-card text-xs hover:bg-brand-100 transition-colors duration-micro whitespace-nowrap">
          Diagnóstico rápido →
        </Link>
      </div>

      <!-- Empty state -->
      <EmptyState
        v-if="!teachers.data?.length"
        title="Aún no hay profesores verificados"
        description="Vuelve pronto — estamos verificando a los primeros profesores de MOVA."
      >
        <template #icon>
          <Icon name="teachers" :size="40" :stroke-width="1.5" class="text-ink-subtle" />
        </template>
      </EmptyState>

      <!-- Grid of teachers -->
      <div v-else class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <Link
          v-for="t in teachers.data"
          :key="t.id"
          :href="route('teachers.show', t.id)"
          class="bg-surface rounded-elevated border border-line shadow-elevation-1 hover:shadow-elevation-2 hover:border-brand-200 transition-all duration-ui p-5 flex flex-col"
        >
          <div class="flex items-center gap-3 mb-3">
            <img
              v-if="t.user?.avatar_url && !failedAvatars.has(t.id)"
              :src="t.user.avatar_url"
              :alt="t.user?.name"
              loading="lazy"
              class="w-12 h-12 rounded-control object-cover flex-shrink-0"
              @error="failedAvatars.add(t.id)"
            />
            <div v-else class="w-12 h-12 bg-gradient-to-br from-brand-500 to-brand-700 rounded-control flex items-center justify-center text-white font-black text-base flex-shrink-0">
              {{ t.user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
            </div>
            <div class="min-w-0">
              <p class="font-bold text-ink text-sm truncate">{{ t.user?.name }}</p>
              <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600">
                <Icon name="verified-badge" :size="14" />
                Verificado por MOVA
              </span>
            </div>
          </div>

          <div v-if="t.review_count" class="flex items-center gap-1 mb-3" role="img" :aria-label="`Calificación ${Number(t.avg_rating).toFixed(1)} de 5, ${t.review_count} ${t.review_count === 1 ? 'reseña' : 'reseñas'}`">
            <span class="text-amber-400 text-sm" aria-hidden="true">★</span>
            <span class="text-xs font-semibold text-ink" aria-hidden="true">{{ Number(t.avg_rating).toFixed(1) }}</span>
            <span class="text-xs text-ink-subtle" aria-hidden="true">({{ t.review_count }} {{ t.review_count === 1 ? 'reseña' : 'reseñas' }})</span>
          </div>
          <p v-else class="text-xs text-ink-subtle mb-3">Profesor nuevo en MOVA</p>

          <div v-if="t.subjects?.length" class="flex flex-wrap gap-1.5 mb-4">
            <span v-for="s in t.subjects" :key="s.id"
              class="inline-block px-2 py-0.5 bg-brand-50 text-brand-700 text-xs font-semibold rounded-chip">
              {{ s.name }}
            </span>
          </div>

          <p v-if="t.bio" class="text-xs text-ink-muted line-clamp-2 mb-4">{{ t.bio }}</p>

          <span class="mt-auto text-center px-4 py-2 border border-brand-200 text-brand-700 text-sm font-semibold rounded-card">
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
            'px-3.5 py-2 rounded-card text-sm font-medium transition-colors duration-micro',
            link.active
              ? 'bg-brand-600 text-white shadow-elevation-1'
              : 'bg-surface border border-line-strong text-ink-muted hover:border-brand-300 hover:text-brand-700',
            !link.url && 'opacity-40 pointer-events-none'
          ]"
        />
      </div>

    </div>
  </PublicPageLayout>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import PublicPageLayout from '@/Layouts/PublicPageLayout.vue'
import EmptyState from '@/Components/EmptyState.vue'
import Icon from '@/Components/Icon.vue'

defineProps({ teachers: Object, stats: Object })

const authUser = computed(() => usePage().props.auth?.user ?? null)
const isParent = computed(() => {
  const roles = usePage().props.auth?.user?.roles
  if (!roles) return false
  return Array.isArray(roles) ? roles.includes('parent') : Object.values(roles).includes('parent')
})

// Un avatar_url presente no garantiza que la imagen cargue (URL vencida,
// almacenamiento movido, etc.) — sin este fallback, una carga fallida deja
// un icono de imagen rota en vez de las iniciales que ya usamos cuando no
// hay avatar_url en absoluto. Un Set por id de profesor, no una bandera
// global: la falla de un avatar no debe ocultar los demás.
const failedAvatars = reactive(new Set())
</script>
