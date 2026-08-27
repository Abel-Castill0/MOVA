# MOVA — FASE 0: Auditoría de Testing, Observabilidad, Automatización y Deuda Técnica

**Rol:** QA Engineer senior + DevOps Engineer
**Fecha:** 2026-08-26
**Alcance:** Solo investigación y documentación. Ningún archivo del proyecto fue modificado. Se instalaron dependencias (`composer install`, `npm install`, `npm run build`) únicamente para poder ejecutar la suite de tests en este worktree aislado — son artefactos gitignored (`vendor/`, `node_modules/`, `public/build/`), no cambios al código versionado.

---

## 0. Documentos de referencia no disponibles en este árbol

Este worktree es un checkout aislado desde `HEAD` (commit `692b365`). Los siguientes documentos mencionados en las instrucciones **no existen en este árbol** porque son archivos nuevos y sin commitear de la sesión principal:

- `docs/MOVA_SYSTEM_KNOWLEDGE.md`
- `docs/MOVA_PRODUCTION_READINESS.md`
- `docs/MOVA_QA_BASELINE.md`
- `docs/MOVA_CREDENTIAL_EXPOSURE.md`
- `docs/MOVA_QA_SECURITY_POLICY.md`

En su lugar se usó `MOVA_MASTER_CONTEXT.md` (sí commiteado, fechado 2026-07-18) como línea base del mapa de deuda técnica, contrastado en cada punto contra el código real en `HEAD`. Una parte importante de los hallazgos de esta auditoría es que **varios ítems marcados como pendientes en `MOVA_MASTER_CONTEXT.md` ya fueron resueltos** en commits posteriores a esa fecha — el documento maestro está desactualizado en al menos 6 puntos (detallado en la sección 4).

También se detectó (vía `git status` del repo principal, no de este worktree) que hay ~40 archivos modificados sin commitear, y que varios scripts de `qa/` (`check-twilio.mjs`, `end-to-end-welcome-email.mjs`, `global-setup.js`, `test-wizard-flow.mjs`, `qa/auth/admin.json`) aparecen **borrados en el working tree pero no en `HEAD`** — es decir, siguen existiendo en este worktree porque el borrado aún no se comiteó. No se tocó nada de `qa/` durante esta auditoría.

---

## 1. Testing audit

### 1.1 Estado real de la suite (ejecutada, no asumida)

```
php artisan test
Tests: 2 warnings, 174 passed (736 assertions)
Duration: ~38s
```

Esto es **muy superior** a lo que documenta `MOVA_MASTER_CONTEXT.md` (57 tests). La suite creció de 57 a 174 tests desde julio. Desglose por archivo (23 archivos, todos en `tests/Feature/`, no existe `tests/Unit/` — decisión deliberada, documentada en un comentario de `phpunit.xml`: "MOVA no tiene tests unitarios puros, todo el valor está en los Feature tests"):

| Archivo | Enfoque |
|---|---|
| `MonetizationIntegrityTest.php` | El más grande — ledger, créditos, pagos, jitsi/credenciales no expuestas |
| `LessonSettlementScenariosTest.php` | Liquidación automática de clases, force-complete/force-refund admin |
| `RescheduleTest.php` | 17 tests — reprogramación, solapamientos, locks |
| `TeacherVerificationGateTest.php`, `TeacherReferralRequestTest.php`, `TeacherReferralCodeTest.php` | Flujo de códigos de referido de profesor |
| `AuthorizationPolicyTest.php` | Negativos de autorización sobre Policies |
| `NotificationSecurityTest.php` | Que un usuario no vea notificaciones ajenas |
| `SubjectProfanityFilterTest.php` | Filtro de materias con lenguaje inadecuado |
| `MentorshipRequestTest.php`, `MovaCriticalFlowTest.php` | Flujos críticos punta a punta |
| `Auth/*` (7 archivos) | Scaffolding de Breeze + Google OAuth |
| `ProfileTest.php` | Perfil, avatar (incluye Cloudinary fallback) |

