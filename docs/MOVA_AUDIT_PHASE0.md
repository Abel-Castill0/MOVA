# MOVA — Auditoría Integral, Fase 0: Reconstrucción y Diagnóstico del Sistema

**Fecha:** 2026-08-26. **Alcance:** solo investigación y documentación — ningún archivo de `app/`, `resources/`, `database/`, `tests/` o configuración fue modificado durante esta fase. Ninguna credencial real se tocó, ninguna conexión a producción se intentó, ningún commit se hizo.

**Método:** 6 subagentes especializados corrieron en paralelo, cada uno en su propio git worktree aislado, cubriendo: (1) rutas/permisos/BD, (2) seguridad integral, (3) frontend/UX/UI/performance, (4) testing/observabilidad/deuda técnica, (5) integraciones externas, (6) modelo de negocio/flujos/bugs funcionales. Sus reportes completos, sin editar, están en [`docs/phase0-appendices/`](phase0-appendices/) (A1-A6) — este documento es la síntesis y reconciliación de los seis, no un reemplazo. Donde este documento resume, el apéndice correspondiente tiene la evidencia línea por línea.

---

## 🔒 Regla de proceso — AUDIT SNAPSHOT DRIFT (obligatoria a partir de ahora, para cualquier auditoría futura de MOVA)

BUG-1 no fue solo "un bug que resultó estar corregido" — fue la manifestación concreta de un problema de proceso: **un agente auditó un snapshot del código (`HEAD` vía `git worktree`) que no era el mismo snapshot que un desarrollador ve y edita hoy (el working tree principal, con ~150 rutas sin commitear).** El diagnóstico no estaba mal razonado; estaba razonado sobre una fotografía vieja.

**Regla, efectiva desde ahora**: ningún informe de auditoría de MOVA es válido sin declarar, al inicio, el snapshot exacto que inspeccionó:

```
AUDIT SNAPSHOT
Commit HEAD: <hash>
Branch: <nombre>
origin/master: <hash, y si diverge de HEAD>
Working tree: <"limpio" | "N archivos modificados/untracked, incluidos">
¿Checkout aislado o directorio de trabajo real?: <cuál>
Timestamp: <UTC>
Entorno: PHP/Node/Composer/npm exactos
```

Si dos auditorías usan snapshots distintos, sus resultados **no son directamente comparables** sin decir explícitamente por qué difieren — exactamente lo que le pasó a BUG-1. Esta sección de `MOVA_AUDIT_PHASE0.md` ya se corrigió seis veces por esta misma razón (F-26 en la ronda de documentación, BUG-1 en esta); la regla existe para que la séptima vez no haga falta.

**Snapshot de esta corrección (capturado ahora, no asumido):**

```
Commit HEAD:      692b3651d09cb2731865efcb0d83dc83d2a36102
Branch:           master
origin/master:    692b3651d09cb2731865efcb0d83dc83d2a36102 (0 commits de divergencia en cualquier dirección)
Working tree:     2 archivos staged, 69 modificados sin stage, 96 untracked (directorios expandidos) — TODOS incluidos en esta re-verificación
Checkout:         directorio de trabajo real (C:\Users\ABEL\OneDrive\Desktop\ProyectoMOVA) — NO un worktree aislado
Timestamp:        2026-08-27T03:29:01Z
PHP:              8.1.25 (cli, ZTS)
Node:             v22.23.2
npm:              10.9.8
Composer:         2.8.10
```

---

**Advertencia metodológica que hay que leer antes que el resto:** los 6 agentes corrieron en checkouts aislados desde `HEAD` (`692b365`) vía `git worktree`. Esto tuvo dos consecuencias que cambian cómo hay que leer todo lo demás:

1. **Los documentos de esta sesión (`MOVA_SYSTEM_KNOWLEDGE.md`, `MOVA_PRODUCTION_READINESS.md`, `MOVA_QA_BASELINE.md`, `MOVA_CREDENTIAL_EXPOSURE.md`, `MOVA_QA_SECURITY_POLICY.md`) no existen en `HEAD`** — son archivos nuevos sin commitear. Los 6 agentes trabajaron sin poder leerlos, y en su lugar usaron `MOVA_MASTER_CONTEXT.md` (sí commiteado, fechado 2026-07-18) más el código real. Resultado: **`MOVA_MASTER_CONTEXT.md` resultó estar desactualizado en al menos 11 puntos** (detallado en la sección N) — el código real avanzó un mes por delante del documento que se suponía era la referencia.
2. **El agente de Seguridad hizo el hallazgo más importante de toda la Fase 0**, precisamente gracias a este aislamiento: al comparar su worktree (checkout limpio de `HEAD`/`origin/master`, sin divergencia) contra el checkout principal (donde vive todo el trabajo de esta serie de auditorías), confirmó que **prácticamente todo el trabajo de remediación de seguridad de las últimas rondas — incluida la eliminación de la contraseña de producción de F-26 — nunca se commiteó.** Esto se desarrolla en la sección A y G.
3. **⚠️ Corrección post-Fase 0, encontrada al reaccionar a la propia síntesis**: como los 6 agentes auditaron un checkout de `HEAD` — no el checkout principal con ~40 archivos modificados sin commitear —, **cualquier archivo de esa lista pudo haber cambiado su comportamiento entre lo que el agente vio y lo que existe hoy en el árbol de trabajo real.** Esto se verificó explícitamente para los 5 "bugs confirmados" de la sección Q, ejecutando la suite de tests real contra el checkout principal (no contra un worktree aislado): **BUG-1 (borrar un alumno con historial → 500 crudo) resultó estar YA CORREGIDO** en el checkout principal — existe `tests/Feature/StudentDeletionIntegrityTest.php` (12 tests, todos en verde, ejecutados ahora mismo) que confirma un soft-delete + anonimización + bloqueo explícito de `forceDelete()` sobre historial protegido. Los otros 4 bugs (BUG-2 a BUG-5) y N1 se re-verificaron línea por línea contra el código actual y **siguen siendo reales**. Detalle completo en la sección Q, ya corregida.

---

## A. Executive Summary

**MOVA tiene un núcleo financiero y de autorización excepcionalmente sólido — pero casi todo lo que hace que eso sea cierto hoy vive únicamente en un directorio de trabajo sin commitear, y el propio proceso de esta auditoría no había verificado eso hasta ahora.**

Los tres hechos que definen el estado real del sistema en este momento, en orden de urgencia:

1. **🔴 P0 — El HEAD real de git (`692b365`, idéntico a `origin/master`) sigue exponiendo la contraseña RAW de la base de datos MySQL de producción**, en texto plano, en 2-3 archivos tracked (`qa/test-wizard-flow.mjs`, `qa/end-to-end-welcome-email.mjs`, y `qa/auth/admin.json` con cookies de admin ya expiradas). Esto no es "recuperable del historial" — es lo que **cualquier `git clone` de hoy recibe**. La documentación de rondas anteriores (`MOVA_CREDENTIAL_EXPOSURE.md`) afirmaba erróneamente que esto ya estaba contenido; quedó corregido en esta misma sesión, antes de que terminara la Fase 0, gracias al hallazgo independiente del agente de Seguridad. Ver sección G.
2. **🟠 P1 — Todo el trabajo de esta serie de auditorías (migración WhatsApp Twilio→Meta con firma HMAC, el gate de suspensión F-22, el firewall de mutaciones de QA F-25, el targeting seguro F-24A, la arquitectura completa de pagos Culqi/`payment_webhooks`) existe solo como cambios sin commitear.** Un checkout limpio de `origin/master` hoy todavía tiene WhatsApp sobre Twilio sin gate de suspensión, sin ninguna herramienta de seguridad de QA. Si el directorio de trabajo de esta máquina se pierde antes de commitear, **todo ese trabajo desaparece** y el próximo despliegue desde git parte de la versión pre-fix. Ninguna afirmación de "esto ya está arreglado" en cualquier documento de esta auditoría es válida para lo que Railway desplegaría hoy.
3. **El código real, una vez que se mira el checkout principal, está considerablemente mejor construido de lo que la documentación más antigua (`MOVA_MASTER_CONTEXT.md`, 2026-07-18) sugiere** — 7 Policies bien cableadas, locks + idempotencia consistentes en toda mutación financiera, throttling en prácticamente cada ruta de escritura, un webhook de WhatsApp con verificación HMAC fail-closed, JaaS con JWT firmado reemplazando el Jitsi público que el documento maestro todavía describe como riesgo crítico sin resolver. Se encontraron **4 bugs funcionales reales que siguen abiertos hoy** (re-verificados contra el checkout principal, no solo contra el worktree aislado) y **1 bug adicional que la Fase 0 reportó como abierto pero que ya está corregido** en el checkout principal, con 12 tests de regresión dedicados. La suite de tests real del checkout principal son **454 tests / 1328 assertions, todos en verde** (ejecutado ahora mismo) — no los 174 que vio el agente en su worktree aislado desde `HEAD`, que carecía de ~40 archivos con cambios sin commitear.

**Ningún hallazgo de esta fase permite decir "MOVA es seguro" o "MOVA está listo para producción"** — ni en sentido positivo ni negativo de forma absoluta. Lo que sigue es un inventario honesto, con evidencia citada, de lo que se verificó, lo que se encontró roto, y lo que queda como `UNKNOWN / REQUIERE VERIFICACIÓN`.

---

## B. System Context (arquitectura)

**Stack verificado (no asumido) — ver también `composer.json`/`package.json`:**

| Tecnología | Versión | Uso | Estado |
|---|---|---|---|
| PHP | ^8.1 | Runtime backend | Activo |
| Laravel | ^10.10 (10.50.2 en Audit Baseline previo) | Framework | Activo |
| Laravel Sanctum | ^3.2 | Instalado | Sin uso confirmado de tokens API (Inertia usa sesión) — `UNKNOWN`, no verificado a fondo en Fase 0 |
| Spatie Laravel-Permission | ^6.25 | Roles (parent/teacher/admin) | Activo — **solo roles, sin permisos finos** (ver N-A4) |
| Laravel Socialite | ^5.29 | Login Google | **Activo**, gateado por `GOOGLE_LOGIN_ENABLED` (default false) — contradice la creencia previa de que no estaba en uso (ver P, D5) |
| Pusher (server+cliente) + Laravel Echo | ^7.2 / ^2.4 / ^8.5 | Tiempo real | Activo, degrada con gracia si no configurado |
| Cloudinary | ^2.3 | Avatares | **Activo**, con fallback a disco local — contradice `MOVA_MASTER_CONTEXT.md` que lo daba por no integrado |
| Sentry | ^4.26 | Observabilidad de errores | Instalado y bien configurado, pero `SENTRY_LARAVEL_DSN` vacío por defecto — inactivo hasta que se configure |
| Resend | ^1.4 | Correo alternativo | Instalado, no es el mailer activo por defecto |
| firebase/php-jwt | ^7.1 | JWT para JaaS/Jitsi | Activo, RS256, bien construido (ver G) |
| Vue | ^3.4 | Frontend | Activo |
| Inertia | ^1.0 | Puente Laravel↔Vue | Activo |
| Tailwind | ^3.2 | Estilos | Activo |
| Vite | ^5.0 | Build | Activo, code-splitting real por página confirmado con `npm run build` (ver K) |
| Redis | — | **No integrado** — ningún cliente (`predis`/`phpredis`) instalado pese a que `config/*.php` menciona la opción | Boilerplate inerte, no un problema |
| MySQL (prod) / SQLite (tests) | — | BD | **Divergencia real**: FKs `RESTRICT` de MySQL no se aplican en SQLite — causa directa de BUG-1 (ver Q) |

