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
