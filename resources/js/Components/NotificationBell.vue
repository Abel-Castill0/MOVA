<template>
  <div class="relative">
    <button @click="open = !open" class="relative p-1 text-gray-500 hover:text-gray-700">
      🔔
      <span v-if="unread > 0"
        class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">
        {{ unread > 9 ? '9+' : unread }}
      </span>
    </button>

    <div v-if="open" class="absolute right-0 bottom-full mb-2 w-80 bg-white rounded-xl border border-gray-200 shadow-lg z-50 overflow-hidden">
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Notificaciones</span>
        <button v-if="unread > 0" @click="markAll" class="text-xs text-indigo-600 hover:underline">Marcar todas</button>
      </div>
      <div class="max-h-72 overflow-y-auto divide-y divide-gray-50">
        <div v-if="!notifications.length" class="px-4 py-6 text-center text-sm text-gray-400">Sin notificaciones</div>
        <div v-for="n in notifications" :key="n.id"
          @click="markOne(n.id)"
          :class="['px-4 py-3 cursor-pointer hover:bg-gray-50 transition-colors', !n.read_at ? 'bg-indigo-50/40' : '']">
          <p class="text-sm text-gray-800">{{ n.data?.message || n.data?.type?.replace(/_/g, ' ') }}</p>
          <p class="text-xs text-gray-400 mt-0.5">{{ fmtDate(n.created_at) }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

const open = ref(false)
const notifications = ref([])
const unread = computed(() => notifications.value.filter(n => !n.read_at).length)

async function load() {
  try {
    const { data } = await axios.get('/notifications')
    notifications.value = data
  } catch {}
}

async function markOne(id) {
  await axios.post(`/notifications/${id}/read`)
  const n = notifications.value.find(x => x.id === id)
  if (n) n.read_at = new Date().toISOString()
}

async function markAll() {
  await axios.post('/notifications/read-all')
  notifications.value.forEach(n => { n.read_at = new Date().toISOString() })
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

let interval
onMounted(() => {
  load()
  interval = setInterval(load, 30000)
  window.addEventListener('mova:notification', load)
})
onUnmounted(() => {
  clearInterval(interval)
  window.removeEventListener('mova:notification', load)
})
</script>