**Nota metodológica importante:** al ejecutar la suite en este worktree recién clonado, **13 tests fallaron inicialmente** con `500` (Inertia/Vite manifest ausente) porque `public/build/` y `node_modules/` no existen hasta que se corre `npm install && npm run build`. Esto **no es un bug del código** — es que el worktree aislado nunca tuvo los assets compilados. Tras compilar, los 13 pasaron limpiamente. Se documenta esto explícitamente porque un CI mal configurado (sin paso de build antes de test) reproduciría exactamente estos 13 falsos negativos — ver oportunidad de automatización en §3.

**2 warnings persistentes** en `Auth/AuthenticationTest.php` (`login screen can be rendered`, `users can authenticate using the login screen`): un `file_get_contents(...)` falla como warning (no como error) cuando el archivo corre dentro de la suite completa, pero **no se reproduce ejecutando el archivo aislado** (`vendor/bin/phpunit tests/Feature/Auth/AuthenticationTest.php` → 100% verde, sin warnings). Esto apunta a una dependencia de orden de ejecución / posible caché estático de Vite manifest o de un recurso (favicon, fuente) entre tests — no bloquea la suite, pero es una señal de higiene de test a investigar (ver 4).

### 1.2 Cobertura real vs `route:list` (100 rutas de controladores de la app)

Se comparó cada nombre de ruta contra referencias en `tests/Feature/*.php`. **Sin ninguna cobertura de test** (ni positiva ni negativa):

- `marketplace` (página pública informativa)
- `teachers.show` (perfil público de profesor — página SEO-crítica según CLAUDE.md)
- `notifications.index`, `notifications.read`, `notifications.readAll`
- `admin.users` (index), `admin.users.suspend`, `admin.users.unsuspend`
- `admin.teachers.pending` (index), `admin.teachers.reject`
- `admin.requests`, `admin.lessons` (index), `admin.reviews` (index), `admin.reviews.hide`, `admin.reviews.show`
- `legal.privacy`, `legal.terms`, `about`
- `landing.student`, `landing.teacher` (páginas de invitación por código)
- `parent.settings.update` (control parental)
- `parent.reports`, `teacher.reports`
- `class-offers.index`, `class-offers.toggle`, `class-offers.edit/update/destroy` (parcialmente — solo negativos de autorización, no el camino feliz)
- `lesson-reports.create`, `reviews.create`
- `diagnostics.request` (aceptar una oferta desde resultados del diagnóstico)
- `verification.send` (reenvío de verificación de email — indirectamente vía un test, ver detalle)
- `admin.ai-usage`

Esto es un hallazgo nuevo y concreto, no solo una repetición de M3: **todo el panel de administración de moderación** (suspender usuarios, aprobar/rechazar profesores pendientes, ocultar reseñas) **no tiene un solo test**, a pesar de que son las únicas rutas donde un humano (admin) ejerce poder unilateral sobre otros usuarios — exactamente el tipo de superficie donde conviene un test negativo ("un admin suspendido no debería...", ver A3) y uno positivo.

### 1.3 Debilidades cualitativas (código real, no solo el nombre del test)

- **`MovaCriticalFlowTest.php` sigue teniendo solo 2 métodos de test** (`test_teacher_registration_dynamic_subject_and_price_limit`, `test_diagnostic_creates_generic_class_request`) pese a llamarse "critical flow" — el nombre promete más cobertura de la que entrega. Confirma M3 en este punto específico.
- **`DiagnosticRecommendationService`** (el algoritmo de scoring — corazón del producto según el propio código) y **`DiagnosticAiEnrichmentService`** (guardrails de IA: anonimización, `solve_homework` nunca dispara IA, rate limits diario/mensual, auto-disable tras errores) **no tienen ningún test dedicado** — cero resultados al grepear su nombre en `tests/`. Confirma M3 sin cambios.
- **`WhatsAppChannel`** y **`SafeMailChannel`** (degradación cuando fallan Twilio/SMTP) — cero tests. Confirma M3 sin cambios.
- Los tests de autorización (`AuthorizationPolicyTest.php`) sí cubren negativos reales ("Fulano no puede editar la oferta de Mengano") — este patrón está bien y debería extenderse a las rutas de admin listadas en 1.2.
- **`tests/Concerns/AssertsFinancialInvariants.php`** existe como trait compartido para verificar invariantes del ledger — buena práctica, usado consistentemente en los tests financieros.

