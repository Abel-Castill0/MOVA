# MOVA — Auditoría de diseño exhaustiva (documento vivo)

**No es un anuncio de "rediseño terminado".** Es el rastreador honesto de
cobertura que el propio proceso de esta sesión exigió: inventario completo
por filesystem real, matriz por archivo, y ninguna fila oculta o marcada
"no aplica" sin justificación explícita.

**Identificación de esta revisión** (obligatoria — nunca mezclar snapshots
sin declararlo; corrige un hash de encabezado que quedó desactualizado tras
varios commits posteriores y generó confusión real durante una revisión):

```text
audit_revision:   2026-08-27.12
generated_at:     2026-08-27T13:37:58Z
repository_head:  193f9a7   (rama master — el commit que introduce este cambio de doc queda por encima de este hash en `git log`)
origin_head:      692b3651d09cb2731865efcb0d83dc83d2a36102   (58 commits detrás de local, sin push)
working_tree:     limpio salvo package-lock.json (ajeno a este documento)
authoring_commit: se confirma en el mensaje del commit que introduce este cambio
```

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
| `P0` | Rompe flujo, confianza o comprensión — bloquea al usuario | `active_offer` (ver "🚨 PRODUCT BLOCKERS" arriba) |
| `P1` | Inconsistencia importante o fricción real | `Students/Create.vue` vs `Edit.vue` con dos estilos distintos para el mismo formulario (Fase 3, slice 3) |
| `P2` | Mejora relevante de UX/UI | Migración de emoji a Lucide, tokens de color |
| `P3` | Pulido | Un radio o un tamaño de icono ligeramente distinto |

`active_offer` es P0/P1 — se trató como tal (sección propia, no perdido en
la matriz). El resto de esta sesión ha sido P2 (el grueso del trabajo) con
algunos P1 reales encontrados en el camino (la divergencia Create/Edit, las
tildes eliminadas en `Teacher/Credits/Index.vue`, el texto en inglés de
`ConfirmPassword.vue`).

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

| Superficie crítica | Estimado | Qué falta |
|---|---:|---|
| Auth | ~100% | Nada pendiente de este barrido — 2 páginas siguen `BLOCKED_VISUAL_VERIFICATION` (requieren sesión) |
| Parent — core journey | ~35% | `Dashboard/Parent`, `Students/*`, `ParentLessonCard`, `WeeklyCalendar` hechos; `Marketplace/Index`, `Teachers/Show`, `ClassRequests/Create`, `Diagnostics/*`, `Lessons/ParentIndex`, `LessonReports/ParentIndex`, `Reviews/Create` sin tocar |
| Teacher — core journey | ~50% | `Dashboard/Teacher`, `Teacher/Setup`, `Teacher/Edit`, `Teacher/Credits`, `TeacherLessonCard`, `ClassRequests/TeacherIndex` hechos; `LessonReports/Create`, `LessonReports/TeacherIndex`, `Lessons/TeacherIndex` sin tocar |
| Marketplace/descubrimiento | ~90% (2/2 páginas) | `Marketplace/Index.vue`, `Teachers/Show.vue` migrados + **2 P0 de exposición pública real** encontrados y corregidos, uno de ellos en la home pública (`WelcomeController`, no un archivo de Marketplace en sí — ver sección propia arriba, incluye auditoría acotada de las 10 rutas públicas de MOVA). Falta: filtros/búsqueda (brecha de funcionalidad ya documentada, no de diseño — no inventada aquí) y `BROWSER_VERIFIED`/`DARK_VERIFIED`/`RESPONSIVE_VERIFIED` (bloqueados: MySQL local no disponible en este sandbox ahora mismo) |
| Booking/Checkout | 0% | `ClassRequests/Create.vue`, `ClassOffers/*`, `Diagnostics/*` — sin tocar (el widget `TimeSlotPicker` que consumen sí está hecho) |
| Class experience (Jitsi) | 0% (deliberado) | `JitsiModal.vue` diferido a propósito para su revisión de seguridad dedicada — no es un olvido |
| Admin | ~25% | `Admin/Requests`, `Admin/Recharges` hechos (2/8); `Dashboard/Admin`, `Admin/Users`, `Admin/PendingTeachers`, `Admin/Lessons`, `Admin/Reviews`, `Admin/AiUsage` sin tocar |

