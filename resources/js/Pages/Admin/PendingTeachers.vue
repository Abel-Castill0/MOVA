<template>
  <AppLayout title="Profesores pendientes">
    <div class="space-y-6">
      <h2 class="text-2xl font-bold text-gray-900">Profesores pendientes de verificación</h2>

      <div v-if="!teachers.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <div class="text-5xl mb-3">✅</div>
        <p class="text-gray-500">No hay profesores pendientes</p>
      </div>
      <div v-else class="space-y-4">
        <div v-for="t in teachers" :key="t.id" class="bg-white rounded-xl border border-gray-200 p-5">
          <div class="flex items-start justify-between gap-4">
            <div class="flex-1">
              <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-700 font-bold flex-shrink-0">
                  {{ t.user?.name?.charAt(0) }}
                </div>
                <div>
                  <p class="font-semibold text-gray-900">{{ t.user?.name }}</p>
                  <p class="text-sm text-gray-500">{{ t.user?.email }}</p>
                </div>
              </div>
              <p class="text-sm text-gray-600 mb-2">{{ t.bio || 'Sin descripción' }}</p>
              <div class="flex items-center gap-2">
                <span class="text-sm font-semibold text-gray-900">€{{ parseFloat(t.hourly_rate).toFixed(2) }}/h</span>
                <span class="text-gray-300">·</span>
                <div class="flex flex-wrap gap-1">
                  <span v-for="s in t.subjects" :key="s.id" class="text-xs bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded">{{ s.name }}</span>
                </div>
              </div>
            </div>
            <div class="flex gap-2 flex-shrink-0">
              <Link :href="route('admin.teachers.verify', t.id)" method="post" as="button"
                class="px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 transition-colors">Verificar</Link>
              <Link :href="route('admin.teachers.reject', t.id)" method="delete" as="button"
                class="px-4 py-2 bg-red-100 text-red-700 text-sm font-semibold rounded-lg hover:bg-red-200 transition-colors">Rechazar</Link>
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
defineProps({ teachers: Array })
</script>
