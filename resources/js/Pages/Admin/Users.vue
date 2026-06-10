<template>
  <AppLayout title="Usuarios">
    <div class="space-y-6">
      <h2 class="text-2xl font-bold text-gray-900">Usuarios registrados</h2>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Nombre</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Email</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Teléfono</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Rol</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Registro</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="u in users.data" :key="u.id" class="hover:bg-gray-50">
              <td class="px-5 py-3.5 text-sm font-medium text-gray-900">{{ u.name }}</td>
              <td class="px-5 py-3.5 text-sm text-gray-600">{{ u.email }}</td>
              <td class="px-5 py-3.5 text-sm text-gray-500">{{ u.phone ?? '–' }}</td>
              <td class="px-5 py-3.5">
                <span v-for="r in u.roles" :key="r.name" class="inline-block text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full mr-1">{{ r.name }}</span>
              </td>
              <td class="px-5 py-3.5 text-sm text-gray-400">{{ fmtDate(u.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="users.last_page > 1" class="flex gap-1">
        <component v-for="link in users.links" :key="link.label"
          :is="link.url ? Link : 'span'"
          :href="link.url"
          v-html="link.label"
          :class="['px-3 py-1.5 rounded-lg text-sm transition-colors', link.active ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-indigo-300', !link.url && 'opacity-40 pointer-events-none']"
        />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
defineProps({ users: Object })
function fmtDate(d) { return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' }) }
</script>
