<template>
  <AppLayout title="Solicitudes recibidas">
    <div class="space-y-6">
      <h2 class="text-2xl font-bold text-gray-900">Solicitudes abiertas</h2>

      <div v-if="!requests.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <div class="text-5xl mb-3">📋</div>
        <p class="text-gray-400">No hay solicitudes abiertas</p>
      </div>

      <div v-else class="space-y-3">
        <div v-for="r in requests" :key="r.id" class="bg-white rounded-xl border border-gray-200 p-5">
          <div class="flex items-start justify-between gap-4">
            <div class="flex-1">
              <p class="font-semibold text-gray-900">{{ r.subject?.name }}</p>
              <p class="text-sm text-gray-500">Alumno: {{ r.student?.first_name }} {{ r.student?.last_name }}</p>
              <p class="text-sm text-gray-600 mt-2 line-clamp-2">{{ r.help_needed }}</p>
              <div v-if="r.preferred_times?.length" class="mt-2 flex flex-wrap gap-1">
                <span v-for="t in r.preferred_times" :key="t" class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ t.replace(/_/g, ' ') }}</span>
              </div>
            </div>
            <Link :href="route('teacher.requests.accept', r.id)"
              class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 flex-shrink-0">
              Aceptar
            </Link>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
defineProps({ requests: Array })
</script>
