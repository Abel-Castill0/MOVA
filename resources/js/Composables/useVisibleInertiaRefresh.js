import { onMounted, onUnmounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

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

  // El websocket anuncia cambios; la fuente de verdad sigue siendo HTTP
  // autorizado. El listener desaparece al desmontar la página.
  function onRealtimeRefresh() {
    if (document.hidden) return
    refresh()
  }

  function refreshOnReturn() {
    if (!document.hidden && Date.now() - lastRefreshAt >= 5000) refresh()
  }

  onMounted(() => {
    mounted = true
    lastRefreshAt = Date.now()
    intervalId = window.setInterval(refresh, intervalMs)
    window.addEventListener('mova:realtime-refresh', onRealtimeRefresh)
    window.addEventListener('focus', refreshOnReturn)
    document.addEventListener('visibilitychange', refreshOnReturn)
  })

  onUnmounted(() => {
    mounted = false
    window.clearInterval(intervalId)
    window.removeEventListener('mova:realtime-refresh', onRealtimeRefresh)
    window.removeEventListener('focus', refreshOnReturn)
    document.removeEventListener('visibilitychange', refreshOnReturn)
  })

  return { refresh, isRefreshing }
}
