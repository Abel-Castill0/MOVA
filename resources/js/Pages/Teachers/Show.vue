<template>
  <Head>
    <meta name="description" :content="`Clases particulares con ${teacher.name} en MOVA` + (teacher.subjects?.length ? `: ${teacher.subjects.map(s => s.name).join(', ')}.` : '.') + ' Profesor verificado, clases en vivo por videollamada.'" />
  </Head>
  <PublicPageLayout :title="teacher.name + ' — MOVA'">
    <div class="max-w-3xl mx-auto space-y-6">

      <!-- Back -->
      <Link :href="route('marketplace')"
        class="inline-flex items-center gap-1.5 text-sm text-ink-muted hover:text-brand-600 transition-colors duration-micro">
        <Icon name="back" :size="16" />
        Volver al marketplace
      </Link>

      <!-- Profile header -->
      <div class="bg-surface rounded-elevated border border-line shadow-elevation-1 p-6 sm:p-8 flex flex-col sm:flex-row gap-5 sm:gap-8">
        <!-- Avatar -->
        <div class="flex-shrink-0 flex flex-col items-center gap-2">
          <img
            v-if="teacher.avatar_url && !avatarFailed"
            :src="teacher.avatar_url"
            :alt="teacher.name"
            class="w-20 h-20 rounded-card object-cover shadow-elevation-2"
            @error="avatarFailed = true"
          />
          <div v-else class="w-20 h-20 bg-gradient-to-br from-brand-500 to-brand-700 rounded-card flex items-center justify-center text-white font-black text-3xl shadow-elevation-2">
            {{ teacher.name?.charAt(0)?.toUpperCase() }}
          </div>
          <span v-if="teacher.is_verified" class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 text-emerald-700 text-xs font-semibold rounded-control border border-emerald-200">
            <Icon name="verified-badge" :size="14" />
            Verificado por MOVA
          </span>
        </div>

        <!-- Info -->
        <div class="flex-1 min-w-0">
          <h1 class="text-2xl font-black text-ink">{{ teacher.name }}</h1>

          <!-- Subjects -->
          <div v-if="teacher.subjects?.length" class="flex flex-wrap gap-2 mt-2">
            <span v-for="s in teacher.subjects" :key="s.id"
              class="px-2.5 py-1 bg-brand-50 text-brand-700 text-xs font-semibold rounded-chip">
              {{ s.name }}
            </span>
          </div>

          <!-- Stats row -->
          <div class="flex flex-wrap gap-4 mt-4">
            <div class="text-center">
              <p class="text-xl font-black text-ink">S/ {{ parseFloat(teacher.hourly_rate ?? 0).toFixed(0) }}</p>
              <p class="text-xs text-ink-subtle">por hora</p>
            </div>
            <div v-if="teacher.avg_rating" class="text-center" role="img" :aria-label="`Calificación ${teacher.avg_rating} de 5, ${teacher.review_count} ${teacher.review_count === 1 ? 'reseña' : 'reseñas'}`">
              <p class="text-xl font-black text-amber-500" aria-hidden="true">★ {{ teacher.avg_rating }} <span class="text-sm font-bold text-ink-subtle">de 5</span></p>
              <p class="text-xs text-ink-subtle" aria-hidden="true">{{ teacher.review_count }} {{ teacher.review_count === 1 ? 'reseña' : 'reseñas' }}</p>
            </div>
            <div v-if="teacher.classes_completed > 0" class="text-center">
              <p class="text-xl font-black text-ink">{{ teacher.classes_completed }}</p>
              <p class="text-xs text-ink-subtle">clases dictadas</p>
            </div>
          </div>

          <!-- Bio -->
          <p v-if="teacher.bio" class="text-sm text-ink-muted mt-4 leading-relaxed">{{ teacher.bio }}</p>
          <p v-else class="text-sm text-ink-subtle mt-4 italic">Este profesor aún no ha completado su presentación.</p>

          <!-- Modality -->
          <div class="flex items-center gap-1.5 mt-4 text-xs text-ink-muted">
            <Icon name="online-class" :size="16" class="text-brand-500" />
            Clases online por videollamada — link generado automáticamente al confirmar
          </div>

          <!-- Código de referido: solo llega en el payload si el visitante
               es el propio profesor o un padre con historial (ver
               TeacherPublicController::referralCodeVisibleTo). No hay v-if
               que ocultar aquí más allá de "¿vino el dato?" — el backend ya
               decidió la visibilidad. isOwnProfile también viene del
               backend (is_own_profile) — antes se aproximaba en el
               frontend con "¿el usuario tiene el rol teacher?", que
               mostraba el texto equivocado si un mismo User tenía rol
               teacher Y había tomado una clase como padre con OTRO
               profesor. -->
          <div v-if="teacher.referral_code" class="mt-4 inline-flex items-center gap-2 bg-brand-50 border border-brand-100 rounded-control px-3 py-2">
            <span class="text-xs text-brand-700">
              {{ teacher.is_own_profile ? 'Tu código — compártelo para que un alumno pueda volver a elegirte:' : 'Tu código con este profesor:' }}
            </span>
            <span class="font-mono tracking-widest text-sm font-bold text-brand-800">{{ teacher.referral_code }}</span>
          </div>

          <!-- CTA: siempre al formulario general, nunca preselecciona a
               este profesor — el padre elige materia, no profesor. -->
          <div class="mt-5">
            <Link v-if="authUser && isParent" :href="route('class-requests.create')"
              class="inline-flex items-center gap-2 min-h-[44px] px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-card shadow-elevation-1 hover:bg-brand-700 active:scale-[0.97] transition-[transform,background-color] duration-micro ease-out-expo focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 whitespace-nowrap">
              Solicitar una clase
              <Icon name="arrow-right" :size="16" />
            </Link>
            <Link v-else-if="!authUser" :href="route('login')"
              class="inline-flex items-center gap-2 min-h-[44px] px-5 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-card shadow-elevation-1 hover:bg-brand-700 active:scale-[0.97] transition-[transform,background-color] duration-micro ease-out-expo focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 whitespace-nowrap">
              Iniciar sesión para solicitar
            </Link>
          </div>
        </div>
      </div>

      <!-- Reviews section -->
      <div>
        <h2 class="text-lg font-black text-ink mb-4">Reseñas verificadas</h2>

        <EmptyState
          v-if="!teacher.reviews?.length"
          title="Profesor nuevo en MOVA"
          description="Aún no tiene reseñas. ¡Sé el primero en calificarlo!"
        >
          <template #icon>
            <Icon name="reviews" :size="32" :stroke-width="1.5" class="text-amber-400" />
          </template>
        </EmptyState>

        <div v-else class="space-y-3">
          <div v-if="teacher.avg_rating" class="bg-amber-50 border border-amber-100 rounded-elevated p-4 flex items-center gap-4">
            <p class="text-4xl font-black text-amber-500">{{ teacher.avg_rating }}<span class="text-lg text-ink-subtle">/5</span></p>
            <div>
              <div class="flex gap-0.5" role="img" :aria-label="`${Math.round(teacher.avg_rating)} de 5 estrellas`">
                <span v-for="n in 5" :key="n" :class="['text-xl', n <= Math.round(teacher.avg_rating) ? 'text-amber-400' : 'text-gray-200']" aria-hidden="true">★</span>
              </div>
              <p class="text-xs text-ink-muted mt-0.5">Basado en {{ teacher.review_count }} {{ teacher.review_count === 1 ? 'reseña verificada' : 'reseñas verificadas' }}</p>
            </div>
          </div>

          <div v-for="r in teacher.reviews" :key="r.id"
            class="bg-surface rounded-card border border-line p-4">
            <div class="flex items-center gap-1 mb-1" role="img" :aria-label="`${r.rating} de 5 estrellas`">
              <span v-for="n in 5" :key="n" :class="['text-sm', n <= r.rating ? 'text-amber-400' : 'text-gray-200']" aria-hidden="true">★</span>
              <span class="text-xs text-ink-subtle ml-2" aria-hidden="true">{{ fmtDate(r.created_at) }}</span>
            </div>
            <p v-if="r.comment" class="text-sm text-ink leading-relaxed">{{ r.comment }}</p>
            <p v-else class="text-xs text-ink-subtle italic">Sin comentario adicional.</p>
          </div>
        </div>
      </div>

      <!-- Trust section -->
      <div class="bg-canvas rounded-elevated border border-line p-5 sm:p-6">
        <h3 class="font-bold text-ink mb-4">¿Por qué confiar en MOVA?</h3>
        <div class="grid sm:grid-cols-3 gap-4">
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 bg-emerald-100 rounded-control flex items-center justify-center text-emerald-600 flex-shrink-0">
              <Icon name="verified-badge" :size="18" />
            </div>
            <div>
              <p class="text-sm font-semibold text-ink">Profesores verificados</p>
              <p class="text-xs text-ink-muted mt-0.5">Cada profesor es revisado por el equipo MOVA antes de aparecer aquí.</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 bg-brand-100 rounded-control flex items-center justify-center text-brand-600 flex-shrink-0">
              <Icon name="online-class" :size="18" />
            </div>
            <div>
              <p class="text-sm font-semibold text-ink">Videollamada automática</p>
              <p class="text-xs text-ink-muted mt-0.5">El enlace de videollamada se genera solo al confirmar la clase.</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 bg-brand-100 rounded-control flex items-center justify-center text-brand-600 flex-shrink-0">
              <Icon name="reminders" :size="18" />
            </div>
            <div>
              <p class="text-sm font-semibold text-ink">Recordatorios incluidos</p>
              <p class="text-xs text-ink-muted mt-0.5">Te avisamos por correo electrónico antes de cada clase.</p>
            </div>
          </div>
        </div>
      </div>

    </div>
  </PublicPageLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import PublicPageLayout from '@/Layouts/PublicPageLayout.vue'
import EmptyState from '@/Components/EmptyState.vue'
import Icon from '@/Components/Icon.vue'

defineProps({ teacher: Object })

const authUser = computed(() => usePage().props.auth?.user ?? null)
const isParent = computed(() => {
  const roles = usePage().props.auth?.user?.roles
  if (!roles) return false
  return Array.isArray(roles) ? roles.includes('parent') : Object.values(roles).includes('parent')
})

// Igual que en Marketplace/Index.vue: un avatar_url presente no garantiza
// que la imagen cargue.
const avatarFailed = ref(false)

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>