**Arquitectura de capas (Browser → Frontend → Inertia → Laravel → Middleware → Controllers → Services → Models → DB → Proveedores externos):** confirmada como un patrón consistente y repetido en todo el código, no solo en la superficie. El hallazgo más notable es un **patrón de doble capa de defensa** aplicado sistemáticamente en toda mutación financiera: `$this->authorize()` antes de la transacción (chequeo rápido) + re-lectura con `lockForUpdate()` y re-validación de estado **dentro** de la transacción (cierra la ventana TOCTOU). Ver detalle completo en [A1](phase0-appendices/A1_rutas_db_permisos.md) §2.2.

**`LessonSettlementService`** (no documentado en `MOVA_MASTER_CONTEXT.md`) es la capa nueva más importante: consolidó lo que antes eran 3-4 implementaciones divergentes de liquidación de créditos en un único punto, con `CONSUMABLE_STATES`/`REFUNDABLE_STATES` como listas cerradas.

**No se encontró** ninguna dependencia circular, ni un "God controller"/"God model" real (ningún archivo de `resources/js/Pages` supera 461 líneas; en backend, `LessonController` es el más grande pero mantiene una sola responsabilidad de dominio). Código muerto real sí se encontró — ver N.

---

## C. Business Model (re-verificado contra código real)

Actores: `parent`, `teacher`, `admin`. Entidades núcleo: `User`, `Student` (no es una cuenta — pertenece a su padre), `TeacherProfile`, `ClassRequest`, `Lesson` (tabla `classes`), `CreditTransaction` (ledger append-only), `RechargeRequest`, `ClassEvent` (bitácora inmutable).

**Cambios reales frente a la documentación existente (verificados en código, no supuestos):**
- **3 niveles de tarifa**, no 2: S/20 base → S/25 (≥5 clases completadas Y rating≥4.0) → S/30 "Élite" (≥20 clases Y rating≥4.5). `TeacherProfile::maxAllowedRate()`.
- **`price_frozen_pen` sí se congela al agendar** — el hallazgo antiguo "el monto se recalcula en el frontend" está resuelto.
- **Liquidación automática (C-1) existe pero sigue en `--dry-run`** en `Kernel.php` — no escribe nada en producción todavía, es una decisión de negocio pendiente (F-02), no un bug.
- **Pivote de producto no reflejado en la documentación existente**: el marketplace es ahora puramente informativo — el padre ya no elige un profesor directamente, solo vía código de referido de 6 caracteres o matching automático por materia. El diagnóstico ya no muestra un top-5 de recomendaciones (el algoritmo sigue corriendo, pero su salida se descarta — ver N, código muerto funcional).

Detalle completo, con matriz ACTOR→ACCIÓN→CONDICIONES→RESULTADO→EFECTOS SECUNDARIOS, en [A6](phase0-appendices/A6_modelo_negocio_flujos_bugs.md) §1-2.

---

## D. Functional Flows

Los 4 flujos principales (Autenticación, Parent, Teacher, Admin) y su comportamiento en cada edge case pedido (doble-click, refresh, sesión expirada, request concurrente, cambio de estado entre lectura/escritura, error de proveedor externo, error de BD, timeout/abandono) están documentados exhaustivamente en [A6](phase0-appendices/A6_modelo_negocio_flujos_bugs.md) §2-3. Resumen de lo más importante:

- **El flujo de créditos/clases está protegido en cada edge case pedido**: doble-click, refresh, request concurrente y cambio de estado entre lectura/escritura están todos cubiertos por `lockForUpdate()` + re-validación + `idempotency_key` único. Verificado por lectura de código en `LessonController`, `LessonSettlementService`, `Admin\RechargeController`.
- **El flujo de diagnóstico NO tiene la misma protección** — es el único flujo de creación de datos reales (no financieros) sin transacción ni idempotencia. Ver BUG-2 en Q.
- **Timeout/abandono**: una clase `scheduled` sin confirmar 7 días escala a `needs_admin_review` (requiere admin, sin efecto financiero). Una clase `paid` sin reporte del profesor 7 días se auto-liquida **sin exigir el reporte pedagógico** — ver BUG-3 en Q, el hallazgo de lógica de negocio más importante de esta fase.

---

## E. Backend Audit

Ver [A1](phase0-appendices/A1_rutas_db_permisos.md) completo. Puntos clave:
- 7 Policies (`ClassOffer`, `ClassRequest`, `Lesson`, `RechargeRequest`, `StudentDiagnostic`, `Student`, `TeacherReview`) todas con `before()` de bypass admin, todas invocadas consistentemente desde sus controladores.
- **Excepción real**: `AdminController` (8 acciones mutantes: verificar/rechazar profesor, cancelar/forzar-completar/forzar-reembolsar clase, suspender/reactivar usuario) no usa Policies — solo el middleware `role:admin`. Funcionalmente correcto hoy, pero sin separación de funciones ("admin financiero" vs "admin de moderación"), y **sin `Log::info` en `suspendUser`/`unsuspendUser`** a diferencia del resto de acciones admin.
- `Subject::firstOrCreateByName()` no captura `UniqueConstraintViolationException` bajo carrera concurrente — único punto de unicidad del sistema que no sigue el patrón `try/catch` + refetch que el resto del código usa religiosamente (N1, P3).

## F. Frontend Audit

Ver [A3](phase0-appendices/A3_frontend_ux_performance.md) completo. 77 archivos en `resources/js/`, ninguno cruza el umbral de "componente gigante" (461 líneas máximo). Buena señal arquitectónica: `utils/statusColors.js` es fuente única para colores/etiquetas de estado de `Lesson`/`ClassRequest`, consumida por 10 archivos — pero **esa disciplina no se extendió al dominio de créditos/recargas**, duplicado en 4 archivos (F1). Los wizards de Registro (6 pasos) y Diagnóstico (5 pasos) pierden todo su progreso en un refresh accidental — el `step` vive solo en estado local, nunca en URL/`sessionStorage` (F2).

## G. Security Audit

Ver [A2](phase0-appendices/A2_seguridad.md) completo — es el apéndice más importante de los 6.

**Re-clasificación de F-01 a F-27** (tabla completa en el apéndice): la mayoría de los hallazgos históricos de seguridad de esta serie de auditorías están **CONFIRMADO FIXED**, pero **solo en el checkout principal sin commitear** — F-22 (suspensión bloquea WhatsApp), F-24A/F-25 (firewall QA), la arquitectura Meta de WhatsApp completa, no existen en el `HEAD` real de git. F-21 (webhook Culqi) confirmado `STILL OPEN` — es un stub deliberado, sin ruta HTTP, cero superficie de ataque activa porque no hay endpoint que atacar.

**Hallazgos nuevos por clasificación THEORETICAL/PROVEN/LIKELY/NOT APPLICABLE** (23 ítems, N-01 a N-23 en el apéndice). Los que importan:
- **N-01 (🔴 P0, PROVEN)**: la contraseña de MySQL de producción sigue en el `HEAD` real de git — ya corregido en la documentación de F-26 durante esta misma sesión (ver más abajo).
- **N-02 (🟠 P1, PROVEN)**: todo el trabajo de seguridad de esta serie de rondas sin commitear — riesgo de pérdida total si el directorio de trabajo se pierde.
- **Mass assignment, IDOR, SQLi, Command Injection, CSRF, Login/OTP/rate-limiting, carga de archivos, notificaciones**: todos **NOT APPLICABLE (verificado)** en la muestra de controladores de mayor riesgo revisada (créditos, lecciones, alumnos, admin, recargas). El webhook de WhatsApp usa `hash_equals()` (constant-time) tanto para la firma HMAC como para el verify token — correcto y poco común de ver bien hecho.
- **N-09 (LIKELY NOT EXPLOTABLE)**: 3 usos de `v-html` en componentes de paginación — el dato viene del paginador de Laravel (strings fijos), no de input de usuario, pero no se leyó el componente compartido para confirmarlo al 100%.
- **N-11 (🟡 P2, LIKELY)**: `SESSION_SECURE_COOKIE` no está en `.env.example` — si el `.env` real de producción tampoco la define, la cookie de sesión no llevaría el flag `Secure`.
- **N-22 (UNKNOWN)**: no se ejecutó `composer audit`/`npm audit` en esta sesión de seguridad (sí se ejecutó por separado en la sesión de testing/deuda — ver N, hallazgo de `league/commonmark`).

**Corrección aplicada durante esta misma Fase 0** (no un hallazgo pendiente — ya resuelto en la documentación): al recibir el hallazgo N-01, se verificó con `git cat-file -e HEAD:<ruta>` que, efectivamemente, los 3 archivos de F-26/F-27 seguían presentes en HEAD y en `origin/master` (idénticos, sin divergencia). `docs/MOVA_CREDENTIAL_EXPOSURE.md`, `docs/MOVA_PRODUCTION_READINESS.md`, `docs/MOVA_QA_BASELINE.md` y `docs/MOVA_QA_SECURITY_POLICY.md` ya fueron corregidos para reflejar esto con precisión (antes decían erróneamente "HEAD actual: ✅ Limpio"). **La rotación de la contraseña y el commit de la eliminación de los 3 archivos siguen sin ejecutarse** — eso permanece como acción pendiente del usuario, no de esta sesión.

## H. Database Audit

Ver [A1](phase0-appendices/A1_rutas_db_permisos.md) §3 completo. El ledger financiero está **excepcionalmente bien defendido**: `credit_transactions.idempotency_key` UNIQUE a nivel de BD, `lockForUpdate()` en cada mutación financiera revisada, ninguna encontrada sin lock. Las FKs de historial financiero/de clases se endurecieron de `CASCADE`/`SET NULL` a `RESTRICT` en una migración que **audita su propia precondición y postcondición** (`assertForeignKeysMatchBaseline()`, `assertRollbackDoesNotExposeHistory()`) — nivel de rigor poco común.

**El hallazgo más importante de esta sección es una consecuencia no anticipada de ese mismo endurecimiento**: `StudentController::destroy()` nunca se actualizó para reflejar que `classes.student_id`/`class_requests.student_id` ahora son `RESTRICT`, no `CASCADE`. Ver BUG-1 en Q — es, con evidencia, el bug funcional más severo de toda la Fase 0.

## I. UX/UI Audit

Ver [A3](phase0-appendices/A3_frontend_ux_performance.md) §3-4. Hallazgos concretos, con propuesta de corrección específica en cada caso (no genérica):
- **`window.confirm()` nativo** en borrar un estudiante y aprobar una recarga de dinero real — rompe la identidad visual "elegante/cara" que CLAUDE.md exige, justo en las dos acciones más sensibles del sistema. El patrón correcto (`Modal.vue`) ya existe en el propio proyecto.
- **Contraste insuficiente sistémico**: `text-slate-400`/`text-gray-400` (≈2.57:1, calculado) usado 151 veces en 44 archivos para texto informativo real, muy por debajo del mínimo WCAG AA (4.5:1).
- **SEO**: `Marketplace/Index.vue` y `Teachers/Show.vue` (páginas que CLAUDE.md nombra explícitamente) solo tienen meta description, sin Open Graph. Cero JSON-LD en todo el proyecto.
- **Accesibilidad de modales**: `Modal.vue` no implementa `role="dialog"`, no gestiona foco al abrir/cerrar, no atrapa `Tab`.