### 1.4 Automatización E2E (Playwright)

`MOVA_MASTER_CONTEXT.md` afirma "Playwright instalado pero **no hay specs E2E**" — esto ya **no es cierto**: existe `qa/tests/flujo-completo.spec.js` (441 líneas, 3 tests con `test.step`):
1. Flujo de 8 pasos completo: solicitud → agendar → pago → reporte → calificación (usa `mova:testing-backdate-lesson` para simular el paso del tiempo).
2. UI de recargas de crédito (verifica que carguen los 3 paquetes, sin simular pago real).
3. Calendario semanal (pestañas, navegación, "volver a hoy").

Es una mejora real y bien construida (usa `test.describe.serial`, guarda contra ejecutarse accidentalmente contra producción con un chequeo de `BASE_URL`). Pero sigue siendo **una sola familia de specs**, cubre solo caminos felices, depende de un servidor local corriendo manualmente, y no está integrado a ningún pipeline de CI (no existe `.github/workflows/` en el repo). No cubre: flujos de admin, negativos de autorización end-to-end, ni el flujo de profesor sin verificar.

### 1.5 Modelos sin factory (B8 — sigue vigente)

Solo existe `database/factories/UserFactory.php`. `TeacherProfile`, `Lesson`, `Student`, `ClassRequest`, etc. declaran `HasFactory` pero no tienen factory definida — los 174 tests construyen todo el grafo de datos a mano en cada `setUp()`/test. Esto no bloquea nada hoy, pero es fricción creciente: cada test nuevo repite el mismo boilerplate de crear usuario+perfil+materia+oferta.

---

## 2. Observabilidad

### 2.1 Logging

`config/logging.php` es el default de Laravel sin personalizar: canal `stack` → `single` (archivo plano `storage/logs/laravel.log`, sin rotación — el driver `daily` con 14 días de retención existe configurado pero **no es el que se usa por defecto**). Con `QUEUE_CONNECTION=database` y workers de larga duración en Railway, un `single` sin rotación puede crecer indefinidamente si algo Log::error en bucle.

`grep -rn "Log::"` sobre `app/` da 41 usos en 8 archivos: `WhatsAppChannel`, `SafeMailChannel`, `GmailApiMailChannel`, `AdminController`, `AiUsageController`, `PhoneVerificationController`, `DiagnosticAiEnrichmentService`, `GmailApiMailService`. Son en su mayoría `Log::warning`/`Log::error` en rutas de degradación (WhatsApp sin Twilio, IA sin API key, etc.) — es decir, **fallos silenciosos por diseño** (fallback garantizado), pero **hoy nada lee esos logs activamente**. Si Twilio empieza a rechazar todos los mensajes o la IA cae en fallback permanente, el único rastro es una línea en `laravel.log` que nadie monitorea activamente salvo que alguien entre a Railway a leer logs manualmente.

### 2.2 Sentry

`sentry/sentry-laravel: ^4.26` está instalado y `config/sentry.php` está presente y bien configurado (breadcrumbs de queue/notifications/SQL, tracing de queue jobs, `send_default_pii: false` correcto para una plataforma con menores). `app/Exceptions/Handler.php` tiene un `reportable()` vacío — esto es **correcto y no un bug**: el paquete `sentry-laravel` se auto-registra vía su propio Service Provider y engancha automáticamente el reporte de excepciones (incluidas las de jobs fallidos en cola), sin necesitar código explícito en el Handler.

`.env.example` ya tiene `SENTRY_TRACES_SAMPLE_RATE=0.1` correctamente documentado (el ítem B4 de `MOVA_MASTER_CONTEXT.md`, que decía que estaba en `1.0` y debía bajarse a `0.1`, **ya está resuelto**).

**Brecha real:** no hay evidencia de que las alertas de Sentry (Slack/email por regla de umbral) estén configuradas — eso vive en el dashboard de Sentry, no en el repo, así que no se puede confirmar ni descartar desde el código. Recomendación operativa: verificar en el dashboard de Sentry que existan reglas de alerta para errores nuevos y para el `environment=production`.

