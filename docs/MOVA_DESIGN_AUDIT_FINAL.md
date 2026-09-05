> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# MOVA — Auditoría de diseño exhaustiva (documento vivo)

**No es un anuncio de "rediseño terminado".** Es el rastreador honesto de
cobertura que el propio proceso de esta sesión exigió: inventario completo
por filesystem real, matriz por archivo, y ninguna fila oculta o marcada
"no aplica" sin justificación explícita.

**Identificación de esta revisión** (obligatoria — nunca mezclar snapshots
sin declararlo; corrige un hash de encabezado que quedó desactualizado tras
varios commits posteriores y generó confusión real durante una revisión):

```text
audit_revision:   2026-08-29.1
generated_at:     2026-08-29T19:45:56Z
repository_head:  e3c8cf9   (rama master — directorio de trabajo real, no worktree aislado)
origin_head:      692b3651d09cb2731865efcb0d83dc83d2a36102   (sin re-fetch en esta revisión)
working_tree:     SUCIO en el momento de esta revisión — auditoría de dominio `Diagnostics`: 4 archivos de código (DiagnosticsController.php, DiagnosticAiEnrichmentService.php, config/diagnostic.php, Diagnostics/Create.vue) + 2 archivos de test (AuthorizationPolicyTest.php, DiagnosticIdempotencyTest.php) + este documento. 552/552 tests + build limpio confirmados antes de este commit.
authoring_commit: se confirma en el mensaje del commit que introduce este cambio (fix(diagnostics): audit AI/privacy/idempotency contract — user_id bug, error swallowing, product decision on dead recommendations engine)
```

**Nota de proceso sobre worktrees** (aclaración solicitada explícitamente):
esta revisión y todos los `php artisan test`/`npm run build` citados en las
secciones de Register/RegisteredUserController/InputError/i18n de más abajo
se ejecutaron directamente en `C:/Users/ABEL/OneDrive/Desktop/ProyectoMOVA`
— el directorio de trabajo real, con su propio `vendor/`, no un worktree
aislado ni un `vendor` compartido por junction/symlink. `git worktree list`
en este momento muestra dos worktrees adicionales
(`.claude/worktrees/jolly-hellman-5ca1a1`, `.claude/worktrees/serene-davinci-30dfbd`),
pero son de tareas en segundo plano lanzadas por esta sesión (spawn_task),
ejecutadas de forma independiente — ninguna evidencia de test citada en
este documento proviene de ellos. El riesgo real que señala esta nota (un
autoloader optimizado con rutas absolutas resolviendo fuera del worktree
activo) es una precaución válida para *cualquier* ejecución futura sobre un
worktree con `vendor` compartido — no algo que haya ocurrido en esta
revisión, y no debe leerse como que sí ocurrió.

El header de la revisión anterior (`2026-08-27.04`) citaba `HEAD ed96447`
como si fuera el `master` de este repositorio — **era, en realidad, el HEAD
de un worktree paralelo no relacionado** (`claude/jolly-hellman-5ca1a1`,
tarea de iconos, Fase 3), copiado por error al redactar el header inicial y
nunca actualizado en las revisiones siguientes. No invalida el contenido
verificado de esas revisiones (cada hallazgo cita su propio commit/comando),
pero si vas a citar "el HEAD de este documento", usa siempre el bloque de
arriba, no un hash suelto en el texto.

El inventario se regenera con `python resources/../scratchpad/audit_signals.py`
(script ad hoc, no commiteado) cada vez que se actualiza este documento —
los números de "emoji restantes"/"indigo restante" son una re-lectura real
de cada archivo, no una copia del hallazgo original de la Fase 0.

---

## Vocabulario de verificación (obligatorio desde este punto en adelante)

**"Migrado" y "rediseñado" no son lo mismo.** Migrar emoji→Lucide, indigo→
brand, o aplicar `BaseButton`/tokens es una condición necesaria, nunca
suficiente, para decir que una página está resuelta. Por eso cada página
usa uno de estos estados — nunca una palabra genérica como "completado y
verificado" que mezcle niveles distintos de evidencia:

| Estado | Significa |
|---|---|
| `AUDITED` | Se leyó el archivo completo y se identificaron sus problemas reales (no solo emoji/indigo). |
| `IMPLEMENTED` | El código fue modificado (tokens, iconos, componentes, copy, estados). |
| `BUILD_VERIFIED` | `npm run build` limpio después del cambio. |
| `TEST_VERIFIED` | La suite de PHP (`php artisan test`) sigue en verde; se nombra el test específico si ejercita esa ruta. |
| `BROWSER_VERIFIED` | Se cargó la página real en el Browser pane y se inspeccionó (screenshot, DOM, o interacción real — clic/teclado). |
| `A11Y_VERIFIED` | Foco por teclado, contraste, `aria-*` verificados en esa página específica (no heredado de los primitivos). |
| `RESPONSIVE_VERIFIED` | Probado en los breakpoints reales del proyecto. |
| `DARK_VERIFIED` | Probado con `data-theme="dark"` forzado — no solo "compila con los tokens". |
| `PERFORMANCE_VERIFIED` | Bundle/build comparado contra la línea base. |
| `DONE` | Todos los estados anteriores que aplican a esa fila están cumplidos — ver la distinción página/primitivo abajo. |
| `BLOCKED_VISUAL_VERIFICATION` | No se puede verificar visualmente por una razón externa concreta (auth+DB en este sandbox) — **nunca se convierte en `DONE` ni en `BROWSER_VERIFIED ✅` solo porque el build pasa.** La razón siempre se nombra. |
| `NO CHANGE — VERIFIED` | Se auditó la página y genuinamente no necesitaba cambios — un resultado válido, no una omisión. |

**`DONE` no es exclusivo de páginas — pero la barra es distinta para cada
fila.** Una **página de producto** requiere su propia validación de
página: no basta con que sus componentes compartidos estén verificados,
porque el layout, el copy y la interacción específicos de esa página no
se prueban por transitividad. Un **primitivo compartido**
(`BaseButton`/`Modal`/`Icon`/etc.) sí puede alcanzar `DONE` de forma
independiente el día en que TODAS sus dimensiones aplicables —
`BUILD_VERIFIED`, `TEST_VERIFIED`, `BROWSER_VERIFIED`, `A11Y_VERIFIED`,
`RESPONSIVE_VERIFIED`, `DARK_VERIFIED` cuando corresponda — tengan
evidencia real, sin que eso implique nada sobre las páginas que lo
consumen.

**Ninguna fila de este documento llega a `DONE` todavía — ni páginas ni
primitivos.** Lo que existe hoy, fila por fila, es como mucho
`IMPLEMENTED` + `BUILD_VERIFIED` + `TEST_VERIFIED`, y `BROWSER_VERIFIED`
solo donde la ruta no exige sesión — el resto está honestamente en
`BLOCKED_VISUAL_VERIFICATION`, nunca maquillado. Ningún primitivo tiene
todavía su `BROWSER_VERIFIED`/`A11Y_VERIFIED`/`RESPONSIVE_VERIFIED`/
`DARK_VERIFIED` completos como para cruzar la barra de arriba — la regla
se redefine para cuando ese trabajo exista, no se usa para adelantar una
conclusión que la evidencia todavía no sostiene.

### Clasificación de emoji (no perseguir el cero ciego)

| Categoría | Qué es | Acción |
|---|---|---|
| `UI_ICON` | Funciona como icono de interfaz (nav, botón, badge, estado) | Migrar a Lucide siempre |
| `CONTENT` | Parte de un texto que el usuario lee como contenido, no como interfaz | Conservar |
| `BRAND_COPY` | Aparece en copy de marketing/marca | Evaluar caso a caso, no mecánicamente |

Todo lo migrado en esta sesión hasta ahora era `UI_ICON` real (nav, badges de
estado, botones de acción) — verificado archivo por archivo, no asumido.

### Clasificación de `indigo` (no perseguir el cero ciego)

| Categoría | Qué es | Acción |
|---|---|---|
| `LEGACY` | Resto de Laravel Breeze, nunca fue una decisión de marca | Eliminar → `brand` o el rol semántico que corresponda |
| `SEMANTIC` | Codifica un estado de negocio real (ver "Sistema de estados semánticos" en `DESIGN.md`) | Conservar el ROL, no necesariamente el tono `indigo` — reasignar al rol semántico correcto |
| `DECORATIVE` | Parte de un degradado/fondo sin significado de estado | Evaluar si el degradado en sí debe existir (ver el caso del CTA de diagnóstico, abajo) |
| `THIRD_PARTY` | Vendría de una librería externa no tocada por MOVA | No modificar |
| `BRAND_CONFLICT` | Un azul/índigo técnicamente válido (no Breeze, no un estado) que compite visualmente con el propio `brand` de MOVA | Reasignar — la pregunta correcta es "¿este color pertenece semánticamente al sistema?", no "¿contiene la palabra indigo?" |

Todo el `indigo` encontrado hasta ahora ha sido `LEGACY` (focus rings,
botones, checkboxes heredados de Breeze) o `SEMANTIC` mal asignado (los
estados `paid`/`open`, que sí necesitaban un rol propio pero nunca debieron
llamarse "indigo porque sí" — ver `DESIGN.md`, sección "Sistema de estados
semánticos", con la justificación de por qué cada uno es un `accent` propio
y no una elección estética). Ninguna aparición ha sido `THIRD_PARTY` ni
`BRAND_CONFLICT` — pero la deduplicación de `statusColors.js` sí expuso un
caso adyacente real: el "scheduled" de las tarjetas de clase usaba `brand-500`
(el azul de MARCA) donde el badge ya usaba `blue` genérico — dos azules
para un mismo estado, uno de los cuales competía con el rol de `brand`
(acciones/identidad). Corregido: `scheduled` usa `blue` en los tres
consumidores; `brand` queda reservado para acciones y marca.

### Severidad de la deuda (para no pulir P3 mientras hay P0/P1 abiertos)

| Severidad | Qué significa | Ejemplo real de esta sesión |
|---|---|---|
| `P0` | Rompe flujo, confianza o comprensión — bloquea al usuario o expone datos que no deberían ser públicos | La exposición pública de `specific_rate`/pivot/fila completa de `User` (sección "🔴 P0 — PUBLIC SENSITIVE DATA EXPOSURE" arriba) |
| `P1` | Inconsistencia importante o fricción real, o una decisión de negocio pendiente que bloquea una mejora concreta | `active_offer` en el checklist de perfil de profesor (`PRODUCT DECISION REQUIRED`, ver sección propia) — **corregido aquí**: una versión anterior de esta tabla lo listaba como ejemplo de `P0`, contradiciendo la propia sección que lo clasifica y explica por qué no lo es; también `Students/Create.vue` vs `Edit.vue` con dos estilos distintos para el mismo formulario (Fase 3, slice 3) |
| `P2` | Mejora relevante de UX/UI | Migración de emoji a Lucide, tokens de color |
| `P3` | Pulido | Un radio o un tamaño de icono ligeramente distinto |

`active_offer` es `P1` — nunca `P0` (severidad real: fricción de onboarding,
no bloqueo de un flujo crítico de dinero; ver razonamiento completo en su
sección propia). Se trató como tal desde el principio (sección propia, no
perdido en la matriz) — la fila de ejemplo de arriba simplemente citaba mal
el caso, y quedó corregida en esta misma revisión. El resto de esta sesión
ha sido P2 (el grueso del trabajo) con algunos P1 reales encontrados en el
camino (la divergencia Create/Edit, las tildes eliminadas en
`Teacher/Credits/Index.vue`, el texto en inglés de `ConfirmPassword.vue`).

### Qué significa cada columna de la matriz

- **Emoji restantes / Indigo restante**: re-lectura mecánica del archivo
  (cuenta bruta) — el veredicto real de cada ocurrencia (`UI_ICON` vs
  `CONTENT`, `LEGACY` vs `SEMANTIC`) vive en la celda "Migrada", no en este
  número.
- **Migrada (tokens+iconos)**: resume el estado `IMPLEMENTED` de esa página
  — qué se cambió y por qué. No implica `BROWSER_VERIFIED` ni `DONE`.
- **Responsive / Dark / A11y**: `pendiente` significa exactamente eso —
  ningún primitivo compartido "hereda" estos estados a la página que lo usa;
  se verifican por página.
- **QA**: los tres estados de evidencia de ejecución —
  `BUILD_VERIFIED`/`TEST_VERIFIED`/`BROWSER_VERIFIED` — siempre nombrados
  por separado, nunca colapsados en un solo "✅ verificado".

---

## Resumen numérico (inventario completo, filesystem como fuente de verdad)

| | Total |
|---|---:|
| Archivos `.vue` totales | 82 |
| Pages | 53 |
| Components | 26 |
| Layouts | 3 |
| Emoji restantes (suma) | 113 en 30 archivos (199/43 al inicio de la Fase 3) — de estos, 1 es `CONTENT` deliberadamente conservado (`LandingFooter.vue`), el resto sigue sin clasificar |
| `indigo` restante (suma) | 26 en 11 archivos (72/18 al inicio; el 1 de `Checkbox.vue` es un falso positivo documentado abajo) |

### Panel de cobertura (la métrica real — no cantidad de commits)

No usar el conteo de commits como indicador de avance. Esta tabla es la que
importa:

| | Cantidad |
|---|---:|
| Vistas/componentes totales (baseline oficial, filesystem real) | **82/82** |
| Con algún trabajo real (`AUDITED`/`IMPLEMENTED`/`COMPLETA`/`PARCIAL`/`NO CHANGE`) | **42/82** |
| `BUILD_VERIFIED ✅` (literal, esta terminología exacta) | **24/82** |
| `TEST_VERIFIED ✅` (literal) | **22/82** |
| `BROWSER_VERIFIED ✅` (real, no bloqueado) | **5/82** (`Login`, `Register`, `ForgotPassword`, `ResetPassword`, `Terms` — `GuestLayout` se verificó indirectamente vía esas mismas 4 páginas Auth, no cuenta aparte) |
| `BLOCKED_VISUAL_VERIFICATION` (auth+DB, no reproducible en este sandbox) | **16/82** |
| `A11Y_VERIFIED` / `RESPONSIVE_VERIFIED` / `DARK_VERIFIED` / `PERFORMANCE_VERIFIED` (por página, no heredado de primitivos) | **0/82** |
| `DONE` | **0/82** |
| Duplicaciones arquitectónicas reales encontradas y corregidas | 3 (`statusColors.js`, `rechargeStatusColors.js`, `timeSlots.js`) — 1 de ellas era un bug de color en producción (`open` azul vs. cyan), no solo una limpieza de código |
| Product blockers elevados | 1 (`active_offer`, P1 + `PRODUCT DECISION REQUIRED`) |

**Cifras exactas, obtenidas por script sobre las 82 filas de la matriz real
(`grep`/conteo programático, no a mano)** — ya no hay `~`. Nota de precisión
honesta: `42/82` tienen trabajo real, pero `BUILD_VERIFIED ✅` solo cuenta
`24/82` porque los commits más tempranos de esta sesión (antes de fijar el
vocabulario exacto de verificación) documentaron la evidencia con palabras
distintas ("✓ tokens", "Fase 2", etc.) que un conteo estricto no reconoce
como la cadena literal `BUILD_VERIFIED ✅` — es un desfase de formato en filas
antiguas, no una brecha real de verificación (esos builds sí se ejecutaron,
ver los commits correspondientes). No se reescribieron esas ~18 filas
retroactivamente para "inflar" el número — se señala aquí en vez de ocultarlo.

### Cobertura por superficie crítica (juicio editorial, no conteo mecánico)

El panel de arriba cuenta archivos por igual — 82 vistas "pesan" lo mismo,
aunque `Legal/Terms.vue` y "el flujo de pago de una clase" no importan lo
mismo para el negocio. Esta tabla es una estimación razonada, marcada como
tal (no viene de un script), para no perder de vista que "42/82 tocadas"
puede significar mucho trabajo en superficies secundarias y cero en las que
de verdad mueven la aguja:

**Renombrado en esta revisión** (corrección conceptual solicitada
explícitamente): "Booking/Checkout" agrupaba `ClassRequests/Create.vue`
junto a pricing/créditos, pero `Create.vue` no cobra nada ni fija
precio/duración — eso ocurre exclusivamente en `Accept`/`LessonController`.
Nombrar el dominio "Checkout" sugiere una etapa de pago que `Create.vue`
nunca es. La taxonomía real de MOVA es:

```text
Discovery (Marketplace/descubrimiento)
   ↓
Request (ClassRequests/Create — intención, sin precio ni horario final)
   ↓
Acceptance/Scheduling (ClassRequests/Accept — aquí se fija duración,
                        horario, precio y créditos)
   ↓
Lesson (la clase ya agendada)
   ↓
Settlement (reportes, reseñas, liquidación de créditos)
```

Cada fila de abajo usa este vocabulario en vez de "Booking/Checkout". Y
donde antes decía "Marketplace ~90%", ese porcentaje medía **cobertura de
implementación** (tokens, iconos, proyección de datos), no "90% listo
para producción" — la propia fila ya reconocía `BROWSER_VERIFIED`/
`DARK_VERIFIED`/`RESPONSIVE_VERIFIED` bloqueados, así que un `~90%` sin
esa aclaración podía leerse mal. Separado en dos columnas explícitas.

| Superficie crítica | Cobertura de implementación | QA/journey pendiente | Qué falta |
|---|---:|---|---|
| Auth | ~100% | `BROWSER_VERIFIED` bloqueado en 2 páginas (requieren sesión) | Nada pendiente de este barrido |
| Parent — core journey | ~40% | sin verificar en su mayoría | `Dashboard/Parent`, `Students/*`, `ParentLessonCard`, `WeeklyCalendar`, `ClassRequests/Create` hechos; `Marketplace/Index`, `Teachers/Show`, `Diagnostics/*`, `Lessons/ParentIndex`, `LessonReports/ParentIndex`, `Reviews/Create` sin tocar |
| Teacher — core journey | ~50% | sin verificar en su mayoría | `Dashboard/Teacher`, `Teacher/Setup`, `Teacher/Edit`, `Teacher/Credits`, `TeacherLessonCard`, `ClassRequests/TeacherIndex` hechos; `LessonReports/Create`, `LessonReports/TeacherIndex`, `Lessons/TeacherIndex` sin tocar |
| Discovery (Marketplace) | ~90% de implementación (2/2 páginas) — **no** 90% listo para producción | `BROWSER_VERIFIED`/`DARK_VERIFIED`/`RESPONSIVE_VERIFIED` **bloqueados** (MySQL local no disponible en este sandbox al momento de esa pasada) | `Marketplace/Index.vue`, `Teachers/Show.vue` migrados + **2 P0 de exposición pública real** encontrados y corregidos, uno en la home pública (`WelcomeController`, ver sección propia). Falta filtros/búsqueda (brecha de funcionalidad ya documentada, no de diseño) |
| Request (`ClassRequests/Create.vue`) | ~15% del tramo Request→Lesson (1/6 páginas) | `BROWSER_VERIFIED` ✅ real (ver su sección de cierre) | `ClassOffers/*`, `Diagnostics/*`, `LessonReports/Create.vue`, `Reviews/Create.vue` siguen sin tocar (el widget `TimeSlotPicker` que consumen sí está hecho) |
| Acceptance/Scheduling (`ClassRequests/Accept.vue`) | 0% | **siguiente objetivo de esta sesión** — auditoría de backend antes de UI | Es la superficie económicamente más sensible del journey: duración, horario, precio y créditos se deciden aquí, no en `Create` |
| Class experience (Jitsi) | 0% (deliberado) | diferido a su propia revisión de seguridad | `JitsiModal.vue` — no es un olvido |
| Admin | ~25% | sin verificar en su mayoría | `Admin/Requests`, `Admin/Recharges` hechos (2/8); `Dashboard/Admin`, `Admin/Users`, `Admin/PendingTeachers`, `Admin/Lessons`, `Admin/Reviews`, `Admin/AiUsage` sin tocar |

**Lectura correcta de esta tabla:** el trabajo de mayor impacto real para el
negocio (Discovery, Request→Acceptance→Lesson, Class experience) sigue
mayoritariamente sin tocar — `Request` pasó de `0%` a un primer paso real
(`ClassRequests/Create.vue`), no a completo, y `Acceptance/Scheduling`
sigue en `0%`. El orden de dominios restante prioriza esto explícitamente
— ver "Flujos críticos" abajo. El
siguiente tramo lógico de este mismo journey es `ClassRequests/TeacherIndex.vue`
→ `ClassRequests/Accept.vue` (donde se decide duración, precio y créditos —
la parte económicamente sensible), no una página de un dominio distinto.

## Identidad visual por rol (el lenguaje es común, la composición no)

| Rol/superficie | Prioridad |
|---|---|
| Parent | confianza, claridad, decisiones sin fricción |
| Student (gestionado por el padre, no un rol propio — ver nota) | simplicidad, orientación, bajo esfuerzo cognitivo |
| Teacher | productividad, agenda, acciones rápidas |
| Admin | densidad controlada, precisión, auditoría |
| Marketplace | descubrimiento, confianza, conversión |
| Checkout/pagos | mínima distracción, máxima claridad |
| Clase en vivo (Jitsi) | inmersivo, funcional, cero ruido |
| Landing/público | marca, narrativa, conversión |

