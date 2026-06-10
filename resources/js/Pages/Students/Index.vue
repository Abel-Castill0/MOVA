<template>
  <AppLayout title="Mis hijos">
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold text-gray-900">Mis hijos</h2>
        <Link :href="route('students.create')"
          class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition-colors">
          + Añadir hijo
        </Link>
      </div>

      <div v-if="!students.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <div class="text-5xl mb-3">🎒</div>
        <p class="text-gray-500 mb-4">Aún no has añadido ningún hijo</p>
        <Link :href="route('students.create')" class="text-indigo-600 hover:underline text-sm">Añadir ahora</Link>
      </div>

      <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="s in students" :key="s.id" class="bg-white rounded-xl border border-gray-200 p-5">
          <div class="flex items-start justify-between">
            <div>
              <p class="font-semibold text-gray-900">{{ s.full_name }}</p>
              <p class="text-sm text-gray-500 capitalize">{{ s.grade_level }}</p>
              <p v-if="s.school" class="text-xs text-gray-400 mt-1">{{ s.school }}</p>
            </div>
            <div class="flex gap-2">
              <Link :href="route('students.edit', s.id)" class="text-xs text-indigo-600 hover:underline">Editar</Link>
              <Link :href="route('students.destroy', s.id)" method="delete" as="button"
                class="text-xs text-red-500 hover:underline"
                @click.prevent="confirmDelete(s.id)">Eliminar</Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({ students: Array })

function confirmDelete(id) {
  if (confirm('¿Eliminar este estudiante?')) {
    router.delete(route('students.destroy', id))
  }
}
</script>