### 2.3 Jobs fallidos

`failed_jobs` existe (migración estándar de Laravel, driver `database-uuids`). No hay ningún comando ni ruta que liste/resuma `failed_jobs`, ni un `schedule()` que alerte si la tabla crece. Como Sentry captura automáticamente las excepciones de jobs en cola (vía su integración con `queue_job_transactions`), los fallos individuales sí llegan a Sentry — pero **nadie ve la acumulación agregada** (p. ej. "40 notificaciones de recordatorio fallaron esta semana por Twilio caído") a menos que entre a consultar `failed_jobs` manualmente vía tinker/DB.

### 2.4 `mova:reconcile-ledger` — la pieza de observabilidad financiera que existe pero no está conectada

Este es el hallazgo más concreto de la sección. El propio docblock del comando dice textualmente:

> "Observabilidad financiera permanente de MOVA... Devuelve código de salida distinto de 0 cuando encuentra anomalías, **para poder integrarlo en CI, en un paso previo al despliegue o en monitorización**."

Es decir: el comando fue diseñado explícitamente para automatizarse, con exit code apto para pipelines. **Hoy no está en `Kernel.php`, no está en ningún workflow de CI (no existe `.github/workflows/`), y no está en `railway.queue.toml` ni `railway.scheduler.toml`.** Solo se ejecuta manualmente (confirmado en `docs/HANDOFF_FINAL.md`, que documenta corridas manuales de `mova:reconcile-ledger --json` durante sesiones de auditoría). Con un ledger financiero append-only donde una anomalía real (descuadre) sería crítica, que la única herramienta de detección dependa de que un humano recuerde ejecutarla es un vacío de observabilidad real, no cosmético.

### 2.5 Qué fallo importante hoy pasaría desapercibido en producción

1. **Descuadre del ledger** (`credit_transactions` vs `classes`/`teacher_profiles`) — detectable solo si alguien corre `mova:reconcile-ledger` manualmente.
2. **WhatsApp cayendo en fallback silencioso** por Twilio (sandbox expirado, credenciales rotadas) — solo un `Log::warning`/`Log::error`, capturado por Sentry como excepción individual pero sin alerta agregada de "WhatsApp lleva N horas sin enviar nada".
3. **IA de diagnóstico permanentemente en modo fallback** (por ejemplo, tras rotar `OPENAI_API_KEY` — ver M7) — mismo patrón, solo logs, cero alerta agregada.
4. **Acumulación de `failed_jobs`** sin tendencia visible salvo entrando a Sentry issue por issue.
5. **El propio scheduler caído** (C1 original) — es autorreferencial: si `mova-scheduler` no corre, tampoco corre nada que pudiera detectar que no está corriendo. Esto requiere un health check *externo* (ver §3).

---

## 3. Oportunidades de automatización

