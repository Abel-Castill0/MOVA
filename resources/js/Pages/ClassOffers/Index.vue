<template>
  <AppLayout title="Mis ofertas">
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold text-gray-900">Mis ofertas de clases</h2>
        <Link :href="route('class-offers.create')"
          class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition-colors">
          + Nueva oferta
        </Link>
      </div>

      <div v-if="!offers.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <div class="text-5xl mb-3">📚</div>
        <p class="text-gray-500">No tienes ofertas publicadas</p>
      </div>

      <div v-else class="space-y-3">
        <div v-for="o in offers" :key="o.id" class="bg-white rounded-xl border border-gray-200 p-5">
          <div class="flex items-start justify-between gap-4">
            <div class="flex-1">
              <div class="flex items-center gap-2 mb-1">
                <p class="font-semibold text-gray-900">{{ o.title }}</p>
                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', o.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">
                  {{ o.is_active ? 'Activa' : 'Inactiva' }}
                </span>
              </div>
              <p class="text-sm text-indigo-600 font-medium">{{ o.subject?.name }}</p>
              <p class="text-sm text-gray-500 mt-1">{{ o.description }}</p>
            </div>
            <div class="flex gap-2 flex-shrink-0">
              <Link :href="route('class-offers.edit', o.id)" class="px-3 py-1.5 text-sm text-indigo-600 border border-indigo-200 rounded-lg hover:bg-indigo-50">Editar</Link>
              <Link :href="route('class-offers.toggle', o.id)" method="post" as="button"
                class="px-3 py-1.5 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">
                {{ o.is_active ? 'Desactivar' : 'Activar' }}
              </Link>
              <Link :href="route('class-offers.destroy', o.id)" method="delete" as="button"
                class="px-3 py-1.5 text-sm text-red-600 border border-red-100 rounded-lg hover:bg-red-50">
                Eliminar
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
defineProps({ offers: Array })
</script>
