---
paths:
  - "resources/js/Pages/Welcome.vue"
  - "resources/js/Pages/Marketplace/Index.vue"
  - "resources/js/Pages/Teachers/Show.vue"
---

# SEO — páginas públicas

Estas 3 páginas son indexables y compartibles; cualquier cambio que las toque debe conservar (o añadir si falta):

- `<title>` único y descriptivo de esa página/entidad (no genérico "MOVA").
- `<meta name="description">` específico (ver `Welcome.vue` como referencia ya implementada).
- Open Graph (`og:title`, `og:description`, `og:type`) — `Marketplace/Index.vue` y `Teachers/Show.vue` aún no lo tienen completo, añadir al tocarlas.
- Imagen social (`og:image`) donde exista un asset relevante (foto de perfil del profesor en `Teachers/Show.vue`, banner en `Welcome.vue`).
- Canonical/indexability explícitos si la página admite parámetros de query que no deben indexarse por separado.
- Structured data (JSON-LD) solo si el schema aplica de verdad al contenido (p. ej. `Person`/`Course` en `Teachers/Show.vue`) — no forzarlo donde no calza.
