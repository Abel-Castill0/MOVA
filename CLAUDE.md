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
Todo componente debe verse elegante, profesional y "caro": profundidad sutil (sombras), tipografía limpia, microinteracciones fluidas, espaciado generoso, bordes redondeados suaves. Nada genérico ni sobrecargado. Movimiento fluido y apropiado al dispositivo, priorizando 60fps cuando sea razonablemente alcanzable, sin sacrificar accesibilidad ni rendimiento.

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

### Dependency Budget — antes de añadir cualquier dependencia nueva a MOVA
No basta con que una librería resuelva el problema. Antes de instalarla, evaluar explícitamente: impacto en bundle/runtime, mantenimiento del proyecto upstream, licencia, vulnerabilidades conocidas, compatibilidad con Vue/Laravel, duplicación con algo ya instalado, y — la pregunta que más filtra — si el mismo resultado se logra con código propio de pocas líneas. Nunca añadir una dependencia solo para ahorrar unas pocas líneas de código.

### Si evalúas herramientas, skills, librerías o MCPs nuevos (para MOVA o para mi propia configuración de Claude Code)
No instalar algo solo porque es bueno, popular o apareció en una lista — debe justificar: problema real de MOVA, beneficio concreto, ausencia de solución equivalente ya instalada, coste de mantenimiento/contexto, riesgo de seguridad/supply-chain. Clasificar siempre en una de cuatro categorías: **INSTALL NOW** (brecha real, se implementa ya), **INSTALL LATER** (útil, pero depende de una fase futura del roadmap — ej. UX/UI premium), **OPTIONAL** (solo ante una necesidad concreta que aún no existe), **REJECT** (con motivo explícito: incompatible con el stack, redundante con algo que ya existe, o sin caso de uso). No acumular herramientas redundantes entre sí (dos librerías de animación, dos MCPs de browser, dos sistemas de memoria) — un stack pequeño y coherente gana sobre uno grande. Cuando el tooling compite con otro trabajo por prioridad: seguridad/producción > integridad de negocio (créditos/pagos/reservas) > QA > performance > UX/UI > SEO > growth/marketing. "Analiza/evalúa todo" nunca significa "instala todo" — significa evaluar cada ítem contra este filtro y ejecutar solo lo que lo supera.

### Si haces una auditoría, revisión de seguridad, o cualquier afirmación de tipo "esto ya está arreglado/verificado" — Audit Snapshot Contract
Una auditoría de esta base de código encontró, de forma verificada, que un checkout aislado (`git worktree` desde `HEAD`) puede describir un estado del código distinto al del directorio de trabajo real cuando hay cambios sin commitear — y que confundir ambos produjo un hallazgo de seguridad reportado como abierto cuando ya estaba corregido.

Toda verificación debe declarar explícitamente, antes de cualquier conclusión, el snapshot exacto contra el que se hizo: `HEAD` (hash), `origin/<rama>` (hash y si diverge), si se usó un checkout aislado o el directorio de trabajo real, estado del working tree (limpio / N archivos modificados-untracked), y la marca de tiempo. Nunca declarar algo "verificado" o "corregido" sin decir contra qué snapshot — y nunca mezclar evidencia de un checkout aislado con evidencia del directorio de trabajo real sin señalarlo explícitamente.

Al reportar la exposición de un secreto, mantener siempre separadas estas cinco dimensiones, sin mezclarlas bajo una sola cifra: exposición histórica (todo lo que alguna vez lo contuvo), exposición en `HEAD` local, exposición en el remoto actual, exposición en el working tree, y validez de la credencial. Rotar una credencial, limpiar el remoto actual (commit+push hacia adelante) y purgar el historial de git son tres controles distintos que actúan sobre superficies distintas — nunca tratar uno como sustituto de otro.
