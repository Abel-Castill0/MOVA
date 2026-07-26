# MOVA — Reglas del proyecto

Stack: Laravel 10 + Vue 3 + Inertia + Tailwind. Plataforma de tutorías online en Perú (padres, profesores, alumnos menores de edad).

## Diseño (frontend Vue/Tailwind)

Todo componente debe verse elegante, profesional y "caro": profundidad sutil (sombras), tipografía limpia, microinteracciones fluidas, espaciado generoso, bordes redondeados suaves. Nada genérico ni sobrecargado. Animaciones a 60fps. Antes de tocar UI compleja, invoca las skills `ui-ux-pro-max` e `impeccable`.

## Código

- Respeta el patrón ya establecido: controladores delgados + Policies para autorización (`app/Policies/`), transacciones con `lockForUpdate()` en toda mutación financiera, ledger de `credit_transactions` append-only con `idempotency_key` único.
- No introduzcas vulnerabilidades. Cambios sensibles (auth, dinero, datos de menores) requieren una pasada explícita de revisión de seguridad antes de darse por terminados.

## Razonamiento

Antes de proponer cambios complejos o arquitectónicos: observar el código real primero (no asumir), orientarse contra los patrones ya existentes en el repo, decidir explícitamente el enfoque, y recién ejecutar. Si algo no está claro, preguntar antes de tocar código — no adivinar sobre partes críticas (dinero, menores, autenticación).

## SEO

Páginas públicas (`Welcome`, `Marketplace`, perfiles públicos de profesor) deben llevar meta tags, Open Graph y structured data cuando se toquen.

## `/handoff`

Cuando el usuario escriba `/handoff`, generar `docs/SESSION_HANDOFF.md` con: objetivos de la sesión, qué se probó, qué falló, qué se logró, y siguientes pasos concretos.

## Legal / menores de edad

MOVA maneja videollamadas y datos de menores (alumnos). Cualquier cambio en Jitsi, en el manejo de datos de estudiantes, o en el modelo `Student`/`teacher_profiles` debe pasar por una revisión de seguridad explícita antes de mergear.
