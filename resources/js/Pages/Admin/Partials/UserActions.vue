<template>
  <div v-if="actionable" class="flex flex-wrap gap-2">
    <button v-if="!user.suspended_at" type="button"
      class="min-h-[44px] px-3 text-xs font-semibold text-amber-800 bg-amber-50 hover:bg-amber-100 rounded-lg transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-600"
      :aria-label="`Suspender a ${user.name}`" @click="$emit('suspend', user)">
      Suspender
    </button>
    <button v-else type="button" :disabled="busy"
      class="min-h-[44px] px-3 text-xs font-semibold text-green-800 bg-green-50 hover:bg-green-100 rounded-lg transition-colors disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600"
      :aria-label="`Reactivar a ${user.name}`" @click="unsuspend">
      {{ busy ? 'Reactivando…' : 'Reactivar' }}
    </button>
    <button type="button"
      class="min-h-[44px] px-3 text-xs font-semibold text-red-700 bg-red-50 hover:bg-red-100 rounded-lg transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600"
      :aria-label="`Eliminar la cuenta de ${user.name}`" @click="$emit('erase', user)">
      Eliminar
    </button>
  </div>
  <span v-else class="text-xs text-gray-500">{{ isAdmin ? 'Administrador' : '—' }}</span>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({ user: { type: Object, required: true } })
defineEmits(['suspend', 'erase'])

const busy = ref(false)
const isAdmin = computed(() => props.user.roles?.some((r) => r.name === 'admin'))
const actionable = computed(() => !isAdmin.value && !props.user.account_closed_at && !props.user.account_erased_at)

function unsuspend() {
  if (busy.value) return
  busy.value = true
  router.post(route('admin.users.unsuspend', props.user.id), {}, { preserveScroll: true, onFinish: () => { busy.value = false } })
}
</script>
