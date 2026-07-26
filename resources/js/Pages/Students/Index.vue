<template>
  <AppLayout title="Mis hijos">
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-black text-slate-900">Mis hijos</h2>
        <Link :href="route('students.create')"
          class="px-4 py-2.5 bg-brand-600 text-white text-sm font-bold rounded-xl hover:bg-brand-700 active:scale-95 transition-all shadow-sm shadow-brand-600/20">
          + Añadir hijo
        </Link>
      </div>

      <div v-if="!students.length" class="text-center py-16 bg-white rounded-2xl border border-gray-100">
        <div class="text-5xl mb-3">🎒</div>
        <p class="text-slate-500 mb-4">Aún no has añadido ningún hijo</p>
        <Link :href="route('students.create')" class="text-brand-600 hover:underline text-sm font-medium">Añadir ahora</Link>
      </div>

      <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="s in students" :key="s.id"
          class="bg-white rounded-2xl border border-gray-100 p-5 hover:border-brand-300 hover:shadow-md transition-all">
          <div class="flex items-start justify-between">
            <div>
              <p class="font-semibold text-slate-900">{{ s.full_name }}</p>
              <p class="text-sm text-slate-500 capitalize">{{ s.grade_level }}</p>
              <p v-if="s.school" class="text-xs text-slate-400 mt-1">{{ s.school }}</p>
            </div>
            <div class="flex gap-1">
              <Link :href="route('students.edit', s.id)"
                class="text-xs text-brand-600 hover:text-brand-800 hover:bg-brand-50 px-2.5 py-1.5 rounded-lg transition-colors font-medium">
                Editar
              </Link>
              <Link :href="route('students.destroy', s.id)" method="delete" as="button"
                class="text-xs text-red-500 hover:text-red-700 hover:bg-red-50 px-2.5 py-1.5 rounded-lg transition-colors font-medium"
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