## J. Performance Audit

Ver [A3](phase0-appendices/A3_frontend_ux_performance.md) §5 y [A4](phase0-appendices/A4_testing_observabilidad_deuda.md) B7. **Mejor de lo esperado**: `npm run build` (ejecutado realmente, no asumido) confirma code-splitting real por página — decenas de chunks pequeños (2-20 kB), `echo-*.js` separado (73.6 kB / 21.3 kB gzip), `app-*.js` en 249 kB (90 kB gzip), `Welcome.vue` (página pública SEO-crítica) en 169 kB (56 kB gzip) como chunk propio. GSAP/Swiper como `dependencies` sin `manualChunks` explícito, pero el code-splitting automático de Vite ya debería limitar su impacto a las 2 páginas que los usan — no confirmado con inspección directa del manifest en esta ronda específica (parcialmente confirmado en la ronda de testing). Ningún `<img>` usa `loading="lazy"` — impacto bajo (avatares pequeños).

## K. Testing Audit

**Corrección — dos cifras distintas, por una razón real, no un error de conteo:** el apéndice [A4](phase0-appendices/A4_testing_observabilidad_deuda.md) §1 reporta `php artisan test` → **174 passed, 736 assertions** — ese número es real, pero corresponde al worktree aislado del agente (checkout limpio desde `HEAD`, sin los ~40 archivos con cambios sin commitear del checkout principal). **Al ejecutar la misma suite contra el checkout principal ahora mismo, el resultado es 454 passed, 1328 assertions, todos en verde** — coincide con el Audit Baseline ya documentado en `MOVA_PRODUCTION_READINESS.md`. La diferencia (280 tests) es, en su mayoría, la batería completa de tests de WhatsApp/Meta, pagos/Culqi, y las pruebas financieras de concurrencia que existen solo en el checkout principal. Sin `tests/Unit/` (decisión deliberada y documentada en `phpunit.xml`).

**Todas las brechas de cobertura señaladas en el apéndice A4 (panel de moderación de admin, `DiagnosticRecommendationService`, `DiagnosticAiEnrichmentService`, `WhatsAppChannel`/`SafeMailChannel` sin test dedicado) siguen siendo válidas** — se re-confirmaron por grep directo sobre el checkout principal, no dependen de qué worktree las vio.

**Brechas de cobertura reales, no genéricas:**
- **Todo el panel de moderación de administrador** (suspender usuario, aprobar/rechazar profesor, ocultar reseña) — cero tests, ni positivos ni negativos, pese a ser la única superficie donde un humano ejerce poder unilateral sobre otros usuarios.
- `DiagnosticRecommendationService` (el algoritmo de scoring, "el corazón del producto" según la documentación) y `DiagnosticAiEnrichmentService` (guardrails de IA) — cero tests dedicados.
- `MovaCriticalFlowTest.php` sigue con solo 2 métodos pese al nombre.
- **La suite corre en SQLite, que no aplica FKs por defecto** — BUG-1 (borrado de estudiante con historial) casi con certeza no está cubierto, porque el motor de test no puede manifestar el error que sí ocurriría en MySQL de producción. Es exactamente el tipo de divergencia motor-de-test-vs-producción que esta auditoría pedía buscar explícitamente.
- Playwright: existe `flujo-completo.spec.js` (441 líneas, 3 tests reales, bien construido) — contradice la creencia previa de "sin specs E2E". Cubre solo caminos felices, sin integración a CI (no existe `.github/workflows/`).

## L. Automation Opportunities

Tabla completa (7 ítems) en [A4](phase0-appendices/A4_testing_observabilidad_deuda.md) §3. Las 3 de prioridad **Alta**:
1. `mova:reconcile-ledger` — diseñado explícitamente (según su propio docblock) para correr en CI/monitorización, hoy solo se ejecuta manualmente.
2. No existe ningún pipeline de CI — ni tests, ni `npm audit`/`composer audit`, ni build se verifican automáticamente antes de mergear.
3. El scheduler de Railway es un punto ciego autorreferencial — si se cae, nada dentro de la app lo detecta (esto fue exactamente el incidente histórico C1).

## M. Observability

Ver [A4](phase0-appendices/A4_testing_observabilidad_deuda.md) §2. Sentry está instalado y bien configurado pero inactivo por defecto (DSN vacío). `failed_jobs` existe pero nada resume ni alerta sobre su acumulación. **5 fallos concretos que hoy pasarían desapercibidos en producción**, listados con evidencia en el apéndice: descuadre del ledger, WhatsApp en fallback silencioso, IA en fallback permanente, acumulación de jobs fallidos, y el propio scheduler caído (autorreferencial).

## N. Technical Debt

`MOVA_MASTER_CONTEXT.md` (2026-07-18) resultó estar desactualizado en **al menos 11 de sus ~20 ítems de deuda documentados** — la mayoría porque ya fueron resueltos, no porque el análisis original fuera incorrecto en su momento:

| Ítem | Estado documentado (2026-07-18) | Estado real verificado (2026-08-26) |
|---|---|---|
| A1 (sin Policies) | 🟠 Abierto | ✅ **RESUELTO** — 7 Policies |
| A2 (sin throttle financiero) | 🟠 Abierto | ✅ **RESUELTO** — throttle en casi toda mutación |
| A3 (admin sin `not.suspended`) | 🟠 Abierto | **Reclasificado** — diseño deliberado y mitigado, no bug |
| A4 (admin sin permisos finos) | 🟠 Abierto | ❌ **SIGUE ABIERTO** |
| A5 (`env()` en runtime) | 🟠 Abierto | ✅ **RESUELTO** en ambos puntos señalados |
| C3 (Jitsi sin control de acceso) | 🔴 Crítico | ✅ **RESUELTO** — migrado a JaaS con JWT RS256 |
| M1 (vestigios de Zoom) | 🟡 Medio | ✅ **RESUELTO** por completo |
| M2 (2 layouts) | 🟡 Medio | ✅ **RESUELTO** — 3 layouts activos, ninguno vestigial |
| M4 (seeder no idempotente) | 🟡 Medio | ✅ **RESUELTO** |
| M6 (precio duplicado en frontend) | 🟡 Medio | ✅ **RESUELTO** — `price_frozen_pen` es la fuente de verdad |
| B2-B5 (varios) | 🟢 Bajo | ✅ **RESUELTOS** todos |
| B6 (`npm audit`) | 3 vulnerabilidades | **Cambió, no resuelto** — hoy son 4 (todas dev-time, 0 en `--omit=dev`) |
| B7 (bundle sin code-split) | 🟡 Medio | ✅ **MEJORÓ SUSTANCIALMENTE** — code-splitting real confirmado |
| B8 (sin factories) | 🟢 Bajo | Sigue vigente |

**Hallazgo nuevo de cadena de suministro**: `composer audit` reporta 6 avisos sobre `league/commonmark` (dependencia transitiva de Laravel, sin uso directo de `Str::markdown()` en MOVA — riesgo real bajo).

**Código muerto encontrado** (clasificado, no solo listado): `ClassOfferController::store()` inalcanzable por diseño (P4, safe to remove con comentario explicando por qué); `DiagnosticRecommendationService::compute()` sigue ejecutándose en cada diagnóstico pero su salida nunca se muestra (`recommendations: []` hardcodeado) — trabajo de cómputo desperdiciado, no un bug.

## O. Documentation Gaps

`MOVA_MASTER_CONTEXT.md` es, con evidencia de los 6 agentes independientes, **el documento más desactualizado del repositorio** — no por mala fe, simplemente por ser el más antiguo (2026-07-18) frente a un código que siguió avanzando. Discrepancias concretas encontradas: Cloudinary descrito como "no integrado" (está activo), Jitsi descrito como vulnerable sin JWT (ya migrado a JaaS con JWT), Socialite/Google Login no mencionado (está activo y completo), el pivote del marketplace informativo no reflejado, 3 niveles de tarifa descritos como 2. `SETUP.md` pide una cuenta de Zoom (ya no existe en el código) y describe Gmail como "App Password" (el código implementa OAuth2 completo).

**Recomendación explícita**: `MOVA_MASTER_CONTEXT.md` necesita una actualización completa antes de usarse como referencia en cualquier trabajo futuro — no parches puntuales, una revisión de punta a punta contrastada con el código real (exactamente lo que esta Fase 0 ya hizo, sección por sección).

## P. External Integrations

Tabla completa de clasificación (13 integraciones) en [A5](phase0-appendices/A5_integraciones_externas.md) §1. Las más relevantes:
- **WhatsApp (Meta Cloud API)**: activa no crítica (apagada por defecto), sin reintentos por diseño deliberado (documentado: Meta no expone idempotency key propia), dos capas de idempotencia en el webhook, fail-closed sin credenciales.
- **Culqi**: confirmado sin cambios desde F-21 — stub deliberado, cero ruta HTTP, cero superficie de ataque activa.
- **JaaS/Jitsi**: activa y crítica, el diseño de seguridad más sólido de las 13 integraciones — JWT RS256, ventana de acceso acotada, `livestreaming`/`recording`/`transcription` deshabilitados, falla con 500 explícito si faltan credenciales (nunca degrada a sala pública).
- **Correo**: cascada real Gmail API → SMTP → log, pero sin caché de access token entre envíos (2 llamadas HTTP por correo).
- **Discrepancias D1-D5** (Cloudinary, Socialite, Jitsi/JaaS, todas ya cubiertas en N/O) documentadas con su fuente exacta y por qué la documentación anterior quedó obsoleta, no simplemente citadas como error.

## Q. Confirmed Bugs

**Cada fila de esta tabla fue re-verificada directamente contra el checkout principal** (no solo contra el worktree aislado que originó el hallazgo) — con lectura de código actual y, donde aplica, ejecución real de tests. Esto es lo que reveló que BUG-1 ya está corregido.

