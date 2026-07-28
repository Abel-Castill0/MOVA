<template>
  <AppLayout title="Mis ofertas">
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-black text-slate-900">Mis ofertas de clases</h2>
        <Link :href="route('class-offers.create')"
          class="px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-sm shadow-brand-600/20">
          + Nueva oferta
        </Link>
      </div>

      <div v-if="!offers.length" class="text-center py-16 bg-white rounded-2xl border border-gray-100">
        <div class="text-5xl mb-3">📚</div>
        <p class="text-slate-500">No tienes ofertas publicadas</p>
      </div>

      <div v-else class="space-y-3">
        <div v-for="o in offers" :key="o.id"
          class="bg-white rounded-2xl border border-gray-100 p-5 hover:border-brand-300 hover:shadow-md transition-all">
          <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-1">
                <p class="font-semibold text-slate-900">{{ o.title }}</p>
                <span :class="['text-xs px-2 py-0.5 rounded-full font-bold', o.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500']">
                  {{ o.is_active ? 'Activa' : 'Inactiva' }}
                </span>
              </div>
              <p class="text-sm text-brand-600 font-medium">{{ o.subject?.name }}</p>
              <p class="text-sm text-slate-500 mt-1">{{ o.description }}</p>
            </div>
            <div class="flex gap-2 flex-wrap sm:flex-shrink-0">
              <Link :href="route('class-offers.edit', o.id)"
                class="px-3 py-1.5 text-sm font-medium text-brand-600 border border-brand-200 rounded-xl hover:bg-brand-50 transition-colors">Editar</Link>
              <Link :href="route('class-offers.toggle', o.id)" method="post" as="button"
                class="px-3 py-1.5 text-sm font-medium text-slate-600 border border-gray-200 rounded-xl hover:bg-slate-50 transition-colors">
                {{ o.is_active ? 'Desactivar' : 'Activar' }}
              </Link>
              <Link :href="route('class-offers.destroy', o.id)" method="delete" as="button"
                class="px-3 py-1.5 text-sm font-medium text-red-600 border border-red-100 rounded-xl hover:bg-red-50 transition-colors">
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
