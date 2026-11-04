// Traducción de los slugs de rol de Spatie Permission (en inglés en BD) al
// español que ve el usuario — se necesita en el sidebar de AppLayout.vue y
// en LandingNavbar.vue (mismo dato, dos lugares donde se muestra "con qué
// cuenta estoy").
const ROLE_LABELS = {
  parent: 'Padre',
  teacher: 'Profesor',
  admin: 'Administrador',
}

export function roleLabel(roleSlug) {
  return ROLE_LABELS[roleSlug] ?? roleSlug ?? 'Usuario'
}

// Rol que gobierna la interfaz cuando una cuenta tiene varios (p. ej. admin y parent): el mismo orden que usa el
// menú lateral de AppLayout.vue. El orden de `roles` viene de la base de datos y no es una prioridad.
const ROLE_PRIORITY = ['admin', 'teacher', 'parent']

export function primaryRole(roles) {
  const list = Array.isArray(roles) ? roles : []
  return ROLE_PRIORITY.find(r => list.includes(r)) ?? list[0]
}