| # | PROBLEMA | AUTOMATIZACIÓN | BENEFICIO | RIESGO | PRIORIDAD |
|---|---|---|---|---|---|
| 1 | `mova:reconcile-ledger` — diseñado para CI/monitoreo según su propio docblock, pero solo se ejecuta manualmente | Agregar `$schedule->command('mova:reconcile-ledger --json')->daily()` en `Kernel.php`, con salida a un canal que alerte (Sentry captura excepción si exit code ≠ 0 se traduce en una, o un job dedicado que notifique a Slack/email si el reporte JSON trae anomalías > 0) | Detección temprana de descuadres financieros sin depender de que un humano recuerde correr el comando | Bajo — el comando ya es solo lectura, no muta nada | **Alta** |
| 2 | No existe pipeline de CI (`.github/workflows/` no existe) — ni `php artisan test`, ni `npm audit`/`composer audit`, ni build de assets se verifican automáticamente antes de mergear | GitHub Actions (o el hook de build de Railway) que corra `composer install && npm ci && npm run build && php artisan test` en cada push/PR a `master` | Evita que una regresión llegue a producción sin que la suite de 174 tests la haya visto correr; también evita el "funciona en mi máquina" que hoy depende de que cada dev tenga assets compilados localmente | Bajo — es aditivo, no cambia comportamiento de la app | **Alta** |
| 3 | El estado del scheduler de Railway (`mova-scheduler`) es un punto ciego autorreferencial: si se cae, nada lo detecta desde dentro de la app | Un healthcheck externo simple (cron de un tercero tipo Healthchecks.io/cronitor, o un endpoint `/healthz` que verifique `cache()->get('scheduler_last_run')` actualizado por el propio `schedule:run`, con alerta si pasa >5 min sin actualizarse) | Detecta en minutos, no en días, si el scheduler completo (recordatorios + liquidación) dejó de correr — este fue exactamente el incidente C1 original | Bajo — es puramente aditivo y de solo lectura | **Alta** |
| 4 | `failed_jobs` no tiene ninguna alerta ni resumen periódico | Comando propio (`mova:report-failed-jobs`) agendado semanalmente que cuente fallos por tipo de job y notifique si supera un umbral; o activar `queue:failed` en un canal de Slack/email vía notificación simple | Visibilidad agregada de degradación de canales (WhatsApp, email) en vez de depender de leer Sentry issue por issue | Bajo | Media |
| 5 | `mova:purge-jitsi-urls` es un comando de limpieza histórica de un solo uso (post-fix de C3) — no hay evidencia en el repo de que se haya ejecutado alguna vez contra producción | Verificar manualmente (una sola vez) si ya corrió en producción; si no, ejecutar `--dry-run` primero y luego en real. No requiere automatizarse (es intencionalmente un comando, no una migración, según su propio docblock) | Cierra definitivamente la exposición histórica de `jitsi_url` en `notifications.data` para menores | Bajo si se corre con `--dry-run` primero, como está diseñado | Media (una sola vez, no recurrente) |
| 6 | Cobertura de tests para el panel de administración de moderación (suspender, aprobar profesor, ocultar reseña) es cero — no es "automatización" per se pero es la precondición para automatizar con confianza cualquier cambio futuro ahí | Escribir `AdminModerationTest.php` cubriendo happy path + negativos (usuario no-admin no puede, admin suspendido si/no puede según se resuelva A3) | Reduce el riesgo de que un refactor futuro del panel admin rompa autorización sin que nadie lo note | Ninguno (solo tests) | Media |
| 7 | Sin factories para `TeacherProfile`, `Lesson`, `Student`, etc. (B8) | Definir factories básicas — no reemplaza los tests existentes, pero reduce el boilerplate de los próximos | Tests nuevos más rápidos de escribir, menos duplicación | Ninguno | Baja |

---

## 4. Deuda técnica — re-clasificación de `MOVA_MASTER_CONTEXT.md` + hallazgos nuevos

`MOVA_MASTER_CONTEXT.md` está fechado 2026-07-18. El código en `HEAD` (commit `692b365`, más reciente) muestra que **una parte sustancial de la deuda documentada ya fue pagada**. Esta es la re-clasificación punto por punto, verificada contra el código real:

### 🔴 Crítico

- **C1 (scheduler caído)** — **NO VERIFICABLE desde el código.** `railway.scheduler.toml` existe en el repo y su contenido (`buildCommand`/`startCommand` con bucle `while true; ... schedule:run ... sleep 60`) es coherente y comparable a `railway.queue.toml`. Si el archivo de config apunta correctamente, el problema descrito (config file path mal apuntado en el dashboard de Railway) sería resuelto — pero esa configuración vive en el dashboard de Railway, fuera del repo, y no se puede confirmar ni descartar desde aquí. **Requiere verificación operativa directa en Railway**, tal como recomendaba el documento original.
- **C2 (secretos en `.env.claude.local`)** — **NO VERIFICABLE desde este worktree.** `.gitignore` sigue cubriendo `.env*` correctamente. Si el archivo físico sigue existiendo en disco dentro de la carpeta sincronizada con OneDrive (fuera del árbol de git que audita este agente) no se puede confirmar ni descartar aquí. Requiere verificación directa en el directorio del proyecto principal.
- **C3 (Jitsi sin control de acceso)** — **RESUELTO.** Se migró a JaaS (`app/Services/JaasService.php` + `config/jaas.php`): tokens JWT firmados RS256 con `kid`, expiración de 24h, flag de moderador, y features `livestreaming`/`recording`/`transcription` explícitamente en `false`. El propio código documenta la razón del cambio ("meet.jit.si... corta el embed a los 5 minutos en producción"). **Recomendación:** dado que este es exactamente el tipo de cambio que CLAUDE.md exige pasar por revisión de seguridad explícita (Jitsi + menores), confirmar que esa revisión ya ocurrió; si no, es la pieza más importante para priorizar en una pasada de seguridad dedicada (no cubierta por este documento, que es de dominio testing/observabilidad/deuda).
- **C4 (sin backups automatizados verificados)** — sigue sin evidencia en el código de un job de backup automatizado verificado. Sin cambios respecto al documento original (fuera del dominio de esta auditoría confirmar si existe a nivel de infraestructura de Railway/MySQL gestionado).