| ID | Severidad | Categoría | Estado real (checkout principal, re-verificado) | Evidencia |
|---|---|---|---|---|
| **BUG-1** | — | DATA INTEGRITY | ✅ **YA CORREGIDO en el checkout principal.** Originalmente: borrar un `Student` con historial producía un 500 crudo en MySQL (FK `RESTRICT` sin manejar). Verificado ahora: `Student` usa `SoftDeletes` (migración `2026_08_24_000003_add_soft_deletes_to_students_table.php`, sin commitear), `StudentController::destroy()` hace soft-delete + anonimización de datos del menor, y `Student::forceDelete()` sobre un alumno con historial lanza `RuntimeException` explícita en vez de permitir el borrado físico. **`tests/Feature/StudentDeletionIntegrityTest.php` — 12/12 tests passed, ejecutado ahora mismo contra el checkout principal** (no un worktree aislado). El propio docblock del test cita el hallazgo original como "F-18, hallazgo de la reauditoría post-Fase 3" — es decir, ya se había encontrado y corregido en una ronda de esta misma auditoría anterior a la Fase 0, en un archivo que simplemente nunca se commiteó. | Ejecución real, `tests/Feature/StudentDeletionIntegrityTest.php` |
| BUG-2 | 🟠 **P2** | FUNCTIONAL | ❌ **Sigue abierto** — re-confirmado por grep directo: `DiagnosticsController.php` no está en la lista de archivos modificados del checkout principal (no cambió desde que el agente lo vio) y sigue sin `DB::transaction()`, sin idempotencia, sin constraint UNIQUE. Doble-click crea dos diagnósticos y dos solicitudes de clase reales. | [A6](phase0-appendices/A6_modelo_negocio_flujos_bugs.md) §4 |
| BUG-3 | 🟠 **P2** | BUSINESS LOGIC | ❌ **Sigue abierto** — re-confirmado: `LessonSettlementService::CONSUMABLE_STATES` (línea 38) sigue incluyendo `'paid'` sin exigir `lessonReport()->exists()`; `TeacherReviewController::assertReviewable()` (línea 127) sigue exigiéndolo para calificar. La contradicción persiste en el código actual. | ídem, re-verificado por grep directo |
| BUG-4 | 🟡 **P3** | BUSINESS LOGIC | ❌ **Sigue abierto** — re-confirmado: `grep specific_rate app/Http/Controllers/LessonController.php` (versión actual, modificada) → cero resultados. El campo se sigue sin usar para el cobro real pese a los cambios recientes en ese controlador. | ídem |
| BUG-5 | 🟡 **P3** | FUNCTIONAL / TIMEZONE | ❌ **Sigue abierto** — re-confirmado: `config/app.php` (sin cambios en el checkout principal) sigue en `'timezone' => 'UTC'`. | ídem |
| N1 | 🟢 **P3** | DATA INTEGRITY | ❌ **Sigue abierto** — `app/Models/Subject.php` no está en la lista de archivos modificados; `firstOrCreateByName()` sigue sin `try/catch` de `UniqueConstraintViolationException`. | [A1](phase0-appendices/A1_rutas_db_permisos.md) §3.6 |

**Balance real: de 5 "bugs confirmados" + N1 reportados por la Fase 0, 5 siguen abiertos hoy y 1 (BUG-1, el único P1) ya está corregido** — pero solo en el checkout principal sin commitear, lo que refuerza que N-02 (sección G) no es un tecnicismo: mientras ese trabajo no se commitee, "ya está corregido" y "nunca existió el fix" son indistinguibles para cualquiera que clone el repositorio.

## R. Confirmed Vulnerabilities

**Ninguna vulnerabilidad alcanzó clasificación PROVEN mediante explotación reproducida en vivo** — consistente con las reglas de esta fase (sin ataques contra ninguna instancia real). Los dos hallazgos 🔴 P0 de esta ronda son de **exposición de secretos**, no de lógica de aplicación explotada:

| ID | Severidad | Clasificación | Resumen |
|---|---|---|---|
| N-01 (⊃ F-26/F-27) | 🔴 P0 | PROVEN | Contraseña RAW de MySQL de producción y cookies de admin en el `HEAD` real de git — confirmado leyendo el archivo directamente en un checkout aislado, no en historial antiguo. **Corregido en la documentación durante esta misma sesión**; el commit/push y la rotación siguen pendientes de acción del usuario. |
| N-02 | 🟠 P1 | PROVEN | Todo el trabajo de remediación de seguridad de esta serie de auditorías sin commitear — riesgo de pérdida total y de que ningún hallazgo "FIXED" sea válido para lo que Railway despliega hoy. |
| F-21 (re-confirmado) | 🟠 P1 | STILL OPEN, no explotable hoy | Webhook de Culqi inexistente — sin ruta, sin superficie de ataque activa. |

Todo lo demás investigado (IDOR, mass assignment, SQLi, CSRF, XSS, SSRF, OTP abuse, brute force, replay, webhook forgery) fue **NOT APPLICABLE (verificado, no vulnerable)** en la muestra de mayor riesgo revisada, o quedó en LIKELY/UNKNOWN explícitamente marcado como tal — ver tabla completa en [A2](phase0-appendices/A2_seguridad.md) §2-3.

## S. Suspected Issues Requiring Verification

Consolidado de los 6 apéndices (`UNKNOWN / REQUIERE VERIFICACIÓN` explícito en cada uno):

- **Estado real de `origin/master` en GitHub** exactamente hoy — bloqueado en la sesión de seguridad por el aislamiento del agente hacia comandos `git` del checkout principal; confirmado independientemente por esta sesión (ver G).
- Si la contraseña de MySQL de producción, `JAAS_PRIVATE_KEY`, o cualquier otra credencial real sigue activa — deliberadamente no verificado en ningún agente.
- `APP_DEBUG`/`APP_ENV`/`SESSION_SECURE_COOKIE` reales en el `.env` de producción.
- Auditoría de dependencias con herramienta — parcialmente hecha (`composer audit`/`npm audit` sí se ejecutaron en la sesión de testing; no en la de seguridad, que no tenía `vendor/`/`node_modules`).
- `VerifyCsrfToken.php` — no revisado por excepciones (`$except`) indebidas.
- Componente compartido de paginación (origen exacto de `link.label` en los 3 `v-html`) — no leído directamente.
- Reproducción empírica de doble-booking/race conditions con dos requests concurrentes reales contra MySQL — el diseño (locks + índices + UNIQUE) es coherente por lectura de código, pero ningún agente ejecutó una prueba de concurrencia real.
- Comportamiento visual real en breakpoints 768-1024px (posible zona muerta del sidebar), foco de teclado real en `Modal.vue`, percepción real de fricción en los wizards de 5-6 pasos — todo requiere navegador/interacción manual.
- Estado de `C1` (scheduler de Railway) y `C2` (posible archivo `.env.claude.local` fuera del árbol de git) — dependen del panel de Railway y del disco fuera de git, no verificables desde el código.
- `docs/HANDOFF_FINAL.md` (77KB+) no se leyó completo en ningún agente — probablemente documenta en detalle varios hallazgos que aquí se infirieron solo de comentarios del código.
- Si el botón de login con Google es visible hoy en el frontend — confirmado el backend completo, no se inspeccionó el componente Vue de login/registro.

---

## T. Risk Matrix

| ID | Área | Hallazgo | Severidad | Probabilidad | Impacto | Estado | Prioridad |
|---|---|---|---|---|---|---|---|
| N-01 | SECURITY | Contraseña de MySQL de producción en HEAD real de git | 🔴 Crítico | Alta (ya expuesta) | Crítico (compromiso total de BD) | STILL OPEN (doc corregida) | **P0** |
| N-02 | SECURITY / PROCESS | Todo el trabajo de seguridad de esta auditoría sin commitear | 🟠 Alto | Alta | Alto (pérdida de trabajo + falsa sensación de seguridad) | PROVEN | **P0** |
| BUG-1 | DATA INTEGRITY / FUNCTIONAL | Borrar `Student` con historial → 500 crudo | — | — | — | ✅ **FIXED** (verificado, 12 tests en verde contra checkout principal) — ver Q | **Ninguna, cerrado** |
| F-21 | BUSINESS LOGIC / ARCHITECTURE | Webhook de Culqi inexistente | 🟠 Alto (bloqueante de negocio) | N/A (sin tráfico) | Alto (bloquea pagos automáticos) | STILL OPEN | **P1** |
| BUG-3 | BUSINESS LOGIC | Auto-liquidación sin reporte pedagógico obligatorio | 🟠 Medio | Media | Medio (erosiona diferenciador de producto) | CONFIRMED | **P2** |
| BUG-2 | FUNCTIONAL | Diagnóstico sin protección de doble-envío | 🟡 Medio | Media (doble-click/red lenta) | Bajo-Medio (datos duplicados, no dinero) | CONFIRMED | **P2** |
| A4 | AUTHORIZATION | Admin sin permisos finos, sin separación de funciones | 🟡 Medio | Baja (requiere insider) | Medio | STILL OPEN | **P2** |
| N-11 | SECURITY | `SESSION_SECURE_COOKIE` no definida | 🟡 Bajo-Medio | Baja | Medio si se explota | LIKELY | **P2** |
| K (cobertura) | TESTING | Panel admin de moderación sin ningún test | 🟡 Medio | — | Medio (riesgo de regresión futura) | CONFIRMED | **P2** |
| BUG-4 | BUSINESS LOGIC | `specific_rate` no se cobra realmente | 🟢 Bajo | Media | Bajo (expectativa incorrecta del profesor) | CONFIRMED | **P3** |
| BUG-5 | FUNCTIONAL | Contadores "de hoy" en UTC, no hora de Lima | 🟢 Bajo | Alta (ocurre siempre) | Bajo | CONFIRMED | **P3** |
| N1 | DATA INTEGRITY | `Subject::firstOrCreateByName` sin manejo de carrera | 🟢 Bajo | Baja (requiere concurrencia real) | Bajo | CONFIRMED | **P3** |
| I-UI1 | UX/UI | Contraste `slate-400` en 151 ocurrencias | 🟢 Bajo | — | Bajo (accesibilidad) | CONFIRMED | **P3** |
| I-UI4 | UX/UI / SEO | Sin OG/JSON-LD en páginas públicas clave | 🟢 Bajo | — | Bajo (SEO, no funcional) | CONFIRMED | **P3** |
| L-1 | AUTOMATION | `mova:reconcile-ledger` no está en el scheduler ni en CI | 🟡 Medio | — | Medio (detección tardía de descuadres) | CONFIRMED | **P2** |
| L-2 | DEVOPS | Sin pipeline de CI | 🟡 Medio | — | Medio (regresiones sin red de seguridad) | CONFIRMED | **P2** |
| N-comm | TECH DEBT | `league/commonmark` con 6 avisos (transitiva, sin uso directo) | 🟢 Bajo | Baja | Bajo | CONFIRMED | **P4** |

---

## U. Recommended Roadmap

**PHASE 0 — Reconstrucción del sistema.** ✅ Completada con este documento. Objetivo: contexto verificado, no asumido. Sin dependencias previas.

**PHASE 1 — Bloqueos críticos y seguridad (empieza aquí, antes que cualquier otra cosa).**
- Objetivo: cerrar N-01/N-02 y decidir sobre F-26/F-27.
- Resuelve: exposición activa de credenciales, riesgo de pérdida de todo el trabajo de esta auditoría.
- Dependencias: ninguna — es lo primero, literalmente antes de tocar cualquier otro hallazgo de este documento.
- Riesgo de NO hacerlo ya: cualquier otro trabajo de las fases siguientes también quedaría sin commitear y en el mismo riesgo.
- Criterio de finalización: el usuario confirma que ha (a) revisado y commiteado los cambios acumulados de esta serie de auditorías por áreas lógicas, (b) rotado la contraseña de MySQL de producción, (c) ejecutado el checklist de verificación post-rotación de `MOVA_CREDENTIAL_EXPOSURE.md`.

