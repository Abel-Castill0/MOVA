<template>
  <div class="relative" ref="root">
    <button ref="trigger" type="button" @click="open = !open" aria-label="Notificaciones"
      :aria-expanded="open" :aria-controls="panelId"
      class="relative flex items-center justify-center w-11 h-11 text-gray-500 hover:text-gray-700 active:bg-gray-100 rounded-full transition-colors">
      <Icon name="notification" :size="20" />
      <span v-if="unread > 0"
        class="absolute top-1.5 right-1.5 w-4 h-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">
        {{ unread > 9 ? '9+' : unread }}
      </span>
    </button>

    <div v-if="open" :id="panelId" role="region" aria-label="Notificaciones recientes" :class="['absolute w-80 max-w-[calc(100vw-2rem)] bg-white rounded-xl border border-gray-200 shadow-lg z-50 overflow-hidden', placementClasses]">
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Notificaciones</span>
        <button v-if="unread > 0" type="button" :disabled="busy" @click="markAll" class="text-xs text-brand-600 hover:underline disabled:opacity-50">Marcar todas</button>
      </div>
      <p v-if="error" role="alert" class="px-4 py-2 text-xs text-red-700 bg-red-50">{{ error }}</p>
      <div class="max-h-72 overflow-y-auto divide-y divide-gray-50">
        <div v-if="!notifications.length && !error" class="px-4 py-6 text-center text-sm text-gray-500">Sin notificaciones</div>
        <button v-for="n in notifications" :key="n.id" type="button"
          :disabled="busy || !!n.read_at"
          @click="markOne(n.id)"
          :aria-label="n.read_at ? `Notificación leída: ${messageFor(n)}` : `Marcar como leída: ${messageFor(n)}`"
          :class="['block w-full text-left px-4 py-3 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-brand-600 transition-colors disabled:cursor-default', !n.read_at ? 'bg-brand-50/40' : '']">
          <p class="text-sm text-gray-800">{{ n.data?.message || n.data?.type?.replace(/_/g, ' ') }}</p>
          <p class="text-xs text-gray-500 mt-0.5">{{ fmtDate(n.created_at) }}</p>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, useId } from 'vue'
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
const trigger = ref(null)
const panelId = useId()
const notifications = ref([])
const unread = ref(0)
const error = ref('')
const busy = ref(false)

function messageFor(notification) {
  return notification.data?.message || notification.data?.type?.replace(/_/g, ' ') || 'Sin asunto'
}

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
  try {
    const { data } = await axios.get('/notifications')
    notifications.value = data.notifications
    unread.value = data.unread_count
    error.value = ''
  } catch {
    error.value = 'No se pudieron cargar las notificaciones. Vuelve a intentarlo.'
  }
}

// Ningún error de red se ignora silenciosamente. Tras marcar, se reconsulta
// el conteo real (puede haber avisos no leídos más antiguos que los 20 visibles).
async function markOne(id) {
  if (busy.value) return
  busy.value = true
  try {
    await axios.post(`/notifications/${id}/read`)
    await load()
  } catch {
    error.value = 'No se pudo marcar la notificación como leída.'
  } finally {
    busy.value = false
  }
}

async function markAll() {
  if (busy.value) return
  busy.value = true
  try {
    await axios.post('/notifications/read-all')
    await load()
  } catch {
    error.value = 'No se pudieron marcar las notificaciones como leídas.'
  } finally {
    busy.value = false
  }
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function onEscape(event) {
  if (event.key === 'Escape' && open.value) {
    open.value = false
    trigger.value?.focus()
  }
}

let interval
onMounted(() => {
  load()
  interval = setInterval(load, 30000)
  window.addEventListener('mova:notification', load)
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onEscape)
})
onUnmounted(() => {
  clearInterval(interval)
  window.removeEventListener('mova:notification', load)
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onEscape)
})
</script>