**Nota verificada (no asumida):** MOVA tiene 3 roles reales de login
(`parent`/`teacher`/`admin` — `RoleSeeder.php`). "Student" no inicia sesión;
es un perfil hijo gestionado por el padre (`Students/*.vue`, ya bajo "Parent
(gestión de hijos)" en la matriz). El lenguaje de "identidad para Student"
se aplica al *contenido que un padre ve sobre su hijo/a*, no a una superficie
propia.

## Flujos críticos a auditar como experiencia única (no como páginas sueltas)

Ninguno auditado como flujo todavía — cada página que lo compone aparece
suelta en la matriz de abajo. Se marcan aquí para no perderlos de vista:

- **Padre**: Marketplace → perfil de profesor → solicitud → diagnóstico IA →
  clase asignada → reporte → reseña.
- **Profesor**: registro → verificación (`PendingTeachers`) → perfil/setup →
  disponibilidad → solicitudes → clase → reporte → créditos.
- **Padre (gestión de hijos)**: crear/editar/eliminar estudiante.
- **Admin**: login → dashboard → revisión (profesores/solicitudes/recargas) →
  auditoría.

### Orden de ejecución decidido (reemplaza "Admin es lo siguiente")

El plan original de esta fase seguía dominios en el orden en que se venían
tocando archivos (Auth → Students → Parent → Teacher → shared components →
Admin). Se corrige explícitamente: **limpiar Admin página por página antes
de tocar los journeys comerciales de arriba invertía el eje de prioridad** —
dejaba pulida la superficie de menor tráfico (Admin, uso interno) mientras
Discovery/Request/Acceptance — donde MOVA genera y cobra clases —
seguía al 0%. Diez páginas administrativas bien migradas valen menos que un
journey de negocio crítico funcionando de punta a punta.

Orden vigente a partir de aquí:

1. **Bloque de integridad técnica** — ✅ **cerrado esta sesión**:
   `class_requests.status` (P0) y `classes.status` (P1) resueltos, ambos
   con guard "fail loud" de drift en MySQL, ambos verificados fresh +
   incremental + rollback, 496/496 tests. Categoría de riesgo
   `DATABASE CONTRACT / ENVIRONMENT PARITY DRIFT` registrada en
   `docs/MOVA_SYSTEM_KNOWLEDGE.md` §29 para las dos direcciones del
   problema (SQLite demasiado estricto / demasiado permisivo) — no se
   auditó ninguna otra tabla de estado de forma exhaustiva todavía
   (`RechargeRequest`/`PaymentOrder`/`WhatsAppMessage` no mostraron el
   mismo síntoma en esta pasada, pero tampoco se les aplicó el mismo
   barrido línea por línea; queda como riesgo conocido, no como "ya
   descartado").
1.5. **Integration Gate** — ✅ ejecutado, ver sección siguiente. `PASS`.
2. **Journey del padre** (el corazón comercial de MOVA, 0% hoy) — **siguiente
   paso**: `Marketplace/Index.vue` → `Teachers/Show.vue` →
   `ClassRequests/Create.vue` → `Diagnostics/*` →
   `ClassOffers/*`/checkout.
3. **Resto del journey del profesor** (~50% hecho): `LessonReports/*`,
   `Lessons/TeacherIndex`.
4. **Experiencia de clase (Jitsi)** — sigue con su revisión de seguridad
   dedicada como condición previa, no se adelanta solo por orden de lista.
5. **Admin** — se retoma después, no antes, precisamente porque es la
   superficie de menor impacto directo en el negocio entre las que faltan.

## Integration Gate (2026-08-27, tras cerrar el bloque de integridad)

Punto de control explícito antes de empezar un dominio grande nuevo —
no es una fase, es un checklist de una sola pasada:

```text
git status --short --branch     → master, limpio salvo package-lock.json (ajeno)
git branch -vv                  → sin ramas relacionadas con este bloque sin mergear
git worktree list                → 1 worktree paralelo activo, tarea no relacionada (iconos, Fase 3)
git rev-list --left-right --count HEAD...origin/master → 44 ahead / 0 behind / no diverged
php artisan test                 → 496/496 ✅
migraciones duplicadas           → ninguna (verificado por nombre de archivo y por tabla afectada)
utilidades duplicadas            → ninguna nueva introducida en este bloque
audit doc ↔ HEAD                 → consistente (este párrafo se escribe en el mismo commit que lo cierra)
MOVA_SYSTEM_KNOWLEDGE.md          → actualizado (§29, ambos hallazgos)
MOVA_DESIGN_AUDIT_FINAL.md        → actualizado (esta sección)
```

**INTEGRATION GATE: PASS.** No se ejecutó `npm run build` en esta pasada —
el bloque de integridad fue 100% backend (migraciones + tests PHP), cero
archivos `.vue`/`.js` tocados; correrlo no habría probado nada sobre este
cambio. Se ejecutará antes/durante el trabajo de Marketplace, que sí toca
frontend.

---

## Integration Gate (2026-08-27, re-sincronización tras el bloque `Register.vue`/`RegisteredUserController`/`InputError`/i18n)

Disparado por una revisión externa que señaló, correctamente, que este
documento se estaba quedando atrás del código real — encabezado con hash
desactualizado, filas de la matriz contradiciendo sus propias secciones de
cierre, y un ejemplo de severidad mal clasificado. Antes de corregir una
sola línea, se verificó el estado real, no se asumió nada de lo señalado:

```text
git status --short --branch                              → master, limpio salvo package-lock.json (preexistente, ajeno a esta sesión)
git worktree list                                         → 2 worktrees paralelos (jolly-hellman-5ca1a1, serene-davinci-30dfbd) — tareas en segundo plano de esta sesión, NINGUNA evidencia de este documento proviene de ellos
git rev-list --left-right --count HEAD...origin/master     → 71 ahead / 0 behind / no diverged
php artisan test                                            → 544/544 (1723 assertions) ✅ — ejecutado en el directorio de trabajo real, vendor propio, no worktree aislado
npm run build                                                → limpio, sin warnings nuevos ✅
```

**Trabajo real de este bloque, verificado uno por uno antes de escribirlo
(ninguno inventado ni asumido por la crítica externa)**:

| Commit | Qué corrige | Evidencia |
|---|---|---|
| `0f507fe` | `Register.vue`: el wizard dejaba al usuario varado en el último paso cuando un error de servidor (email duplicado) caía en un campo de un paso anterior sin montar. `stepForField()` genérico (no solo `email`) + `onError` navega al paso más temprano con error | Revert-confirm-restore real (`git stash` sobre el archivo reprodujo el fallo); repro en vivo con `ana@mova.test` — el wizard salta a "PASO 3 DE 5" con el error visible |
| `b8c157c` | `RegisteredUserController::store()`: mismo patrón `abort_if()` → `HttpException` plano ya corregido antes en `ClassRequestController` (`206d886`). Escribir el test de regresión encontró un segundo bug más serio en las mismas líneas: `User`/`TeacherProfile` se creaban ANTES del chequeo de materias, sin transacción — una lista de materias vacía dejaba una cuenta huérfana a medio crear incluso con el mensaje ya corregido. Envuelto en `DB::transaction()` | `git stash` reprodujo ambos fallos (422 crudo + fila huérfana); verificado en vivo contra el servidor real vía `fetch()` (el wizard no puede alcanzar este estado por sus propios guards de cliente) + `tinker` confirmando 0 filas huérfanas |
| `bc84e70` | `InputError.vue` (13 call-sites reales, no supuestos): sin `role`/`aria-live` de ningún tipo — un error de validación no se anunciaba a un lector de pantalla. Añadido `role="alert"` + `aria-atomic="true"` | Verificado en vivo en Login (real, campo poblado vs. campo vacío sin doble anuncio), Register y Teacher/Credits/Index (mismo patrón `:message="form.errors.X"`, confirmado por grep) |
| `a4a3c1d` | `APP_LOCALE` nunca leía de `env()` (hardcodeado a `en`); cero archivos `lang/` en el proyecto — cualquier validación sin mensaje custom salía en inglés en una app 100% en español. `lang/es/{validation,auth,passwords}.php` escritos a mano (evaluado contra el filtro de dependencias de `CLAUDE.md`: un paquete de terceros no se justifica para texto estático de un solo idioma) | Verificado en vivo vía `fetch()` real contra el servidor corriendo: email duplicado y fallo de login ahora en español, confirmados con sesión/CSRF reales, no solo en `tinker` |

**Contradicciones reales encontradas y corregidas en el propio documento**
(no en el código) durante esta re-sincronización:

1. La tabla de severidad (línea ~120) usaba `active_offer` como ejemplo de
   `P0`, contradiciendo su propia sección de cierre (`P1 · PRODUCT DECISION
   REQUIRED`, que ya explicaba por qué no es `P0`) — corregido, con el
   ejemplo de `P0` real (la exposición pública de datos) en su lugar.
2. `Pages/Marketplace/Index.vue` y `Pages/Teachers/Show.vue` seguían
   marcados `NO` en la matriz de 82 archivos pese a estar migrados y
   cerrados hace varios commits — corregido, con un re-conteo real de
   emoji/indigo (no reutilizado del original): se encontró un 💡 suelto en
   `Marketplace/Index.vue` que ninguna revisión anterior había detectado.
3. `Pages/ClassRequests/Create.vue` seguía marcado `NO` pese a tener su
   propia sección de cierre completa un poco más abajo en el mismo
   documento — corregida la fila.
4. La fila de accesibilidad de `Create.vue` decía "`InputError.vue` no
   tiene `role='alert'`" — cierto cuando se escribió, falso desde `bc84e70`
   — corregida.
5. El resumen de dominios ("Booking/Checkout | 0%") seguía listando
   `ClassRequests/Create.vue` como sin tocar — corregido a `~15%`.

**Lo que esta re-sincronización NO hizo** (alcance deliberado, no
descuido): no se regeneró mecánicamente el panel completo de 82 archivos
desde cero (`42/82`, `24/82`, etc.) — solo se corrigieron las filas
verificadas una por una arriba. Un script real de inventario
(`filesystem → estado → resumen generado`) sigue siendo trabajo futuro, no
hecho aquí; los números agregados de la sección "Resumen numérico" pueden
seguir subcontando el trabajo real por esta misma razón, y se señala en vez
de ocultarlo.

**INTEGRATION GATE: PASS.**

---

## 🔴 P0 — PUBLIC SENSITIVE DATA EXPOSURE / PUBLIC MODEL SERIALIZATION DRIFT · CONTENIDO LOCALMENTE, NO DESPLEGADO

**Una sola clase de vulnerabilidad, no incidentes independientes.** Las
primeras dos correcciones (`0b1bc13`, `904ea07`) se documentaron primero
como "el bug de Marketplace" y "el bug de Welcome" — eso subestimaba lo
que realmente pasó. Ambos son instancias de la misma causa raíz genérica
(ver abajo), y el propio proceso de cierre de esta clase encontró **tres
instancias más** (el pivot de `subjects`, una fuga hermana en un endpoint
de admin, y una cuarta superficie en `ClassRequestController::create()`
encontrada al clasificar `specific_rate` con evidencia en vez de
etiquetarlo "sensible" por reflejo) — evidencia de que valía la pena
tratarlo como categoría, no como archivo por archivo.

### Causa raíz (formulación genérica y reusable, no "un bug de paginate")

```text
Las superficies públicas dependían de la serialización de modelos
Eloquent sin imponer un contrato de proyección pública suficientemente
estricto. La composición de agregados (withAvg/withCount) y relaciones
(belongsTo/belongsToMany con pivot) podía provocar que se serializaran
atributos internos — de la columna base o de una relación cargada — no
destinados al público, incluso cuando el código ya intentaba restringir
columnas.
```

No es "un bug de `paginate()`" ni "un bug de `get()`": ambos métodos
estaban afectados por la misma causa (`get($columns)` es lo que
`paginate($n, $columns)` llama internamente), y una tercera variante del
mismo problema (pivot de relación `belongsToMany`) no pasaba por ninguno
de los dos.

### Incident Response Status (formal)

```text
classification:                P0 — PUBLIC SENSITIVE DATA EXPOSURE /
                                PUBLIC MODEL SERIALIZATION DRIFT
exposure_confirmed:             YES (json_encode() real, las 4 instancias,
                                antes de corregir cada una)
exploitation_confirmed:         UNKNOWN — no verificable desde este
                                repositorio; no se afirma ni se descarta
affected_public_endpoints:      / (Welcome), /marketplace — 2 rutas
                                PÚBLICAS reales
affected_authenticated_endpoint: /admin/pending-teachers — admin-only,
                                severidad menor, mismo patrón de raíz
first_known_vulnerable_commit:  03db9f5 (home, 2026-06-10, primer commit
                                del repo) · 2fb22fd (marketplace,
                                2026-08-22) — ambos confirmados ancestros
                                de origin/master
local_fix:                      YES — las 3 superficies (2 públicas + 1
                                admin), verificado con tests de regresión
                                en las 3
staging:                        N/A — no existe entorno de staging
                                separado en este proyecto
production_fix:                 NOT VERIFIED / NOT DEPLOYED
data_scope:                     atributos públicos/internos de
                                TeacherProfile (incl. datos de contacto de
                                pago y metadata interna de moderación) +
                                atributos internos de User (solo en la
                                variante admin) + tarifa específica por
                                materia vía pivot
credential_rotation:            NOT CURRENTLY JUSTIFIED BY EVIDENCE — ver
                                razonamiento abajo
user_notification:              PRODUCT/SECURITY DECISION PENDING — tuya,
                                no técnica
```

**No se describe esto como "resuelto" a secas.** Mientras el entorno
desplegado siga corriendo el código anterior a estos commits, la
exposición sigue existiendo en producción — lo que existe hoy es un fix
verificado y listo en las 3 superficies encontradas, no un incidente
cerrado de punta a punta.

### Precisión técnica del mecanismo (corregida)

La formulación inicial — "`paginate()`/`get()` ignoran su segundo
argumento" — era una simplificación excesiva. La mecánica real, verificada
empíricamente (no solo leída en la documentación): una vez que
`withAvg()`/`withCount()` ya establecieron una proyección en el query
builder (ambos llaman `addSelect()` internamente), pasar una lista de
columnas más tarde a `get($columns)` — y por extensión a `paginate($n,
$columns)`, que llama a `get($columns)` internamente — **no garantiza que
esa lista termine siendo la proyección final servida**. No es que el
argumento se ignore siempre y en todo contexto; es que la combinación
`select previo (por agregado) + columnas tardías` no es determinista, y en
ambos casos reales de este repositorio terminó sirviendo la fila completa.
La solución determinista es fijar la proyección ANTES de los agregados:

```text
->select([...])   ← fija el contrato ANTES
->with(...)
->withCount(...)  ← addSelect() ya no puede pisar nada
->withAvg(...)
->paginate()/->get()  ← sin argumento de columnas, no hace falta
```

### Las cuatro instancias de la misma causa raíz, lado a lado

| | `/marketplace` | `/` (home pública) | `subjects` pivot (ambas rutas) | `/admin/pending-teachers` |
|---|---|---|---|---|
| Severidad | 🔴 P0 público | 🔴 P0 público | 🟠 P1 público (menor) | 🟡 P2/P3 — admin-only, no es la clase pública |
| Mecanismo exacto | `paginate($n, $columns)` no determinista tras `withAvg()`/`withCount()` | `get($columns)` (lo mismo que llama `paginate()` internamente) | pivot de `belongsToMany` con `withPivot()` no se suprime restringiendo columnas del modelo relacionado | relación `belongsTo('user')` cargada sin restricción de columnas |
| Commit que lo introdujo | `2fb22fd` (2026-08-22) | `03db9f5` — el primer commit del repo (2026-06-10) | mismo origen que las dos anteriores (el pivot nunca se restringió) | no rastreado individualmente — encontrado al verificar `reviewedBy`, no por sí solo |
| ¿Ancestro de `origin/master`? | Sí | Sí | Sí | Sí |
| Ventana de exposición estimada | ~5 días | ~11 semanas | igual que las dos anteriores | N/A (requiere `role:admin`, no es exposición pública) |
| Campos expuestos | `yape_number`, `plin_number`, `referral_code`, `rejection_reason`, `reviewed_by`, `credits_*`, etc. | los mismos, salvo lo que `$hidden` ya cubría | `teacher_subject.specific_rate` (columna pivote) + IDs de la tabla pivote | `User` completo salvo `password`/`remember_token`: `phone_verification_code_hash`, `suspension_reason`, `whatsapp_opt_in_at`, etc. |
| Fix | `0b1bc13` | `904ea07` | `607f9ec` (ambos endpoints) | `1c1ac31` |
| Tests | `TeacherPublicVisibilityTest.php` (8) | `WelcomeFeaturedTeachersExposureTest.php` (3) | `PublicTeacherProfileExposureContractTest.php` (2, allowlist forward-looking) | `AdminPendingTeachersExposureTest.php` (2) |

Los tres hallazgos posteriores al primero se encontraron exactamente por
las razones correctas, no por casualidad: el segundo por la auditoría
acotada de superficies públicas recomendada tras el primero; el tercero
(pivot) al construir el test de allowlist forward-looking y descubrir que
`subjects[0]` traía una clave que nadie había puesto en la lista permitida;
el cuarto (admin) al verificar explícitamente que `reviewedBy` no
escondiera una fuga hermana — que no la escondía, pero reveló que su
vecino `user` sí tenía una.

### Auditoría acotada de superficies públicas (completa, no una muestra)

Las 10 rutas registradas fuera de cualquier middleware de autenticación,
revisadas una por una:

| Ruta | Controller | Resultado |
|---|---|---|
| `/` | `WelcomeController` | 🔴 Vulnerable → corregido (`904ea07`) |
| `/marketplace` | `MarketplaceController` | 🔴 Vulnerable → corregido (`0b1bc13`) |
| `/teachers/{id}` | `TeacherPublicController` | ✅ Seguro — array armado a mano, sin serializar el modelo |
| `/quienes-somos` | `AboutController` | ✅ Seguro — solo `count()`, ningún modelo devuelto |
| `/invitacion/profesor` | `TeacherInvitationController` | ✅ Seguro — sin props en absoluto |
| `/invitacion/alumno` | `StudentInvitationController` | ✅ Seguro — sin props en absoluto |
| `/sitemap.xml` | `SitemapController` | ✅ Seguro — sin agregados encadenados (la mecánica ni siquiera aplica) y `->map()` reconstruye la salida explícitamente |
| `/terminos`, `/privacidad` | `LegalController` | ✅ Seguro — sin props en absoluto |
| `/robots.txt`, `/healthz` | closures | ✅ Seguro — strings estáticos |

**Resultado: 2 de 10 rutas públicas vulnerables, ambas ya corregidas.** No
se auditaron rutas autenticadas (fuera del alcance acordado — esta fue una
auditoría de exposición **pública**, no una re-auditoría general).

### Clasificación de sensibilidad de `TeacherProfile` (contrato por campo)

| Campo | Clasificación | Dónde puede aparecer |
|---|---|---|
| `id`, `bio`, `hourly_rate`, `is_verified` | **PUBLIC** | Marketplace, home, perfil público |
| `subjects` (relación), `user.name`, `user.avatar_url` | **PUBLIC** | Marketplace, home, perfil público |
| `avg_rating`/`review_count` (calculados) | **PUBLIC** | Marketplace, home, perfil público |
| `referral_code` | **OWNER_ONLY / REPEAT_CUSTOMER** | Solo perfil propio o padre con clase completada (`TeacherPublicController::referralCodeVisibleTo()`) |
| `yape_number`, `plin_number` | **OWNER_ONLY** | Solo `Teacher/Edit.vue`/`Setup.vue` (perfil propio) |
| `mentorship_slots_total` | **OWNER_ONLY** | Solo `Teacher/Edit.vue`/`Setup.vue` |
| `credits_available`, `credits_reserved` | **OWNER_ONLY** | Solo `Teacher/Credits/Index.vue`, vía array armado a mano — nunca por serialización del modelo |
| `rejection_reason`, `rejected_at` | **ADMIN_ONLY** | Solo `Admin/PendingTeachers.vue` |
| `reviewed_by` (relación cargada) | **ADMIN_ONLY** | Solo `Admin/PendingTeachers.vue` — ver la nota sobre colisión de nombre abajo |
| `user_id` | **INTERNAL** | Ningún frontend lo lee nunca — solo necesario en memoria para resolver la relación `user` |
| `completed_classes_count`, `is_experienced`, `mentorship_slots_taken` | **INTERNAL** | Solo lógica de negocio en `TeacherProfile.php`/servicios — ningún frontend los lee |
| `reviewed_at` | **INTERNAL** | Ningún frontend lo lee (solo se muestra `rejected_at`) |

**Defensa en profundidad, en capas explícitas — ninguna es "la" solución:**

```text
Capa 0 — proyección explícita por endpoint (->select() antes de agregados)
         ← la que realmente define el contrato público de cada ruta
Capa 1 — TeacherProfile::$hidden (campos INTERNAL, sin lector en ningún
         frontend) ← defensa adicional, no sustituto de la Capa 0
Capa 2 — tests de contrato negativo + positivo, por endpoint
```

**No se introduce un `TeacherPublicResource`/DTO formal en esta pasada.**
Es la evolución correcta si el número de superficies públicas de MOVA
sigue creciendo, pero con 3 superficies públicas reales hoy (`/`,
`/marketplace`, `/teachers/{id}`) y ya con proyección explícita + tests en
las tres, introducir una clase de Resource ahora sería la abstracción
antes de la necesidad — la misma regla anti-sobreingeniería que ya rige
este documento. Se revisa si aparece una cuarta superficie pública con
necesidades de proyección similares.

### Evaluación de exposición (acotada, con lo que este repositorio puede responder)

```text
1. ¿Desde cuándo?           /marketplace: commit 2fb22fd (2026-08-22, ~5
                            días). /: commit 03db9f5 (2026-06-10, ~11
                            semanas) — el primer commit del repositorio.
2. ¿Cuántos profesores?     NO VERIFICABLE desde esta sesión — requiere
                            acceso a la base de datos de producción, que
                            esta sesión no tiene.
3. ¿Cacheada?               NO VERIFICABLE — sin visibilidad de
                            infraestructura (CDN/proxy) desde este
                            repositorio.
4. ¿En logs de aplicación?  NO VERIFICABLE desde este repositorio.
5. ¿Accesible sin sesión?   SÍ, confirmado — ambas rutas están fuera de
                            cualquier middleware de autenticación
                            (verificado en routes/web.php).
6. ¿Indexada por buscadores? Comprobación real hecha (WebSearch, no
                            asumida): sin resultados que confirmen
                            indexación del dominio de producción
                            (mova-production-8750.up.railway.app, citado
                            en docs/FINAL_PRODUCTION_SIGNOFF.md) ni de su
                            contenido. Ausencia de evidencia no es
                            evidencia de ausencia — un motor de búsqueda
                            puede no haber re-crawleado todavía, o este
                            buscador puede no reflejar el índice completo
                            de Google/Bing — pero no se encontró indicio
                            positivo de indexación.
7. ¿Producción corre el código vulnerable ahora mismo? NO VERIFICADO —
                            sin acceso a Railway desde esta sesión.
```

### Contención — qué se hizo y qué NO se hizo

**Hecho**: proyección explícita en las 3 superficies (`0b1bc13`,
`904ea07`, `1c1ac31`), pivot de `subjects` cerrado en ambos endpoints
públicos (`607f9ec`), `TeacherProfile::$hidden` como capa adicional
(`92e71c4`), un test de contrato allowlist forward-looking además de los
de lista negra (`607f9ec`) — **19 tests de regresión nuevos en total**
entre los cinco commits, todos verificados como detección real
(revertidos temporalmente, confirmado que fallan con el mensaje exacto
esperado, restaurados).

**Deliberadamente NO hecho**: no se rotó `yape_number`/`plin_number` —
son los números de cuenta de pago de cada profesor, no credenciales
técnicas; rotarlos no es una decisión que competa a esta sesión ni una
acción automática razonable sin que tú definas el alcance real y decidas
si amerita avisar a los profesores verificados. No se hizo push — sigue
bajo la regla F-26/F-27. No se intentó verificar producción directamente
(sin credenciales, sin acceso). No se introdujo un `TeacherPublicResource`/
DTO formal: con la proyección explícita + el allowlist ya cubriendo las 2
superficies públicas reales, la abstracción precedería a la necesidad —
se reconsidera si aparece una tercera superficie pública con proyección
similar. No se auditaron rutas autenticadas más allá del hallazgo
incidental de `/admin/pending-teachers` (encontrado verificando
`reviewedBy`, no por una nueva búsqueda deliberada) — no se abrió una
segunda auditoría general.

### `specific_rate` — dos campos distintos, no uno solo (corrección importante)

Al cerrar el pivot de `subjects` se afirmó sin más "se filtraba
`specific_rate`" — eso mezclaba dos columnas homónimas y no distinguía
"serialización no intencionada" de "brecha de confidencialidad". Rastreado
cada consumidor real antes de clasificar, no asumido:

| | `teacher_subject.specific_rate` (el pivot) | `class_offers.specific_rate` |
|---|---|---|
| ¿Quién lo escribe? | `RegisteredUserController`, `TeacherProfileController::storeSetup()`/`update()` — **los tres siempre lo sincronizan como `null`** (`['specific_rate' => null]`), verificado leyendo las 3 llamadas reales | `ClassOfferController` — el profesor fija una tarifa real y acotada por `TeacherProfile::maxAllowedRate()` |
| ¿Quién lo lee? | Ningún backend ni frontend — cero lectores encontrados | `ClassRequests/Create.vue:9` — el padre lo ve como el precio de la clase que está solicitando |
| Clasificación | **INTERNAL / DEAD** — no es un dato de precio real hoy, es una columna vestigial siempre nula | **PUBLIC (para quien solicita)** — es la información de precio que el producto necesita mostrar |
| Qué se hizo | Ocultado del payload público (`607f9ec`) — corrección correcta, pero la razón real es "elimina una clave no intencionada del contrato", no "cierra una fuga de precios en producción", porque el valor real casi siempre es `null` | Sin cambios — sigue siendo parte legítima del contrato de `ClassRequestController::create()`, con proyección explícita ahora (`4df28d1`) |

**Lección aplicada**: *unintended serialization* ≠ *confidentiality
breach* automáticamente. El pivot se corrigió porque violaba el contrato
de respuesta declarado (nadie decidió exponerlo), no porque el valor en sí
fuera sensible — en la práctica casi siempre es `null`. Si en el futuro
`teacher_subject.specific_rate` se reactiva como funcionalidad real de
precio por materia, esta clasificación debe revisarse explícitamente, no
heredarse de esta nota.

### Quinto hallazgo — `ClassRequestController::create()` (mismo patrón, encontrado siguiendo el rastro de `specific_rate`)

Investigar los consumidores reales de `class_offers.specific_rate` llevó
directamente a `ClassRequestController::create()`: `'offer' => $offer`
pasaba el `ClassOffer` completo con `teacherProfile.user` sin proyección.
Esta ruta exige `role:parent` (no pública), pero cualquier padre
autenticado puede pasar `?offer_id=N` (IDs secuenciales) para una oferta
de un profesor con el que no tiene ninguna relación — y recibía
`yape_number`/`plin_number`/`referral_code` del profesor (más allá de lo
que `$hidden` ya bloquea) y su `User` completo (`email`, `phone`,
`phone_verification_code_hash`, `suspension_reason`, etc.). Verificado con
`json_encode()`, corregido con proyección explícita (`4df28d1`),
`ClassRequestCreateOfferExposureTest.php` (1 test, 26 assertions,
verificado como detección real).

### Verificación final: `$appends`/accessors (el último rincón de la familia)

Pedido explícitamente antes de cerrar: ¿algún modelo de esta familia
reintroduce un campo oculto vía `$appends` o un accessor? Verificado con
grep, no asumido — `$appends` SÍ es un patrón real en esta base de código
(`Lesson::$appends = ['has_jitsi_room', 'end_time', 'credit_cost']`,
`Student::$appends = ['full_name']`), pero **ninguno de los 4 modelos de
esta familia** (`TeacherProfile`, `User`, `Subject`, `ClassOffer`)
declara `$appends`, un accessor estilo `getXAttribute()`, ni el estilo
nuevo `Attribute::make()`. Ninguna reintroducción oculta de campos en
esta familia.

### Checklist de cierre de esta clase de vulnerabilidad

```text
[x] Causa raíz documentada de forma genérica y reusable
[x] Las 10 rutas públicas de MOVA auditadas (2 vulnerables, 8 seguras)
[x] / corregido (904ea07)
[x] /marketplace corregido (0b1bc13)
[x] TeacherProfile revisado campo por campo (contrato de sensibilidad)
[x] reviewedBy verificado — correcto, sin cambios necesarios
[x] Hallazgo hermano de reviewedBy (relación `user` sin acotar) corregido (1c1ac31)
[x] Pivot de subjects corregido en ambos endpoints públicos (607f9ec) —
    reclasificado como limpieza de contrato, no fuga de precio (ver arriba)
[x] specific_rate (class_offers, el real) clasificado PUBLIC con evidencia,
    no por reflejo — sigue expuesto a quien solicita, por diseño
[x] Quinto hallazgo — ClassRequestController::create() corregido (4df28d1)
[x] $appends/accessors/Attribute::make() verificados en los 4 modelos —
    ninguno reintroduce un campo oculto
[x] currentInertiaVersion — confirmado 0 referencias en todo el
    repositorio (`rg`/Grep, no asumido)
[x] Positive response contracts (tests)
[x] Negative response contracts (tests)
[x] Allowlist forward-looking (protege contra campos futuros, no solo los conocidos)
[x] Tests de regresión — 22 assertions/tests nuevos en 6 clases de test
    (TeacherPublicVisibilityTest, WelcomeFeaturedTeachersExposureTest,
    PublicTeacherProfileExposureContractTest, AdminPendingTeachersExposureTest,
    TeacherProfileHiddenFieldsTest, ClassRequestCreateOfferExposureTest),
    cubriendo: contrato público de listado, contrato de perfil individual,
    relaciones anidadas, pivot, serialización de admin, y el formulario
    de solicitud de clase — todos verificados como detección real
    (revertidos, confirmado el fallo exacto, restaurados), no contados
    como métrica sola
[x] Suite completa — 531/531
[ ] Build de frontend — no aplica, ningún archivo .vue/.js tocado en este bloque de seguridad
[x] Audit document actualizado (esta sección)
[x] MOVA_SYSTEM_KNOWLEDGE.md actualizado (§19-20: nota correctiva +
    política arquitectónica de proyección pública explícita)
[x] Git state — 56 ahead / 0 behind, verificado con fetch; historial
    revisado línea por línea (git log --oneline -12): un solo commit de
    diseño (cd0c8ee) intercalado, en su orden cronológico real, ningún
    commit de seguridad mezclado con cambios cosméticos
```

**PUBLIC SERIALIZATION DRIFT — CLOSED FOR CURRENT SCOPE.** No se reabre
esta auditoría salvo que aparezca evidencia nueva de una fuga P0/P1 real
— el siguiente trabajo de esta sesión entra a `ClassRequests/Create.vue`
por el contrato de negocio (precio, créditos, disponibilidad, idempotencia,
autorización, concurrencia) antes que por UX/UI, no una sexta ronda de
búsqueda de exposición.

---

## 🔍 `ClassRequests/Create.vue` — auditoría de contrato de negocio (backend primero, antes de tocar UI)

Se planteó la duda de si el hallazgo de `?offer_id=` en
`ClassRequestController::create()` era **BOLA/autorización de objeto**
(Parent A accede a un recurso que no debería poder consultar) o
**minimización de datos** (el recurso es correcto de consultar, pero
devolvía demasiado). Se investigó con evidencia, no por intuición — la
regla que se pidió seguir explícitamente.

### Veredicto sobre `?offer_id=`: minimización de datos, NO autorización de objeto

Evidencia recogida, en orden:

1. **`ClassOfferPolicy` no declara ninguna ability `view`** (solo
   `update`/`delete`, ambas exigen `$classOffer->teacher_profile_id ===
   $profile->id`) — leído el archivo completo. Nunca existió una
   autorización de "quién puede ver una oferta" que este endpoint
   estuviera saltándose: esa autorización no existe en el sistema, para
   nadie.
2. **El propio historial de `MarketplaceController` documenta que el
   Marketplace anterior al rediseño listaba `ClassOffer` completo — con
   profesor, materia y precio — a CUALQUIER visitante, sin autenticación
   alguna** (docblock del método `index()`, ya citado en la sección P0 de
   arriba). Es decir: la combinación (nombre del profesor, materia,
   tarifa) que `?offer_id=` seguía exponiendo tras la corrección de
   `4df28d1` es exactamente el mismo nivel de dato que ya era público sin
   ningún login antes del rediseño.
3. **`TeacherPublicController::show()` expone hoy mismo, a cualquier
   visitante sin sesión, ese mismo nivel de dato** (nombre, materias,
   tarifa por hora) para cualquier profesor verificado — verificado en la
   sección P0 de arriba.

**Conclusión, con evidencia, no por intuición**: no existía ninguna
frontera de autorización que cruzar — `ClassOffer` (de un profesor
verificado) nunca fue un recurso privado en el modelo de negocio de MOVA,
es el mismo nivel de "anuncio profesional público" que el Marketplace y el
perfil público ya muestran. El P0 real y ya corregido en `4df28d1` es
**data minimization**: la fuga no era "Parent A ve algo que no debería
poder ver", era "el endpoint devolvía campos que ni siquiera el
Marketplace público expone" (`yape_number`, `plin_number`,
`referral_code`, y el `User` completo del profesor). Se mantiene la
severidad P0 de esa parte — el dato expuesto sí era sensible — pero se
corrige la clasificación: **no es BOLA**, y no se introduce una
autorización nueva que el modelo de negocio nunca tuvo.

**Categoría superior adoptada para futuros hallazgos de esta familia**,
como se pidió — `RESOURCE ACCESS & DATA PROJECTION`, con dos dimensiones
que se evalúan siempre por separado, con evidencia, nunca asumidas:

```text
A. Data minimization / projection — ¿el endpoint devuelve más de lo que
   necesita, incluso a un consumidor legítimamente autorizado?
B. Object-level authorization — ¿existe una frontera de autorización real
   en el modelo de negocio que el endpoint esté saltándose?
```

En el caso de `?offer_id=`: **A confirmado y corregido, B evaluado y
descartado con evidencia** (no hay frontera B que exista para este
recurso).

### Verificación del resto del flujo `ClassRequests/Create` → aceptación (leído, no asumido)

Se rastreó la cadena completa `ClassRequestController::store()` →
`ClassRequestPolicy::accept()` → `LessonController::store()` — el punto
donde de verdad se mueven créditos y se fija el precio. Cada invariante
que se pidió verificar, con el archivo/línea real:

| Invariante | Estado | Evidencia |
|---|---|---|
| Student ownership | ✅ Ya correcto | `ClassRequestController::store()`: `auth()->user()->students()->findOrFail($data['student_id'])` — un `student_id` de otro padre lanza 404, no se puede crear la solicitud |
| Teacher/subject/offer válido en la aceptación | ✅ Ya correcto | `ClassRequestPolicy::accept()`: profesor debe estar `is_verified`; si la solicitud está vinculada a un profesor específico (código de referido), es EXCLUSIVA de ese profesor; si no, exige que el profesor sea dueño de la oferta o enseñe la materia — nunca confía en lo que el cliente afirma |
| Precio autoritativo del servidor | ✅ Ya correcto | `LessonController::store()`: `$rate = $classRequest->classOffer?->specific_rate ?? $teacherProfile->hourly_rate` — ambos valores de BD, el cliente solo envía `class_request_id`/`start_time`/`duration_minutes`, nunca un precio |
| Créditos autoritativos + a prueba de carrera | ✅ Ya correcto | `$creditsNeeded = Lesson::creditCostForMinutes(...)` (servidor) verificado contra `TeacherProfile::lockForUpdate()` DENTRO de la transacción — no la lectura previa a la transacción, la relockeada — evita TOCTOU |
| Disponibilidad validada server-side | ✅ Ya correcto | `hasScheduleOverlap()` se llama dos veces: una vez fuera de la transacción (fail-fast de UX) y otra vez DENTRO con `lockForUpdate()` sobre las lecciones candidatas — un segundo intento concurrente no puede colar un solape |
| Orden de lock contra doble reserva | ✅ Ya correcto — **lock ordering verificado, no paralelismo real ejecutado** | El lock de `TeacherProfile` actúa como mutex por profesor: dos `accept()` para el mismo profesor se serializan por el orden lock→check→create; verificado con dos llamadas SECUENCIALES en el test (la segunda ve el estado que la primera ya confirmó) — PHPUnit no ejecuta dos transacciones en paralelo real, ver la nota de precisión más abajo |
| Doble-aceptación del mismo `ClassRequest` | ✅ Ya correcto — mismo matiz | `abort_if($classRequest->status !== 'open', ...)` reevaluado DENTRO de la transacción con `lockForUpdate()` sobre el propio `ClassRequest` — un segundo accept tras el primero ve `status === 'accepted'` y aborta; no es "idempotencia" en sentido estricto, es una máquina de estados protegida por lock (ver la sección de terminología más abajo) |

**Nada de esto se tocó ni se "arregló" — ya estaba bien construido.**
Documentarlo como verificado (no asumido) es en sí mismo el resultado de
esta pasada: la superficie más sensible de dinero/menores de MOVA
(aceptar una solicitud, reservar créditos, fijar precio) ya tenía las
protecciones correctas antes de esta sesión.

### Dos hallazgos reclasificados de P3 a P2 — y RESUELTOS, no solo documentados

Revisión aceptada: "el botón se deshabilita" es deduplicación de UI, no
idempotencia de request; y "la aceptación bloquea el daño después" no es
lo mismo que "no crear un estado inválido desde el principio". Ambos se
resolvieron en el código, no se dejaron como nota (commit `19518a5`).

#### Corrección de terminología: **NO es idempotencia estricta**

El mecanismo implementado se nombra correctamente **Temporal Semantic
Deduplication**, no *idempotencia*. La diferencia es real, no solo de
vocabulario:

```text
Idempotency-Key (no implementado)     Temporal Semantic Deduplication (esto)
──────────────────────────────────    ───────────────────────────────────────
El cliente genera un identificador    El servidor infiere "misma intención"
único por intento de envío            a partir del contenido del payload
El mismo request se reconoce          Dos requests con contenido idéntico
de forma inequívoca, sin importar     dentro de una ventana de 30s se tratan
cuánto tiempo pase                    como el mismo — fuera de la ventana,
                                       o entre sesiones/dispositivos
                                       distintos, NO hay garantía
```

**Límite documentado explícitamente, no escondido**: esta deduplicación
NO protege contra un reintento que llegue después de 30 segundos, ni
contra dos clientes/pestañas distintos enviando la misma intención de
forma independiente fuera de esa ventana. Para el alcance actual de MOVA
(creación de una `ClassRequest`, sin dinero/créditos de por medio en ese
paso — eso ocurre recién en la aceptación, ya protegida por
`lockForUpdate()`) esto es una decisión de diseño proporcionada, no un
atajo. Si en el futuro el flujo de creación empieza a mover dinero
directamente, o necesita reconciliarse entre sesiones/dispositivos, ahí sí
correspondería una `Idempotency-Key` formal generada por el cliente — se
deja registrado como deuda de diseño futura, no como bug actual.

- **Deduplicación temporal de reintento de red** (P2 → RESOLVED). La
  pregunta correcta, como se pidió, no era "¿existe idempotencia?" sino
  "¿qué debe pasar cuando la misma intención llega dos veces?" — se
  decidió explícitamente: dos solicitudes con exactamente la misma huella
  (mismo alumno, materia, texto de `help_needed`, `is_mentorship`,
  `class_offer_id`, `teacher_profile_id` ya resuelto) dentro de 30
  segundos son la MISMA solicitud lógica y deben colapsar en una fila; la
  misma intención minutos después es una solicitud nueva y legítima, y no
  debe bloquearse. Implementado sin migración de esquema ni clave de
  idempotencia generada por el cliente: la transacción bloquea
  (`lockForUpdate()`) la fila del `Student` ya autorizado — el mismo
  patrón ya establecido en `LessonController::store()` con
  `TeacherProfile` — y busca una `ClassRequest` con la misma huella creada
  dentro de la ventana antes de insertar. **Orden verificado, no
  asumido**: el lock ocurre ANTES de buscar el duplicado y ANTES de
  crear (`Student::lockForUpdate()` → `ClassRequest::where(...)` →
  `new ClassRequest(...)`) — el orden inverso (buscar duplicado, luego
  bloquear) sí tendría TOCTOU; este no lo tiene.
- **Validación temprana de oferta** (P2 → RESOLVED). Antes solo se
  comprobaba el estado de la oferta en el camino de mentoría.
  **Disambiguado explícitamente, como se pidió**: son dos campos de dos
  modelos distintos, no un flag ambiguo — `ClassOffer.is_active`
  (¿el profesor sigue ofreciendo esto?) y `TeacherProfile.is_verified`
  (¿el profesor pasó verificación de admin?), comprobados juntos:
  `$offer->is_active && $offerTeacherProfile?->is_verified`. Ahora se
  comprueba para las dos rutas (mentoría y regular), reutilizando la
  misma oferta/perfil ya cargados para el check de cupos existente.
  **Se mantiene, explícitamente, que esto NO sustituye la validación de
  `ClassRequestPolicy::accept()`** — entre crear y aceptar, el estado de
  la oferta/profesor puede cambiar (un admin podría desverificar al
  profesor, o el profesor desactivar la oferta), así que `accept()` sigue
  revalidando desde cero, no confía en que `create()` ya lo comprobó.

#### Matriz de comportamiento de la deduplicación (pedida explícitamente, verificada, no asumida)

| Situación | Resultado | Test |
|---|---|---|
| Mismo intento, reenviado inmediatamente | 1 fila | `test_resubmitting_the_exact_same_intent_within_the_window_does_not_duplicate` |
| Mismo intento, exactamente a los 30s | 1 fila (límite inclusivo) | `test_the_30_second_window_boundary_is_inclusive_then_expires` |
| Mismo intento, a los 31s | 2 filas (nueva solicitud) | mismo test, tercer paso |
| Distinta materia | 2 filas | `test_a_genuinely_different_request_right_after_is_not_treated_as_a_duplicate` |
| Distinto profesor (código de referido) | 2 filas | `distinctIntentProvider` |
| Distinto `is_mentorship` | 2 filas | `distinctIntentProvider` |
| Distinto texto de `help_needed` (aunque sea un edit mínimo) | 2 filas — **deliberado, heurístico documentado, no un bug** | `distinctIntentProvider` |
| Duración (`duration_minutes`) | N/A — no es un campo de `store()`, se decide en `LessonController::store()` al aceptar, no en la creación | — |
| Dos envíos "simultáneos" (mismo proceso PHPUnit, secuencial) | 1 fila | `test_two_sequential_submissions_for_the_same_intent_never_produce_two_rows_matching_the_locking_order` — **no es una prueba de concurrencia real** (PHPUnit no ejecuta en paralelo); sigue la misma técnica ya establecida en `FinancialConcurrencyTest::test_the_same_class_request_cannot_be_accepted_twice()` (llamada A, luego B, se confirma que B ve el estado que A ya confirmó). La garantía real contra concurrencia VERDADERA viene del orden lock→check→create dentro de la transacción, no de este test — el test documenta y protege ese orden, no lo demuestra bajo hilos paralelos reales |

`tests/Feature/ClassRequestStoreIntegrityTest.php` — 10 tests en total
(5 originales + 5 de esta pasada). Verificados los 5 originales como
detección real: revertido el controller, confirmado que 3 de los 5
fallaban con el comportamiento exacto de antes (302 en vez de 422, 2 filas
en vez de 1), restaurado. Re-ejecutados `MentorshipRequestTest`/
`TeacherReferralRequestTest` (10 tests que ya cubrían rutas cercanas) para
confirmar cero regresión — ambos siguen en verde.

### Mapa de flujo de datos (pedido explícitamente antes de UX/UI)

| Campo | Fuente | ¿Confiable del cliente? | Validado en | ¿Recalculado? |
|---|---|---|---|---|
| `offer_id` (GET, prellenado) | Cliente | ❌ | Backend (`findOrFail` + proyección explícita) | — (solo lectura, informativo) |
| `student_id` | Cliente | ❌ | Backend (`auth()->user()->students()->findOrFail()`) | — |
| `subject_id` | Cliente | ❌ | Backend (`exists:subjects,id`) | — |
| `class_offer_id` | Cliente | ❌ | Backend (`exists` + `is_active`+`is_verified`, nuevo) | — |
| `teacher_referral_code` | Cliente | ❌ | Backend (resuelto contra `referral_code`+`is_verified`, nunca lo que devolvió `lookupTeacherByCode()`) | ✅ resuelto a `teacher_profile_id` server-side |
| `is_mentorship` | Cliente | ❌ | Backend (bool coaccionado) | — |
| `help_needed` | Cliente | ❌ | Backend (`required\|string\|max:2000`) | — |
| `duration_minutes` (en `LessonController::store()`, no en `create()`) | Cliente | ❌ | Backend (`integer\|min:30\|max:240`) | — (es un input válido, no un precio) |
| `start_time` | Cliente | ❌ | Backend (`date\|after:now` + `hasScheduleOverlap()` con lock) | — |
| `specific_rate`/`hourly_rate` (precio base) | BD | ✅ | Backend | ✅ `$rate = classOffer?->specific_rate ?? teacherProfile->hourly_rate` |
| `price_frozen_pen` | Calculado | ✅ | Backend | ✅ `round($rate * $creditsNeeded, 2)` — el cliente nunca envía un precio |
| `credits_available`/`reserved` | BD | ✅ | Backend (`lockForUpdate()`) | ✅ recalculado bajo lock dentro de la transacción |
| Disponibilidad del profesor | BD | ✅ | Backend (`hasScheduleOverlap()`, dos veces, la segunda con lock) | ✅ |

**Principio confirmado, no solo enunciado**: el cliente expresa intención
(`quiero pedir clase de X con el alumno Y`); el servidor determina
validez, precio, créditos y disponibilidad. Ningún campo financiero o de
autorización llega del cliente como valor final — todos se derivan o se
verifican contra la base de datos en el momento de la operación.

### Cierre de esta pasada — estado formal, por dimensión (nunca colapsado en una sola palabra)

```text
Business contract:                VERIFIED
Root cause de ?offer_id:          DATA MINIMIZATION — object-level
                                   authorization evaluada y descartada
                                   con evidencia (ver arriba), no BOLA
Student ownership:                VERIFIED
Teacher/subject/offer integrity:  VERIFIED
Pricing (server-authoritative):   VERIFIED
Credits (server-authoritative +
race-safe):                       VERIFIED
Availability (server-side):       VERIFIED
Accept-time lock ordering:        VERIFIED (lock de TeacherProfile como
                                   mutex; lock de ClassRequest para
                                   doble-aceptación — orden leído en el
                                   código, no asumido)
Transactional protection:         VERIFIED (toda mutación crítica dentro
                                   de DB::transaction())
Duplicate/race detection under
lock:                             VERIFIED por ejecución serial — los
                                   tests confirman que la SEGUNDA llamada
                                   ve el estado que la primera ya
                                   confirmó
Real parallel transaction test
(dos procesos/hilos ejecutando al
mismo instante real):             NOT DIRECTLY EXERCISED — PHPUnit
                                   corre en un solo proceso, secuencial;
                                   ningún test de esta suite (tampoco
                                   FinancialConcurrencyTest, su propio
                                   precedente) ejecuta dos transacciones
                                   verdaderamente simultáneas. La
                                   garantía contra una carrera real
                                   descansa en el ORDEN de las
                                   operaciones dentro de la transacción
                                   (lock→check→create), verificado por
                                   lectura de código — no en haber
                                   observado paralelismo real bajo carga.
                                   No se afirma más que esto.
Temporal semantic deduplication:  VERIFIED (ventana de 30s, matriz
                                   completa de comportamiento arriba)
Strict request idempotency:       NOT IMPLEMENTED / NOT REQUIRED FOR
                                   CURRENT SCOPE — registrado como deuda
                                   de diseño futura, no como bug
Early offer validation:           VERIFIED (is_active de ClassOffer +
                                   is_verified de TeacherProfile,
                                   disambiguado explícitamente)
Data-flow map:                    COMPLETO (tabla arriba)
Full suite:                       541/541
```

**Nunca se afirma "fully idempotent"** para describir el mecanismo
actual — es deduplicación semántica temporal, con su límite documentado
explícitamente, no una garantía de idempotencia formal. Con esto, el
contrato de negocio de `ClassRequests/Create.vue` queda verificado y los
dos hallazgos reales resueltos con evidencia — la fase de UX/UI puede
empezar sin dejar sin comprobar ningún invariante de dinero/autorización/
concurrencia/duplicación detrás del diseño.

### Matriz de existencia/finalidad de datos en `Create` (verificada leyendo el código real, no asumida — corrige la premisa de diseño antes de tocar UI)

Pedida explícitamente antes de diseñar, porque la respuesta cambia
sustancialmente lo que la pantalla puede mostrar honestamente. Leído
`ClassRequests/Create.vue` completo + `TimeSlotPicker.vue` +
`ClassRequestController::create()`/`store()` + `LessonController::store()`:

| Dato | ¿Existe en `Create`? | ¿Es definitivo en este punto? | Fuente real |
|---|---|---|---|
| Profesor | Solo si se llegó vía `?offer_id=` (nombre, tarifa/hora) — si no, la solicitud queda abierta a "el primer profesor disponible" (o exclusiva de un profesor si se usa código de referido) | Parcial — identificado, pero la aceptación puede no ocurrir nunca | `offer.teacher_profile.user.name` / código de referido resuelto en `store()` |
| Alumno | Sí | Sí — elegido explícitamente, ownership verificado server-side | `form.student_id` |
| Materia | Sí (propia o heredada de la oferta) | Sí | `form.subject_id` |
| Modalidad (mentoría) | Sí, checkbox | Sí, es la intención declarada | `form.is_mentorship` |
| **Horario/fecha específica** | **❌ NO EXISTE en esta pantalla** — `TimeSlotPicker` recoge una preferencia LAXA (mañana/tarde/noche/flexible vía `TIME_SLOTS`), nunca una fecha+hora concretas | No aplica — no es un slot reservable, es una preferencia orientativa | `form.preferred_times` (array de etiquetas, no de horarios) |
| **Duración** | **❌ NO EXISTE en esta pantalla en absoluto** — no es un campo de `ClassRequestController::store()` | No aplica | Se decide recién en `LessonController::store()`, `'duration_minutes' => 'required\|integer\|min:30\|max:240'`, cuando el PROFESOR acepta y agenda |
| **Precio** | Solo como referencia informativa cuando hay oferta (`S/ XX/h`, una tarifa por hora, no un total) | **NO** — sin duración todavía definida no puede existir un precio total; lo que se muestra hoy es una tarifa de referencia, nunca "el precio de esta clase" | `offer.specific_rate`/`teacher_profile.hourly_rate` |
| **Créditos** | **❌ NO EXISTE — y NUNCA debe existir en esta pantalla** | No aplica al padre en ningún punto del flujo | Los créditos son exclusivamente del PROFESOR — se descuentan de `TeacherProfile.credits_available` en `LessonController::store()`, invisibles para el padre. Confirmado: `ClassRequests/Accept.vue` (donde sí aparecen créditos) es la pantalla del PROFESOR (`teacher.requests.accept`, ruta bajo `role:teacher`), no del padre — grepeado en todo `resources/js/Pages/ClassRequests` y `Dashboard/Parent.vue`: ningún archivo del lado padre muestra créditos |

**Consecuencia directa para el diseño, no negociable**: `Create.vue` no
debe mostrar "Precio final", "Duración", "Créditos utilizados" ni "Saldo
restante" — ninguno de esos datos existe todavía en este punto del flujo,
y "créditos" no es un concepto que el padre deba ver jamás. La pantalla
es, honestamente, una **expresión de intención abierta** (¿qué necesita mi
hijo, con qué profesor si ya tengo uno en mente, y cuándo suelo estar
disponible?), no una cotización ni una reserva confirmada. El diseño debe
comunicar exactamente eso — qué pasa después (un profesor la acepta y
recién ahí se fija horario y precio) — no inventar una sensación de
cierre que el backend no respalda.

### Cierre del rediseño de `Create.vue` — estado formal, por dimensión

Commit `f7b03b7`. Nunca colapsado en "DONE" — cada fila es lo que
realmente se verificó, con su método, no una afirmación de intención.

| Dimensión | Estado | Evidencia |
|---|---|---|
| Decisiones de UX | ✅ Aplicadas | Estructura intención→revisión→confirmación; tarjetas de modalidad con copy que explica la consecuencia real (`is_mentorship` → `hasAvailableMentorshipSlots()`); horario marcado explícitamente "preferencia orientativa"; resumen "Antes de enviar" inmediatamente antes del CTA |
| Supuestos de negocio | ✅ Verificados contra código, no asumidos | Matriz de existencia/finalidad arriba — leída de `ClassRequestController::create()`/`store()`, `LessonController::store()`, `TimeSlotPicker.vue`, no inferida |
| Contrato de backend usado | ✅ Sin cambios de contrato — solo consumo | `student_id`, `subject_id`, `class_offer_id`, `teacher_referral_code`, `is_mentorship`, `help_needed`, `preferred_times`; errores ahora vía `form.errors` gracias a `206d886` |
| Campos mostrados | Alumno, materia, profesor/oferta (si aplica), modalidad, código de referido, necesidad, preferencia horaria, resumen pre-envío | — |
| Campos omitidos deliberadamente | Precio final, duración, créditos — ninguno existe en este punto del flujo (ver matriz arriba); no es una omisión de diseño, es la ausencia real del dato | — |
| Estados implementados | Inicial/vacío, carga (`Enviando...` + `aria-busy` vía `PrimaryButton`), CTA deshabilitado hasta alumno+materia, error de validación por campo (`InputError`), código de referido no encontrado (verificado en vivo, dos veces: feedback live-lookup + `form.errors`), oferta inactiva/no verificada, cupo de mentoría lleno — estos tres últimos vía `ValidationException` real, no simulados | Suite completa 542/542; happy path y error de código verificados en navegador real |
| Accesibilidad | Parcial, verificado con evidencia real, no asumido | `aria-pressed` en las tarjetas de modalidad y en los botones de horario (confirmado por grep); orden de tabulación llega a todos los controles incluidas las tarjetas y los botones de horario (confirmado con `document.activeElement`, no solo visualmente); anillo de foco visualmente distinto del indicador de "seleccionado". **Gap cerrado en `bc84e70` (esta fila quedó desactualizada tras ese commit — corregido aquí)**: `InputError.vue` ya tiene `role="alert"` + `aria-atomic="true"` (commit `bc84e70`), verificado en vivo en 3 formularios reales (Login, Register, Teacher/Credits), no solo por build. Sigue sin `aria-describedby`/`aria-invalid` conectando cada `<input>` con su `InputError` correspondiente — ese es el gap real que queda abierto (clasificado `P2 — accessibility`, deliberadamente no resuelto en la misma pasada por ser un componente compartido por toda la app que merece su propia auditoría, no un ajuste aislado) |
| Responsive | ✅ Verificado en navegador real a 375px | Grid de horario 2 columnas sin overflow, tarjetas de modalidad legibles, CTA deshabilitado correctamente renderizado; no verificado en tablet (768px) — pendiente, bajo riesgo dado que el layout es una sola columna fluida sin breakpoints intermedios en este archivo |
| Modo oscuro | ⚠️ BLOCKED — no aplicable todavía, no es una regresión de este cambio | Confirmado por grep: `darkMode` está configurado en `tailwind.config.js` pero cero archivos `.vue` usan clases `dark:` y nada escribe `[data-theme]` — el modo oscuro no existe en ninguna pantalla de la app todavía (es la Fase 1 de un plan de sistema de diseño más amplio, sin empezar; ver plan `jolly-squishing-kazoo.md`). Emulé `prefers-color-scheme: dark` y, correctamente, no cambió nada — eso es lo esperado, no un fallo |
| Performance | No medido específicamente para este archivo | `npm run build` completó sin errores/warnings nuevos; no se capturó el tamaño de bundle antes/después de este cambio puntual (la línea base de bundle completo de la app está en el plan de sistema de diseño, no en esta pasada específica) |
| Tests | ✅ 542/542 (1712 assertions) | Ejecutado con el commit del rediseño ya aplicado; ningún archivo PHP tocado en este commit, así que la suite no podía romperse por este cambio — se re-ejecutó de todos modos por disciplina, no por sospecha |
| Build | ✅ Sin errores/warnings nuevos | `npm run build` |
| Navegador (`BROWSER_VERIFIED`) | ✅ Interacción real, no solo build | Login real (`ana@mova.test`), llenado de formulario con datos reales, envío con código inválido → error confirmado llegando al usuario (antes era código muerto), envío happy-path → fila real creada en MySQL local (`ClassRequest#19`, verificado por `tinker`, no solo por la UI) con `is_mentorship=true` y el resto de campos exactos, redirect + flash "Solicitud enviada." confirmado |
| Riesgos remanentes | `InputError` sin `role="alert"` (arriba); tablet sin verificar explícitamente; activación por teclado (Enter/Espacio) de los botones de horario/modalidad no se pudo re-confirmar con esta herramienta de automatización por una limitación del dispatch sintético de teclas (el click con mouse sí togglea correctamente, y el código es un `<button type="button">` nativo sin ningún `@keydown` que intercepte — la activación por Enter/Espacio es comportamiento por defecto del navegador para cualquier botón nativo enfocado, no algo que la app implemente o pueda romper, así que el riesgo real es bajo, pero no está re-verificado con teclado físico) | — |
| Git | ✅ Commit atómico `f7b03b7`, separado del fix de backend (`206d886`) y de la documentación de la matriz (`abff3ff`) | Sin push — sigue bloqueado por la rotación pendiente de F-26, no relacionado con este cambio |

---

## 🔍 `ClassRequests/Accept.vue` — auditoría de seguridad + integridad financiera (backend primero, antes de tocar UI)

Instrucción explícita para esta pasada: `Register.vue` y
`ClassRequests/Create.vue` quedan cerrados salvo evidencia nueva — no se
reabren aquí. El siguiente objetivo del journey
(Discovery→Request→**Acceptance/Scheduling**→Lesson→Settlement) es esta
pantalla, y por ser la superficie donde se deciden duración, horario,
precio y créditos, se audita como operación transaccional de negocio, no
como formulario — exactamente el mismo orden que `Create.vue`: leer el
contrato real primero, reproducir los caminos de riesgo, corregir solo lo
que de verdad está roto, verificar, y recién después UX.

### GET/prepare — `ClassRequestController::accept()`

```text
GET /teacher/requests/{classRequest}/accept
```

Verificado leyendo el archivo completo, no asumido:

| Invariante | Estado | Evidencia |
|---|---|---|
| Autorización del profesor | ✅ ya sólida, sin tocar | `$this->authorize('accept', $classRequest)` → `ClassRequestPolicy::accept()`: exige `is_verified` (hallazgo crítico ya corregido en 2026-08-22, con test), respeta el código de referido exclusivo si existe, y si no, matchea por oferta o por materia — nunca confía en `class_offer_id`/`subject_id` del cliente porque ninguno de los dos viene del cliente en este endpoint (son del modelo ya cargado) |
| Proyección de datos | 🔧 **corregido en esta pasada** | `$classRequest->load(['student', 'subject'])` sin restricción de columnas serializaba `Student` completo — incluye `birth_date` y `school`, datos reales de un menor, a un profesor que ni siquiera aceptó todavía la solicitud. Ninguno de los dos se renderiza en `Accept.vue` (confirmado leyendo el `<template>`: solo `subject.name`, `student.first_name`/`last_name`, `help_needed`). Restringido a `student:id,parent_user_id,first_name,last_name,grade_level` y `subject:id,name` |
| Estado de la solicitud antes de renderizar | 🔧 **corregido en el Final Integrity Gate** | Reclasificado en revisión de "solo UX" a **lifecycle authorization/data exposure**: la Policy comprueba elegibilidad pero nunca `status`, así que un profesor podía cargar el GET completo para una solicitud ya `accepted` — el POST ya bloqueaba re-aceptar, pero seguía sirviendo nombre/apellido/grado del alumno a un profesor que ya no tiene ninguna acción legítima sobre esa solicitud. Confirmado en vivo ANTES del fix (200 con los datos del alumno intactos) y con test (`test_accept_get_redirects_away_without_exposing_student_data_once_no_longer_open`, revert-confirm-restore real). Ahora: `status !== 'open'` → `redirect()->route('teacher.requests')->with('error', …)` antes de tocar `student`/`subject` en absoluto |

### POST/accept — `LessonController::store()` (`Accept.vue` envía a `route('lessons.store')`, no a un endpoint de `ClassRequestController`)

**Hallazgo principal, corregido**: 5 instancias de `abort()`/`abort_if()`/`abort_unless()` — el mismo patrón de fallo silencioso ya corregido dos veces antes en esta sesión (`ClassRequestController`, `RegisteredUserController`), encontrado aquí por tercera vez. La diferencia que eleva la severidad: el chequeo `status !== 'open'` (una vez antes de la transacción como fail-fast, y otra vez bajo `lockForUpdate()` dentro de ella) **es** el mecanismo real que impide aceptar la misma solicitud dos veces — no una validación decorativa. Con `abort_if()`, el profesor que pierde la carrera veía la página de error genérica de Laravel en vez de cualquier indicación de qué pasó.

**Clasificación explícita por instancia (Final Integrity Gate — revisión solicitada: no todo `abort()` se convierte automáticamente)**:

| Chequeo | Semántica original | Semántica nueva | Por qué |
|---|---|---|---|
| Sin perfil de profesor | `abort_unless(…, 403)` | **Sin cambio — sigue 403** | No es recuperable reenviando este mismo formulario (el perfil se crea atómicamente al registrarse; ver `RegisteredUserController`) — es un estado de cuenta roto, casi inalcanzable en la práctica, más cercano a autorización/precondición que a validación de negocio. Convertirlo a `ValidationException` en un primer intento fue un error de clasificación, corregido: se revirtió a `abort_unless(403)` |
| Solicitud ya no `open` (×2: fail-fast + re-chequeo bajo lock) | `abort_if(…, 403)` | `ValidationException` | El profesor sigue siendo elegible, sigue autorizado — el recurso cambió de estado, no el permiso. Recuperable: puede intentar con otra solicitud |
| Créditos insuficientes | `abort_if(…, 422)` | `ValidationException` | Recuperable: recargar saldo o elegir una duración menor |
| Cupo de mentoría lleno | `abort_unless(…, 422)` | `ValidationException` | Recuperable: intentar más tarde o con otra solicitud |

**Corrección de campo, también solicitada en revisión**: las 3 conversiones de negocio (arriba) usaban inicialmente `duration_minutes` (créditos) y `class_request_id` (status/mentoría) por ser "el campo más cercano" — pero eso sugiere visualmente que cambiar ESE campo resuelve el problema, cuando la solución real (recargar saldo, elegir otra solicitud, esperar cupo) no está en el formulario en absoluto. Las 3 ahora comparten una única clave nueva, `accept` — un error de negocio a nivel de formulario, no de campo. `start_time` sigue reservado exclusivamente para el error que sí es sobre la hora elegida (solapamiento de horario, sin cambios).

`Accept.vue` renderiza un solo banner (`role="alert"`, ligado a `form.errors.accept`) para los tres casos de negocio, separado del aviso ya existente basado en `creditsAvailable` (un snapshot cargado al abrir la página, que puede quedar desactualizado si el profesor aceptó otra clase en otra pestaña) — ambos visibles, sin mezclarse: uno es la señal previa al envío, el otro el veredicto real del servidor.

### Matriz de invariantes (pedida explícitamente, verificada línea por línea contra `LessonController::store()`)

| Campo/Regla | Fuente | Cliente controla | Backend valida | Backend recalcula |
|---|---|---|---|---|
| `class_request_id` | Cliente (elige cuál aceptar) | ✅ | ✅ `exists:class_requests,id` + `authorize('accept', …)` + re-chequeo de `status` bajo lock | — |
| `start_time` | Cliente | ✅ | ✅ `required\|date\|after:now` + `hasScheduleOverlap()` (fail-fast y de nuevo bajo lock) | Normalizado a UTC ISO8601 (`Carbon::parse()->utc()->toIso8601String()`) — transformación, no recálculo de negocio |
| `duration_minutes` | Cliente | ✅ | ✅ `required\|integer\|min:30\|max:240` | — se usa tal cual para créditos/precio, nunca se re-deriva de otra fuente |
| `price` (`price_frozen_pen`) | Servidor | ❌ — no existe en el `validate()`, un intento de enviarlo se ignora silenciosamente | — | ✅ `round($rate * $creditsNeeded, 2)`; `$rate` viene de `classOffer.specific_rate` o `teacherProfile.hourly_rate`, ninguno del cliente |
| `credits` (`creditsNeeded`) | Servidor | ❌ | ✅ contra `credits_available` bajo `lockForUpdate()` | ✅ `Lesson::creditCostForMinutes($duration)` — función server-side, 1 crédito por hora iniciada (`ceil`), el cliente nunca envía un número de créditos |
| `teacher_profile_id` | Servidor (`auth()->user()`) | ❌ | ✅ implícito vía sesión + policy | — |
| `student_id` | DB (vía `classRequest`) | ❌ | ✅ viene de `$classRequest->student_id`, nunca del request | — |

Confirma el principio ya establecido esta sesión: *el cliente expresa intención (qué solicitud, cuándo, cuánto dura), el servidor determina el resultado (con quién, cuánto cuesta, cuántos créditos)*.

### Duración — auditoría solicitada explícitamente

`min:30|max:240`, sin restricción de múltiplos de 30 en el backend — aunque el frontend solo ofrece 4 valores fijos (30/60/90/120), el servidor acepta cualquier entero intermedio (p. ej. 37, 241 sí lo bloquea `max:240` pero 239 no). **No es un bug de negocio**: `Lesson::creditCostForMinutes()` recalcula correctamente para cualquier duración (`ceil(minutos/60)`, mínimo 1 crédito) y `hasScheduleOverlap()` extiende la ventana de solapamiento con la duración real recibida, no con un valor asumido — verificado leyendo ambas funciones. Es una permisividad del backend mayor que la que el frontend ofrece, documentada como tal, no corregida (correspondería a agregar una regla `in:` o `multiple_of` si se decide cerrarla, decisión de producto, no de seguridad).

### `start_time`/disponibilidad

`hasScheduleOverlap()` se ejecuta dos veces: una vez fuera de la transacción (fail-fast, sin lock, para dar feedback rápido) y otra vez dentro, con `lockForUpdate()` sobre las lecciones candidatas — el mismo patrón que el resto de invariantes financieras de esta sesión. Reutilizada también por `reschedule()` (con `$excludeLessonId` para no chocar consigo misma) en vez de una segunda implementación divergente — ya evitó una deuda real documentada en el propio código (comentario `C-2`).

### Concurrencia — terminología precisa, no colapsada

`test_the_same_class_request_cannot_be_accepted_twice` (ya existente, no escrito en esta pasada) prueba dos llamadas HTTP **secuenciales** sobre el mismo recurso — PHPUnit es un proceso único y síncrono, esto no es paralelismo real. Lo que sí demuestra, con evidencia real:

- **Orden de lock verificado**: `lockForUpdate()` sobre `ClassRequest` antes del re-chequeo de `status`, y sobre `TeacherProfile` antes de tocar créditos.
- **Rollback atómico verificado**: el profesor que pierde la carrera termina con `credits_available`/`credits_reserved` sin tocar (10/0, confirmado por el test) y sin fila en `classes` — no créditos parcialmente reservados con una lección inexistente.
- **Creación única de `Lesson` verificada**: `assertDatabaseCount('classes', 1)` tras el intento duplicado.
- **Ejecución paralela real**: **NO EJERCITADA** — ni por este test ni por la verificación en vivo de esta pasada (dos requests HTTP reales, pero secuenciales: el profesor A completó su aceptación antes de que el profesor B siquiera cargara la página). No se afirma paralelismo real en ningún punto de este documento para este hallazgo.

### Rollback — verificado, no asumido

Mismo test: created `Lesson` + `credit_transactions.reservation` + `class_requests.status='accepted'` para el ganador, CERO cambios para el perdedor — la transacción de este último nunca llegó a ejecutar ninguna escritura (el `throw` ocurre antes de `Lesson::create()`). No hay escenario de "créditos actualizados pero lección no creada" porque todas las escrituras viven dentro del mismo `DB::transaction()` y el `throw` de cualquier `ValidationException` lo revierte completo (Laravel captura cualquier `Throwable` dentro de `DB::transaction()`, no solo excepciones de base de datos).

### Máquina de estados

`class_requests.status`: `pending_parent_approval → open → accepted | rejected | completed` (enum verificado en la migración). `accepted` es terminal respecto a una segunda aceptación — verificado, no asumido: el re-chequeo bajo lock dentro de la transacción es exactamente lo que lo hace terminal en la práctica, no solo el enum.

### Notificaciones — verificado el orden exacto

Preocupación explícita de esta pasada: que "clase aceptada" nunca se le diga al padre antes de que la transacción haya comprometido de verdad. Confirmado leyendo ambos archivos:

```text
DB::transaction() { … Lesson::create() … $teacherProfile->update() … }  ← termina, commit real
        ↓ (línea siguiente, fuera de la transacción)
event(new ClassConfirmed($lesson))
        ↓
SendClassConfirmationNotifications implements ShouldQueue  ← encolado, no síncrono
```

El evento se dispara en la línea inmediatamente posterior al cierre de `DB::transaction()`, nunca dentro — si la transacción hubiera lanzado, la ejecución nunca llega a esa línea. El listener implementa `ShouldQueue`, así que además de estar correctamente secuenciado, no bloquea la respuesta HTTP al profesor.

### Taxonomía de errores — cubiertos en esta pasada

`request no encontrada` (`findOrFail`, 404 nativo de Laravel) · `sin perfil de profesor` (403, autorización — sin cambios) · `no autorizado` (policy, 403) · `solicitud ya no open` (×2, fail-fast y bajo lock, `ValidationException`/`accept`) · `horario ocupado` (×2, `ValidationException`/`start_time`, ya estaba bien antes de esta pasada) · `créditos insuficientes` (`ValidationException`/`accept`) · `cupo de mentoría lleno` (`ValidationException`/`accept`). Cada error de negocio ahora resulta en un redirect-back con `session('errors')`, nunca en la página de error genérica de Laravel; los dos únicos casos que siguen siendo un `abort()` crudo (perfil inexistente, autorización) lo son deliberadamente, no por descuido — ver clasificación arriba.

### Lo que esta pasada deliberadamente NO hizo

- **No tocó `join()`/`confirmPayment()`/`cancel()`/`reschedule()`** — mismo patrón de `abort_if()` crudo presente ahí también, pero pertenecen a páginas distintas (`Lessons/ParentIndex.vue`, `Lessons/TeacherIndex.vue`), fuera del alcance de "Accept.vue". Flageado por separado (`task_29e7c685`), priorizado en revisión: `reschedule()` primero (historial real de riesgo de integridad — `duration_minutes` sin recalcular precio/créditos, ya corregido antes en `C-2`, ver comentario en el propio código), luego `confirmPayment()` (financiero), luego `cancel()`, luego `join()`.
- **No rediseñó `Accept.vue`** — instrucción explícita: primero backend verificado, después UX. La pantalla sigue siendo el formulario original salvo los slots de error.
- **No afirma paralelismo real** en ningún punto — ver sección de concurrencia arriba.
- **No implementó** idempotency-key formal, infraestructura de test paralelo, una capa DTO nueva, ni un sistema de locking o notificaciones nuevo — ninguno de los gates de esta pasada mostró evidencia de que la arquitectura actual sea insuficiente; añadir cualquiera de esos ahora sería sobre-arquitecturar sin necesidad demostrada.

### Final Integrity Gate — 6 puntos, todos verificados antes de cerrar

Pedido explícitamente en revisión, como paso final antes de UX (no una auditoría nueva):

1. **GET sobre solicitud cerrada → autorización correcta**: ✅ corregido y verificado (redirect + flash, sin servir datos del alumno) — ver fila del GET arriba y `test_accept_get_redirects_away_without_exposing_student_data_once_no_longer_open`.
2. **Proyección de `Student` verificada**: ✅ ya corregido en la pasada anterior, re-confirmado aquí — solo `id,parent_user_id,first_name,last_name,grade_level`, nunca `birth_date`/`school`.
3. **Créditos insuficientes = sin mutación de DB**: ✅ verificado explícitamente — `credits_available`/`credits_reserved` sin cambios, `classes` en 0, y ahora también `ClassRequest.status` confirmado como `'open'` (la tercera aserción, antes solo inferida, ahora explícita).
4. **Cupo de mentoría lleno = sin mutación de DB**: ✅ ya verificado (mismo patrón: `Lesson::count()===0`, request sigue `'open'`, créditos intactos).
5. **Todos los errores de negocio mapeados semánticamente**: ✅ ver clasificación y corrección de campo arriba — ninguno atado a un campo que no puede resolverlo.
6. **Ningún error de autorización convertido accidentalmente a 422**: ✅ encontrado y corregido — "sin perfil de profesor" había sido convertido en el primer intento; revertido a `abort_unless(403)` tras la clasificación explícita.

### Cierre — estado formal, por dimensión (vocabulario exacto, sin colapsar)

```text
Acceptance business contract:    VERIFIED
Authorization:                   VERIFIED
State transition:                VERIFIED
Pricing:                         VERIFIED
Credits:                         VERIFIED
Availability:                    VERIFIED
Transaction:                     VERIFIED
Notification ordering:           VERIFIED
Student data projection:         VERIFIED
Error protocol:                  VERIFIED
Real parallel concurrency:       NOT DIRECTLY EXERCISED
Strict idempotency:              NOT IMPLEMENTED / NOT REQUIRED FOR CURRENT SCOPE
```

| Dimensión | Estado | Evidencia |
|---|---|---|
| Contrato GET | ✅ Auditado, 2 correcciones (proyección + lifecycle) | `ClassRequestController::accept()` |
| Contrato POST | ✅ Auditado, 4 conversiones a `ValidationException` + 1 revertida a 403 tras clasificación | `LessonController::store()` |
| Autorización | ✅ Ya sólida, verificada, sin tocar | `ClassRequestPolicy::accept()` |
| Duración | ✅ Auditada, permisividad documentada, no es bug | `min:30\|max:240`, recálculo correcto en cualquier punto del rango |
| Horario/disponibilidad | ✅ Ya sólida, verificada, sin tocar | `hasScheduleOverlap()`, doble chequeo con lock |
| Precio | ✅ 100% servidor, cliente no puede imponerlo | Verificado: no existe en `validate()` |
| Créditos | ✅ 100% servidor, verificado bajo lock, sin mutación en fallo | `Lesson::creditCostForMinutes()` + Gate #3 |
| Transacción/locking | ✅ Ya sólida, verificada, sin tocar | `lockForUpdate()` en `ClassRequest` y `TeacherProfile` |
| Rollback | ✅ Verificado (test existente + repro en vivo) | 0 cambios para el perdedor de la carrera |
| Máquina de estados | ✅ Verificada | `accepted` terminal, re-chequeo bajo lock |
| Notificaciones | ✅ Verificado el orden (post-commit, encolado) | `event()` después de `DB::transaction()`, listener `ShouldQueue` |
| Taxonomía de errores | ✅ Clasificada explícitamente (autorización vs. negocio), campos corregidos a `accept` | Ver tabla de clasificación arriba |
| Tests | ✅ 545/545 — 5 tests corregidos/añadidos (4 con el mismo punto ciego de "solo status code" ya visto 2 veces antes esta sesión, +1 nuevo para el gap del GET) | `FinancialConcurrencyTest`, `MentorshipRequestTest`, `MonetizationIntegrityTest` |
| Regression proof | ✅ `git stash` reprodujo cada fallo real antes de restaurar (LessonController y ClassRequestController, por separado) | Revert-confirm-restore |
| Build | ✅ Sin errores/warnings nuevos | `npm run build` |
| Navegador (`BROWSER_VERIFIED`) | ✅ Tres escenarios reales, no solo build/PHPUnit | Carrera de dos profesores reales; créditos insuficientes vía envío real de Inertia (no solo `fetch()` — confirma que el propio `<template>` renderiza `form.errors.accept`); GET sobre solicitud ya `accepted` redirige con flash real, sin datos del alumno en la respuesta |
| Concurrencia real | `NOT DIRECTLY EXERCISED` — ver sección propia, terminología no colapsada | — |
| Idempotencia estricta | `NOT IMPLEMENTED / NOT REQUIRED FOR CURRENT SCOPE` — el mecanismo actual (lock + re-chequeo de estado) protege la invariante real; no hay evidencia de que falte más | — |
| UX/UI | **Deliberadamente no tocado** — siguiente fase | — |
| Riesgos remanentes | ~~4 `abort_if()` restantes~~ **`reschedule()`/`confirmPayment()`/`cancel()`/`join()` ya auditados y cerrados (ver secciones propias abajo)** — el ciclo completo del hotspot `LessonController` (`task_29e7c685`) queda cerrado con esta pasada; `aria-describedby`/`aria-invalid` en `InputError.vue` sigue pendiente (`task_5404e77d`, no relacionado con este bloque) | — |
| Git | ✅ Commits atómicos `0fdc1c2` (hallazgo inicial) + `9a58951` (Final Integrity Gate), separados de su documentación | Sin push — sigue bloqueado por F-26 |

**`ClassRequests/Accept.vue` (backend) → CERRADO PARA ESTE ALCANCE.** Continúa UX/UI: la pantalla debe comunicar alumno, materia, solicitud, horario, duración, precio y créditos según los datos genuinamente disponibles en cada punto del flujo — mismo principio que `Create.vue` ("no muestres valores que el backend todavía no ha determinado"), no una "reserva" antes de que el backend la confirme.

---

## 🔍 `LessonController::reschedule()` — auditoría de contrato (siguiente hotspot priorizado sobre `confirmPayment()`/`cancel()`/`join()`)

Instrucción explícita: no asumir que `reschedule()` equivale a `store()` —
auditar desde cero. Dominio distinto: `store()` crea un `Lesson` desde un
`ClassRequest` abierto; `reschedule()` solo mueve `start_time` sobre un
`Lesson` **ya existente**.

### Hallazgo central: la duración ya es inmutable, por diseño previo

Leído el código completo antes de asumir nada: `reschedule()` **rechaza la
sola presencia** del campo `duration_minutes` en la request (no solo un
valor distinto — cualquier valor, incluso igual al original). El propio
comentario del código (`C-2 v1`) documenta que esto cierra un exploit real
anterior a esta sesión: un padre podía ampliar una clase de 30 min a 4h
pagando y consumiendo lo de 30 min. Como la duración no puede cambiar,
`price_frozen_pen` y el ledger de créditos **no pueden verse afectados por
este endpoint en absoluto** — no por casualidad, sino porque el camino que
los tocaría está bloqueado antes de llegar ahí. Responde directamente la
pregunta central pedida en revisión ("¿qué pasa financieramente cuando una
clase cambia de duración?"): no puede cambiar de duración vía `reschedule()`
— cambiarla de forma seguro (recalculando costo/créditos/ledger) queda
explícitamente fuera de este v1, para una v2 futura no planificada aún.

### Clases ya liquidadas — ya protegido

`abort`/ahora `ValidationException` si `status !== 'scheduled'`, verificado
contra el enum completo (`paid`, `pending_parent_confirmation`, `completed`,
`cancelled`, `needs_admin_review`) — probado exhaustivamente por
`RescheduleTest::test_reschedule_is_rejected_for_every_non_scheduled_status`
(ya existente, no escrito en esta pasada) con los 5 estados reales, no
inventados. Una clase pagada/completada/cancelada no puede reprogramarse.

### Autorización — ya sólida

`LessonPolicy::reschedule()`: profesor asignado o padre dueño del alumno,
admin vía `before()`. Verificado con 4 tests ya existentes (dueño ✅,
profesor asignado ✅, padre ajeno ❌, profesor ajeno ❌).

### Lo que sí era un hallazgo real, corregido en esta pasada

1. **Clasificación de excepciones** (mismo patrón ya corregido en `store()`):
   3 `abort()`/`abort_if()` crudos, ninguno de autorización (esa ya corre por
   `authorize('reschedule', …)`, sin tocar) — los 3 son conflictos de estado
   de recurso, reclasificados a `ValidationException`. `duration_minutes`
   mantiene esa clave literal (el mensaje sí es sobre ese campo concreto);
   "la lección ya no está `scheduled`" usa una clave de negocio propia,
   `reschedule` — mismo patrón que `accept` en `Accept.vue`, no reutiliza
   `start_time` ni inventa uno nuevo.
2. **Proyección de datos** — mismo root cause que `ClassRequestController::
   accept()`, encontrado en las dos páginas que alojan la UI de
   reprogramar/cancelar: `teacherIndex()` serializaba `student` completo
   (`birth_date`/`school`, sin uso — `TeacherLessonCard.vue` solo lee
   `first_name`/`last_name`); `parentIndex()` serializaba `teacherProfile.
   user` completo (email/teléfono/hashes del profesor — `ParentLessonCard.vue`
   solo lee `user.name`, `yape_number`, `plin_number`, estos dos últimos
   legítimamente necesarios porque así paga el padre). Ambos restringidos a
   columnas explícitas.
3. **Consumo en frontend** — encontrado al verificar el fix anterior en
   vivo, no en el código a simple vista: tanto `TeacherIndex.vue` como
   `ParentIndex.vue` solo leían `e.start_time` en su `onError`, descartando
   en silencio los mensajes reales bajo `reschedule`/`duration_minutes` y
   mostrando siempre el genérico "Error al reprogramar." — mismo patrón que
   `Register.vue`: un fix de backend sin consumo en frontend sigue siendo
   invisible. Corregido en ambos archivos.

### Regression proof

`git stash` sobre `LessonController.php` reprodujo las 9 fallas reales
esperadas (5 aserciones de status-code corregidas a
`assertSessionHasErrors()`, más 4 que dependían indirectamente del
comportamiento corregido) antes de restaurar.

### Concurrencia

`test_reschedule_reevaluates_status_under_lock_not_from_a_stale_read`
(ya existente) prueba que el estado se relee bajo `lockForUpdate()` en vez
de confiar en la instancia cargada antes — **NOT DIRECTLY EXERCISED** para
paralelismo real, mismo límite de PHPUnit ya documentado en toda esta
sesión.

### Cierre — vocabulario exacto

```text
Authorization:              VERIFIED (ya sólido, sin tocar)
Duration contract:          VERIFIED (inmutable por diseño, ya cerrado)
Schedule contract:          VERIFIED (overlap + exclusión propia, ya sólido)
Pricing:                    VERIFIED (no puede cambiar — duración inmutable)
Credits:                    VERIFIED (no puede cambiar — duración inmutable)
State machine:               VERIFIED (5 estados no-scheduled probados)
Concurrency:                 NOT DIRECTLY EXERCISED (mismo límite de PHPUnit)
Transaction/rollback:        VERIFIED (ya sólido, sin tocar)
Notifications:               VERIFIED (post-commit, ShouldQueue, mismo patrón que store())
Data projection:             VERIFIED (corregido en esta pasada — 2 endpoints)
Error protocol:               VERIFIED (corregido en esta pasada — 3 conversiones + 2 fixes de frontend)
Tests:                        545/545 — 5 assertSessionHasErrors() corregidas, 0 nuevas (cobertura ya existía)
Browser:                       VERIFIED (mensaje real confirmado en el modal, no solo el fallback genérico)
```

**`LessonController::reschedule()` → CERRADO PARA ESTE ALCANCE.** Sin
hallazgos de integridad financiera nuevos — el riesgo histórico (`C-2`) ya
estaba cerrado antes de esta sesión, con test real. Los hallazgos de esta
pasada fueron protocolo de error y proyección de datos, el mismo patrón ya
visto en `Create.vue`/`Register.vue`/`Accept.vue`.

---

## 🔍 `LessonController::confirmPayment()` — auditoría de contrato (financiero, priorizado sobre `cancel()`/`join()`)

Instrucción explícita: no asumir que está bien solo porque `accept()`/
`reschedule()` lo estén — auditar desde cero, empezando por la pregunta
que el nombre del método no responde por sí solo: **¿quién puede
confirmar el pago?**

### Quién confirma — verificado, no inferido

`LessonPolicy::confirmPayment()`: `$user->hasRole('parent') && $user->
students()->where('id', $lesson->student_id)->exists()` — **solo el
padre dueño del alumno**, más admin vía `before()`. El profesor **no**
puede confirmar su propio pago — verificado leyendo la Policy completa,
no supuesto por el nombre.

### Qué mueve realmente esta operación — hallazgo central

`confirmPayment()` **no toca créditos, ledger, ni ningún monto** — solo
cambia `Lesson.status` de `'scheduled'` a `'paid'` y registra un
`ClassEvent`. El consumo/devolución real de créditos vive enteramente en
`LessonSettlementService` (código anterior a esta sesión, ya endurecido:
comentarios propios citan `C-1`, `Fase 3B`, `BUG-3`), leído completo antes
de asumir nada:

- **Fuente autoritativa del monto**: `Lesson::reservedCreditAmount()` lee
  el ledger (`credit_transactions` tipo `reservation`) — nunca se
  recalcula desde `duration_minutes`/`price_frozen_pen` al momento de
  liquidar. Congelado en `accept()`, consumido/devuelto exactamente igual
  después, sin importar qué cambie mientras tanto.
- **Idempotencia real, no solo protección de transición de estado**: el
  `UNIQUE` de `credit_transactions.idempotency_key` es la garantía —
  `consume()`/`refund()` intentan el `INSERT` directamente y capturan
  `UniqueConstraintViolationException` para devolver el estado actual sin
  fallar, en vez de confiar solo en un chequeo de `credits_settled_at`
  antes del lock (que dos transacciones concurrentes podrían leer igual).
- **Notificación post-commit, con la razón explícita ya en el código**:
  "una cola con `after_commit=false` podría procesar la notificación
  antes del commit real si se despachara dentro" — exactamente la
  preocupación que este documento viene verificando en cada endpoint,
  aquí ya resuelta antes de esta sesión.

### Premisa verificada y descartada: no hay doble representación del dinero

Se pidió explícitamente comprobar si `Lesson.price` y `PaymentOrder.
amount` podían divergir. Rastreado el modelo `PaymentOrder` completo:
pertenece a `RechargeRequest` (un profesor comprándole créditos a MOVA) —
**dominio financiero completamente distinto**, sin relación con
`Lesson`/`confirmPayment()`. `price_frozen_pen` es lo que el padre le debe
al profesor (fuera de la plataforma, Yape/Plin); `PaymentOrder.
amount_minor` es lo que el profesor le paga a MOVA por créditos. Nunca
tocan el mismo dinero ni el mismo registro — el riesgo planteado no existe
en el código real.

### Hallazgo real, corregido en esta pasada

Mismo patrón que `accept()`/`reschedule()`: 2 `abort_unless()`/`abort_if()`
crudos (×2 sitios cada uno: fail-fast + re-chequeo bajo lock = 4 llamadas),
ninguno de autorización (esa ya corre aparte, sin tocar) — ambos
conflictos de estado del recurso. Convertidos a `ValidationException` bajo
una clave de negocio propia, `confirmPayment` — este formulario no tiene
NINGÚN campo (`ParentIndex.vue` lo dispara con un POST de body vacío, solo
un botón), así que no había ningún campo real al que atar el error, mismo
razonamiento que `accept`/`reschedule`.

**Frontend**: mismo hallazgo por tercera vez — `ParentIndex.vue`'s
`confirmPayment()` descartaba en silencio el mensaje real y mostraba
siempre un texto genérico hardcodeado. Corregido a leer `e.confirmPayment`
primero.

### Doble confirmación — verificado en vivo, no solo en PHPUnit

El botón de confirmar pago está oculto por el cliente hasta que la clase
termina (mismo patrón que `canAffordSelected` en `Accept.vue`) — se
verificó vía `fetch()` real contra el servidor, no clic simulado.
Secuencia completa confirmada por inspección directa de base de datos:
confirmación real (`Lesson#12`: `scheduled → paid`, exactamente 1
`ClassEvent`) → segundo intento rechazado con el mensaje real
("Solo se puede confirmar el pago de clases programadas.") → tercer
chequeo de BD: sigue `paid`, sigue exactamente 1 `ClassEvent` — ninguna
transición ni efecto financiero duplicado.

**El `409` investigado, no dejado como anomalía** — pedido explícitamente
en revisión ("hay que averiguar qué significa ese 409, no asumir que fue
un error"). Investigado hasta la causa real, con evidencia:

```text
grep de "409"/Inertia::location() en el paquete inertia-laravel
  → única llamada real: Middleware.php::onVersionChange(), condición
    `$request->method() === 'GET' && X-Inertia-Version no coincide`
  → NO es un 409 de negocio: la app no tiene ningún abort(409, ...) en
    el dominio de Lesson (el único abort(409) real de la app vive en
    RechargeApprovalService — recargas, dominio ajeno)
```

Reproducido de forma limpia (`Lesson#14`, sesión real, cookies frescas):
la respuesta con `status:409` traía **el header `x-inertia-location:
".../dashboard"` y body vacío** — la firma exacta de `Inertia::location()`,
que solo se dispara para `GET`. Mi propia request era un `POST`, así que
esta no fue la respuesta a MI request — fue la respuesta a la
**redirección GET que el `fetch()` del navegador siguió automáticamente**
después de que el `POST` real ya hubiera tenido éxito con un
redirect-back normal, sin llevar el header `X-Inertia-Version` que un
cliente Inertia real sí adjunta. Confirmado contra base de datos:
`Lesson#14` quedó `paid` con exactamente 1 `ClassEvent` — el `POST`
original **sí tuvo éxito**, una sola vez; el `409` fue un artefacto de
usar `fetch()` crudo para probar un flujo que un profesor/padre real
nunca dispara así (`router.post()` de Inertia sí gestiona esto
internamente y nunca expone este código a la aplicación). No es un
hallazgo de negocio, es una particularidad de la técnica de verificación
— documentado con la causa real en vez de dejarlo como misterio.

### Regression proof

`git stash` sobre `LessonController.php` reprodujo las 6 fallas reales
esperadas antes de restaurar.

### Precisiones de vocabulario pedidas en revisión

- **`confirmPayment()` no es la liquidación financiera** — ver sección
  "Qué mueve realmente esta operación" arriba. El nombre del método
  puede inducir a pensar "aquí se cobra"; no es así. No se renombra
  (cambio innecesario sobre código estable), pero queda registrado aquí
  y en `MOVA_SYSTEM_KNOWLEDGE.md` para que un desarrollador futuro no lo
  asuma por el nombre.
- **`ClassEvent::log('payment_confirmed', ...)` es un registro de
  auditoría del cambio de estado, no evidencia del efecto financiero**
  — confirmado leyendo `ClassEvent::log()`: escribe `event_type`,
  `actor_id`, `lesson_id`, `class_request_id`, sin tocar créditos ni
  ledger. Se usó como evidencia de "exactamente una transición de
  estado", nunca como evidencia de "exactamente un efecto financiero"
  (esa evidencia es `credit_transactions`, verificada por separado en
  `LessonSettlementService`, no en este endpoint).
- **Por qué admin puede confirmar pago**: no es una decisión específica
  de `confirmPayment()` — `LessonPolicy::before()` concede acceso total
  a admin para TODAS las abilities de esta Policy (`view`, `cancel`,
  `reschedule`, `confirmPayment`, `createReport`, `createReview`), mismo
  patrón ya verificado en `ClassRequestPolicy`/`RechargeRequestPolicy`
  esta sesión — es la convención "admin = anulación operativa/soporte"
  ya establecida en toda la app, no una excepción de este endpoint.

### Cierre — vocabulario exacto

```text
confirmPayment business contract:  VERIFIED
financial settlement:              VERIFIED ELSEWHERE, en LessonSettlementService — confirmPayment()
                                    no lo ejecuta, solo habilita la elegibilidad (status='paid')
Authorization:                     VERIFIED (solo padre dueño + admin, ya sólido)
State machine:                     VERIFIED (scheduled→paid, doble chequeo bajo lock)
Financial authority (del ledger):  VERIFIED (reservedCreditAmount(), nunca recalculado) — verificado en
                                    LessonSettlementService, no en confirmPayment() mismo
Transaction:                       VERIFIED (ya sólido, sin tocar)
Concurrency:                       NOT DIRECTLY EXERCISED (mismo límite de PHPUnit en toda la sesión)
Retry semantics:                   VERIFIED (segundo intento rechazado, sin duplicar — confirmado en BD, no solo HTTP status)
Data projection:                   VERIFIED (ya corregido en el bloque de reschedule() — misma página)
Error protocol:                    VERIFIED (corregido en esta pasada — 2 conversiones + 1 fix de frontend)
Notifications:                     VERIFIED (post-commit, ShouldQueue, razón documentada en el propio código)
Tests:                             545/545 — 6 assertSessionHasErrors() corregidas, 0 nuevas
Browser:                           VERIFIED (secuencia completa confirmada por inspección directa de BD; el 409
                                    investigado hasta su causa real, no dejado como misterio)
```

**`LessonController::confirmPayment()` → CERRADO PARA ESTE ALCANCE.** Sin
hallazgos financieros nuevos — el mecanismo real de liquidación
(`LessonSettlementService`) ya era sólido antes de esta sesión, y la
premisa de doble representación del dinero (`PaymentOrder` vs. `Lesson`)
quedó descartada con evidencia, no solo con una suposición.

---

## 🔍 `LessonController::cancel()` — auditoría de contrato (financiero, priorizado sobre `join()`)

Instrucción explícita: no asumir que se comporta como `accept()`/
`reschedule()`/`confirmPayment()` — auditado desde cero (`route`, `policy`,
`controller`, `service`, `model`, `tests`, notificaciones).

### Máquina de estados real, reconstruida leyendo `AdminController.php` junto con `LessonController.php` — no asumida

```text
scheduled → cancelled
    vía LessonController::cancel() (profesor asignado o padre dueño)
    o AdminController::cancelLesson() (admin)
    — siempre devolución completa: a 'scheduled' nunca se consumió nada

scheduled / paid / pending_parent_confirmation / needs_admin_review → cancelled
    SOLO vía AdminController::forceRefundLesson()
    → LessonSettlementService::refund() (ya auditado y endurecido, sin
      tocar en esta pasada)

paid / pending_parent_confirmation / needs_admin_review → completed
    SOLO vía AdminController::forceCompleteLesson() → consume(),
    o el scheduler tras la gracia, o una reseña del padre
```

**Responde directamente la pregunta central de la revisión** ("¿puede
cancelarse una clase ya `paid`, y es eso más delicado?"): `LessonController::
cancel()` **solo puede ejecutarse nunca sobre `paid`/`completed`** — el
chequeo `status !== 'scheduled'` (fail-fast y re-chequeo bajo lock) lo hace
estructuralmente imposible por esta vía. El camino de estados más amplios
existe, pero es un mecanismo completamente distinto, exclusivo de admin, ya
auditado en la pasada de `confirmPayment()` (`LessonSettlementService`).

**Duplicación ya reconocida, no un hallazgo nuevo**: `LessonController::
cancel()` y `AdminController::cancelLesson()` son prácticamente idénticos
línea por línea (mismo `reservedCreditAmount()`, mismo `abort` de anomalía
financiera, misma actualización de perfil) — el propio comentario de
`LessonSettlementService` ya lo admite explícitamente: *"Deliberadamente NO
se migran aquí cancel() ni AdminController::cancelLesson()... Migrarlos
queda como deuda técnica explícita (ver FOLLOW-UP), no como descuido."* No
se toca en esta pasada — sería refactorizar código estable sin evidencia
nueva de que sea insuficiente, exactamente lo que la disciplina anti-
sobre-arquitectura de esta sesión pide evitar.

### Hallazgo real, corregido en esta pasada

Mismo patrón que los 3 métodos anteriores: 4 `abort_unless()`/`abort_if()`/
`abort()` crudos (2 chequeos de estado ×2 sitios, 1 captura de anomalía
financiera, 1 chequeo de créditos reservados insuficientes) — ninguno de
autorización (esa ya corre aparte, sin tocar). Convertidos a
`ValidationException` bajo una clave de negocio propia, `cancel` — el
formulario solo tiene `reason` (opcional), ninguno de estos errores es
sobre ese campo.

**Frontend — hallazgo distinto y más severo que en `reschedule()`/
`confirmPayment()`**: ninguno de los dos (`Lessons/TeacherIndex.vue`,
`Lessons/ParentIndex.vue`) tenía `onError` en absoluto para `submitCancel()`
— una cancelación fallida no mostraba NADA, ni siquiera el genérico que
`reschedule()`/`confirmPayment()` ya tenían. Corregido: nuevo ref
`cancelError`, banner con `role="alert"` en ambos modales, `onError` que lee
`e.cancel` primero.

### Tests — 3 huecos reales, no cubiertos por ninguna prueba existente (grepeado antes de asumir)

1. **Doble cancelación** (`test_cancelling_a_lesson_twice_does_not_refund_twice`):
   no existía ninguna prueba de esto — verificado en BD, no solo status
   HTTP: exactamente 1 asiento `refund`, sin duplicar tras el segundo
   intento.
2. **`confirmPayment()` vs `cancel()` sobre la misma lección**
   (`test_confirm_payment_wins_race_against_cancel_on_the_same_lesson`) —
   pregunta específica de la revisión. Prueba **secuencial** (mismo límite
   de PHPUnit documentado en toda la sesión, no paralelismo real):
   `confirmPayment()` gana (llega primero, `scheduled → paid`); `cancel()`
   sobre la lección ya `paid` falla con el mensaje real, sin tocar créditos
   ni ledger una segunda vez.
3. **`reschedule()` + `cancel()` en secuencia**
   (`test_a_rescheduled_lesson_can_still_be_cancelled_and_refunds_correctly`)
   — no es un conflicto real (`reschedule()` nunca saca la lección de
   `scheduled`), pero se verifica que componen correctamente:
   `reservedCreditAmount()` sigue el ledger, no se ve afectado por el cambio
   de horario.

### Efecto financiero explícito de cancelar una `scheduled` (pedido en revisión, no dejarlo implícito)

```text
scheduled → cancelled
  Lesson.status:        scheduled → cancelled
  credits_reserved:     -N (N = reservedCreditAmount(), del ledger)
  credits_available:    +N
  credit_transactions:  +1 fila, type='refund', idempotency_key="lesson:{id}:release"
  mentorship_slots_taken (si is_mentorship): -1 (mínimo 0)
  ClassEvent:            +1 fila, event_type='class_cancelled' (registro de auditoría,
                          no evidencia financiera en sí — mismo matiz ya establecido
                          para 'payment_confirmed' en la pasada de confirmPayment())
  notifications:         ClassCancelledNotification, ShouldQueue, después de
                          DB::transaction() — nunca antes del commit (verificado
                          leyendo el código, mismo patrón que los 4 métodos anteriores)
```

No es "sin efecto financiero" — el efecto real es una devolución completa y
determinista, documentado explícitamente en vez de dejarlo implícito en la
narrativa.

### Precisión de terminología — "carrera" es demasiado fuerte para lo que en realidad se demostró

Corrección explícita pedida en revisión: los tres escenarios verificados en
vivo esta sesión (modal de `cancel()` abierto + estado mutado por fuera +
envío; el mismo patrón antes en `reschedule()`) son **stale UI / TOCTOU de
UX** — una mutación externa del estado mientras la UI seguía mostrando el
estado anterior, seguida de una revalidación correcta bajo lock — no dos
transacciones ejecutándose en paralelo. Se corrige la etiqueta:

```text
Stale state / lifecycle race (TOCTOU de UX):  VERIFIED — el modal detecta
    correctamente que el estado cambió por fuera y muestra el mensaje real
True parallel transaction execution:          NOT DIRECTLY EXERCISED —
    PHPUnit y esta verificación en navegador son ambos de un solo proceso;
    nunca se ejecutaron dos transacciones simultáneas de verdad
```

### Regression proof

`git stash` sobre `LessonController.php` reprodujo la falla real esperada
antes de restaurar.

### Cierre — vocabulario exacto

```text
Authorization:               VERIFIED (profesor asignado o padre dueño; admin vía before(), ya sólido)
State machine:                VERIFIED (scheduled→cancelled únicamente por esta vía — reconstruida completa, no asumida)
Financial impact:             VERIFIED (efecto explícito arriba — siempre devolución completa desde 'scheduled'; nunca toca 'paid'/'completed')
Authoritative financial source: VERIFIED (reservedCreditAmount(), ledger — ya verificado en confirmPayment(), reconfirmado aquí)
Transaction/rollback:         VERIFIED (ya sólido, sin tocar)
Duplicate cancellation:       VERIFIED (nuevo test, revert-confirm-restore)
Cancel vs confirmPayment:     VERIFIED (nuevo test, secuencial — NOT real parallel execution)
Cancel vs reschedule:         VERIFIED (nuevo test — composición confirmada, no era un conflicto real)
Stale state / lifecycle race (TOCTOU de UX): VERIFIED (ver sección propia — no colapsado con paralelismo real)
True parallel transaction execution: NOT DIRECTLY EXERCISED
Notifications:                VERIFIED (post-commit, ShouldQueue — ver efecto financiero explícito arriba)
Data projection:              VERIFIED (ya corregido en el bloque de reschedule() — misma página)
Error protocol:                VERIFIED (corregido en esta pasada — 4 conversiones + 2 fixes de frontend, uno de ellos un onError inexistente; findOrFail()/authorize() sin tocar — 404/403 conservan su semántica, no se convirtieron a `cancel`)
Duplicación cancel()/AdminController::cancelLesson(): DOCUMENTADA, NO CORREGIDA — deuda técnica ya reconocida antes de esta sesión, sin evidencia nueva que justifique tocarla ahora
Tests:                         548/548 — 1 assertSessionHasErrors() corregida, 3 nuevas
Browser:                       VERIFIED (cancelación real vía UI confirmada en BD; stale-state de modal en vivo confirmó el mensaje real donde antes no mostraba nada — ver precisión de terminología arriba)
```

**`LessonController::cancel()` → CERRADO PARA ESTE ALCANCE.** Sin hallazgos
financieros nuevos — la máquina de estados ya limitaba correctamente el
alcance de esta operación a `scheduled`, y la duplicación con
`AdminController::cancelLesson()` ya era deuda técnica reconocida, no un
descubrimiento de esta pasada.

---

## 🔍 `LessonController::join()` + `JitsiModal.vue` + JaaS/JWT — auditoría de frontera de seguridad (una sola frontera, no `join()` aislado)

Auditado explícitamente como **una sola frontera de seguridad**, no
`join()` por un lado y `JitsiModal.vue` por otro: `route` → `LessonPolicy` →
`LessonController::join()` → `JaasService` (JWT) → identidad de sala →
`useJitsiMeet.js` → `JitsiModal.vue` → JaaS/Jitsi. El principio rector,
confirmado en el código real, no asumido: **MOVA autoriza primero, genera
credenciales después** — `join()` decide "¿puede esta persona entrar a esta
clase?" con `authorize('view', $lesson)` + el chequeo de `status` +
`jitsi_room` existente ANTES de tocar `JaasService`; JaaS/Jitsi nunca ve una
petición de un usuario que MOVA no haya autorizado ya.

### Autorización — quién puede entrar, no solo quién puede ver

`LessonController::join()` llama `authorize('view', $lesson)`
(`LessonPolicy`) — el mismo policy que gobierna ver la clase en el listado.
Verificado que esto es correcto y no una falsa equivalencia "ver ≠ entrar":
`LessonPolicy::view()` ya exige ser el profesor asignado
(`teacherProfile.user_id === $user->id`) o el padre dueño
(`student.parent_user_id === $user->id`), y `join()` no añade ni relaja
ninguna condición adicional sobre esa base — la superficie de "quién puede
ver" y "quién puede entrar" son deliberadamente la misma persona, verificado
leyendo `LessonPolicy` completo, no asumido por el nombre del método.
Confirmado con test real pre-existente
(`test_lesson_join_rejects_unrelated_parent_and_teacher`): un profesor B o
un padre no dueño reciben 403 aunque la clase exista y esté dentro de la
ventana horaria — la ventana no sustituye a la autorización, se aplican en
ese orden.

### Ciclo de vida — qué estados permiten entrar, ventana real leída del código

`join()` exige `in_array($lesson->status, ['scheduled', 'paid',
'pending_parent_confirmation'])` — verificado en el propio controlador, no
inferido. `cancelled`/`completed`/`needs_admin_review` quedan fuera
(confirmado con `test_lesson_join_rejects_invalid_lesson_status`, que
prueba `cancelled` y `completed` explícitamente). La ventana horaria real
(F-06, ya endurecida antes de esta sesión, re-verificada aquí) es
server-side, no solo frontend: `config('jaas.join_window_before_minutes')`
(15 min por defecto) antes del inicio, `join_grace_after_minutes` (120 min)
después del fin — ambos leídos de `config/jaas.php`, no supuestos. Cubierto
por `tests/Feature/JitsiAccessWindowTest.php` (leído completo esta pasada,
no solo hasta la mitad): demasiado temprano, días antes, mucho después de
la gracia, exactamente en el borde de apertura, durante la clase, dentro de
la gracia, ventana configurable, y — crítico — un usuario no relacionado
sigue siendo rechazado **incluso dentro de la ventana**
(`test_an_unrelated_user_is_still_refused_even_inside_the_window`),
confirmando que autorización y ventana son dos chequeos independientes, no
uno sustituyendo al otro.

### Contrato del JWT — específico de usuario, de clase y de sala, acotado en el tiempo

`JaasService` firma con RS256 (nunca HS256/secreto compartido), header
`kid` (id de API key de JaaS, distinto del App ID), claims `aud:'jitsi'`,
`iss:'chat'`, `sub:$appId`, `room` (la sala específica de esta lección),
`exp` (nunca 24h fijas — acotado al mismo fin-de-ventana que el propio
endpoint concedería, con piso en `now()+300s` para no emitir un token ya
vencido), `nbf:now-10`, `context.user.{name, moderator}` (moderador
calculado server-side: `$lesson->teacherProfile?->user_id === $user->id`,
nunca confiado del cliente), `context.features.{livestreaming, recording,
transcription}: false`. **Precisión pedida en revisión**: "decodificar un
JWT" no es, por sí mismo, una afirmación de seguridad — cualquiera puede
decodificar el payload de un JWT sin validar nada (es Base64, no
cifrado). Lo que los tests realmente ejercitan, claim por claim, con
`JWT::decode()` de la librería `firebase/php-jwt` contra la **clave
pública real** derivada de la privada de config (no un mock, no un
stub) — que es lo único que demuestra algo, porque `decode()` lanza
excepción si la firma no verifica contra esa clave:

```text
signature:        VERIFIED — JWT::decode() rechaza el token si la firma RS256
                   no valida contra la clave pública real (no se limita a leer
                   el payload sin verificar)
issuer (iss):      VERIFIED — 'chat', confirmado leyendo el payload decodificado
subject (sub):     VERIFIED — $appId, confirmado leyendo el payload decodificado
room claim:        VERIFIED — coincide con el jitsi_room real de la lección,
                   nunca '*' (test_the_jwt_is_still_scoped_to_the_specific_room)
expiration (exp):  VERIFIED — acotado al fin de ventana (F-06), nunca 24h fijas
                   (test_the_jwt_no_longer_lives_for_24_hours,
                   test_the_jwt_expires_around_the_end_of_the_access_window)
permissions:       VERIFIED — context.user.moderator calculado server-side,
                   context.features.{recording,livestreaming,transcription}
                   forzados a false, confirmado en el payload decodificado
```

Ningún token es reutilizable entre lecciones o usuarios — cada `join()`
genera un JWT nuevo, atado a esa lección y ese usuario en ese momento. Lo
que esto NO demuestra: que JaaS en producción aplique estos claims de la
misma forma (ver "JaaS integration" abajo, separado explícitamente).

### Contrato de sala — identidad determinista, sin adivinar

`jitsi_room` se genera una única vez, en `store()`, como
`"mova-lesson-{$lesson->id}-".Str::random(32)` — por lección, no
derivable de datos públicos. `Lesson::$hidden = ['jitsi_room',
'jitsi_password']` a nivel de modelo (defensa en profundidad), reconfirmado
con test ya existente
(`test_lesson_listings_never_expose_jitsi_credentials`): los listados
(`parent.lessons`/`teacher.lessons`) exponen `has_jitsi_room:true` (booleano
seguro) pero nunca el valor real — la sala y el JWT solo se revelan vía
`GET /lessons/{id}/join`, autenticado y autorizado.

### Integración JaaS — la migración histórica de `meet.jit.si`, re-verificada fresca

Re-grepeado el repositorio completo (`app/`, `resources/js/`, `database/`,
`config/`) esta pasada, no asumido resuelto por documentación previa.
**Clasificación explícita por categoría** (encontrar un string en un
comentario viejo no es lo mismo que encontrarlo en una ruta que la
aplicación ejecuta — colapsar ambas cosas es lo que reabriría este asunto
sin necesidad en una futura auditoría):

```text
runtime / production code path → meet.jit.si  :  0 referencias
    (ninguna ruta de código viva, valor de config activo, ni URL construida
    en tiempo de ejecución apunta al Jitsi público — verificado leyendo
    cada match, no solo contando el grep)

documentation / comments / historical strings  :  6 referencias
    LessonController.php, LessonSettledNotification.php, JaasService.php,
    useJitsiMeet.js, una migración, config/jaas.php — cada una es un
    comentario explicando POR QUÉ se migró a JaaS (ej. "Antes: meet.jit.si,
    cuyo embed se corta a los 5 minutos en producción"), no una URL que el
    código construye o visita
```

Verificado explícitamente en las notificaciones, por nombre, como pidió
esta pasada: `LessonSettledNotification.php` y
`ClassReminderNotification.php` llevan ambas una regla explícita contra
incluir `jitsi_room`/`jitsi_password`/una URL directa de reunión en su
payload (citando el fix real previo "C-3", commit `533a799`) —
re-confirmado con grep dirigido sobre las 8 clases de `app/Notifications/*`
que los únicos matches de `jitsi_room`/`jitsi_password`/`jitsi_token` son
comentarios reafirmando la regla, no fugas. `ClassReminderNotification`
enlaza deliberadamente solo a las rutas propias autenticadas de MOVA
(`teacher.lessons`/`parent.lessons`), forzando cualquier intento de unirse a
pasar por `join()`. El objetivo de este bloque no es llegar a "0 apariciones
de la cadena `meet.jit.si` en todo el repo" — sería borrar contexto
histórico útil sin ganar seguridad real; el objetivo es que la ruta de
producción sea exactamente la arquitectura JaaS pretendida, lo cual ya está
confirmado.

### `JitsiModal.vue` — ciclo de vida del componente

`Teleport` a `body`, pantalla completa, `Transition`. Estados cubiertos:
carga (mientras `openJitsi()` resuelve — sin spinner explícito, ver hallazgo
menor abajo), error (`v-if="error"`, mensaje real del backend o de red),
contenido (`#jitsi-container`, vacío hasta que `JitsiMeetExternalAPI`
monta). Cierre (`closeJitsi()`): llama `api.dispose()` + limpia la
referencia, resetea `showingJitsiModal`/`joinError`/`activeLesson` — **no
deja un estado de reunión reutilizable**, verificado en vivo (ver
Navegador abajo). Deliberadamente NO redirige a confirmación de pago/reporte
si el cierre vino de un error (`hadError`) — solo una clase realmente
atendida debe disparar ese flujo.

**Regresión encontrada y corregida en esta pasada** (exactamente el tipo de
hallazgo que justifica auditar `join()` junto con su consumidor completo, no
aislado): `LessonController::parentIndex()` restringía las columnas de
`teacherProfile` (hecho en la auditoría de `reschedule()`, una pasada
anterior) a `id,user_id,yape_number,plin_number` — sin darse cuenta de que
`JitsiModal.vue` lee `lesson.teacher_profile.referral_code` para mostrarle
al padre, durante la clase en vivo, el código del profesor. El campo quedaba
`undefined` en cuanto un padre abría la modal, silenciosamente, sin ningún
test que lo detectara. Corregido en
[LessonController.php](../app/Http/Controllers/LessonController.php)
(`referral_code` añadido de vuelta a la proyección), con un test de
regresión nuevo
(`test_parent_lesson_listing_still_exposes_teacher_referral_code_for_jitsi_modal`)
verificado con el ciclo completo revert→falla→restaura→pasa, y
**re-verificado en navegador real** (ver Navegador abajo) — no solo en test.

**Hallazgo encontrado y corregido en esta pasada**: `openJitsi()` no tenía
guarda de reentrancia — ni `TeacherIndex.vue` ni `ParentIndex.vue`
deshabilitan el botón "Unirse" mientras la petición de `join()` está en
vuelo, así que un doble click podía, en teoría, pisar la variable `api` con
una segunda instancia de `JitsiMeetExternalAPI` antes de que la primera
fuera descartada, dejando una llamada huérfana consumiendo cámara/micrófono.
Corregido con el mismo patrón ya usado en `cancelling`/`rescheduling`/
`payingId` de esas mismas páginas ("F-12"): `if (showingJitsiModal.value)
return` como primera línea de `openJitsi()`, antes de cualquier `await`.
**Verificado en navegador real, no solo leído** (ver Navegador abajo) — no
existe runner de tests JS en el proyecto (confirmado revisando
`package.json`), así que esta guarda no tiene ni puede tener un test
automatizado; la evidencia es exclusivamente de navegador.

**Precisión de terminología, pedida en revisión**: esto es **client-side
reentrancy protection** — un guard en el componente Vue que evita que el
propio cliente dispare una segunda llamada mientras la primera sigue en
vuelo. No es, y no se etiqueta como, idempotencia de servidor. `join()` es
una operación de **solo lectura** (no muta ninguna fila; genera un JWT
nuevo cada vez que se llama, y eso es correcto — cada JWT tiene su propio
`exp`/`nbf` frescos): si dos llamadas reales llegaran al backend en
paralelo, ambas devolverían 200 con un JWT válido cada una, sin ningún
efecto secundario destructivo ni fila duplicada que deduplicar. Por eso
**no se introduce ningún `Idempotency-Key`** para `join()` — sería
resolver un problema que no existe, para una operación que no escribe
estado. La guarda del cliente existe únicamente para evitar la molestia de
una segunda instancia huérfana de `JitsiMeetExternalAPI` en el navegador
del propio usuario, no para proteger al servidor.

Hallazgo menor, NO corregido (deliberado, fuera de alcance de esta pasada):
no hay estado de carga explícito entre "la modal se abre" y "el contenido
aparece" — pantalla oscura con solo el header durante el fetch de
credenciales + la carga del script externo. Es una brecha de pulido UX, no
de seguridad; queda documentada para la fase de rediseño UX/UI unificado que
sigue a este cierre, no resuelta aquí por disciplina de alcance.

### Protocolo de errores

Clasificación verificada, no asumida: no autenticado → `auth` estándar de
Laravel (redirect a login); no autorizado → 403 (`authorize()`, sin tocar);
recurso inaccesible (lección sin `jitsi_room`) → 404
(`test_lesson_join_returns_not_found_when_room_was_never_created`);
conflicto de ciclo de vida (estado inválido, fuera de ventana) → 403
(mismo código que autorización — MOVA no distingue "no autorizado" de "fuera
de ventana" en el código de estado HTTP, ambos son 403; la ventana es, en
efecto, una extensión de la regla de autorización, no una categoría de error
distinta — verificado leyendo el controlador, no inferido). Nunca se
convierte un fallo de autorización o de ciclo de vida en
`ValidationException` — `join()` no tiene errores de formulario que
convertir, es una operación de solo lectura.

### Divulgación de información

La respuesta de `join()` es exactamente `['jitsi_room', 'jitsi_token',
'jaas_app_id']` — verificado leyendo el `response()->json()` del
controlador. Nunca el `User` completo, nunca `TeacherProfile` completo,
nunca campos privados del alumno, nunca créditos internos, nunca metadata de
moderación. El único otro dato que viaja por esta frontera es el `lesson`
prop ya restringido de `parentIndex()`/`teacherIndex()` (auditado en la
sección de `reschedule()` y reconfirmado/corregido aquí para
`referral_code`).

### Tests — cobertura pre-existente, huecos reales cubiertos

`MonetizationIntegrityTest.php` y `JitsiAccessWindowTest.php` ya cubrían,
**antes de esta sesión**, con firma y claims del JWT verificados
criptográficamente claim por claim (ver desglose arriba, no un simple
"decode"): profesor/padre autorizados, usuarios no relacionados, invitados, estados de
lección inválidos, ventana horaria completa (temprano/tarde/bordes/gracia/
configurable), scope de sala, features deshabilitadas (recording/streaming/
transcription), y no-exposición de credenciales en listados. Único hueco
real encontrado: la regresión de `referral_code` en `parentIndex()` (test
nuevo, arriba). Expiración real de un JWT ya vencido contra JaaS en vivo
sigue siendo `UNKNOWN-03` (documentado en `config/jaas.php` antes de esta
sesión) — no reproducible sin infraestructura externa, no se afirma
resuelto.

### Build / Navegador — evidencia ejecutada, no solo leída

```text
Build:      BUILD_VERIFIED ✅ (npm run build, sin errores/warnings nuevos)
PHPUnit:    549/549 verdes tras el fix de parentIndex() + el test nuevo (0 regresiones)
Navegador:  BROWSER_VERIFIED ✅ — flujo real, no simulado:
    1. Login real como padre (ana@mova.test), MySQL local reiniciado vía XAMPP.
    2. Lección movida temporalmente a la ventana de acceso (tinker, revertido
       después) para poder ejercer join() de verdad — sin esto no hay botón
       "Ingresar a la Sala Virtual" (canJoinJitsi() del frontend lo oculta).
    3. GET /lessons/{id}/join real → 200 → iframe real de JaaS montado en
       #jitsi-container (confirmado document.querySelector, no supuesto) →
       la modal pidió cámara/micrófono real (bloqueado por el sandbox del
       navegador, pero confirma que JitsiMeetExternalAPI se inicializó con
       un JWT válido).
    4. Header de la modal renderizó "Código del profesor: C5SPNP — pídelo
       para tu próxima solicitud" — la manifestación real de la regresión
       de referral_code ya corregida (antes del fix esa línea no se
       renderizaba: v-if sobre un valor undefined).
    5. Cierre real: closeJitsi() navegó a /dashboard?post_class=...&
       post_class_ends_at=... (router.visit real, no interceptado) — el
       dashboard mostró "La clase está en curso", confirmando que el
       flujo post-cierre consume esos parámetros correctamente.
    6. Guarda de reentrancia: dos clicks sincrónicos reales sobre el mismo
       botón (mismo tick de JS, el escenario que un doble click humano
       reproduce) → window.axios.get('/join') interceptado y contado →
       exactamente 1 llamada, no 2 — confirmado con un contador inyectado
       en la propia página, no inferido de los logs de red acumulados de
       la sesión.
    7. Datos de prueba revertidos a su estado original tras la verificación
       (start_time, status, jitsi_room de la lección 15) — sin dejar
       estado de prueba residual en la base local.
```

**Estas dimensiones se mantienen separadas a propósito — un `200 + iframe
montado` es evidencia de integración funcional, no una prueba por sí solo
de que los claims del JWT sean correctos, ni de que producción se comporte
igual:**

```text
Backend JWT contract (firma/claims):   VERIFIED — vía PHPUnit + JWT::decode() real, no el browser test
Authorization (policy/estado/ventana): VERIFIED — vía PHPUnit + confirmado indirectamente en browser (canJoinJitsi() ocultó el botón hasta mover la ventana)
Browser integration (flujo real):      VERIFIED — login/join/iframe/cierre/doble-click, esta pasada
JaaS actual connection (8x8.vc real):  VERIFIED — external_api.js real se cargó y JitsiMeetExternalAPI se inicializó de verdad (no un mock del SDK)
Production infrastructure (Railway):   NOT VERIFIED — esta sesión no tiene acceso a producción; ver "Producción, explícitamente fuera de esta pasada" abajo
```

### Principio de frontera de seguridad (permanente — se sincroniza a `MOVA_SYSTEM_KNOWLEDGE.md`)

JaaS autentica; MOVA autoriza — son dos capas distintas, y un JWT válido
nunca sustituye la autorización propia de MOVA. Precisado en tres capas
explícitas (no dos), cada una con su propio dueño y su propia pregunta:

```text
Capa 1 — MOVA authorization
  ¿Puede este usuario entrar a ESTA clase?
  → LessonPolicy::view() + in_array(status) + ventana horaria (F-06)
  → dueño: MOVA. Sin esto, nada de lo siguiente ocurre.

Capa 2 — Meeting credential generation
  ¿Para qué lesson/room se generan las credenciales, y con qué límites?
  → JaasService: room de ESA lección, exp acotado a la ventana ya concedida,
    moderator calculado server-side
  → dueño: MOVA (backend), usando lo que la Capa 1 ya decidió.

Capa 3 — JaaS authentication
  ¿JaaS acepta las credenciales que MOVA generó?
  → JaaS valida la firma RS256 del JWT contra la clave pública configurada
  → dueño: JaaS. No decide autorización de negocio — solo confía en la
    firma. Confirmado en el código, no solo enunciado: `join()` completa
    la Capa 1 entera ANTES de que `JaasService` (Capa 2) genere nada, y
    Capa 3 ocurre fuera de MOVA por completo.
```

Regla explícita: **para entrar a una clase, MOVA autoriza primero (Capa 1),
genera credenciales después (Capa 2), y JaaS/Jitsi recibe únicamente las
credenciales correspondientes a ese usuario y esa clase (Capa 3) — nunca al
revés, y ninguna capa sustituye a otra.**

### Sin sobre-ingeniería

No se introdujo ningún sistema de autenticación nuevo, servicio de tokens
nuevo, capa DTO global, ni capa de WebSocket — la arquitectura actual
(policy + chequeo de estado/ventana server-side + JWT RS256 acotado) ya
resuelve correctamente lo auditado. Los dos cambios reales de esta pasada
(`referral_code` en la proyección, guarda de reentrancia en `openJitsi()`)
son ambos correcciones puntuales de código ya existente, no nueva
arquitectura.

### Cierre — vocabulario exacto

```text
Authorization:                VERIFIED (mismo LessonPolicy::view() que gobierna listados; test real con profesor/padre no relacionados)
Lifecycle:                    VERIFIED (in_array de estados leído del controlador; cancelled/completed rechazados con test real)
JWT contract (firma/claims):  VERIFIED claim por claim — signature/issuer/subject/room/expiration/permissions (ver desglose arriba; nunca "solo decodificado")
Room contract:                VERIFIED (una sala por lección, no derivable; $hidden confirmado con test; solo se revela vía join())
JaaS integration (runtime):    VERIFIED — 0 referencias runtime a meet.jit.si (6 referencias son comentarios/documentación, clasificadas por separado arriba)
JaaS integration (producción real, 8x8.vc en vivo): NOT VERIFIED — ver "Producción" abajo
JitsiModal lifecycle:          VERIFIED (dispose()/reset confirmado en navegador real; loading state ausente = hallazgo menor documentado, no bloqueante)
Error protocol:                VERIFIED (403/404 correctos; join() no tiene errores de formulario que convertir)
Information disclosure:        VERIFIED (respuesta = jitsi_room/jitsi_token/jaas_app_id exclusivamente, leído del controlador)
parentIndex() referral_code regression: reclasificada como Response Contract Integrity / underprojection (no vulnerabilidad de seguridad — ver MOVA_SYSTEM_KNOWLEDGE.md) — FIXED + TEST_VERIFIED (revert-confirm-restore) + BROWSER_VERIFIED
openJitsi() client-side reentrancy guard: FIXED + BROWSER_VERIFIED (no es idempotencia de servidor — join() no muta estado; sin test JS posible, no existe runner en el proyecto)
Tests:                         549/549 (1 nuevo, 0 regresiones)
Build:                         BUILD_VERIFIED ✅
Browser:                       BROWSER_VERIFIED ✅ (flujo autorizado completo, cierre, doble-click — ver dimensiones separadas arriba)
Real concurrency:              NOT DIRECTLY EXERCISED — join() no muta estado (genera un JWT nuevo por llamada); no hay fila que deduplicar, así que esta ausencia no es una brecha, es la naturaleza de una operación de solo lectura
Production infrastructure (Railway): NOT VERIFIED — sin acceso; separado explícitamente como release gate, no como deuda técnica de esta auditoría (ver abajo)
JWT expirado contra JaaS real: NOT DIRECTLY EXERCISED (UNKNOWN-03, ya documentado antes de esta sesión, sin infraestructura externa para reproducirlo)
```

**`LessonController::join()` + `JitsiModal.vue` + JaaS/JWT → CERRADO PARA
ESTE ALCANCE.** Único hallazgo real de seguridad/datos fue la regresión de
`referral_code` (adyacente a `join()`, no en `join()` mismo — causada por
una restricción de columnas de una pasada anterior de esta misma sesión,
y reclasificada como *Response Contract Integrity / underprojection*, no
como vulnerabilidad de seguridad — ver `MOVA_SYSTEM_KNOWLEDGE.md`); el
contrato propio de `join()` ya estaba sólidamente cubierto antes de esta
sesión.

### `LessonController` — CORE BACKEND LIFECYCLE: CLOSED FOR CURRENT SCOPE

Cierra el ciclo completo del hotspot `LessonController`:

```text
store()/accept → reschedule() → confirmPayment() → cancel() → join()   ✅ CLOSED FOR CURRENT SCOPE
```

Ninguno de estos cinco se reabre sin evidencia nueva (un bug reportado en
producción, un cambio de requisito de negocio) — no como ejercicio de
"seguir auditando por auditar". Esto **no** significa "100% terminado" sin
matices — tres cosas quedan explícitamente fuera y separadas, no
mezcladas bajo el mismo ✅:

```text
Production verification (Railway):    NOT VERIFIED — ver "Producción" abajo; es un release gate futuro, no deuda de esta auditoría
True parallel concurrency:            NOT DIRECTLY EXERCISED — PHPUnit y el navegador son de un solo proceso; nunca se ejecutaron dos transacciones reales simultáneas contra ningún método de este hotspot
Global UX/accessibility pass:         PENDING — deliberadamente no tocado, es la fase que sigue a este cierre
```

### Producción, explícitamente fuera de esta pasada

No se trata como deuda técnica infinita ni se repite indefinidamente en
cada cierre — se declara una vez, aquí, como lo que realmente es: **un
release gate, no una fase de desarrollo local.** Antes del primer `push`
futuro (bloqueado hoy por F-26, sin cambios en esa área esta pasada):

```bash
git fetch origin
git status --short --branch
git rev-list --left-right --count HEAD...origin/master
php artisan test
npm run build
php artisan migrate:status
```

y, tras desplegar: smoke test en el entorno real, verificación explícita de
los endpoints críticos de este hotspot (`join()` incluido). Ese es un paso
de release, no un paso de esta auditoría — no se ejecuta aquí porque esta
sesión no tiene acceso a Railway.

---

## 🎨 Lesson Lifecycle UX System — Fase 1 (Cancel/Reschedule/ConfirmPayment/Join)

Primera pasada del rediseño UX/UI unificado pedido explícitamente tras el
cierre del hotspot `LessonController` — no backend/seguridad nuevo, sistema
de acción/modal compartido sobre los primitivos "Native Premium" ya
construidos (`Modal.vue`, `BaseButton.vue`, tokens de `tailwind.config.js`).
Alcance confirmado con el usuario antes de tocar código (`AskUserQuestion`):
Cancel + Reschedule + ConfirmPayment + Join, código listo para producción,
verificado localmente con evidencia real (navegador + BD local + PHPUnit) —
**no** "producción verificada": esta sesión no tiene acceso a Railway, ver
distinción explícita en el cierre. `ClassRequests/Accept.vue` queda fuera
(página completa, no modal) — no se toca en esta pasada.

### Hallazgo real encontrado durante el diseño, no solo estético

Auditando las cuatro acciones juntas (no una por una) se confirmó una
duplicación exacta, letra por letra, no solo "parecida": `Lessons/
ParentIndex.vue` y `Lessons/TeacherIndex.vue` tenían el MISMO modal de
Cancel y el MISMO modal de Reschedule — mismo `router.post`, mismo guard de
doble-submit, mismo mapeo de error — como dos archivos `fixed inset-0`
independientes, ninguno usando `Modal.vue`. `ConfirmPayment` no tenía
siquiera un modal: "Ya pagué" disparaba el POST directo, la única de las
cuatro acciones sin ningún paso de confirmación pese a mover un estado
financiero real (`scheduled → paid`).

### Decisión de arquitectura — un desvío deliberado del pedido original

El registro `product` de la skill `impeccable` trae una regla dura: *"Modal
as first thought. Modals are usually laziness."* Aplicada explícitamente:
Cancel/Reschedule siguen siendo modal (destructivos/consecuentes, la
interrupción se justifica). **ConfirmPayment se resolvió como un segundo
paso INLINE dentro de la misma card** (`ConfirmPaymentAction.vue`) en vez de
un cuarto modal — coherente con que la acción ya vivía inline, y con que el
propio usuario la describió como "confirmación breve, no un checkout".
Confirmado explícitamente con el usuario antes de construir (no asumido).

### Arquitectura — componentes/composables nuevos

```text
Composables/useLessonActions.js    → dueño único de la petición de red +
                                       guard F-12 + mapeo de error para
                                       Cancel/Reschedule (antes duplicado
                                       en 2 archivos)
Components/Lessons/
  CancelLessonModal.vue            → Modal.vue + BaseButton.vue, icono
                                       danger-bg, textarea de motivo
  RescheduleLessonModal.vue        → Modal.vue + BaseButton.vue, preview
                                       "Actual → Nuevo" reactivo
  ConfirmPaymentAction.vue         → paso inline (no modal), usado por
                                       ParentLessonCard.vue Y
                                       Dashboard/Parent.vue (2 sitios) —
                                       antes cada uno tenía su propio botón
```

`useJitsiMeet.js`/`JitsiModal.vue` (ya auditados en la sección de `join()`
arriba) reciben en esta pasada un estado `connecting` explícito —
"Conectando a la sala…" entre abrir la modal y que el iframe de JaaS
monte, antes pantalla negra sin ningún indicio — y el header migra su
último emoji (`🎥`) a `Icon`.

### Regresión evitada por el propio proceso de esta pasada

Al mover `Dashboard/Parent.vue::confirmPayment()` a `ConfirmPaymentAction.vue`
se encontraron y corrigieron, de paso, dos bugs reales preexistentes en ese
archivo (no introducidos por esta sesión, pero visibles solo al tocar la
misma función): `onError` ignoraba `e.confirmPayment` (mensaje genérico
fijo, mismo patrón "Response Contract Integrity" ya documentado para
`referral_code`), y `paymentError` era un `ref` global sin id — con dos
secciones de la página mostrando lecciones a la vez, un error de pago en la
lección A podía alterar el `v-else-if` de una lección B no relacionada por
mero encadenamiento de plantilla. Ambos corregidos con el mismo patrón
`paymentErrorId` que ya usaban `ParentLessonCard.vue`/`Lessons/ParentIndex.vue`.

### Hallazgo real encontrado verificando en navegador (no en el código)

El overlay de "Conectando…" usaba un `<Transition>` de Vue cuyo desmontaje
depende del evento `transitionend`. Verificado en el Browser pane que, en
un entorno sin composición visual activa, ese evento nunca llega — el
`v-if="connecting"` seguía en el DOM con `opacity:0` indefinidamente.
Diagnóstico aislado explícitamente (con y sin `<Transition>`, mismo flujo,
mismo lesson): sin el wrapper, el overlay desaparece correctamente y a
tiempo (≈600ms, medido) — confirma que `connecting.value` sí se pone en
`false` correctamente; el fallo era exclusivo del mecanismo de
`transitionend`, no de la lógica reactiva. Se optó por **quitar el
`<Transition>`** (más simple, ya verificado extremo a extremo) en vez de
reintentarlo, y se añadió `pointer-events-none` al overlay como defensa
adicional: si esta misma condición (pestaña en segundo plano durante la
conexión) ocurriera alguna vez en un navegador real, un overlay atascado
invisible nunca debe poder bloquear clics sobre la llamada real debajo.

### Validation Gate (pasada de cierre pedida en revisión, misma sesión)

Explícitamente NO se reabrió backend ni se tocó código ya cerrado — solo se
verificó lo que el cierre anterior había dejado como `NOT BROWSER_VERIFIED`
o `PARCIAL`.

- **Arquitectura de `useLessonActions.js` — confirmada limitada a
  mutaciones compartidas de Cancel/Reschedule.** No contiene Jitsi
  (`useJitsiMeet.js` sigue siendo el único dueño), ni semántica de estado
  (`statusStyle()` sigue siendo la única fuente), ni formato/permisos —
  verificado releyendo el archivo completo, no de memoria. Ningún
  "god-composable".
- **`reason` (Cancel/Reschedule) es un campo real del contrato, no
  decorativo.** Verificado en `LessonController.php`: se persiste como
  `cancel_reason`/`reschedule_reason` en el modelo y se loguea en
  `ClassEvent::log()` en ambos casos — el textarea/input no es un campo
  falso de UI.
- **Copy de `ConfirmPaymentAction.vue` — confirmado que no implica
  checkout.** "Ya pagué" / "¿Confirmas que la clase fue pagada?" /
  "Confirmar pago" — ninguna variante de "Realizar pago"/"Comprar"/
  "Procesar pago". El monto y Yape/Plin que se muestran (`ParentLessonCard.vue`)
  son contexto informativo preexistente, no tocado en esta pasada — MOVA
  nunca procesa ese pago, solo registra la declaración del padre.
- **Browser (profesor, `profesor@mova.test`) — ahora BROWSER_VERIFIED ✅.**
  Contraseña de prueba reseteada localmente para poder verificar (BD local,
  sin impacto en producción). Confirmado: el profesor ve exactamente
  Reprogramar/Cancelar clase (nunca "Ya pagué" — no por un `if (role ===
  'teacher')` en el frontend, sino porque `ConfirmPaymentAction` nunca se
  importó en `TeacherIndex.vue`/`TeacherLessonCard.vue`; la autorización real
  vive en el backend, esto es solo composición). Ambos modales renderizan
  con datos reales idénticos al lado padre (mismo componente, wiring
  confirmado, no solo asumido).
- **Stale-state reproducido de verdad, en los tres flujos — no como
  concepto, como secuencia real ejecutada:**
  ```text
  Reschedule: modal abierta → tinker cambia la lección a 'cancelled' por fuera
              → submit → "Solo se pueden reprogramar clases programadas."
              (mensaje real del backend, NO genérico) → input conservado
              (la fecha elegida seguía en el campo) → modal sigue abierta y
              usable
  Cancel:     modal abierta → tinker cambia la lección a 'paid' por fuera
              → submit → "Solo se pueden cancelar clases programadas."
              → modal sigue abierta y usable, sin éxito falso
  ConfirmPayment: ya cubierto en el cierre anterior con un ciclo completo
              real (abrir→cancelar→revertir→confirmar→POST 200→BD
              actualizada)
  ```
- **Accesibilidad — foco/Escape verificados con secuencia real, no solo
  heredados por confianza.** Trigger enfocado → click → foco entra al
  modal (confirmado en el primer elemento enfocable real) → `Escape` →
  modal cierra Y foco vuelve exactamente al botón que lo abrió (comparado
  por `id`, no supuesto). Enumeración de elementos enfocables dentro del
  modal confirmada correcta y en orden (textarea → Volver → acción).
- **Responsive — 375px, 768px y 1280px medidos con `getBoundingClientRect()`
  real, no solo mirado.** A 375px: hoja inferior, `bottom` exactamente
  igual a la altura del viewport tras asentarse la animación, sin overflow
  horizontal, botones dentro del viewport. A 768px y 1280px: diálogo
  centrado (no hoja), centrado horizontal exacto (`(viewport-384)/2`),
  sin overflow horizontal en ningún caso.
- **Paridad Parent/Teacher confirmada por construcción, no por
  inspección visual.** Ambas páginas importan el mismo
  `useLessonActions()` y llaman exactamente `route('lessons.cancel', …)`/
  `route('lessons.reschedule', …)` — no hay dos implementaciones que puedan
  divergir, es literalmente la misma función.
- **Trabajo paralelo/worktrees — verificado sin overlap.** `git worktree
  list` + `git merge-base` contra los dos worktrees activos
  (`claude/jolly-hellman-5ca1a1`, `claude/serene-davinci-30dfbd`): ambos ya
  están completamente contenidos en `master` (diff vacío contra su propio
  merge-base) — ningún trabajo divergente sin fusionar que pudiera chocar
  con los archivos de esta pasada.

### Cierre — vocabulario exacto, por dimensión

```text
Duplicación Cancel/Reschedule:   RESUELTA (useLessonActions.js + 2 componentes, un solo lugar que arreglar)
ConfirmPayment sin confirmación: RESUELTO (paso inline, decisión confirmada con el usuario antes de construir)
Arquitectura useLessonActions:   VERIFICADA — limitada a mutaciones compartidas, sin Jitsi/semántica/permisos mezclados
Contrato de `reason`:            VERIFICADO — campo real, persistido y logueado en backend, no decorativo
Copy de ConfirmPayment:          VERIFICADO — no implica checkout, coherente con "declaración de pago", no "procesar pago"
Regresión evitada (Dashboard/Parent.vue onError + paymentError sin id): FIXED, encontrada de paso, no buscada a propósito
Build:                           BUILD_VERIFIED ✅ (limpio, incl. rebuild final del integration gate)
Tests:                           549/549 — sin cambios de backend, 0 regresión (integration gate final incluido)
Browser (padre, ana@mova.test):  BROWSER_VERIFIED ✅ — Reschedule, Cancel, ConfirmPayment (ciclo POST real), Join (JWT real)
Browser (profesor, profesor@mova.test): BROWSER_VERIFIED ✅ — Cancel/Reschedule con datos reales, wiring confirmado
Stale state (los 3 flujos):      BROWSER_VERIFIED ✅ — mensaje real del backend, sin éxito falso, input conservado, modal usable
Accesibilidad (foco/Escape):     BROWSER_VERIFIED ✅ — foco entra al abrir, Escape restaura al trigger exacto, orden de tabulación correcto
Accesibilidad (trampa de Tab completa): HEREDADA de Modal.vue (ya verificada en su propio cierre) — no reprobada exhaustivamente aquí
Responsive (375/768/1280):       BROWSER_VERIFIED ✅ — medido con getBoundingClientRect real en los 3 breakpoints, sin overflow horizontal
Paridad Parent/Teacher:          VERIFICADA por construcción (mismo composable, misma ruta) — no por inspección visual únicamente
Trabajo paralelo/worktrees:      VERIFICADO sin overlap — ambos worktrees activos ya contenidos en master
Terminología "producción":       CORREGIDA — nunca "producción verificada"; es "código listo para producción, verificado localmente"
Datos de prueba:                 Revertidos — lección 15 restaurada a su estado real tras cada ciclo; contraseña de
                                  profesor reseteada localmente para pruebas (sin impacto en producción)
```

**Fase 1 del Lesson Lifecycle UX System → CLOSED FOR CURRENT SCOPE.**
Ninguna dimensión aplicable queda pendiente sin declarar. Continúa, como
siguiente fase de la misma iniciativa (no un tema nuevo): `ClassRequests/
Accept.vue` al mismo lenguaje de jerarquía de acción/estado, y la migración
de accesibilidad sistémica ya priorizada en el plan de rediseño más amplio
(`InputLabel`/`TextInput`/`InputError`/`BaseButton`/`Modal`/`StatusBadge`
antes que páginas de producto sueltas).

---

## 🚨 PRODUCT BLOCKERS — decisiones de negocio pendientes (no ocultar entre hallazgos visuales)

Sección propia, no una fila más de la matriz — para que un hallazgo de
producto real no se pierda entre 139 conteos de emoji. Ninguno de estos se
resolvió aquí: no le corresponde a una auditoría de diseño decidir lógica de
negocio o backend.

### P1 · `PRODUCT DECISION REQUIRED` — `active_offer` en el checklist de perfil de profesor

Dos etiquetas distintas, no una sola: `P1` es la severidad (fricción real de
onboarding, no un bloqueo total de un flujo crítico de dinero — por eso no
es `P0`); `PRODUCT DECISION REQUIRED` es ortogonal a la severidad y significa
"la solución depende de una decisión de negocio que el código no puede
inferir con seguridad" — nunca se resuelve a ciegas solo por tener severidad
asignada.

- **Dónde**: `Dashboard/Teacher.vue`, `profile_checklist.active_offer` /
  `checklistLabels.active_offer` ("Al menos una oferta activa").
- **Comportamiento actual**: el checklist de "perfil completo" exige que el
  profesor tenga al menos una oferta (`ClassOffer`) activa para llegar a 100%.
- **Comportamiento esperado (inferido, no confirmado con el equipo de
  producto)**: el propio código ya documenta que el flujo de crear ofertas
  fue retirado para profesores nuevos (`HANDOFF_FINAL.md §21` — el profesor
  ahora acepta solicitudes abiertas, no publica anuncios). Si `active_offer`
  sigue viniendo del backend sin que exista ninguna UI para cumplirlo, un
  profesor nuevo puede quedar permanentemente por debajo de 100% sin ninguna
  acción disponible para resolverlo.
- **Evidencia**: comentario explícito ya presente en el código antes de esta
  sesión (`Dashboard/Teacher.vue`, tarjeta "Mis ofertas anteriores"); el
  campo `active_offer` sigue en `checklistLabels` sin control de acceso.
- **Riesgo**: onboarding/conversión de profesores nuevos — un checklist que
  nunca puede completarse mina la confianza en la plataforma justo en la
  etapa de activación.
- **Posibles soluciones (no elegidas aquí)**: (a) retirar `active_offer` del
  checklist para profesores sin ofertas legacy; (b) redefinirlo para que se
  cumpla automáticamente al aceptar la primera solicitud; (c) confirmar que
  el backend ya lo excluye para cuentas nuevas y que esto es solo una lectura
  incorrecta del frontend — verificar `DashboardController` antes de
  cualquier cambio.
- **Depende de**: decisión de producto/negocio, no de diseño. Mismo
  tratamiento que `PRIV-STUDENT-RETENTION` en `docs/MOVA_AUDIT_PHASE0.md` —
  documentado y dejado abierto, no resuelto por inferencia.

### P2 · `PRODUCT DECISION REQUIRED` — el motor de recomendaciones de `Diagnostics` se computa pero nunca se muestra

Encontrado auditando el dominio `Diagnostics` completo (2026-08-28), no
diseño visual.

- **Dónde**: `DiagnosticRecommendationService::compute()` (scoring
  determinista de 0-100, con boost opcional de IA acotado a 5 puntos —
  nunca decide la IA), persistido en `diagnostic_recommendations`.
  `DiagnosticsController::results()` devuelve `'recommendations' => []`
  **hardcodeado**, ignorando por completo `$diagnostic->recommendations()`.
  `Diagnostics/Results.vue` nunca declara `recommendations` en `defineProps`
  ni lo referencia en el template — confirmado, no un vacío accidental de
  un solo lado.
- **Comportamiento actual**: cada vez que un padre completa un diagnóstico,
  el backend calcula y guarda hasta 5 recomendaciones reales de profesor
  (rank/score/razones) — trabajo de CPU y escrituras a BD reales, en el
  camino síncrono de la request — que ningún padre llega a ver nunca.
- **Contexto que explica el porqué**: el producto migró de "elige una
  oferta recomendada" a "solicitud genérica automática a todos los
  profesores verificados de la materia" (confirmado en el mensaje de éxito
  de `store()` y en el stub de `requestClass()`, que ya redirige con "el
  diagnóstico ahora crea una solicitud genérica automáticamente"). El
  cálculo de recomendaciones parece haber quedado del diseño anterior, sin
  que nadie lo retirara ni lo reconectara al nuevo flujo.
- **Riesgo**: ninguno de seguridad — es trabajo desperdiciado (CPU +
  escrituras) y una funcionalidad ya construida y probada que el usuario
  final no percibe.
- **Posibles soluciones (no elegidas aquí)**: (a) mostrar las
  recomendaciones reales en `Results.vue` (el motor ya existe, probado,
  determinista) — probablemente el mayor valor de producto disponible en
  todo este dominio con el menor esfuerzo de implementación; (b) eliminar
  `compute()`/`diagnostic_recommendations` si el producto decidió
  definitivamente no usarlas — deja de desperdiciar el ciclo de request;
  (c) mantenerlo calculándose sin mostrarlo, a propósito, para un futuro
  cercano — válido solo si es una decisión consciente, no un olvido.
- **Depende de**: decisión de producto — si "solicitud genérica" es el
  modelo definitivo o una recomendación priorizada sigue siendo el objetivo
  final. No se resuelve por inferencia.

### P1 · `QA TOOLING BUG (no producción)` — `mova:concurrency-verify` reconcilia el ledger global, no el escenario probado

Reclasificado de `P3` a `P1` en revisión (2026-08-29, mismo día del
hallazgo): la severidad inicial subestimaba el efecto real. `P3` sugería
"bajo, puede esperar meses" — pero es precisamente la herramienta que
certificará la concurrencia financiera de Mercado Pago (recargas y
lecciones aceptándose en paralelo). Una herramienta de certificación
financiera que puede reportar `FAIL` sobre un escenario correcto no es un
detalle cosmético — mina la confianza en la propia señal antes de que
empiece a importar de verdad.

Encontrado durante la auditoría financiera previa a Mercado Pago
(2026-08-29), verificando `ConcurrencyProbe`/`ConcurrencyVerify` para
descartarlos como origen de un mismatch histórico en datos de desarrollo
local.

- **Dónde**: `App\Console\Commands\ConcurrencyVerify::handle()` — llama
  `app(LedgerReconciliation::class)->run()` sin acotar a las entidades del
  propio escenario (`$scenario`/`TeacherProfile` que `ConcurrencyProbe`
  creó para esa prueba).
- **Comportamiento actual**: si existe CUALQUIER anomalía en cualquier
  otro perfil de la base (p. ej. datos de desarrollo ya contaminados por
  otra causa), `mova:concurrency-verify` reporta `ROJO` aunque el propio
  escenario probado haya sido perfecto (verificado en vivo: 5 procesos
  reales compitiendo por la misma solicitud produjeron exactamente 1
  lección/1 reserva/0 duplicados — comportamiento correcto — pero el
  comando igual reportó `ledger sano: NO` por un descuadre ajeno).
- **Riesgo**: ninguno de seguridad/producción — es un falso negativo de
  una herramienta de QA. El riesgo real es de confianza: un ingeniero
  viendo `ROJO` podría creer que la concurrencia real falló cuando en
  realidad fue una anomalía preexistente y no relacionada.
- **Posible solución (no elegida ni implementada aquí)**: acotar la
  reconciliación a los `teacher_profile_id`/lecciones que el propio
  escenario creó (algo como `LedgerReconciliation::forProfiles([...])`),
  en vez de `run()` global.
- **Depende de**: prioridad de trabajo de QA, no bloquea Mercado Pago ni
  ningún flujo de producción.

### P1 · `QA INFRASTRUCTURE` — falta una base de datos QA dedicada para pruebas destructivas/concurrencia

Encontrado como consecuencia directa de un incidente real durante esta
misma auditoría (2026-08-29): `php artisan migrate:fresh --seed` contra
`mova` (el único MySQL local disponible, vía XAMPP) perdió la conexión a
mitad de la migración — MySQL murió durante el DDL, dejando la base en un
estado parcial (24 de ~30 tablas) hasta reiniciar el proceso manualmente y
reintentar desde cero. Sin daño porque se detectó antes de seguir, pero
`migrate:fresh` **no es atómico**: un fallo a mitad de camino dejó DDL ya
aplicado, no un rollback limpio.

- **Por qué importa ahora, no solo en general**: `ConcurrencyProbe` ya
  ejecuta procesos MySQL reales concurrentes contra `mova` (auto-limpiando
  su propio escenario, verificado). Mercado Pago va a añadir webhooks +
  colas + reversals + reconciliación sobre ese mismo entorno. Cuantas más
  pruebas destructivas/concurrentes corran contra el único MySQL de
  desarrollo (el mismo que XAMPP ya demostró que puede caerse a mitad de
  una operación), mayor la superficie para perder tiempo diagnosticando
  estados parciales en vez de trabajar en Mercado Pago.
- **Riesgo**: ninguno de producción — es enteramente de productividad de
  desarrollo/QA local.
- **Posible solución (no implementada aquí)**: una base `mova_qa` (o un
  MySQL en contenedor/Docker) dedicada exclusivamente a
  `ConcurrencyProbe`/E2E destructivo/futuras pruebas de reconciliación de
  Mercado Pago, separada de `mova` (desarrollo manual normal).
- **Depende de**: decisión de infraestructura. **Gate explícito, precisado
  en revisión (2026-08-29) para que P1 no se lea como "bloquea todo"**:

  ```text
  MP diseño / mapping de arquitectura        → PUEDE empezar ya, sin mova_qa
  MP provider + creación de orders en TEST   → PUEDE empezar ya, sin mova_qa
  MP concurrencia destructiva (ConcurrencyProbe-style) → requiere mova_qa
  MP webhook E2E intensivo                   → requiere mova_qa
  MP reversal/chargeback E2E                 → requiere mova_qa
  Production readiness                       → requiere este P1 resuelto
  ```

  Resolverlo es requisito de la fase de pruebas E2E intensivas de pagos,
  no del arranque de Mercado Pago.

---

### P2 · RESUELTO — `CreditCheckoutController::status()` (GET) ya no reconcilia como efecto secundario

Encontrado en la ronda "MOVA Yape Checkout Pre-Card Hardening" (2026-09-02),
**corregido en la ronda "MOVA Yape Final Pre-Card Gate" (2026-09-02)**.

- **El hecho original**: `GET /teacher/credits/checkout/{recharge}/status`
  — pensado como lectura para el polling del frontend — llamaba a
  `reconcileOnDemand()`, que podía disparar una llamada real a Mercado
  Pago (`GET /v1/payments/{id}` o `/v1/payments/search`) y, si el pago ya
  se confirmó, abonar créditos vía `RechargeApprovalService::credit()`.
  Un GET mutando estado financiero viola la semántica HTTP esperada
  (idempotente/sin efectos secundarios).
- **La corrección**: `status()` ahora es lectura pura — solo devuelve lo
  ya persistido, sin llamar a `reconcileOnDemand()`. La reconciliación se
  movió a un endpoint nuevo, explícito: `POST
  /teacher/credits/checkout/{recharge}/refresh` (`CreditCheckoutController
  ::refresh()`, misma autorización, mismo throttle `60,1`, mismo payload
  de respuesta que `status()`). El polling del frontend (`Checkout.vue`)
  llama a `refresh()`, nunca a `status()`, mientras espera confirmación —
  un solo request por tick, sin encadenar dos endpoints.
- **Cobertura**: `test_get_status_never_reconciles_or_mutates_state`
  (GET nunca llama a la API de Mercado Pago ni muta balance/estado, ni
  siquiera repetido), `test_repeated_refresh_never_duplicates_credits` y
  `test_credits_are_applied_exactly_once_even_when_refresh_is_polled_repeatedly`
  (POST refresh reconcilia y sigue siendo exactamente-una-vez),
  `test_refresh_endpoint_throttles_after_sixty_polls_per_minute` (rate
  limiting intacto), ownership denegado para `refresh()` en
  `test_a_teacher_cannot_view_or_pay_another_teachers_recharge` — todas en
  `CreditCheckoutControllerTest`/`CheckoutRateLimitingTest`.
- **Nota**: esto no reemplaza los webhooks — mientras
  `MERCADOPAGO_WEBHOOKS_ENABLED=false`, `refresh()` sigue siendo la única
  confirmación casi en tiempo real. Cuando los webhooks reales entren en
  producción, `refresh()` seguirá siendo un endpoint de reconciliación
  explícita legítimo (igual que `mercadopago:reconcile`), solo dejará de
  ser la vía principal.

---

## 🐛 BUG REAL (no de diseño) encontrado al construir el contract test — P0

**Estado, por dimensión — nunca colapsadas en una sola palabra**:

```text
FIXED                     ✅ (migración nueva, no se editó una ya aplicada)
REGRESSION COVERED        ✅ (TeacherRejectClassRequestTest, regresión simulada y confirmada)
SQLITE VERIFIED           ✅ (fresh :memory: + up()/down() + rollback guard)
MYSQL LOCAL VERIFIED      ✅ (127.0.0.1/mova, lectura real de SHOW COLUMNS)
STAGING VERIFIED          ❌ NO VERIFICADO — no existe entorno de staging separado en este proyecto
PRODUCTION (Railway)      ❌ NO VERIFICADO — sin acceso; ver nota abajo
GIT / TRABAJO PARALELO    ✅ RECONCILED (ver pasada de reconciliación abajo)
```

**No se afirma "MySQL resuelto" a secas — solo "MySQL LOCAL verificado".**
Railway/producción no se tocó ni se consultó: esta sesión no tiene
credenciales ni acceso a ese entorno, y no se simuló ni se asumió paridad.
Si quieres cerrar también esa dimensión, la vía más segura sin exponer
ninguna credencial es que tú mismo ejecutes, con tus propias credenciales
de Railway (CLI o dashboard), algo equivalente a:

```bash
railway run php artisan migrate:status
# o, directo a MySQL de producción, de solo lectura:
railway run mysql -e "SHOW COLUMNS FROM class_requests LIKE 'status'"
```

y compartas el resultado (no las credenciales) — con eso puedo confirmar si
producción ya tiene `teacher_rejected` en el ENUM o si le falta la migración
`2026_07_08_000001` (poco probable si el resto del sistema funciona en
producción, pero no se afirma sin evidencia).

Sí queda, además, un **hallazgo nuevo y distinto** en `classes.status` (ver
más abajo) — programado, no arreglado en esta pasada.

**Snapshot original de la corrección** (Audit Snapshot Contract): `HEAD`
`eeca1ffa7dc7212de46bba2a80f653711deec65b` → esta corrección se aplicó
encima; working tree limpio salvo los 2 archivos nuevos de esta corrección;
verificado 2026-08-27T10:40Z, directorio de trabajo real, no worktree
aislado.

### Pasada de reconciliación (post-cierre, misma sesión)

Verificación adicional pedida explícitamente antes de aceptar el cierre —
cada punto se re-ejecutó, no se re-leyó de memoria:

- **Git, con vocabulario explícito** (`git status --short --branch`,
  `git branch -vv`, `git rev-list --left-right --count HEAD...origin/master`,
  `git fetch origin master` para confirmar que no cambió):
  `branch: master` · `HEAD: 58e7951` · `origin/master: 692b3651...`
  (sin cambios tras `fetch`) · **ahead: 41** · **behind: 0** · **diverged: no**
  · working tree: limpio salvo `package-lock.json` (ajeno a este fix). "41
  por delante, nada pusheado" del reporte anterior era la lectura correcta
  de `ahead=41, behind=0` — no ambiguo una vez declarado explícitamente así,
  pero se re-verifica en vez de darlo por sentado.
- **Trabajo duplicado/paralelo**: búsqueda real en los 15 branches locales +
  2 worktrees activos (`git ls-tree` de cada branch, más `git status` +
  `grep` dentro del working tree de `.claude/worktrees/jolly-hellman-5ca1a1`,
  el único worktree con una rama `claude/*` propia). **Ningún otro branch ni
  worktree contiene la migración `2026_08_27_000002...` ni ninguna otra
  ampliación del `CHECK` de `class_requests.status`.** El worktree paralelo
  existente está en una tarea completamente distinta (Fase 3 del rediseño:
  iconos de `AppLayout.vue`), sin tocar esta tabla. La respuesta de
  `dismiss_task` ("ya iniciada por el usuario") no corresponde a ningún
  código encontrado en el repositorio — no se pudo confirmar qué la generó;
  se reporta como sin resolver, no como descartada.
- **Paridad MySQL, con evidencia real, no solo relectura del archivo**:
  consulta de solo lectura contra `DB_DATABASE=mova` en `127.0.0.1` (el
  MySQL de desarrollo local real del usuario, NO el remoto de Railway de
  F-26) — `SHOW COLUMNS FROM class_requests LIKE 'status'` devuelve
  `enum('pending_parent_approval','open','accepted','rejected',
  'teacher_rejected','completed')`. Confirma que el `ENUM` de MySQL ya
  incluía `teacher_rejected` desde que corrió por primera vez
  `2026_07_08_000001` — la paridad rota fue exclusivamente del lado SQLite,
  nunca de MySQL. `php artisan migrate:status` contra esa misma base
  confirma que **ambas migraciones nuevas de esta sesión
  (`2026_08_27_000001` y `_000002`) siguen `Pending`** — no se ejecutó
  `php artisan migrate` contra ella, solo lecturas.
- **Fresh vs. incremental**: no aplica como duda separada para el propio
  test suite — `RefreshDatabase` migra desde cero una base `:memory:` en
  cada clase de test, así que las 480 pruebas (incluidas las 3 nuevas) ya
  son, cada una, una migración *fresh* real, no incremental. Donde sí aplica
  "incremental contra una base ya existente" es en el MySQL de desarrollo
  real de arriba: ahí la migración sigue pendiente y es un no-op declarado
  en código para ese motor — confirmado leyendo la guarda
  `if (DB::getDriverName() === 'mysql') { return; }`, no ejecutado.
- **Auditoría transversal de TODOS los estados de `class_requests`, no solo
  `teacher_rejected`** (pedida explícitamente): se listaron las 6 migraciones
  que tocan esta tabla y se confirmó que solo una (`2026_07_08_000001`)
  altera el `ENUM`; se cruzaron los 6 valores canónicos contra cada escritura
  real en código (`ClassRequestController` y `LessonController`) y contra
  `utils/statusColors.js`. Resultado: `pending_parent_approval`, `open`,
  `rejected`, `teacher_rejected` se escriben en `ClassRequestController`;
  `accepted` se escribe en `LessonController.php:131` (la aceptación real
  ocurre ahí, no en `ClassRequestController::accept()`, que solo renderiza
  la página). **`completed` no tiene ningún punto de escritura en el código
  actual** — está en el enum desde la migración original de 2024 pero
  ningún controlador/listener/job lo asigna hoy (la finalización real se
  rastrea en `classes.status`, tabla distinta). No es un bug — no rompe
  nada — pero es un valor de enum muerto, mismo patrón que ya se documentó
  y limpió para `classes.status`/`in_progress` en
  `2026_08_24_000002_remove_in_progress_from_classes_status_enum.php`.
  Queda anotado, no se toca en esta pasada (bajo impacto, fuera del alcance
  pedido).
- **El no-op de MySQL ya no es silencioso.** El `if (mysql) return;` original
  asumía, sin comprobarlo, que `2026_07_08_000001` ya había corrido en esa
  instalación. Ahora verifica el `ENUM` real (`SHOW COLUMNS ... LIKE
  'status'`) antes de no hacer nada, y lanza `RuntimeException` con
  diagnóstico explícito si `teacher_rejected` no está — un no-op equivocado
  ya no es indistinguible de un éxito. Cubierto por
  `tests/Feature/ClassRequestsStatusEnumMigrationDriftGuardTest.php` (3
  tests, sin `RefreshDatabase`: mockea la fachada `DB` para simular un ENUM
  correcto y uno con drift). **Verificado que el guard realmente dispara**:
  se restauró temporalmente la versión sin el guard (`git show HEAD:...`),
  se confirmó que 2 de los 3 tests fallaban, y se restauró la versión
  corregida. Suite completa tras este cambio: **483/483** (480 previos + 3
  nuevos).

**Corregido** en `database/migrations/2026_08_27_000002_widen_class_requests_status_enum_for_sqlite.php`
— sigue el mismo patrón de reconstrucción ya usado en
`2026_08_23_000002_add_reversal_state_to_recharge_requests.php`; no-op real
en MySQL (el ENUM ahí ya era correcto), reconstruye el CHECK en SQLite.
Verificado, no asumido:
- `up()` probado directamente: el `CHECK` resultante en SQLite ahora incluye
  `teacher_rejected` (confirmado leyendo `sqlite_master.sql` antes/después).
- `down()` probado: revierte el `CHECK` correctamente, y además **se
  verificó que el guard de seguridad bloquea el rollback** si ya existen
  filas en `teacher_rejected` (mismo patrón que la migración de
  `recharge_requests`).
- `tests/Feature/TeacherRejectClassRequestTest.php` (nuevo, 3 tests) cierra
  el hueco de cobertura real: `TeacherVerificationGateTest` ya existente
  solo cubre el camino BLOQUEADO (403, la policy corta antes del `update()`
  — nunca escribe a la BD). Este archivo prueba el camino que faltaba: un
  profesor verificado que sí puede rechazar, con la escritura real
  ocurriendo. **Verificado que el test realmente habría atrapado el bug
  original**: se deshabilitó temporalmente la migración de corrección, se
  confirmó que el test fallaba con un 500 (la misma `CHECK constraint
  failed` reproducida al diagnosticar el bug), y se restauró el archivo
  antes de continuar.
- Suite completa: **480/480 tests pasan** (477 antes de esta corrección +
  3 nuevos).
- Migración renombrada de `_000001_` a `_000002_` tras notar una colisión
  de timestamp con `2026_08_27_000001_add_idempotency_key_to_student_
  diagnostics.php` (el fix de BUG-2 de esta misma sesión) — sin colisión
  funcional real (Laravel desempata alfabéticamente), pero renombrado para
  evitar confusión en el historial de migraciones.

**No se ejecutó `php artisan migrate` contra ninguna base de datos local
real** — la verificación completa se hizo contra la base de datos de test
(SQLite en memoria, la misma que usa `php artisan test`), no contra
`DB_DATABASE=mova` en `127.0.0.1` que aparece en `.env`. Aplicar esta
migración al entorno de desarrollo local real queda como una acción del
usuario, no ejecutada aquí sin pedirlo.

Al intentar construir un test de contrato que verificara los estados reales
de la base de datos contra los registros JS (`utils/statusColors.js`,
`utils/rechargeStatusColors.js`), se descubrió que **no se puede extraer de
forma confiable el enum real de `class_requests.status` desde SQLite**,
porque el propio schema de SQLite (la base de datos con la que corre TODA
la suite de tests) está incompleto respecto a producción (MySQL).

- **El bug, verificado ejecutando la sentencia real, no inferido**:
  `database/migrations/2026_07_08_000001_update_class_requests_status_enum.php`
  amplía el `ENUM` de `class_requests.status` para incluir `teacher_rejected`
  — pero **solo dentro de un `if (driver === 'mysql')`**. En SQLite no hace
  nada. Verificado insertando directamente en un schema migrado desde cero
  en SQLite:
  ```
  INSERT INTO class_requests (..., status) VALUES (..., 'teacher_rejected')
  → SQLSTATE[23000]: CHECK constraint failed: status
  ```
  El `CHECK` real en SQLite solo permite
  `('pending_parent_approval', 'open', 'accepted', 'rejected', 'completed')`
  — sin `teacher_rejected`.
- **Por qué importa**: `ClassRequestController::teacherReject()` (línea ~218)
  escribe literalmente `'status' => 'teacher_rejected'` — el mismo código que
  corre en producción (MySQL, donde sí funciona) fallaría con un 500 si se
  ejecutara contra la base de datos de test (SQLite) tal como está hoy.
  Ningún test existente ejerce este flujo con una escritura real a la BD —
  el grep inicial que sugería cobertura (`TeacherVerificationGateTest`,
  `SubjectProfanityFilterTest`) resultó ser una coincidencia de palabras
  sueltas ("teacher" + "reject"), no del flujo real, verificado leyendo
  ambos archivos.
- **Clasificación**: `P0` — no es `PRODUCT DECISION REQUIRED` como
  `active_offer` (esto no depende de ninguna decisión de negocio; es
  simplemente una migración que nunca se completó para SQLite). Tampoco es
  un hallazgo de diseño — es el mismo tipo de "Audit Snapshot Drift"/
  paridad SQLite-MySQL que `docs/MOVA_AUDIT_PHASE0.md` ya había marcado
  como trabajo pendiente de mayor prioridad que el rediseño visual.
- **No se corrigió aquí**: modificar una migración de estado de negocio ya
  aplicada (`class_requests.status`) cae directamente bajo la regla de
  `CLAUDE.md` de "cambios sensibles... requieren una pasada explícita de
  revisión de seguridad" — no es una llamada que corresponda tomar en medio
  de una auditoría de diseño. El arreglo correcto (una migración *nueva* que
  amplíe el `CHECK` de SQLite, no editar la existente) queda marcado para
  atención dedicada, no aplicado a ciegas.
- **Consecuencia práctica para esta sesión**: el contract test de estados
  (más abajo) usa listas verificadas manualmente contra las migraciones
  reales — no introspección dinámica del schema — precisamente porque este
  hallazgo demuestra que el schema de test no siempre coincide con la
  intención real del dominio.

### `classes.status` no tenía NINGÚN CHECK en SQLite — P1 reliability/environment parity · ✅ RESOLVED

**Estado, por dimensión** (mismo vocabulario que el P0 de arriba, nunca
colapsado en una palabra):

```text
DOMAIN CONTRACT AUDITED   ✅ (los 6 estados canónicos, cada escritor real
                              rastreado en código, ver abajo — no se asumió
                              el enum documentado, se verificó)
FIXED                     ✅ (migración nueva 2026_08_27_000003, no se editó
                              una ya aplicada)
SQLITE FRESH VERIFIED     ✅ (CHECK contiene exactamente los 6 valores;
                              3 índices de status recreados correctamente)
SQLITE INCREMENTAL        ✅ (BD SQLite persistida, no :memory:, sembrada
VERIFIED                     con una fila por estado ANTES de migrar;
                              las 6 sobrevivieron con su valor exacto)
ROLLBACK VERIFIED         ✅ (down() revierte a VARCHAR libre; datos e
                              índices intactos tras el rollback)
MYSQL LOCAL VERIFIED      ✅ (SHOW COLUMNS real, 127.0.0.1/mova: el ENUM ya
                              coincidía exactamente con el contrato canónico)
REGRESSION COVERED        ✅ (13 tests nuevos; verificado que 2 de ellos
                              fallan sin la migración — no decorativos)
STAGING / PRODUCTION      ❌ NO VERIFICADO (mismo límite que el P0: sin
                              acceso a Railway desde esta sesión)
```

**Reclasificado de P2 a P1** (no "incidente de producción confirmado" — es
un riesgo de paridad de entornos, distinción que este documento mantiene
explícita): `classes.status` incluye estados con efecto económico real
(`paid`, `pending_parent_confirmation`), y el patrón de riesgo era que un
typo futuro pasara silenciosamente TODA la suite de tests (100% SQLite) y
solo se descubriera contra MySQL real.

**Auditoría transversal completa hecha antes de tocar el schema** (no se
saltó directo a "reconstruye el CHECK"): se rastreó cada escritor real de
`classes.status` en el código actual —
`LessonController::store()/confirmPayment()/cancel()` (`scheduled`, `paid`,
`cancelled`), `LessonReportController::store()`
(`pending_parent_confirmation`), `LessonSettlementService::consume()/
refund()` (`completed`, `cancelled`), `SettleLessons::escalateToReview()`
(`needs_admin_review`), `AdminController::cancelLesson()` (`cancelled`).
Los 6 estados canónicos tienen escritor real y correcto; ninguno escribe un
valor fuera del conjunto. `in_progress` no tiene escritor en ningún lugar
del código actual (ya limpiado del ENUM de MySQL en
`2026_08_24_000002_remove_in_progress_from_classes_status_enum.php`) — no
se reintrodujo.

**Matiz importante, verificado leyendo cada controlador/servicio**: cada
transición ya valida su precondición explícitamente en código
(`abort_unless($lesson->status === '<estado previo esperado>', 422, ...)`
bajo `lockForUpdate()`) — la ausencia del `CHECK` en SQLite nunca fue la
ÚNICA barrera contra un estado inválido, era la ausencia de una SEGUNDA
capa de defensa a nivel de base de datos. Esta distinción es la razón por
la que se mantiene como P1 (riesgo de que un futuro typo no se detecte en
CI) y no se reclasifica como bug de producción ya ocurrido — no hay
evidencia de que ningún valor inválido haya llegado nunca a escribirse.

Origen histórico (no es un descuido nuevo, es una decisión deliberada de
2026_07_17_000001_add_payment_states_to_classes_table.php): esa migración,
al ampliar el enum de pagos, usó
`$table->string('status')->default('scheduled')->change()` para SQLite en
vez de reconstruir un `CHECK` — el patrón `rebuildStatusColumn()` que este
mismo bloque de integridad usa ahora todavía no existía como práctica
establecida en esa fecha.

**Corregido** en
`database/migrations/2026_08_27_000003_add_check_constraint_to_classes_status_for_sqlite.php`
— mismo patrón que `2026_08_27_000002` (rebuild de columna + guard de
drift MySQL "fail loud"), con una complicación real que `class_requests` no
tenía: **tres** índices tocan `status`
(`classes_status_index`; `classes_status_settled_index`, compuesto con
`credits_settled_at`; `classes_teacher_status_start_index`, compuesto con
`teacher_profile_id`/`start_time`) — los tres se dropean y se recrean con
el mismo nombre y composición exactos, verificado leyendo `sqlite_master`
antes/después, no asumido del código fuente de las migraciones que los
crearon.

El guard de MySQL aquí es más estricto que el de `class_requests`: en vez
de comprobar solo "¿está el valor que me importa?", compara el **conjunto
completo** del ENUM real contra el contrato canónico (orden-independiente)
— detecta tanto un estado ausente como un estado histórico que debería
haberse limpiado (p. ej. un `in_progress` reintroducido) y no se limpió en
una instalación concreta.

13 tests nuevos:
`ClassesStatusEnumMigrationDriftGuardTest.php` (5, guard de MySQL vía mock
de `DB`) y `ClassesStatusCheckConstraintTest.php` (8, comportamiento real
contra la BD de test — las 6 escrituras válidas aceptadas vía
`Lesson::create()`, un valor inválido y un `in_progress` reintroducido
rechazados con `QueryException` real). Verificado que no son decorativos:
se quitó temporalmente la migración, se confirmó que exactamente los 2
tests de rechazo fallaban (los 6 de aceptación seguían pasando — un
VARCHAR libre también acepta valores válidos), se restauró.

Suite completa: **496/496** (483 + 13 nuevos).

---

## 🔍 Auditoría de consistencia cruzada — patrones duplicados entre páginas

`statusColors.js` no era el único caso. Búsqueda dirigida
(`grep` de funciones locales `*Color()`/`*Badge()`/`*Style()`/`*Label()` en
todo `resources/js`) para verificar si existían más — en vez de asumir que
un hallazgo ya cerraba el tema. Resultado: **2 duplicaciones reales
encontradas y corregidas, 1 confirmada como drift real de color, no solo
duplicación de código.**

### Bug real de deriva de color: `open` (ClassRequest) — RESUELTO

- **Dónde**: `Admin/Requests.vue::badgeClass()` tenía su propio mapeo
  independiente del mismo dominio que `utils/statusColors.js` ya cubre.
- **El bug**: `open` se pintaba `bg-blue-50 text-blue-700` en la tabla de
  Admin, pero `cyan` en cualquier `StatusBadge` del resto de la app (padre,
  profesor) — el mismo estado de negocio con dos colores distintos según qué
  pantalla lo mostrara. Exactamente el tipo de drift que la duplicación de
  `paid`/`scheduled` ya había demostrado que podía pasar.
- **Corregido**: `Admin/Requests.vue` ahora importa `statusStyle()`. Se
  conservó una excepción deliberada y documentada: el label local
  distingue "Rechazada (padre)" de "Rechazada (prof.)" para `rejected`/
  `teacher_rejected` — una tabla de administrador se beneficia de saber
  quién rechazó, algo que el label genérico compartido no necesita en el
  resto de la app. El color, en cambio, no tiene excepción — viene 100% de
  la fuente única.

### Duplicación de código sin drift (por suerte) — RESUELTA

- **RechargeRequest** (`pending`/`approved`/`rejected`/`reversed`):
  `Teacher/Credits/Index.vue` (`rechargeLabel`/`rechargeBadge`) y
  `Admin/Recharges/Index.vue` (`statusLabel`/`statusBadge`) implementaban el
  mismo mapeo por separado, con nombres de función distintos. Los colores
  coincidían exactamente (sin drift esta vez), pero el label de `reversed`
  ya divergía: "Recarga revertida" vs. "Revertida". Extraído a
  `utils/rechargeStatusColors.js` (dominio propio, no fusionado con
  `statusColors.js` — son dos ciclos de vida de negocio distintos); ambos
  consumidores importan `rechargeStatusStyle()` ahora.
- **Franjas horarias de disponibilidad**: `TimeSlotPicker.vue` (el widget
  donde el padre elige su disponibilidad) y
  `ClassRequests/TeacherIndex.vue` (donde el profesor ve qué eligió el
  padre) tenían la lista de 6 franjas — mismas claves, mismo texto, mismos
  emoji — duplicada palabra por palabra. Extraído a `utils/timeSlots.js`;
  ambos consumidores la importan y ambos migraron sus emoji a `Icon` de paso.

### Revisado y descartado como duplicación (dominios genuinamente distintos)

- `Admin/AiUsage.vue::statusBadge` — estados de una llamada de IA
  (success/fallback/error/skipped), dominio propio, un solo consumidor.
- `Diagnostics/Create.vue::gradeLevelLabel` — nivel educativo del alumno, no
  relacionado con estados de clase/solicitud/recarga.
- `Welcome.vue::levelColor` — mismo nivel educativo, pero en la landing
  pública; un solo consumidor hoy, sin otro sitio con el que compararlo
  todavía. No se fusiona especulativamente con `gradeLevelLabel` sin un
  segundo caso real que lo justifique.

**Regla explícita para no sobrearquitecturar** (para no terminar con
`utils/statusColors.js` + `rechargeStatusColors.js` + `timeSlots.js` +
diez utilitarios más desconectados): se extrae una fuente única únicamente
cuando existen **las cuatro** condiciones a la vez — concepto de dominio de
negocio real (no solo "dos bloques de código parecidos"), reutilización
real ya confirmada en ≥2 consumidores, riesgo real de deriva semántica
(dos pantallas mostrando el mismo estado con significado distinto), y
beneficio de mantenimiento que supera el coste de una indirección más. Los
tres casos de esta sesión (`statusColors.js`, `rechargeStatusColors.js`,
`timeSlots.js`) cumplían las cuatro — verificado, no asumido, para cada
uno antes de crear el archivo.

### Contract test: el registro de estados ya no puede desincronizarse en silencio

`tests/Feature/DesignSystemStatusRegistryTest.php` (nuevo) — verifica que
todo estado real de `Lesson`/`ClassRequest`/`RechargeRequest` (listas
citadas contra las migraciones reales, no el schema de SQLite — ver el
hallazgo de bug arriba) esté registrado en el archivo JS correspondiente,
y que cada estado tenga tanto `color` como `label` (nunca solo color).
Verificado que el test realmente falla, no solo que compila: se eliminó
`cancelled` de `STATUS_STYLES` temporalmente, se confirmó que 2 de las 4
pruebas fallaban con el mensaje esperado, y se restauró el archivo desde
git antes de continuar. Si mañana alguien agrega un estado nuevo a un enum
de backend y olvida el frontend, `php artisan test` falla — no depende de
que un humano se acuerde de revisar ambos lados.

---

## Matriz de cobertura completa (82 archivos, filesystem real)

<!-- MATRIZ:INICIO -->
### Auth (7 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Auth/ConfirmPassword.vue` | 0 | 0 | **COMPLETA** — además corregido: texto/label/botón en inglés (residuo de Breeze), ahora en español | ✅ (ya usaba primitivos) | ✅ (tokens) | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (ruta tras middleware `auth`, redirige a `/login` sin sesión; este sandbox no tiene DB para autenticar) |
| `Pages/Auth/ForgotPassword.vue` | 0 | 0 | **NO CHANGE — VERIFIED** (ya en español, ya sobre primitivos migrados, sin indigo) | ✅ | ✅ | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED ✅ (`/forgot-password` real, sin DB necesaria) |
| `Pages/Auth/Login.vue` | 0 | 0 | **COMPLETA** — 🚧 → `Icon name="pending"` (Construction) | ✅ | ✅ | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED ✅ (modal probado con clic real, icono Construction confirmado en pantalla) |
| `Pages/Auth/PhoneVerification.vue` | 0 | 0 | **PARCIAL** — 7 indigo→brand corregidos; sigue con markup propio (no usa TextInput/Checkbox/PrimaryButton) — deferred, ver nota | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED: no hay test que cubra esta página · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (requiere sesión con teléfono pendiente de verificar, no reproducible sin DB) |
| `Pages/Auth/Register.vue` | 0 | 0 | **PARCIAL** — 5 emoji migrados a Icon (`pending`/`role-parent`/`role-teacher`); wizard sigue con botones propios en vez de BaseButton — deferred, ver nota. **Bug real corregido en `0f507fe`** (sesión posterior a la migración visual): el wizard dejaba al usuario varado en el último paso, sin ningún mensaje visible, cuando un error de validación del servidor (el único caso real alcanzable: email duplicado — `unique:users`) caía en un campo de un paso anterior no montado en el DOM. `submit()` ahora pasa `onError` a `form.post()`, que mapea cada campo posible (`role`/`name`/`email`/`phone`/`teacher_subject_names`/`password`/`accepted_terms`, leídos directamente de `RegisteredUserController::store()`) al paso que lo renderiza vía `stepForField()`, y navega al más temprano si hay varios | ✅ | ✅ (tokens en lo migrado) | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ (`RegistrationTest`, incluye el caso de email duplicado) · BROWSER_VERIFIED ✅ — dos repros reales, no solo el campo `email`: (1) `ana@mova.test`, salta a "PASO 3 DE 5"; (2) **prueba de prioridad multi-campo** solicitada explícitamente en revisión: nombre de 300 caracteres (pasa la validación del cliente, que solo comprueba longitud > 0 — el cliente no replica `max:255`) + `ana@mova.test` simultáneamente, dos errores reales de servidor en pasos distintos (2 y 3) a la vez — el wizard aterriza en **"PASO 2 DE 5"** (el más temprano), no en el 3, confirmando que `Math.min()` sobre `stepForField()` decide por orden del wizard y no por el orden del objeto `errors` recibido. `email` seguía en `form.errors` al avanzar manualmente al paso 3 después. Verificado también que no quedó ninguna fila duplicada/huérfana para `ana@mova.test` tras el intento (`tinker`: `count() === 1`, la cuenta seed original) |
| `Pages/Auth/ResetPassword.vue` | 0 | 0 | **NO CHANGE — VERIFIED** (ya en español, ya sobre primitivos migrados, sin indigo) | ✅ | ✅ | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED ✅ (`/reset-password/{token}` real con token de prueba — la página solo renderiza el formulario, la validación real del token ocurre en el POST) |
| `Pages/Auth/VerifyEmail.vue` | 0 | 0 | **NO CHANGE — VERIFIED** (ya en español, ya sobre PrimaryButton migrado, sin indigo) | ✅ | ✅ | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (ruta tras middleware `auth`, redirige a `/login` sin sesión) |

### Parent (1 archivo)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Dashboard/Parent.vue` | 0 | 0 | **COMPLETA** — 30 emoji → Icon (semántico, no mecánico: mismo emoji 📅 usado 4 veces distintas se mapeó a `classes` cuando es un icono suelto y a un `<Icon>` inline junto a la fecha cuando acompaña texto); CTA de diagnóstico simplificado de gradiente indigo→brand a tarjeta plana `bg-brand-50` (el gradiente decorativo iba contra PRODUCT.md); 5ª estrella de reseña ahora usa `<Icon fill>` en vez de texto `★`; `dotColor()` alineado a `violet` (mismo tono que `paid` en `statusColors.js`, ver más abajo) | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ (`MonetizationIntegrityTest` ejercita esta ruta server-side) · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (requiere sesión de padre con hijos/clases reales) |

### Parent — gestión de hijos (3 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Students/Create.vue` | 0 | 0 | **NO CHANGE — VERIFIED** (ya en brand tokens, sin indigo; se usó como referencia para corregir Edit.vue) | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ (`StudentDeletionIntegrityTest`, `AuthorizationPolicyTest`) · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (ruta tras `auth`) |
| `Pages/Students/Edit.vue` | 0 | 0 | **COMPLETA** — 7 indigo→brand, y **radio/borde alineados a Create.vue** (`rounded-lg`→`rounded-xl`, `border-gray-300`→`border-gray-200`, `shadow` del botón): eran el mismo formulario con dos estilos visiblemente distintos, hallazgo real de inconsistencia, no solo de color | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (misma razón que Create.vue) |
| `Pages/Students/Index.vue` | 0 | 0 | **COMPLETA** — 🎒 → `Icon name="my-students"`; botón de eliminar propio → `DangerButton` con `:loading="deleting"` (ya existía la lógica, solo faltaba el componente correcto) | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (misma razón que Create.vue) |

### Teacher (4 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Dashboard/Teacher.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 21 emoji→Icon; banner post-clase de `indigo` (LEGACY) a `blue`/info (SEMANTIC: "escribe tu reporte" es rutina, no alarma — el rojo queda reservado a la alerta real de "reporte atrasado" más abajo, evita que dos avisos usen el mismo color con urgencia distinta); unificado el icono de "Solicitudes abiertas" (antes 📬) con el de la acción rápida "Solicitudes" (📋) — mismo concepto, dos emoji distintos en la misma pantalla. **Hallazgo de producto real, no solo visual, documentado en el propio código:** el checklist de perfil sigue pidiendo "Al menos una oferta activa" cuando el flujo de crear ofertas ya no existe para profesores nuevos (ver comentario en el `<script>`) — no se tocó backend, queda anotado explícitamente como pendiente de decisión de producto, no oculto. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ (`MovaCriticalFlowTest` y otros ejercitan estas rutas) · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (requiere sesión de profesor con datos reales) |
| `Pages/Teacher/Credits/Index.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 💳→Icon, botón "x" de cerrar→`Icon name="close"`; tildes españolas eliminadas sistemáticamente corregidas (creditos, Numero, Operacion, Descripcion, Aun). **Deduplicado** (ver "Auditoría de consistencia cruzada" arriba): `rechargeLabel`/`rechargeBadge` locales → `rechargeStatusStyle()` compartido con `Admin/Recharges/Index.vue`. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ (`FinancialConcurrencyTest`, `PaymentOrderTest`, `SpecificRatePricingTest`) · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** |
| `Pages/Teacher/Edit.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — "✓ Copiado"→`Icon name="check"`; el badge de tier "Experto" pasó de `indigo` (LEGACY, sin significado propio) a `blue`, como paso intermedio deliberado de una escala Base(gris)→Experto(azul)→Élite(ámbar) — no una recoloración estética suelta. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ (`TeacherReferralCodeTest`, `TeacherReferralRequestTest` ejercitan esta página) · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** |
| `Pages/Teacher/Setup.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 13 indigo→brand (todos `LEGACY`, resto de Breeze, verificado uno por uno: focus rings, chip de tarifa inicial, chips de materias, botón de envío). Flujo ya claro (bio → tarifa informativa, no editable → materias → guardar) — responde directamente "¿qué información necesita introducir el profesor?": solo bio y materias, la tarifa es automática. Sin cambios de estructura. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** |

### Admin (8 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Admin/AiUsage.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Lessons.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/PendingTeachers.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Recharges/Index.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — deduplicado: `statusLabel`/`statusBadge` locales → `rechargeStatusStyle()` compartido (ver arriba). | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** |
| `Pages/Admin/Requests.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — **bug real de color corregido**: `open` se pintaba azul aquí, cyan en el resto de la app (ver "Auditoría de consistencia cruzada"). Ahora importa `statusStyle()`; conserva el label admin-específico "(padre)"/"(prof.)" para las dos variantes de rechazo, documentado como excepción deliberada. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** |
| `Pages/Admin/Reviews.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Users.vue` | 2 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Dashboard/Admin.vue` | 12 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Marketplace (2 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Marketplace/Index.vue` | 1 (`💡` en la línea 38, texto plano — **hallazgo real de esta re-sincronización, no migrado, no visto antes**) | 0 | **COMPLETA (parcial)** — tokens/tipografía migrados y **2 P0 de exposición pública de datos corregidos** (ver sección propia arriba) durante el rediseño de Marketplace; el 💡 suelto quedó fuera de esa pasada | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: pendiente de re-confirmar en esta revisión (dato de conteo corregido, no la verificación visual) |
| `Pages/Teachers/Show.vue` | 0 (re-contado, verificado con regex Unicode real — no el conteo original de 6) | 0 | **COMPLETA** — tokens/iconos migrados junto con Marketplace/Index.vue, mismo commit del P0 de exposición pública | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: pendiente de re-confirmar en esta revisión |

### Reservas/Solicitudes (6 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/ClassOffers/Edit.vue` | 1 | 6 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassOffers/Index.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassRequests/Accept.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassRequests/Create.vue` | 0 (re-contado, verificado con regex Unicode real) | 0 | **COMPLETA** — rediseño completo (commits `f7b03b7`/`a266f7d`): estructura intención→revisión→confirmación, tarjetas de modalidad, resumen "antes de enviar" restringido a campos genuinamente finales. **Corregido aquí**: esta fila decía `NO` en una revisión anterior de este mismo documento pese a que la sección de cierre del rediseño (ver "Cierre del rediseño de `Create.vue`" más abajo) ya documentaba el trabajo terminado — contradicción real, no intencional | mobile 375px verificado en navegador real | ⚠️ BLOCKED — no aplicable, la app no tiene modo oscuro implementado en ningún lado todavía (ver sección de cierre) | parcial — `aria-pressed` + orden de tabulación verificados; `role="alert"` en `InputError.vue` verificado por separado (ver sección de `InputError` más abajo) | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED ✅ (happy path real: `ClassRequest#19` creado en MySQL local, confirmado por `tinker`) |
| `Pages/ClassRequests/Index.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassRequests/TeacherIndex.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 7 emoji→Icon (📋 vacío + 6 en `TIME_SLOT_LABELS`, ahora deduplicado con `TimeSlotPicker.vue` vía `utils/timeSlots.js`). | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** |

### Clases/Reportes (6 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/LessonReports/Create.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/LessonReports/ParentIndex.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/LessonReports/Show.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/LessonReports/TeacherIndex.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Lessons/ParentIndex.vue` | 3 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Lessons/TeacherIndex.vue` | 3 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Diagnóstico IA (2 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Diagnostics/Create.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Diagnostics/Results.vue` | 1 | 5 | NO | pendiente | pendiente | pendiente | pendiente |

### Reseñas (1 archivo)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Reviews/Create.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Perfil/Settings (6 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Profile/Edit.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/DeleteUserForm.vue` | 0 | 0 | PARCIAL (usa Modal/Danger/SecondaryButton ya migrados) | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/NotificationPreferencesForm.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/UpdateAvatarForm.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/UpdatePasswordForm.vue` | 0 | 0 | PARCIAL (usa PrimaryButton ya migrado) | pendiente | pendiente | pendiente | pendiente |
| `Pages/Profile/Partials/UpdateProfileInformationForm.vue` | 0 | 1 | PARCIAL (usa PrimaryButton ya migrado) | pendiente | pendiente | pendiente | pendiente |

### Legal (2 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Legal/Privacy.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Legal/Terms.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Landing público (2 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Landing/StudentInvitation.vue` | 10 | 3 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Landing/TeacherInvitation.vue` | 6 | 1 | NO | pendiente | pendiente | pendiente | pendiente |

### Público/Sistema (3 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/About.vue` | 3 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Suspended.vue` | 1 | 2 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Welcome.vue` | 22 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Layouts — compartido (3 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Layouts/AppLayout.vue` | 0 | 0 | **PARCIAL** (iconos migrados Fase 3; colores propios aún sin tokenizar — deliberado, ver DESIGN.md) | pendiente | pendiente | pendiente | pendiente |
| `Layouts/GuestLayout.vue` | 0 | 0 | **PARCIAL** — flash messages migradas a Icon (mismo patrón que AppLayout); colores propios del panel de marca aún sin tokenizar (deliberado, mismo motivo que AppLayout) | BUILD_VERIFIED ✅ | pendiente | pendiente | BROWSER_VERIFIED ✅ (indirectamente, vía las 5 páginas Auth sin DB que lo envuelven) |
| `Layouts/PublicPageLayout.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Componentes — compartido (26 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Components/AvailabilityPicker.vue` | 0 | 0 | **NO** — solo se verificó con grep dirigido que no tiene emoji/indigo (no cuenta como `AUDITED`: no se leyó el archivo completo todavía, así que no se puede afirmar que no tenga otros problemas). | pendiente | pendiente | pendiente | pendiente |
| `Components/BaseButton.vue` | 0 | 0 | **COMPLETA** (Fase 2, ver commit `1b58bc2`) | ✅ (utilidades responsive por diseño) | ✅ (tokens) | ✅ (44px, aria-busy, focus-visible) | ✅ (verificado en Login.vue) |
| `Components/Checkbox.vue` | 0 | 1 (`text-brand-600` ya reemplazó el foco; el string "indigo" restante es un comentario histórico, no una clase) | **COMPLETA** (Fase 2) | ✅ | ✅ | pendiente | pendiente |
| `Components/CookieConsent.vue` | 0 | 0 | **NO** — mismo caso que `AvailabilityPicker.vue`: solo grep dirigido, no lectura completa. | pendiente | pendiente | pendiente | pendiente |
| `Components/DangerButton.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | ✅ | ✅ |
| `Components/EmptyState.vue` | 0 | 0 | **COMPLETA** (nuevo, Fase 2) — sin consumidores todavía | pendiente | ✅ (tokens) | pendiente | pendiente |
| `Components/Icon.vue` | 0 | 0 | **COMPLETA** (nuevo, Fase 3) | ✅ | ✅ (currentColor) | ✅ (aria-hidden/aria-label explícito) | ✅ |
| `Components/Illustrations/FamilyIllustration.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/Illustrations/TeacherIllustration.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/InputError.vue` | 0 | 0 | **COMPLETA** (Fase 2) + **`role="alert"`/`aria-atomic="true"` añadidos en `bc84e70`** — componente compartido por 13 páginas reales (grep, no supuesto): `Auth/{Login,Register,ConfirmPassword,ResetPassword,ForgotPassword}`, `Profile/Partials/*` (3), `Students/{Create,Edit}`, `ClassOffers/Edit`, `Teacher/Credits/Index`, `ClassRequests/Create`. Verificado que no colisiona con el único otro `role="alert"` existente en la app (`Admin/Lessons.vue`, componente distinto, sin anidamiento) | ✅ | ✅ | **parcial** — `role="alert"` sí, `aria-describedby`/`aria-invalid` conectando el `<input>` con su error NO (clasificado `P2`, pendiente, ver nota en el cierre de `ClassRequests/Create.vue`) | BUILD_VERIFIED ✅ · TEST_VERIFIED: no aplica (no hay runner JS en el proyecto, confirmado revisando `package.json`) · BROWSER_VERIFIED ✅ (error real de Laravel visible/oculto correctamente en Login; error real de servidor visible en Register tras el fix de `stepForField`) |
| `Components/InputLabel.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | pendiente | pendiente |
| `Components/JitsiModal.vue` | 2 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/LandingFooter.vue` | 1 | 0 | **AUDITED — NO CHANGE (CONTENT)** — el ❤ es "Desarrollado con ❤ por Abel Castillo", copy/crédito personal, no un icono de interfaz. Clasificado `CONTENT` explícitamente, no omitido por descuido. | pendiente | pendiente | pendiente | pendiente |
| `Components/LandingNavbar.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/Lessons/ParentLessonCard.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — deduplicado de `statusColors.js` (`statusStripe()`/`statusLabel()` locales eliminados, ahora consume `statusStyle().stripe/.label`); 6 emoji→Icon (estrellas de reseña ahora `<Icon fill>`, no texto `★`). Personalización menor descartada a propósito: el label local decía "Esperando **tu** calificación" (2ª persona), la fuente compartida dice "Esperando calificación" (genérica) — se prioriza una sola fuente de verdad sobre el matiz de copy, documentado aquí en vez de silenciado. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ |
| `Components/Lessons/TeacherLessonCard.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — mismo deduplicado; 5 emoji→Icon. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ |
| `Components/Lessons/WeeklyCalendar.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 🎥→Icon. Ya era consumidor correcto de `statusStyle()` (no duplicaba el mapeo) — confirmado, no asumido. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ |
| `Components/Modal.vue` | 0 | 0 | **COMPLETA** (Fase 2, ver commit `1b58bc2`) | ✅ (hoja móvil / diálogo centrado, medido en 1280px) | ✅ (tokens) | ✅ (dialog/aria-modal/focus trap/restauración de foco — verificado con teclado real) | ✅ |
| `Components/MovaLogo.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/NotificationBell.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 🔔→Icon; indigo→brand (link "Marcar todas", fondo de no-leído). **A11y real, no solo color**: añadido `aria-haspopup`/`aria-expanded`/`aria-controls` al botón (el panel colapsable no anunciaba su estado). **Bug real de manejo de errores**: `markOne`/`markAll` no tenían `try/catch` (a diferencia de `load()`, que sí) — un fallo de red lanzaba un rechazo de promesa sin manejar; ahora no actualiza el estado local si la petición falla, en vez de asumir éxito optimista. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (solo se usa dentro de `AppLayout`, autenticado) |
| `Components/PrimaryButton.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | ✅ | ✅ |
| `Components/SecondaryButton.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | ✅ | ✅ |
| `Components/Skeleton.vue` | 0 | 0 | **COMPLETA** (nuevo, Fase 2) — sin consumidores todavía | ✅ | ✅ | ✅ (aria-hidden) | pendiente |
| `Components/StatusBadge.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Components/TextInput.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | pendiente | pendiente |
| `Components/TimeSlotPicker.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 6 emoji→Icon (semántico por hora del día: Sunrise/Sun/Sunset/RefreshCw, no genérico); 3 indigo→brand; `aria-pressed` añadido. **Deduplicado**: la lista de franjas horarias se movió a `utils/timeSlots.js`, compartida con `ClassRequests/TeacherIndex.vue` (antes duplicada palabra por palabra). | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (usado en `ClassRequests/Create.vue`, autenticado) |
<!-- MATRIZ:FIN -->

---

## 🔍 `Diagnostics` — auditoría de dominio completo (IA + datos educativos de menor + idempotencia + costo operativo)

Auditado como journey completo, no `Diagnostics/Create.vue` aislado:
`Parent → creación → idempotencia → enriquecimiento IA (opcional) →
scoring determinista → ClassRequest genérica → Results.vue`. Backend
primero, sin tocar UI/UX en esta pasada (por decisión explícita: este
dominio toca datos de un menor + IA + costo operativo, mayor riesgo que
Lesson Lifecycle).

### Hallazgo principal: el dominio ya llegaba notablemente maduro

A diferencia de otros dominios auditados esta sesión, `Diagnostics` no
necesitó ningún hallazgo de seguridad nuevo — el trabajo previo
(`DiagnosticAiEnrichmentService`, `AiPayloadContractTest.php`,
`DiagnosticIdempotencyTest.php`) ya cubría, **verificado leyendo el código,
no de memoria**:

```text
Ownership:            student = $user->students()->findOrFail(...) — 404 real, no solo un exists:
Idempotencia:         hash SHA-256 del CONTENIDO real (padre+alumno+materia+texto+goal+urgency),
                      UNIQUE de BD, distinta de la deduplicación temporal de ClassRequest — mismo
                      patrón que credit_transactions.idempotency_key (documentado en la propia migración)
Privacidad hacia IA:  solo subject_name/level/difficulty_text (redactado)/goal/urgency — NUNCA
                      student_id/parent_id/nombres/email/teléfono/school/school_feedback — verificado
                      campo por campo en AiPayloadContractTest.php (14 tests, preexistentes)
Privacidad hacia el
profesor:             help_needed nunca incluye difficulty_text/ai_summary/ai_risk_flags — solo
                      goal/urgency en texto genérico; confirmado con grep que ClassRequestController
                      nunca toca ningún campo de StudentDiagnostic
Fallback/errores IA:  nunca lanza excepción — try/catch total, saveFallback() en todo camino de fallo;
                      status success/fallback/error/skipped consistente entre AiUsageLog y Admin/AiUsage.vue
Rate limiting:        límite diario (medianoche Lima, no UTC — BUG-5 ya corregido antes de esta sesión)
                      + mensual, ambos con fallback determinista al excederse
Validación de salida: la IA nunca puede inyectar valores fuera de enums cerrados (VALID_LEVELS/
                      VALID_GOALS/VALID_FLAGS); resumen sin tags/markdown, capado a 200 chars
AI nunca decide:      DiagnosticRecommendationService::compute() es el árbitro — boost de IA
                      acotado a +5 puntos y solo dentro de 15 pts del top score, nunca elige al ganador
```

### Hallazgos reales de esta pasada — todos menores, ninguno de seguridad activa

1. **Bug real, sin impacto observable hoy**: `DiagnosticAiEnrichmentService::writeLog()`
   escribía `$diagnostic->user_id` — atributo inexistente en
   `StudentDiagnostic` (el real es `parent_user_id`) — así que
   `ai_usage_logs.user_id` era siempre `NULL` desde que la tabla existe.
   `AiUsageController::index()` nunca proyecta esa columna, así que no
   afectaba ninguna vista actual. **Corregido** en
   [DiagnosticAiEnrichmentService.php](../app/Services/DiagnosticAiEnrichmentService.php).
2. **Config documentada pero sin implementar**: `diagnostic.auto_disable_on_error`
   no se lee en ningún punto del código (`grep` confirma cero referencias en
   `app/`). Por defecto `false`, así que no representaba un riesgo activo —
   pero activarla esperando un auto-apagado real habría sido un supuesto
   falso. **Documentado explícitamente como NOT IMPLEMENTED** en
   [config/diagnostic.php](../config/diagnostic.php) en vez de dejarlo
   como una promesa silenciosa.
3. **Ruta legacy sin consumidor**: `requestClass()` (`POST /diagnostics/{id}/request/{offer}`)
   confirmado sin ninguna referencia en `resources/js/` — es un stub que
   solo redirige, dejado tras el pivote a solicitud genérica. Documentado en
   el propio controlador; no se eliminó la ruta (decisión de limpieza, no
   de esta auditoría).
4. **Gap de consumo de errores en frontend**: `Diagnostics/Create.vue::onError`
   guardaba el error bag completo, pero solo `errors.difficulty_text` tenía
   un `<p>` que lo mostrara — el mismo patrón de "fix de backend sin consumo
   de frontend" encontrado repetidamente esta sesión (`Register.vue`,
   `ClassRequests/Accept.vue`, `Lessons/*Index.vue`). Baja probabilidad en
   el flujo normal (los botones ya restringen a valores válidos del propio
   backend), pero real si un `subject`/alumno se elimina entre cargar la
   página y enviar. **Corregido** con un `topLevelError` computed que
   muestra cualquier error que no sea `difficulty_text`.
5. **`recommendations: []` hardcodeado** — ver **P2** en la sección de
   PRODUCT BLOCKERS arriba. No es un bug de seguridad ni de datos; es una
   decisión de producto pendiente, documentada por separado a propósito.

### Tests nuevos (huecos reales, cobertura previa ya verificada primero)

```text
test_parent_cannot_create_a_diagnostic_for_another_parents_student
    → confirma el findOrFail() con datos reales (antes solo se leía el código)
test_low_confidence_ai_summary_is_hidden_from_the_parent
    → confirma el umbral ai_confidence >= 60 en AMBOS lados (59 oculto, 60 visible)
test_a_duplicate_submission_never_calls_the_ai_provider_twice
    → Http::fake() + contador real de llamadas — confirma que el camino idempotente
      nunca vuelve a golpear al proveedor ni a escribir un segundo AiUsageLog
```

Los tres pasaron en el primer intento (el código ya era correcto) — no se
siguió el ciclo revert-confirma-falla-restaura porque no corregían un bug
de comportamiento, solo cerraban un hueco de verificación. Los cuatro
hallazgos reales (1-4 arriba) sí son fixes de comportamiento — cada uno de
alcance suficientemente pequeño y mecánico (un typo de atributo, un
comentario, un `computed` nuevo) que no requería su propio test de
regresión dedicado más allá de la suite completa.

### Cierre — vocabulario exacto, por dimensión

```text
Ownership (store/results):        VERIFIED — findOrFail() + Policy::view(), ambos con test nuevo real
Idempotencia (contenido, no UI):  VERIFIED — hash SHA-256 + UNIQUE de BD, distinta de dedup temporal de ClassRequest
IA no se llama dos veces:         VERIFIED — Http::fake() + contador real, camino idempotente confirmado
Privacidad hacia el proveedor:    VERIFIED (preexistente, 14 tests) — campo por campo, no solo el texto libre
Privacidad hacia el profesor:     VERIFIED — grep confirma cero referencias a StudentDiagnostic en ClassRequestController
Fallback/error semantics:         VERIFIED (preexistente) — nunca lanza, siempre fallback determinista
Confidence gating de ai_summary:  VERIFIED — test nuevo en ambos lados del umbral (59/60)
user_id en ai_usage_logs:         FIXED (bug real, sin impacto observable previo)
auto_disable_on_error:            DOCUMENTADO como NOT IMPLEMENTED (no se implementó — fuera de alcance)
requestClass() legacy:            DOCUMENTADO, no eliminado (decisión de limpieza, no de esta auditoría)
Error swallowing en Create.vue:   FIXED (topLevelError)
Recomendaciones nunca mostradas:  PRODUCT DECISION REQUIRED (P2, ver PRODUCT BLOCKERS) — no resuelto por inferencia
Tests:                            552/552 (3 nuevos, 0 regresión)
Build:                            BUILD_VERIFIED ✅
Browser:                          NOT BROWSER_VERIFIED — esta pasada fue deliberadamente backend/contrato de
                                   datos primero; UX/UI de Diagnostics (incluida verificación en navegador) es
                                   la fase siguiente, no parte de este cierre
Production verification:          NOT VERIFIED — sin acceso a Railway, igual que el resto de esta sesión
```

**`Diagnostics` — backend/contrato de datos → CLOSED FOR CURRENT SCOPE.**
No reabrir sin evidencia nueva. Sigue, como fase separada y explícita: (a)
UX/UI de `Diagnostics/Create.vue`/`Results.vue` con el mismo lenguaje visual
establecido en Lesson Lifecycle UX, y (b) la decisión de producto P2 antes
de tocar `Results.vue` (mostrar recomendaciones cambia lo que esa página
necesita renderizar).

---

## Decisiones y excepciones (documentadas, no escondidas)

| Decisión | Razón |
|---|---|
| Modo oscuro auto (`prefers-color-scheme`) desactivado | Verificado en navegador: activarlo con solo 13/82 archivos migrados produce una app mitad clara/mitad oscura. Se reactiva cuando la cobertura de tokens sea suficientemente amplia — ver `DESIGN.md`. |
| `Sheet.vue` no construido todavía | Cero consumidores reales hasta la Fase 4 (menú "Más" del padre) — construirlo antes sería código especulativo no verificable. |
| `EmptyState.vue`/`Skeleton.vue` sin consumidores todavía | Establecidos por adelantado (aprobado en el plan), a la espera de que la migración página-por-página los use. |
| `rounded-*` con nombres propios (`chip/control/card/elevated/pill`) en vez de `sm/md/lg/xl` | Evita colisión silenciosa con la escala default de Tailwind — ver `DESIGN.md`. |
| `Checkbox.vue` "indigo restante"=1 | Es un comentario del propio código explicando el reemplazo (`// reemplaza el indigo heredado...`), no una clase activa — falso positivo del grep mecánico, documentado aquí en vez de re-escribir el comentario para "limpiar el número". |
| `Register.vue` (wizard) y `PhoneVerification.vue` migrados solo en color/iconos, no en componentes | Ambos usan botones/inputs `<button>`/`<input>` propios en vez de `BaseButton`/`TextInput`/`Checkbox` — una conversión real (wizard de 5-6 pasos con estados condicionales de validación; formulario de código con estilos centrados/tracking-widest específicos) que merece su propia revisión, no un cambio apurado dentro del barrido de iconos/color. Queda como pendiente explícito, no oculto. |
| ~~Color del estado "pagada"/"abierta" duplicado en 4 lugares~~ **RESUELTO** | `utils/statusColors.js` extendido con campos `stripe` (barra sólida) y `dot` (punto de timeline) junto al `color`/`ring` que ya tenía. `Dashboard/Parent.vue::dotColor()`, `TeacherLessonCard.vue` y `ParentLessonCard.vue` ya NO tienen su propio mapeo — los tres importan `statusStyle()`. De paso se corrigió una inconsistencia real que el propio dedup expuso: el color "scheduled" de las tarjetas usaba `brand-500` (azul de marca) mientras el badge usaba `blue` genérico — dos azules distintos para el mismo estado; ahora los tres consumidores usan el mismo `blue`, reservando `brand` para acciones/marca. |
| ~~`TeacherProfile` sin `$hidden`~~ **RESUELTO (P3, defensa en profundidad)** | Encontrado en paralelo al P0 de `/marketplace` (commit `92e71c4`, tarea independiente): `User` ya tenía `$hidden = ['password', 'remember_token']`, `TeacherProfile` no tenía ninguno pese a cargar `yape_number`/`plin_number`/`credits_*`/campos de moderación interna. Sin fuga activa (verificado: cada consumidor real que pasa el modelo completo ya está correctamente acotado por contexto — perfil propio o admin). Se ocultaron solo los campos con CERO lectores reales en todo el frontend (`user_id`, `credits_available`, `credits_reserved`, `completed_classes_count`, `is_experienced`, `mentorship_slots_taken`, `reviewed_at`) — verificado con `grep` campo por campo antes de decidir, no una lista intuida. `reviewed_by` deliberadamente FUERA de la lista pese a no tener lector "obvio": oculta la relación `reviewedBy` ya cargada bajo la misma clave, rompería `Admin/PendingTeachers.vue`. 18 tests nuevos en `TeacherProfileHiddenFieldsTest.php`, verificados como regresión real. |

## Lo que este documento NO afirma todavía

- Que las 82 vistas tengan un lenguaje visual coherente — **69 de 82 siguen sin tocar**.
- Que exista ningún flujo completo verificado de punta a punta.
- Que el modo oscuro, el responsive o la accesibilidad estén cubiertos en ninguna página de producto (solo en los primitivos compartidos, fila por fila arriba).
- Que exista media/performance budget medido antes/después a nivel de página (sí existe a nivel de bundle global, ver commits de Fase 1-3).

Este documento se actualiza en cada commit de migración subsiguiente —
nunca se reescribe para "verse más terminado" sin que el commit correspondiente
exista.

## Alcance de este documento (para que no crezca sin límite)

Este archivo es un **ledger de evidencia y hallazgos**, no el lugar donde
vive una decisión de arquitectura estable. Regla de dónde va cada cosa:

- **Aquí** (`MOVA_DESIGN_AUDIT_FINAL.md`): qué se auditó, qué se encontró,
  qué se arregló, con qué evidencia, en qué commit, con qué verificación —
  append-only por naturaleza, crece con cada pasada.
- **`DESIGN.md`**: decisiones visuales ya estables (tokens, escalas, el
  porqué de cada rol semántico de color) — el sistema de diseño en sí, no
  el backend.
- **`docs/MOVA_SYSTEM_KNOWLEDGE.md`**: patrones de backend/datos ya
  estables (el patrón de reconstrucción de columna `enum` en SQLite, la
  tabla global de estados, la deuda técnica conocida) — es donde vive
  realmente el patrón `rebuildStatusColumn()` + guard de rollback + guard
  de drift de esta sesión (§26/§29), no en este ledger ni en `DESIGN.md`.

Cuando un hallazgo de aquí se resuelve y su patrón se vuelve un estándar
repetible, la evidencia de que ocurrió (commit, comando, test) se queda
aquí; la descripción del patrón en sí — para que el próximo caso similar lo
siga sin releer este historial — se sincroniza al documento que corresponda
según el tipo de decisión (visual → `DESIGN.md`; backend/datos →
`MOVA_SYSTEM_KNOWLEDGE.md`).