**PHASE 2 — Integridad de datos y reglas de negocio.**
- Objetivo: BUG-2, BUG-3 (BUG-1 ya está corregido — ver Q; solo falta que la Fase 1 lo commitee para que cuente como cerrado de verdad).
- Resuelve: doble-envío de diagnósticos; erosión silenciosa del diferenciador de producto (reporte pedagógico eludible).
- Dependencias: Fase 1 (para que cualquier fix nuevo quede commiteado y no se pierda, igual que ya le pasó al fix de BUG-1).
- Tests necesarios: test de doble-submit para diagnósticos (BUG-2); decisión de negocio explícita sobre BUG-3 antes de escribir el fix (¿el auto-settlement de un `paid` sin reporte debería escalar a `needs_admin_review` en vez de auto-completarse?).
- Criterio de finalización: los 2 bugs tienen either un fix con test que reproduce el bug original en rojo y pasa en verde, o una decisión documentada de por qué no se corrige.

**PHASE 2.5 — Production Parity Audit (nueva, transversal, no sustituye a las fases 2-3).**
- Objetivo: ver sección W. BUG-1 demostró que una divergencia SQLite/MySQL puede esconder un bug real detrás de una suite verde — antes de confiar en "los tests pasan" como señal de que algo está resuelto, hay que saber en qué más difiere el entorno de test del de producción.
- Resuelve: la pregunta de fondo detrás de BUG-1, no solo el síntoma puntual.
- Dependencias: ninguna — puede (y debe) correr en paralelo a las Fases 2-6, y repetirse cada vez que se añada un nuevo test o una nueva dependencia de infraestructura.
- Criterio de finalización: cada fila de la matriz W tiene una respuesta real, no un `?`; y al menos los puntos marcados 🔴/🟠 tienen o un test que se ejecuta contra MySQL real (no solo SQLite), o una decisión documentada de por qué no hace falta.

**PHASE 3 — Bugs funcionales restantes y deuda de cobertura.**
- Objetivo: BUG-4, BUG-5, N1, cobertura de test para el panel de moderación de admin.
- Dependencias: ninguna estricta, puede correr en paralelo a la Fase 2.
- Prioridad menor a Fase 2 porque ninguno de estos bloquea una acción de usuario ni erosiona una promesa central de producto.

**PHASE 4 — Frontend y UX/UI.**
- Objetivo: UI1 (contraste), UI4 (SEO/OG/JSON-LD — CLAUDE.md ya lo exige explícitamente), UX1 (`window.confirm()` nativo), F1 (badges de crédito duplicados), F2 (wizards sin persistencia).
- Dependencias: ninguna del backend.
- Sugerencia explícita del propio audit: invocar `impeccable`/`ui-ux-pro-max` (ya establecido como práctica del proyecto) antes de tocar estas superficies.

**PHASE 5 — Performance.**
- Objetivo: confirmar con `npm run build` + inspección de manifest si GSAP/Swiper contaminan el chunk común (P1 en J); lazy-loading de imágenes donde aplique.
- Baja urgencia — el performance real ya es mejor de lo que la documentación anterior sugería.

**PHASE 6 — Automatización y observabilidad.**
- Objetivo: agendar `mova:reconcile-ledger`, montar un pipeline de CI mínimo (`composer install && npm ci && npm run build && php artisan test`), health check externo para el scheduler.
- Estas 3 acciones son de riesgo bajo (aditivas, no cambian comportamiento) y de beneficio alto — buenas candidatas a ejecutar temprano, en paralelo a las Fases 2-4.

**PHASE 7 — Refactor y arquitectura.**
- Objetivo: separación de funciones en `AdminController` (Policies + permisos finos de Spatie, no solo roles); eliminar código muerto (`ClassOfferController::store()`, `DiagnosticRecommendationService` si el pivote de producto es definitivo).
- Dependencias: decisión de producto sobre si el pivote del marketplace es definitivo.

**PHASE 8 — Regression / hardening / production readiness.**
- Objetivo: re-ejecutar esta misma metodología de Fase 0 (los 6 dominios) una vez completadas las fases 1-7, y comparar contra este documento para confirmar que cada hallazgo cerrado tiene evidencia real, no solo tiempo transcurrido.
- Criterio de finalización: ningún hallazgo P0/P1 de la tabla de la sección T sigue abierto sin una decisión de negocio documentada explícita.

---

## V. Root Cause — Síntesis de patrones detrás de los bugs (no corregir uno por uno sin entender la familia)

Los 5 bugs confirmados (Q) y N1 no son 6 fallos aislados — se agrupan en 4 patrones arquitectónicos reales, cada uno con una causa raíz distinta. Corregir cada bug sin nombrar el patrón que lo produjo deja el resto de instancias de ese mismo patrón sin buscar.

**Patrón A — Deriva de invariantes entre módulos (un cambio en un lugar asume algo que un módulo hermano no garantiza):**
- **BUG-1 (ya corregido)**: la migración que endureció las FKs de `classes.student_id`/`class_requests.student_id` a `RESTRICT` protegía el historial financiero, pero `StudentController::destroy()` —en otro módulo, escrito en otro momento— no se actualizó en el mismo cambio. El fix real (soft delete + anonimización) es exactamente cerrar esa brecha entre los dos módulos.
- **BUG-3 (abierto)**: `LessonSettlementService::CONSUMABLE_STATES` y `TeacherReviewController::assertReviewable()` cada uno define su propia noción de "clase completable/calificable", y **no comparten una sola fuente de verdad** — divergieron con el tiempo.
- **Dónde más buscar este patrón**: cualquier constante/lista cerrada de estados (`CONSUMABLE_STATES`, `REFUNDABLE_STATES`, los estados de `RechargeRequest`, de `ClassRequest`) que se referencie por nombre en más de un archivo es candidata a esta misma deriva. Recomendación: una tabla explícita de "qué estado permite qué acción, en qué módulo" (parte de un futuro `MOVA_STATE_MACHINE.md` o similar), no solo el código disperso.

**Patrón B — Aplicación inconsistente de un patrón ya establecido y correcto (el patrón existe, pero se saltó en un punto):**
- **BUG-2 (abierto)**: `DB::transaction()` + `lockForUpdate()` + `idempotency_key` único es el patrón religiosamente aplicado en cada mutación financiera del sistema — excepto en `DiagnosticsController::store()`, la única creación de datos "reales" (diagnóstico + solicitud de clase) que no lo sigue.
- **N1 (abierto)**: el patrón `try/catch UniqueConstraintViolationException` + refetch se usa en `idempotency_key`, `operation_number_normalized`, `phone_verified_normalized` — pero no en `Subject::firstOrCreateByName()`.
- **Por qué importa nombrarlo así**: esto no es "falta disciplina en general" — es que el equipo SÍ tiene la disciplina (se ve en el 95% del código), lo cual hace más fácil, no más difícil, encontrar el resto de excepciones: **buscar cada `::create()`/`firstOrCreate()` que escriba una tabla con una constraint UNIQUE o que dispare un efecto de negocio real, y verificar si sigue el patrón ya establecido o es una excepción silenciosa como estas dos.**

**Patrón C — Feature a medio conectar (se construyó la entrada, nunca se conectó la salida):**
- **BUG-4 (abierto)**: `specific_rate` se valida y persiste en `ClassOfferController`, pero `LessonController`/`ClassRequestController` (donde se calcula el cobro real) nunca lo leen. Es un feature con un extremo construido y el otro extremo huérfano.
- **Dónde más buscar**: cualquier campo de formulario/columna de BD que se guarde pero cuyo consumidor real no aparezca en un grep dedicado — el propio Phase 0 ya encontró un segundo caso de esta misma familia fuera de los bugs formales: `DiagnosticRecommendationService::compute()` sigue corriendo pero su resultado se descarta (`recommendations: []` hardcodeado) — mismo patrón C, no clasificado como bug porque no rompe nada, pero es la misma causa raíz.

**Patrón D — Default de framework nunca reconsiderado para el producto real:**
- **BUG-5 (abierto)**: `config('app.timezone') = 'UTC'` es el default de cualquier instalación nueva de Laravel; nadie lo revisó para un producto que opera exclusivamente en Perú (UTC-5, sin horario de verano).
- **Dónde más buscar**: cualquier otro default de scaffolding (`config/*.php`) que nunca se haya tocado explícitamente para las necesidades reales de MOVA — candidatos obvios para una pasada dedicada: `config/session.php` (lifetime), `config/cors.php` (ya revisado en G, sin hallazgo), `config/logging.php` (canal `single` sin rotación, ya señalado en M).

---

## W. Production Parity Matrix

Motivada directamente por BUG-1: una suite verde en SQLite no demuestra que el comportamiento en MySQL de producción sea correcto. Esta matriz existe para que esa pregunta deje de resolverse caso por caso (como ocurrió con BUG-1) y se responda de una vez, por área.

| Área | Test (`phpunit.xml`) | Local (dev, `.env.example`) | Staging | Producción (Railway) | Riesgo |
|---|---|---|---|---|---|
| **DB engine** | `sqlite` (forzado explícitamente: `<env name="DB_CONNECTION" value="sqlite" force="true"/>`) | `mysql` (`DB_CONNECTION=mysql` en `.env.example`) | No existe (confirmado en `MOVA_QA_SECURITY_POLICY.md`: "STAGING — no existe todavía para MOVA") | `mysql` | 🔴 **Confirmado real** — causó BUG-1 (FK `RESTRICT` no aplicada en SQLite). Cualquier otra constraint de MySQL no soportada/no emulada por SQLite (charset/collation, `ON UPDATE CURRENT_TIMESTAMP`, tipos `ENUM` reales vs. `CHECK` emulado, precisión de `DECIMAL`) es candidata a la misma clase de bug oculto |
| **Cache** | `array` (Laravel usa `array` en testing por defecto salvo config explícita — no confirmado con una lectura directa de `phpunit.xml` para `CACHE_DRIVER`, marcar `UNKNOWN` hasta confirmar) | `file` (`CACHE_DRIVER=file`) | N/A | `UNKNOWN` — no verificable desde el repo; si Railway usa el mismo `file` sobre un contenedor efímero, un redeploy borraría la caché sin aviso (no necesariamente un bug, pero una característica operativa que vale la pena confirmar que es intencional) | 🟡 Requiere verificación operativa en Railway |
| **Queue** | Síncrono por defecto en tests (`QUEUE_CONNECTION` no forzado en `phpunit.xml` — la mayoría de tests de Laravel corren jobs síncronos salvo que usen `Queue::fake()` explícitamente) | `database` (`QUEUE_CONNECTION=database`) | N/A | `database` (asumido igual, confirmado indirectamente por `railway.queue.toml`) | 🟢 Bajo — mismo mecanismo en todos los entornos relevantes, sin divergencia de motor |
| **Mail** | `array`/`log` (no entrega real, confirmado por `SafeMailChannel`: "si `array`/`log`: no-op") | `gmail_api` (`MAIL_MAILER=gmail_api`) | N/A | `gmail_api` (asumido, ver P) | 🟢 Bajo — `SafeMailChannel` ya normaliza el comportamiento entre entornos, con fallback documentado |
| **Broadcasting** | No aplica en tests (sin conexión real) | **Discrepancia real encontrada al construir esta matriz**: `.env.example` en el checkout principal dice `BROADCAST_DRIVER=pusher`, pero el agente de integraciones (worktree aislado desde `HEAD`) reportó `BROADCAST_DRIVER` sin definir → default `null` en `config/broadcasting.php`. Es decir, **el propio `.env.example` cambió entre el `HEAD` que vio el agente y el checkout principal** — otra instancia del mismo problema de fondo de la sección A/G (trabajo sin commitear cambiando el comportamiento documentado) | N/A | `UNKNOWN` — depende de si Railway tiene `BROADCAST_DRIVER=pusher` configurado explícitamente, no verificable desde el repo | 🟡 Requiere reconciliar qué valor es el vigente antes de asumir cualquiera de los dos |
| **Storage** | `local` (disco de test) | `local` (`FILESYSTEM_DISK=local`) | N/A | `local` + Cloudinary para avatares (ver P) | 🟡 Un contenedor Railway efímero con `FILESYSTEM_DISK=local` pierde cualquier archivo no subido a Cloudinary en cada redeploy — requiere confirmar qué, además de avatares, se guarda en disco local hoy |
| **Auth** | Sesión (scaffolding Breeze) | Sesión | N/A | Sesión (asumido) — Sanctum instalado pero sin uso confirmado de tokens API en esta auditoría | 🟢 Bajo — sin divergencia encontrada |
| **External APIs** | `fake`/mocked (`WHATSAPP_PROVIDER=fake` default, `ProviderGuard` fail-closed) | `fake` por defecto salvo configuración explícita | N/A | Credenciales reales (no verificable desde el repo) | 🟢 Bajo en el sentido de que `ProviderGuard` impide silenciosamente degradar a `fake` en producción con la integración habilitada — el diseño ya mitiga la divergencia entorno-a-entorno |

