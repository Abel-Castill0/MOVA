/**
 * Responsabilidad única: leer la preferencia de tema y escribir
 * data-theme en <html>. No decide ningún color — esa es la responsabilidad
 * exclusiva de las variables CSS en resources/css/app.css. Ningún
 * componente debe leer/escribir el tema por su cuenta; todos pasan por aquí,
 * para que exista una sola fuente de verdad del estado (evita el escenario
 * que el plan de rediseño marcó como riesgo: tokens CSS + clases dark: +
 * variables duplicadas compitiendo entre sí).
 *
 * Persistencia: localStorage, con manejo explícito de los casos en los que
 * puede fallar o venir vacío (ventana privada, storage bloqueado) — nunca
 * asumir que existe.
 */
import { ref, watch } from 'vue'

const STORAGE_KEY = 'mova-theme'

/** @type {'light' | 'dark' | 'system'} */
const theme = ref(readStoredTheme())

function readStoredTheme() {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY)
    if (stored === 'light' || stored === 'dark') return stored
  } catch {
    // localStorage inaccesible (ventana privada, storage bloqueado por
    // política) — se degrada a "system" sin romper la app.
  }
  return 'system'
}

function applyTheme(value) {
  const root = document.documentElement
  if (value === 'system') {
    root.removeAttribute('data-theme')
  } else {
    root.setAttribute('data-theme', value)
  }
}

function persistTheme(value) {
  try {
    if (value === 'system') {
      window.localStorage.removeItem(STORAGE_KEY)
    } else {
      window.localStorage.setItem(STORAGE_KEY, value)
    }
  } catch {
    // Sin persistencia entre sesiones, pero el tema sigue funcionando en
    // la sesión actual — nunca lanzar por esto.
  }
}

// Aplicar inmediatamente al cargar el módulo (complementa, no sustituye, el
// script inline de app.blade.php que evita el flash antes del primer paint).
applyTheme(theme.value)

watch(theme, (value) => {
  applyTheme(value)
  persistTheme(value)
})

export function useTheme() {
  function setTheme(value) {
    if (value !== 'light' && value !== 'dark' && value !== 'system') return
    theme.value = value
  }

  function toggleTheme() {
    const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
    const current = theme.value === 'system' ? (prefersDark ? 'dark' : 'light') : theme.value
    setTheme(current === 'dark' ? 'light' : 'dark')
  }

  return { theme, setTheme, toggleTheme }
}
