import { onMounted, onUnmounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { realtimeStatus } from '@/realtime.js'

/**
 * Inertia no actualiza automáticamente las props de una página abierta en
 * otra sesión. Refresca únicamente las props permitidas cuando la pestaña
 * está visible, y al recuperar el foco si pasaron al menos cinco segundos.
 *
 * No instala un segundo cliente realtime ni expone datos de otras cuentas:
 * cada consulta pasa nuevamente por auth, role y el scope del servidor.
 */
export function useVisibleInertiaRefresh(only, { intervalMs = 30000, shouldRefresh = () => true } = {}) {
  const isRefreshing = ref(false)
  let intervalId
  let mounted = false
  let lastRefreshAt = 0

  function refresh() {
    if (!mounted || document.hidden || isRefreshing.value || !shouldRefresh()) return
    // Con el tiempo real conectado, el sondeo solo queda como red de seguridad (cada ~4 intervalos).
    if (realtimeStatus.value === 'live' && Date.now() - lastRefreshAt < intervalMs * 4) return
    isRefreshing.value = true

    router.reload({
      only,
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        isRefreshing.value = false
        lastRefreshAt = Date.now()
      },
    })
  }

  function refreshOnReturn() {
    if (!document.hidden && Date.now() - lastRefreshAt >= 5000) refresh()
  }

  onMounted(() => {
    mounted = true
    lastRefreshAt = Date.now()
    intervalId = window.setInterval(refresh, intervalMs)
    window.addEventListener('focus', refreshOnReturn)
    document.addEventListener('visibilitychange', refreshOnReturn)
  })

  onUnmounted(() => {
    mounted = false
    window.clearInterval(intervalId)
    window.removeEventListener('focus', refreshOnReturn)
    document.removeEventListener('visibilitychange', refreshOnReturn)
  })

  return { refresh, isRefreshing }
}