**Ampliación de runtime/build (capturada con el snapshot de esta corrección, valores reales no `?`):**

| Área | Test | Local (esta máquina, ahora) | Staging | Producción (Railway) | Riesgo |
|---|---|---|---|---|---|
| **PHP** | 8.1.25 (mismo binario que local, no hay entorno de test separado) | 8.1.25 (ZTS, cli) | N/A | `UNKNOWN` — `composer.json` exige `^8.1`, Railway resuelve la versión exacta en build; no verificable desde el repo | 🟢 Bajo — el constraint `^8.1` acota el rango, y ZTS vs NTS no debería afectar el comportamiento de la aplicación |
| **Node/npm** | v22.23.2 / 10.9.8 (mismo que local — no hay pin de versión de Node en `package.json`/`.nvmrc`) | v22.23.2 / 10.9.8 | N/A | `UNKNOWN` — sin `.nvmrc`/`engines` en `package.json`, Railway puede resolver una versión de Node distinta en su build | 🟡 Sin un pin explícito, un bump de Node en Railway podría comportarse distinto a esta máquina sin que nadie lo note hasta un build roto |
| **Build mode** | N/A (tests no ejecutan `npm run build`) | `npm run build` manual, modo producción de Vite | N/A | `UNKNOWN` — se asume que Railway ejecuta el mismo `npm run build`, no confirmado desde el repo (depende del build command configurado en el dashboard) | 🟡 Requiere confirmar en Railway que el build command coincide exactamente con lo documentado en `SETUP.md` |
| **Environment variables** | `phpunit.xml` fija un subconjunto reducido (`APP_ENV=testing`, `DB_CONNECTION=sqlite`, servicios externos apagados por defecto vía `ProviderGuard`) | `.env` local (no versionado, no auditable desde el repo) | N/A | `.env` real de Railway — completamente fuera del alcance de una auditoría de código | 🟡 Ya señalado en G/S: `APP_DEBUG`/`SESSION_SECURE_COOKIE`/`BROADCAST_DRIVER` reales de producción son `UNKNOWN` |

**Lectura de esta matriz**: la única fila con riesgo 🔴 confirmado (no solo teórico) es DB engine — ya produjo BUG-1. Las filas 🟡 no son bugs conocidos, son preguntas reales sin respuesta que esta Fase 0 no tenía como objetivo cerrar; quedan explícitamente para la Fase 2.5 del roadmap (sección U). Esta matriz está pensada para actualizarse cada vez que se toque `phpunit.xml`, `.env.example`, o la configuración de build/deploy — no es un documento de una sola vez.

---

## X. Fase de Control del Repositorio — Revisión de Diff y Checkpoint Readiness

**Snapshot auditado (idéntico al de la sección de arriba): `HEAD`=`origin/master`=`692b3651d09cb2731865efcb0d83dc83d2a36102`, 0 divergencia, working tree con 149 rutas modificadas/untracked, todas incluidas en esta revisión.** 4 agentes revisaron el diff completo línea por línea, cada uno declarando su propio snapshot y sin usar checkout aislado (aplicando la regla de la sección anterior). Reportes completos en [`B1`](phase0-appendices/B1_diffreview_backend_core.md)-[`B4`](phase0-appendices/B4_diffreview_tests_docs_qa.md).

### Resultado global

**454/454 tests pasan hoy contra el working tree real** (reproducido de forma independiente por 2 agentes distintos, en corridas separadas). **Cero secretos nuevos encontrados** en los 149 archivos (backend, WhatsApp/Payments, frontend, tests/docs/QA — 4 barridos independientes). **Ningún grupo de cambios se clasificó como QA_ARTIFACT, TEMPORARY, OBSOLETE o REVERT.** El código sin commitear es, con evidencia real, sustancialmente mejor que lo que documentaba `MOVA_MASTER_CONTEXT.md`: cierra F-01 (guard de borrado de `User`), F-03 (`ProviderGuard` fail-closed), F-04 (recordatorios atómicos), F-06 (ventana JaaS server-authoritative), F-10 (destinatarios unificados), F-16/F-17 (extracción de `RechargeApprovalService` + Policy legible), F-18 (soft-delete `Student`), además de toda la migración WhatsApp Twilio→Meta y la fundación de Payments/Culqi.

### CHECKPOINT READINESS

**BLOCKERS (impiden dar por segura la fecha del checkpoint sin acción):** Ninguno de código. El único bloqueo real es de **decisión del usuario, no de calidad del código**: los 5 archivos de F-26/F-27 (`qa/test-wizard-flow.mjs`, `qa/auth/admin.json`, `qa/global-setup.js`, `qa/end-to-end-welcome-email.mjs`, `qa/check-twilio.mjs`) siguen presentes con contenido completo en `HEAD`/`origin/master` — confirmado de nuevo con `git cat-file -e` en esta ronda. El checkpoint no debe darse por "limpio" sin incluir su eliminación.

**REQUIRED FIXES (no bloquean el commit, pero deben rastrearse y no darse por cerrados):**
1. **Revisión de seguridad formal y dedicada** (la que exige CLAUDE.md explícitamente) sobre los cambios a `Student` (soft-delete + anonimización de datos de un menor) y sobre la ventana de acceso a JaaS/Jitsi — señalado independientemente por 2 agentes (backend y frontend). El análisis A-J ya cubrió el terreno de seguridad con rigor, pero no sustituye formalmente esa pasada dedicada.
2. `Students/Create.vue` — `<InputError>` nuevo duplica un `<p>` de error legado para `first_name`/`last_name`: el mensaje se mostraría dos veces.
3. `ClassOffers/Edit.vue` — el `<InputError>` de `availability_schedule` no cubre los errores anidados reales (`days.*.*.start/end`) que el backend sí valida.
4. `SETUP.md` y `.telemetry/product.md` — ambos modificados esta sesión pero dejaron intactas menciones a **Zoom** (cuenta OAuth requerida, `ZoomService`, campos `zoom_*`) que ya no existen en el código (eliminado, reemplazado por JaaS — 2 migraciones lo confirman).
5. `docs/MOVA_SYSTEM_KNOWLEDGE.md` — 5 de 6 citas `archivo:línea` muestreadas son numéricamente imposibles (hasta 12x el tamaño real del archivo), pese a que el documento promete explícitamente que sus citas son verificables.
6. `docs/MOVA_QA_SECURITY_POLICY.md` — una frase ("el firewall nunca queda a discreción de un spec individual") es más absoluta de lo que el mecanismo real garantiza (si un spec futuro no importa `test` desde `qa/lib/fixtures.mjs`, sí queda a su discreción). Moot hoy (0 specs lo usan), pero vale suavizar la redacción.
7. `RechargeApprovalService::reverse()` — lógica correcta y probada, pero sin ruta/UI admin que la invoque todavía (ya reconocido como pendiente en el propio frontend).

**SAFE TO COMMIT:** los 12 grupos de backend core, los 3 módulos de WhatsApp/Payments/ProviderGuard, los 9 grupos de frontend, los 13 archivos de test nuevos/modificados (206 tests), toda la documentación marcada DOCS_ONLY, y toda la configuración QA/raíz (`.gitignore`, `.env.example`, `composer.lock`, `phpunit.xml`, `railway.queue.toml`, `qa/**`).

**REMOVE BEFORE COMMIT:** ninguno — ningún agente encontró un artefacto temporal, de debug, o de QA colado fuera de su lugar.

**DOCUMENTATION UPDATES pendientes:** los 5 ítems de REQUIRED FIXES que son de documentación (2, 4, 5, 6 de la lista de arriba). Recomendación adicional no ejecutada: archivar `MOVA_FULL_AUDIT.md`/`PHASE3_COMPLETION`/`PHASE3_REAUDIT`/`PHASE4_AUDIT`/`PHASE5_FINAL_AUDIT`/`PHASE6_AUDIT` bajo `docs/historico/` — son una cadena cronológica autoconsistente (cada uno corrige al anterior, con banners internos que apuntan hacia adelante), no duplicación ciega, pero ya no son la referencia operativa (esa es `MOVA_PRODUCTION_READINESS.md` + este documento).

**SECURITY CONCERNS:** F-26/F-27 (ver BLOCKERS); el componente `button` de las plantillas OTP de Meta no está confirmado contra documentación oficial (WhatsApp apagado por defecto, riesgo real solo al activar); `CulqiPaymentProvider::verifyWebhook()` no existe todavía (cero superficie de ataque real porque no hay ruta HTTP). Ningún secreto nuevo encontrado en 149 archivos.

**TEST STATUS:** 454 passed / 1328 assertions — reproducido de forma independiente por 2 agentes en corridas separadas, además de 3 corridas parciales por dominio (206, 142, 22, 351 tests respectivamente, con solapamiento esperado entre dominios).

**FINAL DIFF STATUS:** 149 rutas (2 staged, 69 modificadas sin stage, resto untracked) — el mismo estado documentado en la sección "AUDIT SNAPSHOT" de arriba, sin cambios desde entonces (esta fase fue de solo lectura).

### Propuesta de checkpoint — NO ejecutada, pendiente de tu confirmación explícita

Dado que ninguna clasificación fue BLOCKED/REVERT y que "no quiero un commit gigante indiscriminado" fue explícito, la recomendación es dividir en commits lógicos coherentes (no 149 archivos sueltos, no un solo commit) — por ejemplo:

