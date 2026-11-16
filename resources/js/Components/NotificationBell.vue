<template>
  <div class="relative" ref="root">
    <button type="button" @click="open = !open" :aria-label="unread ? `Notificaciones, ${unread} sin leer` : 'Notificaciones' "
      aria-haspopup="true" :aria-expanded="open" aria-controls="notification-panel"
      class="relative flex items-center justify-center w-11 h-11 text-gray-500 hover:text-gray-700 active:bg-gray-100 rounded-full transition-colors">
      <Icon name="notification" :size="20" />
      <span v-if="unread > 0" aria-hidden="true"
        class="absolute top-1 right-1 min-w-5 h-5 px-1 bg-red-600 text-white text-[10px] font-bold rounded-full flex items-center justify-center">
        {{ unread > 9 ? '9+' : unread }}
      </span>
    </button>

    <div v-if="open" id="notification-panel" role="region" aria-label="Lista de notificaciones" :class="['absolute w-80 max-w-[calc(100vw-2rem)] bg-white rounded-xl border border-gray-200 shadow-lg z-50 overflow-hidden', placementClasses]">
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Notificaciones</span>
        <button v-if="unread > 0" type="button" @click="markAll" class="text-xs text-brand-600 hover:underline">Marcar todas</button>
      </div>
      <div class="max-h-72 overflow-y-auto divide-y divide-gray-50">
        <p v-if="actionError" role="alert" class="px-4 py-3 text-sm text-rose-700 bg-rose-50">{{ actionError }}</p>
        <div v-if="loadError" class="px-4 py-3 text-sm text-rose-700 bg-rose-50" role="status">
          No se pudieron actualizar las notificaciones.
          <button type="button" class="underline font-semibold" @click="load">Reintentar</button>
        </div>
        <div v-else-if="!notifications.length" class="px-4 py-6 text-center text-sm text-gray-500">Sin notificaciones</div>
        <button v-for="n in notifications" :key="n.id" type="button"
          @click="markOne(n.id)"
          class="block w-full text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-600"
          :class="['px-4 py-3 hover:bg-gray-50 transition-colors', !n.read_at ? 'bg-brand-50/40' : '']">
          <p class="text-sm text-gray-800">{{ n.data?.message || n.data?.type?.replace(/_/g, ' ') }}</p>
          <p class="text-xs text-gray-500 mt-0.5">{{ fmtDate(n.created_at) }}</p>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import Icon from '@/Components/Icon.vue'

// El componente vive en dos lugares con geometría opuesta: al fondo del
// sidebar (el botón está abajo-izquierda, así que el panel debe abrirse
// hacia arriba anclado a la izquierda) y en la topbar móvil (el botón está
// arriba-derecha, así que el panel debe abrirse hacia abajo anclado a la
// derecha). Anclar siempre a la izquierda rompía el caso de la topbar: el
// panel de 320px se dibujaba mayormente fuera del viewport a la derecha.
const props = defineProps({
  placement: { type: String, default: 'up-left', validator: v => ['up-left', 'down-right'].includes(v) },
})

const placementClasses = computed(() => props.placement === 'down-right'
  ? 'right-0 top-full mt-2 origin-top-right'
  : 'left-0 bottom-full mb-2 origin-bottom-left')

const open = ref(false)
const root = ref(null)
const notifications = ref([])
const loadError = ref(false)
const actionError = ref('')
const totalUnread = ref(null)
const unread = computed(() => totalUnread.value ?? notifications.value.filter(n => !n.read_at).length)

// Click-outside estándar: el listener de document es bubble-phase, así que
// el @click del propio botón ya alternó `open` para cuando este corre. Como
// el botón vive dentro de `root`, contains() lo protege de auto-cerrarse en
// el mismo click que lo abre — no hace falta capture ni setTimeout.
function onDocumentClick(event) {
  if (open.value && root.value && !root.value.contains(event.target)) {
    open.value = false
  }
}

async function load() {
  if (document.hidden) return
  try {
    const { data, headers } = await axios.get('/notifications')
    notifications.value = Array.isArray(data) ? data : []
    const headerCount = Number(headers?.['x-mova-unread-count'])
    totalUnread.value = Number.isInteger(headerCount) && headerCount >= 0
      ? headerCount
      : null
    loadError.value = false
  } catch {
    loadError.value = true
  }
}

// AUDITORÍA (hallazgo real, no solo visual): a diferencia de load(), estas
// dos acciones no tenían try/catch — un fallo de red lanzaba un rechazo de
// promesa sin manejar en la consola. No hay espacio visual en este panel
// compacto para un mensaje de error explícito, así que la corrección
// correcta aquí es no actualizar el estado local si la petición falla (en
// vez de asumir optimistamente que se marcó como leída cuando no fue así).
async function markOne(id) {
  const n = notifications.value.find(x => x.id === id)
  if (!n || n.read_at) return
  actionError.value = ''
  try {
    await axios.post(`/notifications/${id}/read`)
  } catch {
    actionError.value = 'No se pudo marcar la notificación. Reinténtalo.'
    return
  }
  n.read_at = new Date().toISOString()
  if (totalUnread.value !== null) totalUnread.value = Math.max(0, totalUnread.value - 1)
  window.dispatchEvent(new Event('mova:notification-read'))
}

async function markAll() {
  actionError.value = ''
  try {
    await axios.post('/notifications/read-all')
  } catch {
    actionError.value = 'No se pudieron marcar las notificaciones. Reinténtalo.'
    return
  }
  notifications.value.forEach(n => { n.read_at = new Date().toISOString() })
  totalUnread.value = 0
  window.dispatchEvent(new Event('mova:notification-read'))
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-PE', { timeZone: 'America/Lima', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function onEscape(event) {
  if (event.key === 'Escape') open.value = false
}

let interval
onMounted(() => {
  load()
  interval = setInterval(load, 30000)
  window.addEventListener('mova:notification', load)
  window.addEventListener('mova:notification-read', load)
  window.addEventListener('focus', load)
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onEscape)
})
onUnmounted(() => {
  clearInterval(interval)
  window.removeEventListener('mova:notification', load)
  window.removeEventListener('mova:notification-read', load)
  window.removeEventListener('focus', load)
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onEscape)
})
</script>
