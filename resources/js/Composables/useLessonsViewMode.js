import { ref, watch } from 'vue'

// Preferencia compartida "Lista"/"Calendario" entre las vistas de clases del
// padre y del profesor. Es intencional que padre y profesor compartan la
// misma clave de localStorage: es una preferencia de interfaz ("¿cómo
// prefiero ver mis clases?"), no un dato por rol.
const STORAGE_KEY = 'mova_lessons_view'

/**
 * Estado y accesibilidad de las pestañas "Lista | Calendario" que usan
 * Lessons/ParentIndex.vue y Lessons/TeacherIndex.vue. Se extrae a composable
 * (en vez de duplicar en ambas páginas) porque la persistencia en
 * localStorage y la navegación por teclado deben comportarse de forma
 * idéntica en los dos roles — divergir aquí sería un bug silencioso.
 */
export function useLessonsViewMode() {
  const stored = typeof window !== 'undefined' ? window.localStorage.getItem(STORAGE_KEY) : null
  const viewMode = ref(stored === 'calendar' ? 'calendar' : 'list')

  watch(viewMode, (value) => {
    window.localStorage.setItem(STORAGE_KEY, value)
  })

  const tabListRef = ref(null)
  const tabCalendarRef = ref(null)

  // Patrón APG de tabs con solo 2 pestañas: ←/→ alternan entre ambas y
  // mueven el foco a la pestaña recién activada (roving tabindex, ya
  // reflejado en el :tabindex="viewMode === '...' ? 0 : -1" del template).
  function onTabsKeydown(event) {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
    event.preventDefault()
    viewMode.value = viewMode.value === 'list' ? 'calendar' : 'list'
    const target = viewMode.value === 'list' ? tabListRef : tabCalendarRef
    target.value?.focus()
  }

  return { viewMode, tabListRef, tabCalendarRef, onTabsKeydown }
}
