# MOVA — Auditoría de diseño exhaustiva (documento vivo)

**No es un anuncio de "rediseño terminado".** Es el rastreador honesto de
cobertura que el propio proceso de esta sesión exigió: inventario completo
por filesystem real, matriz por archivo, y ninguna fila oculta o marcada
"no aplica" sin justificación explícita.

Snapshot de esta versión: `HEAD` `ed96447` (rama `master`), working tree
limpio al generar el inventario, 2026-08-27. El inventario se regenera con
`python resources/../scratchpad/audit_signals.py` (script ad hoc, no
commiteado) cada vez que se actualiza este documento — los números de
"emoji restantes"/"indigo restante" son una re-lectura real de cada archivo,
no una copia del hallazgo original de la Fase 0.

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
| `DONE` | Todos los estados anteriores que aplican a esa página están cumplidos. |
| `BLOCKED_VISUAL_VERIFICATION` | No se puede verificar visualmente por una razón externa concreta (auth+DB en este sandbox) — **nunca se convierte en `DONE` ni en `BROWSER_VERIFIED ✅` solo porque el build pasa.** La razón siempre se nombra. |
| `NO CHANGE — VERIFIED` | Se auditó la página y genuinamente no necesitaba cambios — un resultado válido, no una omisión. |

**Ninguna fila de este documento llega a `DONE` todavía.** Lo que existe hoy,
fila por fila, es como mucho `IMPLEMENTED` + `BUILD_VERIFIED` +
`TEST_VERIFIED`, y `BROWSER_VERIFIED` solo donde la ruta no exige sesión —
el resto está honestamente en `BLOCKED_VISUAL_VERIFICATION`, nunca
maquillado.

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
| Emoji restantes (suma) | 120 en 31 archivos (139/36 tras Teacher, 199/43 al inicio de la Fase 3) — de estos, 1 es `CONTENT` deliberadamente conservado (`LandingFooter.vue`), el resto sigue sin clasificar |
| `indigo` restante (suma) | 26 en 11 archivos (33/13 tras Teacher, 72/18 al inicio; el 1 de `Checkbox.vue` es un falso positivo documentado abajo) |
| Archivos con algún trabajo de esta sesión | 33 de 82 |

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

---

## 🚨 PRODUCT BLOCKERS — decisiones de negocio pendientes (no ocultar entre hallazgos visuales)

Sección propia, no una fila más de la matriz — para que un hallazgo de
producto real no se pierda entre 139 conteos de emoji. Ninguno de estos se
resolvió aquí: no le corresponde a una auditoría de diseño decidir lógica de
negocio o backend.

### P0/P1 — `active_offer` en el checklist de perfil de profesor

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
| `Pages/Teacher/Credits/Index.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 💳→Icon, botón "x" de cerrar→`Icon name="close"`. **Hallazgo de copy real:** el archivo entero tenía las tildes españolas eliminadas de forma sistemática (creditos/Creditos, Numero, Operacion, Descripcion, Aun) — corregido en cada aparición, no solo en el título. Los badges de estado de transacción/recarga (green/amber/slate/blue/rose) ya seguían un criterio semántico razonable antes de esta sesión — no se tocaron. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ (`FinancialConcurrencyTest`, `PaymentOrderTest`, `SpecificRatePricingTest` tocan esta área) · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** |
| `Pages/Teacher/Edit.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — "✓ Copiado"→`Icon name="check"`; el badge de tier "Experto" pasó de `indigo` (LEGACY, sin significado propio) a `blue`, como paso intermedio deliberado de una escala Base(gris)→Experto(azul)→Élite(ámbar) — no una recoloración estética suelta. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ (`TeacherReferralCodeTest`, `TeacherReferralRequestTest` ejercitan esta página) · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** |
| `Pages/Teacher/Setup.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 13 indigo→brand (todos `LEGACY`, resto de Breeze, verificado uno por uno: focus rings, chip de tarifa inicial, chips de materias, botón de envío). Flujo ya claro (bio → tarifa informativa, no editable → materias → guardar) — responde directamente "¿qué información necesita introducir el profesor?": solo bio y materias, la tarifa es automática. Sin cambios de estructura. | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** |

### Admin (8 archivos)

| Archivo | Emoji restantes | Indigo restante | Migrada (tokens+iconos) | Responsive | Dark | A11y | QA |
|---|---:|---:|---|---|---|---|---|
| `Pages/Admin/AiUsage.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Lessons.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/PendingTeachers.vue` | 1 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Recharges/Index.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
| `Pages/Admin/Requests.vue` | 0 | 0 | NO | pendiente | pendiente | pendiente | pendiente |
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
| `Pages/ClassRequests/TeacherIndex.vue` | 7 | 0 | NO | pendiente | pendiente | pendiente | pendiente |

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
| `Components/TimeSlotPicker.vue` | 0 | 0 | **AUDITED + IMPLEMENTED** — 6 emoji→Icon (semántico por hora del día: Sunrise/Sun/Sunset/RefreshCw, no genérico); 3 indigo→brand. **A11y real**: añadido `aria-pressed` a los botones de selección múltiple (no anunciaban su estado activado/desactivado). | pendiente | pendiente | pendiente | BUILD_VERIFIED ✅ · TEST_VERIFIED ✅ · BROWSER_VERIFIED: **BLOCKED_VISUAL_VERIFICATION** (usado en `ClassRequests/Create.vue`, autenticado) |
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

## Lo que este documento NO afirma todavía

- Que las 82 vistas tengan un lenguaje visual coherente — **69 de 82 siguen sin tocar**.
- Que exista ningún flujo completo verificado de punta a punta.
- Que el modo oscuro, el responsive o la accesibilidad estén cubiertos en ninguna página de producto (solo en los primitivos compartidos, fila por fila arriba).
- Que exista media/performance budget medido antes/después a nivel de página (sí existe a nivel de bundle global, ver commits de Fase 1-3).

Este documento se actualiza en cada commit de migración subsiguiente —
nunca se reescribe para "verse más terminado" sin que el commit correspondiente
exista.