1. `security: remove files containing exposed production credentials (F-26/F-27)` — los 5 archivos de F-26/F-27, aislado y fácil de auditar/revertir independientemente.
2. `feat(whatsapp): migrate from Twilio to Meta Cloud API` — todo `app/WhatsApp/*`, `WhatsAppChannel`, webhook, migraciones, consentimiento, tests.
3. `feat(payments): add Culqi payment foundation (stub, disabled by default)` — `app/Payment/*`, `PaymentOrder`/`PaymentWebhook`, `config/payments.php`, migraciones, tests.
4. `fix(data-integrity): soft-delete and anonymize students with academic history (F-18)` — `Student.php` + relaciones `withTrashed()` + frontend de `Students/*.vue`.
5. `fix(security): enforce server-side JaaS access window (F-06)` — `LessonController::join()`, `config/jaas.php`, `JaasService`, `lessonJoin.js`.
6. `refactor(credits): extract RechargeApprovalService, add reversal support` — Grupo 6 de backend + frontend de recargas.
7. `fix(frontend): double-submit guards, native dialogs replaced with modals, validation errors` — el resto de los grupos de UI (F-12/F-13/F-19).
8. `docs: audit phase 0 + diff review + credential exposure documentation` — toda la documentación nueva.
9. `chore(qa): safe-by-default Playwright config, mutation firewall, target safety` — `qa/**` restante.

Esto es una recomendación, no una ejecución — **no se ha creado ningún commit**. Dime si quieres que proceda con esta estructura (u otra), y en qué orden.

---

## Y. Revisiones de Seguridad Formales (exigidas por CLAUDE.md) — `Student` y JaaS/Jitsi

Estas son las dos pasadas dedicadas que CLAUDE.md exige explícitamente antes de mergear cualquier cambio a `Student` o a Jitsi/videollamadas — señaladas como pendientes en la sección X. Se hicieron leyendo el código real (`StudentController`, `StudentPolicy`, `Student.php`, `LessonController::join()`, `LessonPolicy`, `JaasService`, `config/jaas.php`, `routes/web.php`), no releyendo los reportes de los agentes anteriores.

### Y.1 — `Student` (soft-delete + anonimización, dato de menor)

**ACCESS — ¿puede un usuario no autorizado ver a un alumno?**
- `Route::resource('students', StudentController::class)->except(['show'])` — **no existe ninguna ruta `GET /students/{student}`**. No hay forma de pedir un alumno individual por ID; solo `index()` (`auth()->user()->students()->get()`, ya acotado al padre autenticado).
- `edit()`/`update()`/`destroy()` reciben el `Student` por route-model-binding (resuelve por ID, sin acotar por dueño), pero los 3 llaman `$this->authorize('update'|'delete', $student)` **antes** de leer/mutar nada. `StudentPolicy::update()`/`delete()` comprueban `$student->parent_user_id === $user->id`. Manipular el ID en la URL de `edit`/`update`/`destroy` de otro padre da 403, verificado también por el patrón ya usado en `CrossTenantAccessTest` para superficies equivalentes.
- **Veredicto: sin IDOR/BOLA encontrado.**

**MUTATION — ¿puede un usuario no autorizado modificar/borrar un alumno?**
- `store()` crea el alumno vía `auth()->user()->students()->create($data)` — `$data` es el resultado de `$request->validate()`, que **no incluye `parent_user_id`** entre sus reglas; no hay forma de que un atacante inyecte un `parent_user_id` distinto vía mass assignment (Laravel ignora claves no validadas explícitamente, y `create()` aquí se llama sobre la relación ya acotada, no sobre `Student::create($request->all())`).
- `update()`/`destroy()`: mismos guards de `StudentPolicy` que en ACCESS.
- **Defensa adicional, a nivel de modelo, no solo de controlador**: `Student::booted()` intercepta `forceDeleting` y lanza `RuntimeException` si `hasAcademicHistory()` es verdadero — esto protege incluso contra un `Student::forceDelete()` invocado desde **cualquier otro punto del código** (un comando de consola futuro, un job, tinker en producción), no solo desde `StudentController`. Es defensa en profundidad real, no solo un chequeo de ruta.
- **Veredicto: sin ruta de mutación no autorizada encontrada.**

**RETENTION — ¿qué queda después de "eliminar" a un alumno? (el hallazgo real de esta revisión)**
- Con historial académico (`hasAcademicHistory()`: tiene `classes` o `classRequests`): `anonymize()` sustituye `first_name`/`last_name`/`birth_date`/`school` **antes** del soft-delete — la fila que sobrevive no contiene PII real. Correcto.
- **Sin historial académico: el soft-delete ocurre, pero `anonymize()` nunca se llama** (`StudentController::destroy()` solo la invoca dentro del `if ($student->hasAcademicHistory())`). Confirmado por `StudentDeletionIntegrityTest::test_a_student_without_history_is_deleted_cleanly`: tras "eliminar" a un alumno sin historial, `Student::withTrashed()->find($student->id)->first_name` sigue siendo `'Pedro'` — el nombre real, la fecha de nacimiento y el colegio del menor **permanecen en la base de datos indefinidamente**, en una fila soft-deleted que nadie ve en la UI pero que sigue siendo una consulta SQL de distancia.
  - **Esto no rompe nada ni es explotable por un tercero** (sigue protegido por los mismos guards de ACCESS/MUTATION de arriba — no hay ruta que exponga alumnos soft-deleted a nadie). Pero es una divergencia real entre lo que el usuario probablemente espera ("eliminé a mi hijo") y lo que ocurre (los datos siguen ahí, sin ninguna razón de integridad para conservarlos — a diferencia del caso CON historial, aquí no hay ninguna `Lesson`/`ClassRequest` que dependa de la fila).
  - **Recomendación**: para el caso sin historial, no hay ninguna FK que impida un `forceDelete()` real — se podría hacer un borrado físico genuino en vez de un soft-delete sin anonimizar. Es una decisión de producto/privacidad pendiente, no un bug de seguridad explotable; se documenta aquí como hallazgo de RETENTION, no se corrige en este pase (fuera del alcance de "solo los fixes ya identificados").
- **Logs**: `grep -i "Log::.*student"` sobre `app/` → cero resultados. Ninguna ruta de código registra el nombre/fecha de nacimiento/colegio de un alumno en logs.
- **Notificaciones**: 5 clases (`ParentApprovalRequestNotification`, `NewClassRequestNotification`, `PaymentConfirmedNotification`, `LessonReportPublishedNotification`, `LessonSettledNotification`) referencian el nombre del alumno — verificado que en todos los casos el destinatario es el propio padre o el profesor ya asignado a esa clase específica (necesidad legítima de saber a quién está enseñando), nunca un tercero. No es una fuga, es información operativa necesaria para el destinatario correcto.
- **Exports/backups/attachments**: no se encontró ninguna funcionalidad de exportación de datos de alumnos en el código (ni CSV, ni PDF, ni API pública). Backups son responsabilidad de infraestructura (Railway/MySQL gestionado), fuera del alcance de una revisión de código — `UNKNOWN`, ya señalado como C4 en el mapa de deuda técnica.
- **Búsqueda/admin**: no existe ningún panel admin que liste o busque `Student` directamente (confirmado: `AdminController` no referencia el modelo `Student` en ningún método).

**Veredicto Y.1: el diseño de ACCESS y MUTATION es sólido, sin hallazgo explotable. RETENTION tiene un hallazgo real pero no explotable (PII de un alumno sin historial sobrevive indefinidamente sin anonimizar) — clasificado como mejora de privacidad pendiente, no como vulnerabilidad. Esto satisface la revisión de seguridad que CLAUDE.md exige para este cambio.**

### Y.2 — JaaS/Jitsi (`LessonController::join()`, `JaasService`, ventana de acceso)

| Pregunta | Respuesta, con evidencia |
|---|---|
| ¿Quién puede llamar a `/join`? | Ruta agrupada bajo `middleware('not.suspended')`, dentro del grupo autenticado general — cualquier usuario autenticado y no suspendido llega al método, pero `$this->authorize('view', $lesson)` es la primera línea del método (antes de cualquier otra lógica) |
| ¿Puede un usuario acceder al lesson de otro? | No — `LessonPolicy::view()`: `$isTeacher = ... teacher_profile_id === $profile->id`, `$isParent = ... student->parent_user_id === $user->id`. Sin ser ninguno de los dos, `view()` devuelve `false` → 403 |
| ¿El lesson pertenece al usuario autenticado? | Se verifica exactamente así arriba — por relación real (`teacher_profile_id`/`parent_user_id`), no por un campo copiado o inferible |
| ¿El token se emite únicamente dentro de la ventana? | Sí — dos `abort_if` (líneas 194 y 199 de `LessonController.php`) bloquean la generación del token fuera de `[start_time - 15min, end_time + 120min]` (configurable), **antes** de llegar a `$jaas->generateToken()`. Excepción deliberada y documentada para `status='paid'` (la clase ya ocurrió; acceso posterior para repasar es el comportamiento esperado, no una laguna) |
| ¿Puede reutilizarse el token? | Sí, dentro de su ventana de validez — es un JWT stateless, sin lista de revocación ni marca de "ya usado". Esto es una propiedad arquitectónica conocida y aceptada (no un descuido): la mitigación es que la ventana de validez ahora es corta y ligada a la clase real (antes 24h fijas), no que el token sea de un solo uso. Correcto para el caso de uso (un mismo alumno/profesor legítimamente necesita reconectarse si se cae la llamada) |
| ¿Puede manipularse el `lesson` ID para forzar otro token? | No — cambiar el `{lesson}` de la URL simplemente reevalúa `authorize('view', $lesson)` contra la NUEVA lección; sin relación real con ese `$lesson`, sigue siendo 403. No hay forma de "pedir prestado" el contexto de autorización de una lección a otra |
| ¿El JWT tiene expiración correcta? | Sí — `exp` se calcula desde la ventana real de la clase (o desde ahora+ventana de gracia si `status='paid'`), con un piso mínimo de 5 minutos (`max($exp, $now + 300)`) para no emitir nunca un token ya vencido por desfase de reloj |
| ¿El `roomName` está ligado al lesson? | Sí — `$lesson->jitsi_room` (generado como `Str::random(32)`, único por lección) viaja como claim `room` del JWT. Un token de la lección A no sirve para la lección B porque el room name es distinto — **esto depende de que JaaS aplique el claim `room` como restricción real en su lado** (comportamiento estándar documentado del protocolo JaaS, pero no verificable sin una llamada real contra JaaS — coincide con el `UNKNOWN-03` ya señalado en el propio código) |
| ¿Puede un usuario obtener un token y reutilizarlo después de que termine su relación con la clase? | Dentro de la ventana de validez del JWT, sí (propiedad ya cubierta arriba) — pero la ventana ahora está acotada a la clase real, no a 24h. Fuera de esa ventana, un nuevo intento de generar token pasa de nuevo por `authorize()` + los `abort_if` de tiempo, así que no hay forma de obtener un token *nuevo* fuera de ventana aunque se reintente |
| ¿Existe alguna ruta alternativa que emita token? | No — `grep` confirma que `JaasService::generateToken()` se invoca desde un único call site en todo `app/`: `LessonController.php:214` |
| ¿Las notificaciones contienen todavía información sensible (`jitsi_room`/token)? | No — cubierto por `tests/Feature/NotificationSecurityTest.php`, que usa el tokenizer real de PHP (`token_get_all()`, no un regex ingenuo que un comentario pudiera engañar) para confirmar que ningún archivo de `app/Notifications/*.php` incluye el valor real de la sala. Ejecutado en esta misma ronda de revisión (ver sección X) — pasa |
| ¿Puede un admin entrar a cualquier videollamada? | Sí, deliberadamente — `LessonPolicy::before()` da bypass total a `hasRole('admin')`, igual que las otras 6 Policies del sistema. Es consistente con el resto del modelo de confianza de admin (ya puede suspender, forzar reembolsos, verificar profesores) — no es un hallazgo nuevo, es el mismo patrón de confianza ya existente aplicado aquí también |

