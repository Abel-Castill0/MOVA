<template>
  <component :is="layout" title="Buscar profesor">
    <div class="space-y-6">
      <h2 class="text-2xl font-bold text-gray-900">Marketplace de profesores</h2>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap gap-3">
        <select v-model="filters.subject_id" @change="search"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <option value="">Todas las asignaturas</option>
          <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <select v-model="filters.level" @change="search"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <option value="">Todos los niveles</option>
          <option value="primaria">Primaria</option>
          <option value="secundaria">Secundaria</option>
          <option value="universidad">Universidad</option>
        </select>
        <input v-model="filters.max_rate" @change="search" type="number" placeholder="Precio máx. €/h"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-36 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
      </div>

      <div v-if="!offers.data?.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <p class="text-gray-400">No se encontraron ofertas disponibles</p>
      </div>

      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div v-for="o in offers.data" :key="o.id" class="bg-white rounded-xl border border-gray-200 p-5 hover:border-indigo-300 transition-colors">
          <div class="flex items-start justify-between mb-3">
            <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-700 font-bold flex-shrink-0">
              {{ o.teacher_profile?.user?.name?.charAt(0) }}
            </div>
            <span class="text-lg font-bold text-gray-900">€{{ parseFloat(o.specific_rate ?? o.teacher_profile?.hourly_rate ?? 0).toFixed(2) }}/h</span>
          </div>
          <p class="font-semibold text-gray-900 mb-1">{{ o.title }}</p>
          <p class="text-xs text-indigo-600 font-medium mb-2">{{ o.subject?.name }}</p>
          <p class="text-sm text-gray-500 line-clamp-2 mb-4">{{ o.description }}</p>
          <p class="text-sm text-gray-700 font-medium mb-3">{{ o.teacher_profile?.user?.name }}</p>
          <!-- Authenticated: request class; Guest: redirect to login -->
          <Link v-if="authUser" :href="route('class-requests.create', { offer_id: o.id })"
            class="block text-center px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition-colors">
            Solicitar clase
          </Link>
          <Link v-else :href="route('login')"
            class="block text-center px-4 py-2 border border-indigo-600 text-indigo-600 text-sm font-semibold rounded-lg hover:bg-indigo-50 transition-colors">
            Inicia sesión para solicitar
          </Link>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="offers.last_page > 1" class="flex gap-1 flex-wrap">
        <component v-for="link in offers.links" :key="link.label"
          :is="link.url ? Link : 'span'"
          :href="link.url"
          v-html="link.label"
          :class="['px-3 py-1.5 rounded-lg text-sm transition-colors', link.active ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-indigo-300', !link.url && 'opacity-40 pointer-events-none']"
        />
      </div>
    </div>
  </component>
</template>

<script setup>
import { reactive, computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'

const props = defineProps({ offers: Object, subjects: Array, filters: Object })

const authUser = computed(() => usePage().props.auth?.user ?? null)
const layout   = computed(() => authUser.value ? AppLayout : GuestLayout)

const filters = reactive({
  subject_id: props.filters?.subject_id ?? '',
  level: props.filters?.level ?? '',
  max_rate: props.filters?.max_rate ?? '',
})

function search() {
  router.get(route('marketplace'), filters, { preserveState: true, replace: true })
}
</script>