### 🟠 Alto

- **A1 (sin capa de Policies)** — **RESUELTO.** `app/Policies/` existe con 7 policies: `ClassOfferPolicy`, `ClassRequestPolicy`, `LessonPolicy`, `RechargeRequestPolicy`, `StudentDiagnosticPolicy`, `StudentPolicy`, `TeacherReviewPolicy`. Coincide con el patrón que CLAUDE.md exige explícitamente.
- **A2 (rutas financieras sin throttle)** — **RESUELTO.** Confirmado en `routes/web.php`: `lessons.confirm-payment` (`throttle:10,1`), `teacher.credits.recharge` (`throttle:10,1`), `admin.recharges.approve` (`throttle:10,1`), `reviews.store` (`throttle:10,1`) — de hecho, prácticamente todas las rutas mutables del archivo llevan throttle explícito.
- **A3 (grupo `admin` sin `not.suspended`)** — **SIGUE VIGENTE.** `routes/web.php` línea 127: `Route::middleware('role:admin')->prefix('admin')` — sin `not.suspended`, a diferencia de los grupos `parent`/`teacher` que sí lo llevan. Confirmado sin cambios.
- **A4 (rol admin sin permisos finos)** — **SIGUE VIGENTE.** `spatie/laravel-permission` sigue instalado pero un grep de `hasPermissionTo|givePermissionTo|Gate::define|->can(` en `app/` no arroja resultados — solo se usan roles, no permisos granulares.
- **A5 (`env()` en runtime con `config:cache`)** — **RESUELTO en ambos puntos.** `database/seeders/ProductionSeeder.php` ahora usa `config('app.admin_email')`/`config('app.admin_password')`/`config('app.admin_name')`. `DiagnosticAiEnrichmentService::callOpenAi()`/`callGemini()` ahora leen `config('diagnostic.openai_api_key')`/`config('diagnostic.gemini_api_key')` (definidos en `config/diagnostic.php` a partir de `env()`, como corresponde). El bug latente que describía el documento ya no existe.

### 🟡 Medio