**Veredicto Y.2: la ventana de acceso es server-authoritative, la autorización por lección es correcta, no se encontró ninguna ruta de bypass. El único punto que sigue como `UNKNOWN` (ya reconocido honestamente en el propio código, no descubierto ahora) es si JaaS aplica de verdad el claim `room` como aislamiento entre salas — requiere una prueba contra JaaS real, imposible de verificar desde el código. Esto satisface la revisión de seguridad que CLAUDE.md exige para este cambio, con esa única reserva explícita.**

---

## Z. Checkpoint Final Status — post-fixes, pre-commit

**Snapshot:** `HEAD`=`origin/master`=`692b3651...` (0 divergencia), 2 staged / 69 modificados sin stage / 100 untracked (expandido) — el mismo estado de siempre más los archivos que esta fase tocó. Timestamp: `2026-08-27T03:56:20Z`.

Se ejecutaron, en este orden, los 6 fixes acotados que el checkpoint requería (nada más — ninguna otra modificación, ninguna nueva feature, ningún refactor no relacionado):

1. `Students/Create.vue` — eliminado el `<p>` legado duplicado para `first_name`/`last_name`; solo queda `<InputError>`.
2. `ClassOffers/Edit.vue` — nuevo `availabilityFieldErrors` (computed) que traduce cada clave anidada (`availability_schedule.days.{día}.{índice}.{start|end}`) a un mensaje legible, más un `watchEffect` que auto-expande la sección de disponibilidad si llega un error mientras está colapsada.
3. Revisión de seguridad formal de `Student` — sección Y.1. Veredicto: sin hallazgo explotable; un hallazgo real de RETENTION (PII sin anonimizar en alumnos sin historial) documentado como mejora de privacidad pendiente, no como vulnerabilidad.
4. Revisión de seguridad formal de JaaS/`LessonController::join()` — sección Y.2. Veredicto: autorización correcta, ventana server-authoritative, sin ruta de bypass; único `UNKNOWN` residual (aislamiento de sala del lado de JaaS) ya reconocido en el propio código, no nuevo.
5. `SETUP.md` + `.telemetry/product.md` — todas las menciones activas a Zoom corregidas a JaaS/Jitsi (9 líneas en total entre ambos archivos); las menciones que quedan son explícitamente "esto se eliminó", no afirmaciones de que Zoom siga en uso.
6. `docs/MOVA_SYSTEM_KNOWLEDGE.md` — las 6 citas originalmente muestreadas más 4 adicionales encontradas al verificarlas (10 en total) corregidas con el número de línea real; añadido un aviso explícito al inicio del documento de que el resto de citas no fue re-verificado línea por línea. `docs/MOVA_QA_SECURITY_POLICY.md` — la frase sobre el firewall "nunca a discreción de un spec individual" corregida para reflejar la dependencia real de qué módulo importa cada spec futuro.

**Re-verificación tras los fixes:** `php artisan test` → **454 passed (1328 assertions)**, sin regresión — idéntico al conteo de antes de estos 6 cambios (ninguno tocó lógica de backend). `npm run build` → compila limpio, 14.87s, sin errores ni warnings — confirma que los cambios en los 2 archivos Vue son sintácticamente correctos. Barrido de secretos repetido sobre los ~150 archivos (incluidos los 6 recién tocados): cero coincidencias reales (2 falsos positivos, ambos texto explicativo sobre la propia metodología de búsqueda, no valores).

### Re-clasificación de los ítems `KEEP_WITH_FIX` de la sección X

| Ítem | Estado antes | Estado ahora |
|---|---|---|
| `Students/Create.vue` (mensaje duplicado) | KEEP_WITH_FIX | ✅ **KEEP** — corregido y verificado (build limpio) |
| `ClassOffers/Edit.vue` (validación anidada) | KEEP_WITH_FIX | ✅ **KEEP** — corregido y verificado (build limpio) |
| Revisión de seguridad `Student` | Pendiente | ✅ **KEEP** — completada, sección Y.1, sin bloqueante |
| Revisión de seguridad JaaS | Pendiente | ✅ **KEEP** — completada, sección Y.2, sin bloqueante |
| `SETUP.md`/`.telemetry/product.md` (Zoom) | KEEP_WITH_FIX | ✅ **DOCS_ONLY** — corregido |
| `MOVA_SYSTEM_KNOWLEDGE.md` (citas) | KEEP_WITH_FIX | ✅ **DOCS_ONLY** — corregido, con aviso honesto sobre el resto del documento |
| `MOVA_QA_SECURITY_POLICY.md` (frase) | DOCS_ONLY (con nota) | ✅ **DOCS_ONLY** — corregido |
| `RechargeApprovalService::reverse()` sin endpoint | KEEP_WITH_FIX | Sin cambios — sigue **KEEP_WITH_FIX** (no estaba en el alcance de este pase; es trabajo futuro reconocido, no bloqueante) |
| F-26/F-27 (5 archivos en HEAD/origin) | BLOCKED (decisión del usuario) | Sin cambios — sigue **BLOCKED**, es la única decisión que no le corresponde tomar a esta fase |

### CHECKPOINT READY

```
CODE CHECKPOINT        ✅ READY
SECURITY REPOSITORY    ❌ NOT CLEAN  (F-26/F-27 siguen en HEAD/origin/master)
PRODUCTION READINESS   ❌ BLOCKED    (depende de rotar la credencial + decidir sobre el historial)
```

Adoptando explícitamente tu distinción: el **código** está listo para convertirse en checkpoints de git coherentes y revisados. El **repositorio como sistema de secretos** no lo está, y esa es una capa distinta que un commit de código, por bueno que sea, no puede resolver por sí solo.

**BLOCKERS:** ninguno de código. Uno de decisión: los 5 archivos de F-26/F-27 siguen en `HEAD`/`origin/master` — el checkpoint debe incluir su eliminación (commit forward, no reescritura de historia) o quedar documentado explícitamente que se pospuso a propósito.

**REQUIRED FIXES:** ninguno pendiente de los identificados — los 6 se completaron en este pase. Queda 1 ítem no-bloqueante de seguimiento: `RechargeApprovalService::reverse()` sin endpoint/UI admin (trabajo futuro reconocido, no un defecto).

**SAFE TO COMMIT:** todo lo descrito en la sección X (12 grupos backend, 3 módulos WhatsApp/Payments/ProviderGuard, 9 grupos frontend, 13 archivos de test, toda la documentación, config/QA) **más** los 6 fixes de este pase.

**REMOVE BEFORE COMMIT:** nada.

**SECURITY STATUS:** F-26/F-27 sin cambios de estado (siguen en HEAD/origin/master, ver sección G) — **no se rotó ninguna credencial, no se tocó git history, no se hizo push, tal como exigía la prohibición explícita de este pase.** Ninguna otra preocupación de seguridad nueva; las dos revisiones formales pendientes (Y.1/Y.2) ya están cerradas.

**TEST STATUS:** 454/454 passed, 1328 assertions, 0 fallos, 0 warnings nuevos. Reproducido después de los 6 fixes, no solo antes.

**PRODUCTION PARITY:** sin cambios desde la sección W — no se tocó infraestructura ni configuración de entornos en este pase, tal como se pidió explícitamente.

**COMMIT GROUPS:** la propuesta de 9 commits de la sección X sigue vigente; no se ha creado ningún commit.

**PUSH BLOCKERS:** F-26/F-27 en `origin/master` — hacer `git push` de los commits nuevos sin antes rotar la credencial y decidir sobre el historial **no soluciona la exposición existente**, solo añade commits limpios encima de un remoto que ya tiene el secreto. Orden correcto, ya documentado en `MOVA_CREDENTIAL_EXPOSURE.md`: commits locales → (opcional) revisión de diff vs. `origin/master` → rotación de credencial → verificación → recién entonces decidir push/purga de historial. **No se ha hecho push de nada.**

**Criterio de éxito de esta fase — respuesta explícita:** sí, el working tree actual representa un conjunto de cambios coherente, revisado línea por línea por 4 dominios + 2 revisiones de seguridad dedicadas, testeado (454/454), libre de secretos nuevos, y preparado para convertirse en los 9 checkpoints de git propuestos — **con la salvedad explícita, no oculta, de que 5 de esos archivos futuros deben ser una eliminación, no una adición, y de que ningún push debe ocurrir antes de rotar la credencial de F-26.**

---

## Última verificación (checklist de honestidad, antes de cerrar este documento)

1. **¿Se inspeccionó realmente todo lo relevante?** No al 100% — ver sección S para lo explícitamente no cubierto (frontend completo pixel-a-pixel, concurrencia real, `docs/HANDOFF_FINAL.md` completo, credenciales reales).
2. **¿Qué no se pudo inspeccionar?** Todo lo que requiere acceso externo (Railway, cuentas reales de Meta/Culqi/JaaS/Cloudinary/Sentry), interacción manual en navegador, o conectarse con una credencial potencialmente comprometida — listado explícitamente en S.
3. **¿Qué requiere interacción manual?** Verificación visual en breakpoints, percepción de fricción UX, foco de teclado real, race conditions con dos pestañas reales.
4. **¿Qué requiere credenciales?** Todo lo de S relacionado con proveedores externos y con confirmar si la contraseña de MySQL sigue activa.
5. **¿Qué está confirmado?** Todo lo marcado PROVEN/CONFIRMED/NOT APPLICABLE (verificado) en las secciones G, Q, R — con evidencia de archivo:línea citada en los apéndices.
6. **¿Qué es hipótesis?** Todo lo marcado LIKELY, UNKNOWN, o REQUIERE VERIFICACIÓN.
7. **No se declara MOVA "seguro", "listo para producción" ni "sin bugs".** Se declara: el núcleo financiero/autorización está bien construido y bien defendido en el código que existe hoy; ese código en su mayoría no está commiteado; hay 2 hallazgos P0 de proceso/exposición de secretos que deben resolverse antes de cualquier otra cosa; hay 4 bugs funcionales confirmados que siguen abiertos (BUG-2 a BUG-5, más N1) y 1 (BUG-1) que ya se corrigió pero solo en el checkout principal sin commitear; y una lista extensa de UNKNOWN que una fase futura (o una interacción con Railway/navegador real) debe cerrar.
8. **Este documento en sí mismo tuvo que corregirse una vez ya escrito** (secciones K, Q, T, V, W): los 6 agentes de la Fase 0 auditaron un checkout de `HEAD`, no el checkout principal — cualquier hallazgo sobre un archivo que aparezca en `git status` como modificado debe re-verificarse contra el código actual antes de actuar sobre él, exactamente como se hizo aquí con BUG-1. Es la misma lección de "está probado ≠ es correcto" aplicada, esta vez, a la propia auditoría.
