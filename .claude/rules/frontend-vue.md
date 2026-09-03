---
paths:
  - "resources/js/**/*.vue"
  - "resources/js/**/*.js"
  - "resources/js/**/*.ts"
---

# Frontend (Vue/Inertia)

Premium = claridad + confianza + jerarquía + espaciado + tipografía.
NO es: glow, parallax, partículas, ni animación decorativa pesada por defecto.

- Responsive real (mobile-first); nada que solo se probó en desktop.
- Teclado y foco: todo interactivo es alcanzable y operable sin mouse, con foco visible.
- Labels asociados a sus inputs (no solo placeholder).
- `aria-live` en estados async importantes (guardado, error de pago, carga).
- Respeta `prefers-reduced-motion`.
- Limpia timers, listeners e instancias de SDK al desmontar (p. ej. `useJitsiMeet.js`, Mercado Pago Bricks) — sin fugas entre navegaciones de Inertia.
- SDKs pesados específicos de una ruta se cargan lazy, no en el bundle global.
- Evita layout shift: reserva espacio para imágenes/contenido async antes de que cargue.

## Herramientas para trabajo visual no trivial

- Usa `impeccable` cuando aporte valor real al diseño.
- Usa `web-design-guidelines` como auditoría final dirigida de la UI ya construida.
- NO uses `ui-ux-pro-max` por defecto ni invoques varias skills de diseño solapadas para la misma tarea.
