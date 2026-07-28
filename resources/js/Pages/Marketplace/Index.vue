<template>
  <Head>
    <meta name="description" content="Explora profesores particulares verificados por MOVA. Filtra por materia, nivel y precio, y solicita tu clase en línea hoy mismo." />
  </Head>
  <component :is="layout" title="Buscar profesor">
    <div class="space-y-6">

      <!-- Header -->
      <div>
        <h2 class="text-2xl font-black text-slate-900">Encuentra tu profesor ideal</h2>
        <p class="text-sm text-slate-500 mt-1">Profesores verificados por MOVA, clases online con videollamada incluida</p>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5">
        <div class="flex flex-col sm:flex-row gap-3">
          <!-- Search -->
          <div class="relative flex-1">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
            </svg>
            <input
              v-model="filters.search"
              @input="debouncedSearch"
              type="text"
              placeholder="Buscar profesor o materia…"
              class="w-full pl-9 pr-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
            />
          </div>

          <!-- Subject -->
          <select
            v-model="filters.subject_id"
            @change="search"
            class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition bg-white sm:w-48"
          >
            <option value="">Todas las materias</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>

          <!-- Level -->
          <select
            v-model="filters.level"
            @change="search"
            class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition bg-white sm:w-40"
          >
            <option value="">Todos los niveles</option>
            <option value="primaria">Primaria</option>
            <option value="secundaria">Secundaria</option>
            <option value="universidad">Universidad</option>
          </select>

          <!-- Max rate -->
          <input
            v-model="filters.max_rate"
            @change="search"
            type="number"
            min="0"
            placeholder="Precio máx."
            class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition w-full sm:w-36"
          />

          <!-- Clear -->
          <button
            v-if="hasFilters"
            @click="clearFilters"
            class="flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium text-slate-500 hover:text-slate-800 border border-gray-200 rounded-xl hover:border-slate-300 transition whitespace-nowrap"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            Limpiar
          </button>
        </div>

        <!-- Diagnostic banner -->
        <div class="mt-3 pt-3 border-t border-gray-100 flex flex-col sm:flex-row sm:items-center gap-3">
          <p class="text-sm text-slate-500 flex-1">💡 <strong>¿No sabes cuál elegir?</strong> Responde 5 preguntas y te recomendamos profesores.</p>
          <Link v-if="$page.props.auth?.user" :href="route('diagnostics.create')"
            class="inline-block px-4 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs hover:bg-brand-700 transition-colors whitespace-nowrap">
            Diagnóstico rápido →
          </Link>
        </div>

        <!-- Active filter chips -->
        <div v-if="hasFilters" class="flex flex-wrap gap-2 mt-3 pt-3 border-t border-gray-100">
          <span v-if="filters.search" class="inline-flex items-center gap-1 px-2.5 py-1 bg-brand-50 text-brand-700 text-xs font-medium rounded-lg">
            "{{ filters.search }}"
            <button @click="filters.search = ''; search()" class="hover:text-brand-900">&times;</button>
          </span>
          <span v-if="filters.subject_id" class="inline-flex items-center gap-1 px-2.5 py-1 bg-brand-50 text-brand-700 text-xs font-medium rounded-lg">
            {{ subjectName(filters.subject_id) }}
            <button @click="filters.subject_id = ''; search()" class="hover:text-brand-900">&times;</button>
          </span>
          <span v-if="filters.level" class="inline-flex items-center gap-1 px-2.5 py-1 bg-brand-50 text-brand-700 text-xs font-medium rounded-lg capitalize">
            {{ filters.level }}
            <button @click="filters.level = ''; search()" class="hover:text-brand-900">&times;</button>
          </span>
          <span v-if="filters.max_rate" class="inline-flex items-center gap-1 px-2.5 py-1 bg-brand-50 text-brand-700 text-xs font-medium rounded-lg">
            Hasta S/ {{ filters.max_rate }}/h
            <button @click="filters.max_rate = ''; search()" class="hover:text-brand-900">&times;</button>
          </span>
        </div>
      </div>

      <!-- Results count -->
      <p v-if="offers.total !== undefined" class="text-sm text-slate-500">
        {{ offers.total }} {{ offers.total === 1 ? 'oferta encontrada' : 'ofertas encontradas' }}
      </p>

      <!-- Empty state -->
      <div v-if="!offers.data?.length" class="bg-white rounded-2xl border border-gray-100 py-16 px-6 text-center">
        <div class="text-5xl mb-4">🔍</div>
        <p class="text-lg font-bold text-slate-900 mb-1">No encontramos profesores</p>
        <p class="text-sm text-slate-500 mb-5">
          {{ hasFilters
            ? 'Prueba cambiando la materia, el nivel o el rango de precio.'
            : 'Aún no hay profesores disponibles. Vuelve pronto.' }}
        </p>
        <button v-if="hasFilters" @click="clearFilters"
          class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-xl hover:bg-brand-700 transition">
          Limpiar filtros
        </button>
      </div>

      <!-- Grid of offers -->
      <div v-else class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <div
          v-for="o in offers.data"
          :key="o.id"
          class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-brand-200 transition-all flex flex-col"
        >
          <!-- Card header -->
          <div class="p-5 flex-1">
            <!-- Teacher row -->
            <div class="flex items-center gap-3 mb-4">
              <div class="w-10 h-10 bg-gradient-to-br from-brand-500 to-indigo-600 rounded-xl flex items-center justify-center text-white font-black text-sm flex-shrink-0">
                {{ o.teacher_profile?.user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
              </div>
              <div class="min-w-0">
                <p class="font-bold text-slate-900 text-sm truncate">{{ o.teacher_profile?.user?.name }}</p>
                <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                  </svg>
                  Verificado por MOVA
                </span>
              </div>
              <div class="ml-auto flex-shrink-0 text-right">
                <p class="font-black text-slate-900 text-base">S/ {{ rate(o) }}</p>
                <p class="text-xs text-slate-400">/hora</p>
              </div>
            </div>

            <!-- Rating badge -->
            <div v-if="teacherAvgRating(o)" class="flex items-center gap-1 mb-2 -mt-1">
              <span class="text-amber-400 text-sm">★</span>
              <span class="text-xs font-semibold text-slate-700">{{ teacherAvgRating(o) }}</span>
              <span class="text-xs text-slate-400">({{ teacherReviewCount(o) }} {{ teacherReviewCount(o) === 1 ? 'reseña' : 'reseñas' }})</span>
            </div>

            <!-- Offer info -->
            <div class="mb-3">
              <span class="inline-block px-2 py-0.5 bg-brand-50 text-brand-700 text-xs font-semibold rounded-lg mb-2">
                {{ o.subject?.name }}
              </span>
              <p class="font-semibold text-slate-900 text-sm leading-snug">{{ o.title }}</p>
              <p v-if="o.description" class="text-xs text-slate-500 mt-1 line-clamp-2">{{ o.description }}</p>
            </div>

            <!-- Modality badge -->
            <div class="flex items-center gap-1.5 text-xs text-slate-400">
              <svg class="w-3.5 h-3.5 text-brand-500" fill="currentColor" viewBox="0 0 20 20">
                <path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                <path d="M14 6a2 2 0 012-2h2a2 2 0 012 2v8a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z"/>
              </svg>
              Clase online por videollamada
            </div>

            <!-- Mentorship slots -->
            <div
              :class="[
                'mt-3 inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold',
                hasMentorshipSlots(o)
                  ? 'bg-emerald-50 text-emerald-700'
                  : 'bg-slate-100 text-slate-500'
              ]"
            >
              <template v-if="hasMentorshipSlots(o)">
                Cupos de seguimiento continuo: {{ mentorshipSlotsAvailable(o) }} disponibles
              </template>
              <template v-else>
                Agenda Llena - Sin cupos disponibles
              </template>
            </div>
          </div>

          <!-- Card footer -->
          <div class="px-5 pb-5 flex flex-col gap-2">
            <!-- View profile -->
            <Link
              :href="route('teachers.show', o.teacher_profile_id)"
              class="block text-center px-4 py-2 border border-brand-200 text-brand-700 text-sm font-semibold rounded-xl hover:bg-brand-50 transition-colors"
            >
              Ver perfil del profesor
            </Link>

            <!-- CTA -->
            <template v-if="authUser">
              <template v-if="isParent">
                <Link :href="route('class-requests.create', { offer_id: o.id })"
                  class="block text-center px-4 py-2 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 transition-colors shadow-sm shadow-brand-600/20">
                  Solicitar clase
                </Link>
                <Link v-if="hasMentorshipSlots(o)" :href="route('class-requests.create', { offer_id: o.id, is_mentorship: 1 })"
                  class="block text-center px-4 py-2 border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm font-bold rounded-xl hover:bg-emerald-100 transition-colors">
                  Solicitar acompañamiento
                </Link>
                <span v-else class="block text-center px-4 py-2 bg-slate-100 text-slate-400 text-sm font-medium rounded-xl cursor-not-allowed">
                  Agenda Llena - Sin cupos disponibles
                </span>
              </template>
              <template v-else>
                <span class="block text-center px-4 py-2 bg-slate-100 text-slate-400 text-sm font-medium rounded-xl cursor-default">
                  Solo padres pueden solicitar
                </span>
              </template>
            </template>
            <template v-else>
              <Link :href="route('login')"
                class="block text-center px-4 py-2 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 transition-colors">
                Iniciar sesión para solicitar
              </Link>
            </template>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="offers.last_page > 1" class="flex gap-1.5 flex-wrap justify-center">
        <component
          v-for="link in offers.links"
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
  </component>
</template>

<script setup>
import { reactive, computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'

const props = defineProps({ offers: Object, subjects: Array, filters: Object })

const authUser = computed(() => usePage().props.auth?.user ?? null)
const isParent = computed(() => {
  const roles = usePage().props.auth?.user?.roles
  if (!roles) return false
  return Array.isArray(roles) ? roles.includes('parent') : Object.values(roles).includes('parent')
})
const layout   = computed(() => authUser.value ? AppLayout : GuestLayout)

const filters = reactive({
  search:     props.filters?.search     ?? '',
  subject_id: props.filters?.subject_id ?? '',
  level:      props.filters?.level      ?? '',
  max_rate:   props.filters?.max_rate   ?? '',
})

const hasFilters = computed(() =>
  filters.search || filters.subject_id || filters.level || filters.max_rate
)

function search() {
  router.get(route('marketplace'), filters, { preserveState: true, replace: true })
}

let debounceTimer = null
function debouncedSearch() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(search, 350)
}

function clearFilters() {
  filters.search = ''
  filters.subject_id = ''
  filters.level = ''
  filters.max_rate = ''
  search()
}

function rate(offer) {
  const v = offer.specific_rate ?? offer.teacher_profile?.hourly_rate ?? 0
  return parseFloat(v).toFixed(0)
}

function subjectName(id) {
  return props.subjects?.find(s => String(s.id) === String(id))?.name ?? ''
}

function teacherAvgRating(offer) {
  const reviews = offer.teacher_profile?.visible_reviews ?? []
  if (!reviews.length) return null
  const avg = reviews.reduce((sum, r) => sum + r.rating, 0) / reviews.length
  return avg.toFixed(1)
}

function teacherReviewCount(offer) {
  return offer.teacher_profile?.visible_reviews?.length ?? 0
}

function mentorshipSlotsAvailable(offer) {
  const total = Number(offer.teacher_profile?.mentorship_slots_total ?? 0)
  const taken = Number(offer.teacher_profile?.mentorship_slots_taken ?? 0)
  return Math.max(total - taken, 0)
}

function hasMentorshipSlots(offer) {
  return mentorshipSlotsAvailable(offer) > 0
}
</script>