- **M1 (vestigios de Zoom)** — **RESUELTO por completo.** `ZoomService.php` ya no existe en `app/`. No hay ninguna referencia a `zoom_link`/`zoom_password` en `resources/js/` ni en `app/`. Existe además una migración nueva, `2026_07_26_153152_drop_zoom_meeting_id_column.php`, que terminó de eliminar `classes.zoom_meeting_id` del esquema — el último vestigio que el documento original señalaba explícitamente.
- **M2 (dos layouts coexistiendo)** — **RESUELTO por completo.** `AuthenticatedLayout.vue` ya no existe en `resources/js/` (0 resultados de búsqueda del nombre en todo `resources/js/`). Las 32 páginas usan únicamente `AppLayout.vue`.
- **M3 (cobertura desbalanceada)** — **PARCIALMENTE VIGENTE.** La suite creció de 57 a 174 tests y ahora incluye buena cobertura de autorización negativa y de escenarios de liquidación. Pero los puntos específicos señalados siguen intactos: `DiagnosticRecommendationService` y `DiagnosticAiEnrichmentService` sin tests, `WhatsAppChannel`/`SafeMailChannel` sin tests, `MovaCriticalFlowTest` con solo 2 métodos. Playwright ya tiene una spec real (`flujo-completo.spec.js`), lo que corrige parcialmente la afirmación "sin specs E2E" del documento original — ver §1.4.
- **M4 (`LocalTestDataSeeder` no idempotente)** — **RESUELTO.** Un grep de `::create(` en el archivo da cero resultados; todo usa `firstOrCreate`. El propio código lleva comentarios explicando el fix ("nunca en un update... reejecutar el seeder no duplique").
- **M5** — ya estaba marcado resuelto en el documento original; confirmado sin cambios (`Lesson::creditCostForMinutes()` sigue en uso).
- **M6 (monto duplicado en frontend)** — **RESUELTO.** `LessonController` ahora calcula y persiste `price_frozen_pen = round($teacherProfile->hourly_rate * $creditsNeeded, 2)` al agendar. `ParentIndex.vue` ya no recalcula el monto en JavaScript — un comentario en el propio archivo referencia `price_frozen_pen` explícitamente como la fuente de verdad.
- **M7 (`OPENAI_API_KEY` sin cuota)** — no verificable desde el código (depende de la cuenta real de OpenAI); se asume sin cambios salvo que alguien haya rotado la clave o cambiado de plan.

### 🟢 Bajo

- **B1** (rama sin mergear) — fuera del alcance verificable desde un worktree que ya está en `master`.
- **B2** (`bootstrap/cache/.gitignore` borrado) — **RESUELTO**, el archivo existe.
- **B3** (carpeta espuria `.railway-config-pull-22248/`) — **RESUELTO**, no existe en el árbol.
- **B4** (`SENTRY_TRACES_SAMPLE_RATE=1.0`) — **RESUELTO**, `.env.example` ya trae `0.1`.
- **B5** (comentario desactualizado sobre Pusher) — **RESUELTO**, el comentario en `.env.example` ahora dice correctamente "WebSockets / notificaciones en tiempo real vía Laravel Echo".
- **B6** (`npm audit`: 3 vulnerabilidades) — **CAMBIÓ, no resuelto.** Hoy son **4** (1 moderada, 3 altas): `esbuild`/`vite` (moderada, servidor de desarrollo), `nanoid` (alta), `postcss` (alta, path traversal en source maps). Todas son dependencias de build-time (`devDependencies`), no viajan al bundle de producción — `npm audit --omit=dev` da 0 vulnerabilidades. Riesgo real bajo, pero el número cambió respecto al documento y conviene actualizarlo con `npm audit fix` cuando haya ventana de mantenimiento (implica subir Vite a v8, breaking change, según el propio audit).
- **B7** (bundle de 318 kB sin code-splitting) — **MEJORÓ SUSTANCIALMENTE.** El build actual (`npm run build`) genera decenas de chunks pequeños por página (2–20 kB cada uno) más `echo-*.js` (73.6 kB / 21.3 kB gzip) como chunk separado del bundle principal. El chunk `app-*.js` quedó en 249 kB (90 kB gzip) y la página pública `Welcome` (SEO-crítica) en 169 kB (56 kB gzip) como chunk propio — hay code-splitting real por ruta, a diferencia de lo que describía el documento original. Queda como posible mejora futura (no urgente) reducir el peso de `Welcome.vue`, dado que es la página pública de mayor tráfico esperado.
- **B8** (modelos sin factory) — **SIGUE VIGENTE**, solo `UserFactory.php` existe.

### Hallazgo nuevo — vulnerabilidad de Composer no documentada

`composer audit` reporta **6 avisos sobre `league/commonmark`** (versión `^2.2.1`, resuelta a `2.x < 2.9.0`): varios DoS (encabezados colisionantes, footnotes duplicados, bloques de atributos adyacentes, parsing cuadrático) y un bypass de filtro de enlaces inseguros (`CVE-2026-71478`). Es una dependencia **transitiva de `laravel/framework`** (usada por el helper `Str::markdown()` y por las plantillas de correo Markdown de Laravel) — un grep de `Str::markdown|MarkdownMail|::markdown(` en `app/` y `resources/views/` no arroja ningún uso directo en MOVA. Riesgo real bajo (no se le da input de usuario a un parser Markdown en este código), pero es una vulnerabilidad de cadena de suministro real y no estaba en el mapa de deuda original. Se resuelve solo actualizando `laravel/framework`/su dependencia transitiva cuando el ecosistema libere una versión parcheada compatible.

