// Safari en modo privado, políticas del navegador o un iframe restringido
// pueden lanzar SecurityError incluso al acceder a window.localStorage.
export function readLocalPreference(key) {
  if (typeof window === 'undefined') return null
  try {
    return window.localStorage.getItem(key)
  } catch {
    return null
  }
}

export function writeLocalPreference(key, value) {
  if (typeof window === 'undefined') return false
  try {
    window.localStorage.setItem(key, value)
    return true
  } catch {
    return false
  }
}
