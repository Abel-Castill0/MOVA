<template>
  <AppLayout title="Mis solicitudes">
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold text-gray-900">Solicitudes de clase</h2>
        <Link :href="route('class-requests.create')"
          class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700">
          + Nueva solicitud
        </Link>
      </div>

      <div v-if="!requests.length" class="text-center py-16 bg-white rounded-xl border border-gray-200">
        <p class="text-gray-400">Sin solicitudes</p>
      </div>

      <div v-else class="space-y-3">
        <div v-for="r in requests" :key="r.id" class="bg-white rounded-xl border border-gray-200 p-5">
          <div class="flex items-start justify-between gap-4">
            <div class="flex-1">
              <div class="flex items-center gap-2 mb-1">
                <p class="font-semibold text-gray-900">{{ r.subject?.name }}</p>
                <StatusBadge :status="r.status" />
              </div>
              <p class="text-sm text-gray-500">{{ r.student?.first_name }} {{ r.student?.last_name }}</p>
              <p class="text-sm text-gray-400 mt-1 line-clamp-2">{{ r.help_needed }}</p>
              <p v-if="r.class_offer" class="text-xs text-indigo-600 mt-1">
                Prof. {{ r.class_offer?.teacher_profile?.user?.name }}
              </p>
              <div v-if="r.status === 'teacher_rejected' && r.teacher_rejection_reason"
                class="mt-2 text-xs text-red-600 bg-red-50 rounded px-2 py-1">
                El profesor rechazó: {{ r.teacher_rejection_reason }}
              </div>
            </div>
            <div v-if="r.status === 'pending_parent_approval'" class="flex gap-2">
              <Link :href="route('class-requests.approve', r.id)" method="post" as="button"
                class="px-3 py-1.5 bg-green-600 text-white text-xs font-semibold rounded-lg hover:bg-green-700">
                Aprobar
              </Link>
              <Link :href="route('class-requests.reject', r.id)" method="post" as="button"
                class="px-3 py-1.5 bg-red-100 text-red-700 text-xs font-semibold rounded-lg hover:bg-red-200">
                Rechazar
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
import StatusBadge from '@/Components/StatusBadge.vue'
defineProps({ requests: Array })
</script>