---

## 5. Discrepancias documentación ≠ código

- **`SETUP.md` — prerrequisito de Zoom obsoleto.** La sección de "Requisitos previos" sigue pidiendo "Cuenta de Zoom con app Server-to-Server OAuth activa (`meeting:write:admin`)" — pero `ZoomService.php` ya no existe en el código (M1, resuelto) y la plataforma usa JaaS/8x8 (`JAAS_APP_ID`, `JAAS_PRIVATE_KEY`, `JAAS_KEY_ID`), no Zoom. Este requisito debería eliminarse y reemplazarse por las variables de JaaS.
- **`SETUP.md` — Gmail descrito como "App Password" cuando el código implementa OAuth.** El requisito dice "Cuenta de Gmail con App Password de 16 caracteres generada", pero el flujo real (`app/Console/Commands/GmailAuthUrl.php`, `GmailExchangeCode.php`, y `.env.example` con `GMAIL_CLIENT_ID`/`GMAIL_CLIENT_SECRET`/`GMAIL_REFRESH_TOKEN`) es un flujo OAuth2 completo con intercambio de código de autorización, no una App Password simple de SMTP. La sección de credenciales de prueba y comandos (`mova:gmail-auth-url`, `mova:gmail-exchange-code`) en `SETUP.md`/`MOVA_MASTER_CONTEXT.md` §6.3 sí es correcta — pero el prerrequisito inicial en `SETUP.md` describe un mecanismo distinto al implementado.
- **`SETUP.md` §"Credenciales de prueba"** — se verificó que **sí es correcta**: `admin@mova.test`/`password`/admin viene de `RoleSeeder` (llamado desde `DatabaseSeeder`), y los profesores/padres de ejemplo coinciden exactamente con `DatabaseSeeder.php`. Sin discrepancia aquí.
- **`MOVA_MASTER_CONTEXT.md` §5 (mapa de deuda)** — desactualizado en 9 de sus ~20 ítems según la re-clasificación de la sección 4 de este documento (A1, A2, A5, M1, M2, M4, M6, B2, B3, B4, B5 resueltos o cambiados; C3 resuelto). Es el documento con la brecha más grande entre lo escrito y el código real, simplemente porque es el más antiguo de los disponibles en este árbol (2026-07-18) frente a un código que evidentemente siguió evolviéndose después.
- **`docs/HANDOFF_FINAL.md`** menciona `mova:reconcile-ledger` como parte del ritual de verificación manual de sesiones de auditoría ("GREEN, 0 anomalías") — consistente con el código, pero refuerza el hallazgo de la sección 2.4: es una herramienta que hoy vive exclusivamente en la disciplina manual de quien audita, no en la infraestructura automatizada del proyecto.
- **`phpunit.xml`** documenta explícitamente, en un comentario, por qué no existe `tests/Unit/` — esto es una buena práctica de auto-documentación que vale la pena señalar positivamente (evita que alguien intente "arreglar" la ausencia del directorio sin entender la razón deliberada).

---

## Resumen de verificaciones ejecutadas

- `php artisan test` (dos veces: con y sin assets compilados, para aislar falsos negativos de entorno) → 174 passed, 2 warnings, 736 assertions.
- `php artisan route:list --json` → 100 rutas de controladores de app, cruzadas contra referencias en `tests/Feature/`.
- `npm audit`, `npm audit --omit=dev`, `composer audit`.
- `npm run build` → inspección real de los chunks generados y sus tamaños.
- Greps dirigidos sobre `app/`, `routes/`, `database/seeders/`, `resources/js/`, `tests/` para cada ítem del mapa de deuda de `MOVA_MASTER_CONTEXT.md` (no se asumió el estado de ningún ítem sin volver a leer el código).