**Lectura correcta de esta tabla:** el trabajo de mayor impacto real para el
negocio (Marketplace, Booking/Checkout, Class experience) sigue en 0%. El
orden de dominios restante prioriza esto explícitamente — ver "Flujos
críticos" abajo.

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
Marketplace/Booking/Checkout, que es donde MOVA genera y cobra clases,
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
| Concurrencia / doble reserva | ✅ Ya correcto | El lock de `TeacherProfile` actúa como mutex por profesor: dos `accept()` simultáneos para el mismo profesor se serializan; el segundo relee el estado ya actualizado por el primero |
| "Idempotencia" de aceptación (doble-accept del mismo `ClassRequest`) | ✅ Ya correcto | `abort_if($classRequest->status !== 'open', ...)` reevaluado DENTRO de la transacción con `lockForUpdate()` sobre el propio `ClassRequest` — un segundo accept tras el primero ve `status === 'accepted'` y aborta |

**Nada de esto se tocó ni se "arregló" — ya estaba bien construido.**
Documentarlo como verificado (no asumido) es en sí mismo el resultado de
esta pasada: la superficie más sensible de dinero/menores de MOVA
(aceptar una solicitud, reservar créditos, fijar precio) ya tenía las
protecciones correctas antes de esta sesión.

### Dos hallazgos reales, menores, encontrados en el camino (documentados, no P0/P1)

- **`ClassRequestController::store()` no protege contra doble envío por
  reintento de red** (P3). El botón de envío en `Create.vue` ya se
  deshabilita mientras `form.processing` (cubre el doble-click, el caso
  común), y la ruta tiene `throttle:10,1` (limita abuso, no duplicados).
  Pero no existe una clave de idempotencia ni una restricción de
  unicidad — un reintento genuino de red (conexión inestable, no un
  clic doble) podría crear dos `ClassRequest` idénticas. Impacto
  acotado: no mueve dinero/créditos en la creación (eso ocurre solo en
  `LessonController::store()`, ya protegido), en el peor caso dos
  profesores distintos podrían aceptar cada una y el padre terminaría con
  dos clases para una sola necesidad. No se implementa una clave de
  idempotencia ahora — es una decisión de diseño (¿qué forma debe tener?)
  que merece su propia pasada deliberada, no un parche apurado dentro de
  esta auditoría.
- **`ClassRequestController::store()` no valida `is_active`/`is_verified`
  al guardar un `class_offer_id` fuera del camino de mentoría** (P3). Una
  solicitud podría quedar vinculada a una oferta inactiva o a un profesor
  no verificado. El daño real está acotado porque `ClassRequestPolicy::
  accept()` ya bloquea a cualquier profesor no verificado
  independientemente del estado de la oferta — el peor caso es una
  solicitud huérfana que nadie puede/debe aceptar, no un problema de
  seguridad ni de dinero.

### Cierre de esta pasada — autorización a entrar en UX/UI

```text
[x] Root cause de ?offer_id determinado con evidencia: data minimization,
    NO object-level authorization (ver arriba)
[x] Contrato de ClassOffer/oferta verificado contra el código real, no inventado
[x] Student ownership verificado — ya correcto
[x] Teacher/subject/offer integrity verificado — ya correcto
[x] Pricing verificado como autoritativo del servidor — ya correcto
[x] Credits verificado como autoritativo + a prueba de carrera — ya correcto
[x] Availability verificado server-side — ya correcto
[x] Concurrencia verificada (lock de TeacherProfile como mutex) — ya correcto
[x] "Idempotencia" de aceptación verificada (lock sobre ClassRequest) — ya correcto
[x] Dos hallazgos menores (P3) documentados, no bloquean el cierre
```

Con esto, el contrato de negocio de `ClassRequests/Create.vue` está
verificado — la fase de UX/UI puede empezar sin dejar sin comprobar
ningún invariante de dinero/autorización/concurrencia detrás del diseño.

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
| `Pages/Auth/Register.vue` | 0 | 0 | **PARCIAL** — 5 emoji migrados a Icon (`pending`/`role-parent`/`role-teacher`); wizard sigue con botones propios en vez de BaseButton — deferred, ver nota | ✅ | ✅ (tokens en lo migrado) | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED ✅ (selector de rol + modal probados con clic real) |
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
| `Pages/Marketplace/Index.vue` | 4 | 1 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Teachers/Show.vue` | 6 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

### Reservas/Solicitudes (6 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/ClassOffers/Edit.vue` | 1 | 6 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassOffers/Index.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassRequests/Accept.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/ClassRequests/Create.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
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
| `Components/InputError.vue` | 0 | 0 | **COMPLETA** (Fase 2) | ✅ | ✅ | pendiente | pendiente |
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
