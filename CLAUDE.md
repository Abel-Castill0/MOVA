# MOVA — Reglas del proyecto

Stack: Laravel 10 + Vue 3 + Inertia + Tailwind. Plataforma de tutorías online en Perú (padres, profesores, alumnos menores de edad).

---

## Reglas que SIEMPRE aplican (en cualquier tarea, sin excepción)

### Código
- Respeta el patrón ya establecido: controladores delgados + Policies para autorización (`app/Policies/`), transacciones con `lockForUpdate()` en toda mutación financiera, ledger de `credit_transactions` append-only con `idempotency_key` único.
- No introduzcas vulnerabilidades.

### Razonamiento
Antes de proponer cambios complejos o arquitectónicos: observar el código real primero (no asumir), orientarse contra los patrones ya existentes en el repo, decidir explícitamente el enfoque, y recién ejecutar. Si algo no está claro, preguntar antes de tocar código — no adivinar sobre partes críticas (dinero, menores, autenticación).

### Diseño (frontend Vue/Tailwind)
Todo componente debe verse elegante, profesional y "caro": profundidad sutil (sombras), tipografía limpia, microinteracciones fluidas, espaciado generoso, bordes redondeados suaves. Nada genérico ni sobrecargado. Animaciones a 60fps.

---

## Reglas CONDICIONALES (aplican solo cuando la tarea toca el área indicada)

### Si tocas UI compleja (nueva página, rediseño, componente visual no trivial)
Invoca las skills `ui-ux-pro-max` e `impeccable` antes de escribir el componente.

### Si tocas cambios sensibles (auth, dinero, datos de menores)
Requieren una pasada explícita de revisión de seguridad antes de darse por terminados — no basta con que los tests pasen.

### Si tocas Jitsi, el manejo de datos de estudiantes, o el modelo `Student`/`teacher_profiles`
Debe pasar por una revisión de seguridad explícita antes de mergear (MOVA maneja videollamadas y datos de menores).

### Si tocas páginas públicas (`Welcome`, `Marketplace`, perfiles públicos de profesor)
Deben llevar meta tags, Open Graph y structured data cuando se toquen.

### Si el usuario escribe `/handoff`
Generar `docs/SESSION_HANDOFF.md` con: objetivos de la sesión, qué se probó, qué falló, qué se logró, y siguientes pasos concretos.

### Si haces una auditoría, revisión de seguridad, o cualquier afirmación de tipo "esto ya está arreglado/verificado" — Audit Snapshot Contract
Una auditoría de esta base de código encontró, de forma verificada, que un checkout aislado (`git worktree` desde `HEAD`) puede describir un estado del código distinto al del directorio de trabajo real cuando hay cambios sin commitear — y que confundir ambos produjo un hallazgo de seguridad reportado como abierto cuando ya estaba corregido.

Toda verificación debe declarar explícitamente, antes de cualquier conclusión, el snapshot exacto contra el que se hizo: `HEAD` (hash), `origin/<rama>` (hash y si diverge), si se usó un checkout aislado o el directorio de trabajo real, estado del working tree (limpio / N archivos modificados-untracked), y la marca de tiempo. Nunca declarar algo "verificado" o "corregido" sin decir contra qué snapshot — y nunca mezclar evidencia de un checkout aislado con evidencia del directorio de trabajo real sin señalarlo explícitamente.

Al reportar la exposición de un secreto, mantener siempre separadas estas cinco dimensiones, sin mezclarlas bajo una sola cifra: exposición histórica (todo lo que alguna vez lo contuvo), exposición en `HEAD` local, exposición en el remoto actual, exposición en el working tree, y validez de la credencial. Rotar una credencial, limpiar el remoto actual (commit+push hacia adelante) y purgar el historial de git son tres controles distintos que actúan sobre superficies distintas — nunca tratar uno como sustituto de otro.
