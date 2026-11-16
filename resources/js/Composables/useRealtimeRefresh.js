import { onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Cuando llega una señal de tiempo real de alguno de los temas indicados, vuelve a pedir al servidor SOLO las props
 * de la página que dependen de ellos (partial reload de Inertia). La señal no trae datos: lo que se muestra sale siempre
 * de una petición autenticada, así que no hay forma de ver datos de otra cuenta.
 *
 * @param {Record<string, string[]>} topicProps  tema → props de Inertia a refrescar
 * @param {{shouldRefresh?: () => boolean}} [options]
 *
 * La importación del módulo de tiempo real es perezosa: sin sesión o con el tiempo real apagado no descarga nada.
 */
export function useRealtimeRefresh(topicProps, { shouldRefresh = () => true } = {}) {
  let busy = false
  let again = false
  const unsubscribers = []
  let disposed = false

  function reload(only) {
    if (busy) {
      again = true

      return
    }
    busy = true
    router.reload({
      only: [...only],
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        busy = false
        if (again && !disposed) {
          again = false
          reload(only)
        }
      },
    })
  }

  import('@/realtime.js').then(({ onSignal }) => {
    if (disposed) return
    for (const topic of Object.keys(topicProps)) {
      unsubscribers.push(onSignal(topic, (topics) => {
        if (document.hidden || !shouldRefresh()) return
        const only = new Set()
        for (const t of topics) (topicProps[t] ?? []).forEach((p) => only.add(p))
        if (only.size) reload(only)
      }))
    }
  })

  onUnmounted(() => {
    disposed = true
    unsubscribers.forEach((off) => off())
  })
}
