# Auditoría de backend core — MOVA (dominio: Controllers/Models/Policies/Console/Listeners/Providers/config/routes, excluyendo WhatsApp y Payments internos)

## 0. Snapshot auditado

**Snapshot auditado: working tree actual, HEAD=692b365, con cambios sin commitear incluidos — NO un checkout aislado.**

Todo lo revisado a continuación se leyó directamente del árbol de trabajo real en
`C:\Users\ABEL\OneDrive\Desktop\ProyectoMOVA` (mismo proceso que ejecutó `php artisan test`),
no de un `git worktree` ni de un checkout separado. Los diffs se obtuvieron con
`git diff -- <archivo>` contra HEAD y los archivos nuevos se leyeron completos.

---

## 1. Grupos de cambios

### Grupo 1 — F-01: Guard de borrado de `User` independiente del motor de BD

**Archivos:** `app/Models/User.php` (`booted()`, `hasProtectedHistory()`), `app/Http/Controllers/ProfileController.php:356-360` (usa `$user->hasProtectedHistory()` en vez de un método privado duplicado).

- **A. Intención:** que ningún camino de borrado de un `User` con historial financiero/académico pueda saltarse la protección, incluso en SQLite (tests) donde las FK no son RESTRICT como en MySQL.
- **B. Corrección:** sí. `static::deleting()` en `User::booted()` (líneas 402-413) llama a `hasProtectedHistory()` y lanza `RuntimeException` si hay `creditTransactions`, `rechargeRequests`, `classes` del profesor, o `ClassRequest`/`Lesson` de los alumnos del padre. `ProfileController::destroy()` ahora reutiliza el mismo método (antes tenía su propia copia privada `hasProtectedHistory()` que se eliminó, líneas 368-386 del diff).
- **C. Integración:** consistente — mismo patrón que el guard de `Student::booted()` (Grupo 2) y coherente con las migraciones RESTRICT ya existentes de MySQL (`2026_07_10_000002_protect_monetization_history`, no tocada en este diff).
- **D. Seguridad:** reduce riesgo — cierra el hueco de que un `$user->delete()` directo en SQLite/tests destruyera el ledger sin que ningún test lo detectara.
- **E. Datos:** protege integridad del ledger financiero y del historial de clases. No cambia el camino de `forceDelete` (fuera del alcance de este guard, igual que en `Student`).
- **G. Testing:** `tests/Feature/FinancialHistoryDurabilityTest.php` — ejecutado con `php artisan test --filter=FinancialHistoryDurabilityTest`, **9/9 PASS**, incluye `has protected history is the single source of truth`.
- **H. Regresión:** ninguna detectada — un `DELETE` por SQL crudo sigue sin pasar por Eloquent (documentado explícitamente en el docblock, líneas 396-400).
- **I. Mantenibilidad:** mejora — elimina duplicación entre `ProfileController` y ahora un único punto de verdad en el modelo.
- **J. Producción:** seguro.

### Grupo 2 — F-18: Soft delete + anonimización de `Student`

**Archivos:** `database/migrations/2026_08_24_000003_add_soft_deletes_to_students_table.php`, `app/Models/Student.php` (`SoftDeletes`, `booted()`, `hasAcademicHistory()`, `anonymize()`), `app/Http/Controllers/StudentController.php:407-423`, y `withTrashed()` añadido a `Student::belongsTo` en `ClassRequest.php`, `Lesson.php`, `LessonReport.php`, `StudentDiagnostic.php`, `TeacherReview.php`.

- **A. Intención:** `StudentController::destroy()` hacía `$student->delete()` físico sin comprobar nada; con historial (`classes`/`class_requests`) eso era un 500 crudo en MySQL (FK RESTRICT) o borrado en cascada de las clases en SQLite/tests — exactamente el error metodológico que motivó esta auditoría en fases previas (mencionado explícitamente en el contexto de la tarea).
- **B. Corrección:** sí, y de forma consistente entre capas:
  - `StudentController::destroy()` (líneas 407-423): si `hasAcademicHistory()`, llama `anonymize()` (sustituye `first_name`/`last_name`/`birth_date`/`school`) **antes** del soft delete.
  - `Student::booted()` bloquea `forceDelete()` si hay historial (mismo patrón que `User`).
  - Las 5 relaciones `belongsTo(Student::class)` en modelos históricos ahora usan `->withTrashed()` — verificado que sin esto, `ClassRequest::student()`, `Lesson::student()`, etc. devolvían `null` tras el soft delete, rompiendo silenciosamente `?->parent?->notify()` en notificaciones de cancelación/reembolso.
- **C. Integración:** coherente con el patrón ya usado por `User::hasProtectedHistory()` (mismo criterio a nivel de alumno).
- **D. Seguridad/Privacidad (menor de edad):** la anonimización ocurre **antes** de soft-delete, así que la fila que sobrevive no contiene PII del menor. Esto es exactamente el tipo de cambio que CLAUDE.md exige pasar por revisión de seguridad explícita (toca `Student`) — la implementación revisada aquí es razonable, pero **no hay evidencia en el diff de que se haya ejecutado una pasada de seguridad dedicada** más allá de los tests (ver sección 5).
- **E. Datos:** preserva integridad referencial de `classes`/`class_requests`/`lesson_reports`/`student_diagnostics`/`teacher_reviews` contra un alumno dado de baja.
- **G. Testing:** `tests/Feature/StudentDeletionIntegrityTest.php` — **12/12 PASS**, incluyendo "deleting a student with lessons preserves the ledger", "the minors personal data is scrubbed when the row must survive", "force deleting a student with history is refused", "historical lessons still resolve their student after deletion".
- **H. Regresión:** cualquier query existente sobre `Student` que no filtre `deleted_at` explícitamente ahora los excluye por defecto (comportamiento estándar de `SoftDeletes` en Eloquent) — es el comportamiento deseado para "desaparece de los listados del padre". No se detectó ningún `Student::withTrashed()` faltante fuera de los 5 modelos ya corregidos (búsqueda dirigida hecha vía diff, no una búsqueda global adicional).
- **I. Mantenibilidad:** buena — el criterio de "tiene historial" está centralizado en `hasAcademicHistory()`.
- **J. Producción:** seguro, con la salvedad de la nota D.

### Grupo 3 — F-06: Ventana de acceso a la sala JaaS aplicada en el servidor

**Archivos:** `app/Http/Controllers/LessonController.php:174-313`, `config/jaas.php` (nuevas claves `join_window_before_minutes`, `join_grace_after_minutes`), `app/Services/JaasService.php` (fuera del dominio estricto asignado, pero verificado por integración: `generateToken()` ahora acepta `?\DateTimeInterface $expiresAt`).

- **A. Intención:** antes, la regla "la sala se abre 15 min antes" solo vivía en `resources/js/utils/lessonJoin.js` (frontend); un POST directo a `LessonController::join()` devolvía un JWT válido por 24h fijas días antes de la clase — divergencia de autorización real, no solo de UX, con menores de edad de por medio en la videollamada.
- **B. Corrección:** sí. `join()` (líneas 288-298) calcula `opensAt`/`closesAt` desde `config('jaas.*')` y usa `abort_if` (403) fuera de la ventana, exceptuando `status === 'paid'` a propósito (la clase ya ocurrió, acceso posterior para repasar es comportamiento esperado). El JWT expira ahora acotado a esa misma ventana (`$tokenExpiresAt`, líneas 306-308) en vez de 24h fijas.
- **C. Integración:** `JaasService::generateToken()` mantiene compatibilidad retro (`$expiresAt = null` → fallback a 24h, con mínimo operativo de 5 min si el cálculo cae en el pasado) — buen diseño defensivo.
- **D. Seguridad:** reduce significativamente la ventana de exposición de un JWT filtrado (historial de navegador, DevTools, proxy corporativo) de 24h a la ventana real de la clase + gracia configurable (120 min por defecto). Dado que la videollamada involucra menores, este es exactamente el tipo de cambio que CLAUDE.md marca para revisión de seguridad explícita — el razonamiento en el propio código (docblock de `config/jaas.php`) es sólido y admite la incertidumbre pendiente (`UNKNOWN-03` sobre cómo reacciona JaaS a un `exp` que vence a mitad de llamada).
- **E. Datos:** no toca el ledger.
- **G. Testing:** `tests/Feature/JitsiAccessWindowTest.php` — **12/12 PASS**: "joining too early is refused by the backend", "the jwt no longer lives for 24 hours", "the jwt is still scoped to the specific room", "an unrelated user is still refused even inside the window", etc.
- **H. Regresión:** clases ya en estado `'paid'` siguen accesibles indefinidamente dentro de la ventana de gracia — comportamiento previo preservado deliberadamente.
- **I. Mantenibilidad:** buena — configuración centralizada, sin lógica duplicada entre frontend y backend (el frontend sigue teniendo su propia copia en `lessonJoin.js`, que ahora es solo UX, no la autorización real).
- **J. Producción:** seguro, condicionado a que se confirme UNKNOWN-03 contra JaaS real antes de reducir `join_grace_after_minutes` (advertencia ya documentada en el propio código).

### Grupo 4 — F-04: Recordatorios de clase — claim atómico + despacho dentro de transacción

**Archivos:** `app/Console/Commands/SendClassReminders.php` (nuevo método `claimAndDispatch()`, reescritura de `send24hReminders`/`send2hReminders`/`send10mReminders`/`sendPendingReportAlerts`/`sendUnansweredRequestAlerts`), `app/Console/Kernel.php` (`withoutOverlapping()` en `classmate:send-reminders`).

- **A. Intención:** el patrón anterior (SELECT → notify() → update()) dejaba una ventana de carrera entre dos ejecuciones del scheduler (corre cada minuto, el barrido puede tardar más). Un intento previo de arreglo (UPDATE primero, notify después, sin transacción) cambiaba "duplicado" por "pérdida permanente" si el proceso moría entre medias.
- **B. Corrección:** sí, con razonamiento explícito y correcto sobre los niveles de garantía (documentado en el propio código, líneas 22-129): claim+encolado es exactly-once porque `ShouldQueue` con `QUEUE_CONNECTION=database` inserta en la misma conexión transaccional; el procesamiento del job y la entrega externa siguen siendo at-least-once (no se puede evitar). Esto es honesto y verificable — no promete "exactly-once delivery" donde no puede.
- **C. Integración:** la dependencia de `QUEUE_CONNECTION=database` está explícitamente vigilada por `mova:health-check` (`QUEUE_NOT_TRANSACTIONAL`, ver `HealthCheck.php:89-102`) — cierre correcto del ciclo diseño→verificación operativa.
- **D. Seguridad:** no aplica directamente, pero mejora fiabilidad de notificaciones (relevante porque incluye avisos a padres sobre clases de menores).
- **E. Datos:** el claim usa `whereNull`/`where` + `update()` con recomprobación de `status='scheduled'` dentro de la misma transacción — patrón correcto de optimistic-claim.
- **G. Testing:** `tests/Feature/ReminderClaimRecoveryTest.php`, `ReminderConcurrencyTest.php`, `ReminderDeliverySemanticsTest.php` — todos ejecutados, **PASS** ("the claim is atomic so only one competing run wins", "a lesson cancelled between select and claim is not notified", "a worker crash after commit does not release the claim", "external delivery is at least once and this is documented").
- **H. Regresión:** ninguna detectada.
- **I. Mantenibilidad:** el método `claimAndDispatch()` generaliza bien los 3 casos de recordatorio de clase; `sendPendingReportAlerts` y `sendUnansweredRequestAlerts` no lo reutilizan literalmente (tienen su propia transacción inline) pero siguen el mismo principio — leve duplicación de patrón, no de lógica de negocio.
- **J. Producción:** seguro **siempre que** `QUEUE_CONNECTION=database` en producción — verificar esto es responsabilidad de `mova:health-check`, que ya lo cubre.

### Grupo 5 — F-10: Resolución unificada de destinatarios de solicitudes de clase

**Archivos:** `app/Models/ClassRequest.php` (`eligibleTeacherUsers()`), `app/Listeners/SendClassRequestNotifications.php`, `app/Console/Commands/SendClassReminders.php::sendUnansweredRequestAlerts()`.

- **A. Intención:** `SendClassReminders::sendUnansweredRequestAlerts()` reimplementaba solo el caso "vía `classOffer`"; las solicitudes por código de referido (`teacher_profile_id`) y las abiertas (el flujo mayoritario, todos los profesores verificados de la materia) no tenían destinatario en ese recordatorio, y aun así se marcaban como avisadas (`request_reminder_sent_at`) — perdiéndose para siempre.
- **B. Corrección:** sí. `ClassRequest::eligibleTeacherUsers()` centraliza los 3 casos (líneas 51-72 del diff de Models). `SendClassRequestNotifications` (el listener de creación) y `SendClassReminders` ahora comparten el mismo método — no pueden divergir. Además, `sendUnansweredRequestAlerts()` ya **no marca** `request_reminder_sent_at` cuando `eligibleTeacherUsers()` está vacío (antes sí lo hacía, perdiendo la solicitud para siempre); ahora queda candidata para la siguiente pasada.
- **C. Integración:** correcto — el método vive en el modelo, no duplicado entre listener y comando.
- **D. Seguridad:** no aplica.
- **E. Datos:** cambia semántica de "cuándo se marca como avisada" — solo se marca si hubo al menos un destinatario real. Esto es correcto pero cambia el comportamiento observable (antes toda solicitud vieja se marcaba, ahora las sin destinatario no) — documentado y probado.
- **G. Testing:** `tests/Feature/ReminderDeliverySemanticsTest.php`/`ReminderConcurrencyTest.php` — "a request without any eligible teacher is not marked as alerted", "each eligible teacher receives the unanswered alert exactly once" — **PASS**.
- **H. Regresión:** ninguna detectada; `unique('id')` en los destinatarios evita duplicados si un profesor apareciera dos veces.
- **I. Mantenibilidad:** mejora clara — elimina lógica duplicada previamente esparcida en 2 sitios.
- **J. Producción:** seguro.

### Grupo 6 — F-16/F-17: `RechargeApprovalService` (extracción) + `RechargeRequestPolicy` (legibilidad) + reversión de recargas

**Archivos:** `app/Services/RechargeApprovalService.php` (nuevo), `app/Http/Controllers/Admin/RechargeController.php::approve()`, `app/Policies/RechargeRequestPolicy.php`, migraciones `2026_08_23_000001`/`000002`, `app/Models/RechargeRequest.php` (campos `reversed_*`, relaciones `reversedBy()`/`paymentOrder()`).

- **A. Intención:** (1) que la aprobación manual de un admin y la futura acreditación automática por webhook de pago (`PaymentWebhookService`, dominio del otro agente) compartan **el mismo** lock+idempotencia+transacción, evitando que diverjan como ya pasó antes con C-1; (2) permitir revertir una recarga ya aprobada (refund/chargeback) sin destruir el ledger append-only; (3) hacer que `RechargeRequestPolicy` sea legible método por método en vez de depender silenciosamente de un `before()`.
- **B. Corrección:**
  - `RechargeApprovalService::credit()` es una extracción literal del código que antes vivía en el controlador — comportamiento preservado, confirmado por diff línea a línea (mismo `idempotency_key`, mismo lock order: `RechargeRequest` primero, luego `TeacherProfile`).
  - `RechargeApprovalService::reverse()` (líneas 90-136): crea un asiento `'reversal'` con `amount` **negativo** (no reutiliza `'refund'`, que en `LedgerReconciliation::derivedAvailableFor()` suma — un `reversal` de depósito debe restar; ver el propio comentario de la migración `2026_08_23_000001`). Documenta explícitamente que `credits_available` puede quedar negativo si el profesor ya gastó los créditos — decisión de producto asumida, no un bug oculto.
  - `RechargeRequestPolicy`: se eliminó el `before()` que otorgaba todo a admin de forma implícita; ahora `approve()`, `reject()`, `reverse()` declaran explícitamente `$user->hasRole('admin')`. El razonamiento del propio código (F-17) es válido: el historial de MOVA incluye un caso real donde una Policy con lógica implícita ocultó un bug de seguridad (`ClassRequestPolicy::accept()` sin `is_verified`).
- **C. Integración:** correcta — `RechargeController::approve()` ahora delega en el servicio (`app(RechargeApprovalService::class)->credit(...)`), sin lógica financiera inline.
- **D. Seguridad:** el cambio de Policy es una mejora de legibilidad/defensa en profundidad, no un cambio de superficie de ataque (mismo resultado: solo admin puede aprobar/rechazar/revertir). Verificado con `tests/Feature/CrossTenantAccessTest.php::only admins can approve reject or reverse a recharge` — PASS.
- **E. Datos:** el ledger sigue siendo append-only; la reversión no edita ni borra el depósito original.
- **G. Testing:** `tests/Feature/PaymentOrderTest.php` (dominio compartido con Payments) prueba `reverse()` directamente: "reverse approved recharge creates reversal and debits balance", "reverse is idempotent", "a reversal can leave balance negative if already spent", "cannot reverse a recharge that was never approved" — todos **PASS**. `MonetizationIntegrityTest`/`FinancialConcurrencyTest` — **PASS** (181 tests en conjunto, ver sección 5 de metodología).
- **H. Regresión:** ninguna — `RechargeController::reject()` no fue tocado y sigue funcionando igual.
- **I. Mantenibilidad:** mejora — un solo punto de verdad para tocar el ledger de recargas.
- **J. Producción:** seguro, **pero ver hallazgo de la sección 2**: `reverse()` no tiene ningún endpoint HTTP ni ruta admin que lo invoque todavía.

### Grupo 7 — F-02: Modo de liquidación automática (C-1) configurable, no hardcodeado

**Archivos:** `app/Support/SettlementMode.php` (nuevo), `config/credits.php` (`settlement_mode`), `app/Console/Kernel.php`, `app/Console/Commands/SettleLessons.php` (opción `--json`, preflight de anomalías de ledger).

- **A. Intención:** el comando `mova:settle-lessons` estaba agendado con `--dry-run` **hardcodeado** en `Kernel.php` — C-1 llevaba meses "implementado" pero operacionalmente inactivo, sin que nada lo señalara.
- **B. Corrección:** sí. `SettlementMode::current()` lee `credits.settlement_mode` (env `LESSON_SETTLEMENT_MODE`, default `dry_run`) y **lanza** si el valor no es `dry_run`/`live` — evita degradar silenciosamente a ningún comportamiento por un typo. `Kernel.php` construye el comando condicionalmente: `SettlementMode::isLive() ? '' : ' --dry-run'`. El default sigue siendo `dry_run`, así que el comportamiento actual no cambia sin una decisión explícita — correcto.
- **C. Integración:** `mova:health-check` marca `SETTLEMENT_DRY_RUN_IN_PRODUCTION` como advertencia si `environment===production && !isLive()` — cierra el ciclo de visibilidad.
- **D. Seguridad:** no aplica directamente, pero evita que dinero/créditos queden en un estado ambiguo sin que nadie lo note.
- **E. Datos:** `SettleLessons::settleGraceExpired()` ahora hace un preflight que cuenta cuántas `CreditTransaction` tipo `reservation` respaldan cada lección candidata **antes** de tocar nada — si no es exactamente 1, se reporta como anomalía en el `--json` (`safe_to_enable` se pone en `false`). Buena defensa: detecta un ledger corrupto antes de operar sobre él, en vez de que `LessonSettlementService` lo descubra a mitad de la liquidación real.
- **G. Testing:** `tests/Feature/SchedulerConfigurationTest.php` — **19/19 PASS**, incluye "dry run mode schedules the command with the dry run flag", "an invalid settlement mode aborts instead of guessing", "health check flags dry run settlement as dangerous in production".
- **H. Regresión:** ninguna — comportamiento por defecto sin cambios (`dry_run`).
- **I. Mantenibilidad:** buena, config centralizada.
- **J. Producción:** seguro. Activar `live` sigue siendo una decisión de negocio explícita, correctamente gateada.

### Grupo 8 — F-03: `ProviderGuard` fail-closed para proveedores de Pago/WhatsApp

**Archivos:** `app/Providers/AppServiceProvider.php` (bindings de `PaymentProviderContract`/`WhatsAppProviderContract`), `config/payments.php` (nuevo), `config/services.php` (`whatsapp.provider`), `app/Support/ProviderGuard.php` (fuera del dominio Payment/WhatsApp interno, pero el *binding* en `AppServiceProvider` sí es mi dominio).

- **A. Intención:** antes existía (según el propio comentario del diff) una rama `default => new FakePaymentProvider()` que convertía cualquier valor no reconocido de `PAYMENT_PROVIDER` en el proveedor falso — que responde `'paid'` a todo sin cobrar nada realmente. Un typo o un `config:cache` prematuro podía dejar producción "aceptando pagos" con el proveedor Fake sin que nadie lo notara.
- **B. Corrección:** sí. El binding en `AppServiceProvider::register()` (líneas 742-785) usa `match` **sin rama `default`** — si `ProviderGuard::resolve()` devuelve algo no manejado, PHP lanza `UnhandledMatchError` en vez de caer al Fake silenciosamente. `ProviderGuard::resolve()` (no leído en detalle por estar fuera del dominio estricto, pero su contrato se verifica vía `ProviderGuardTest`) aborta el arranque si el Fake se usa en producción con la feature habilitada.
- **C. Integración:** consistente entre pagos y WhatsApp — mismo patrón de guard para ambos proveedores.
- **D. Seguridad:** mejora significativa — cierra un fail-open (degradación silenciosa a un proveedor simulado) convirtiéndolo en fail-closed (arranque abortado).
- **E. Datos:** no aplica directamente (a nivel de binding).
- **G. Testing:** `tests/Feature/ProviderGuardTest.php` — **14/14 PASS**: "an unknown provider aborts instead of falling back to fake", "fake payments in production with payments enabled aborts", "the container binding aborts on an unknown payment provider".
- **H. Regresión:** ninguna — en local/testing con `provider=fake` y feature deshabilitada, sigue funcionando igual.
- **I. Mantenibilidad:** buena.
- **J. Producción:** seguro.

### Grupo 9 — Consentimiento explícito de WhatsApp (opt-in/opt-out) separado de la verificación de teléfono

**Archivos:** `app/Models/User.php` (`wantsWhatsAppNotifications()`, `optInToWhatsApp()`, `optOutOfWhatsApp()`), `app/Http/Controllers/Auth/PhoneVerificationController.php::verify()`, `app/Http/Controllers/NotificationPreferencesController.php` (nuevo), `routes/web.php` (`profile/notifications`), migración `2026_08_24_000004_add_whatsapp_consent_to_users_table.php`.

- **A. Intención:** antes, verificar el teléfono (`phone_verified_at`) fijaba automáticamente `whatsapp_opt_in_at=now()` como efecto colateral — mezclaba "demostró controlar el número" (autenticación) con "aceptó recibir avisos" (consentimiento/decisión de producto). Sin poder justificar por qué se envía un mensaje a alguien concreto.
- **B. Corrección:** sí. `PhoneVerificationController::verify()` ahora valida `whatsapp_notifications => 'sometimes|boolean'` y solo hace `$user->whatsapp_opt_in_at = now()` si la casilla vino marcada **y** no hay opt-out previo (líneas 178-191 del diff). El OTP en sí (`sendWhatsAppCode`) sigue sin depender de ningún opt-in — correcto, porque lo pide el propio usuario al pulsar "enviar código". `NotificationPreferencesController::update()` permite cambiar la preferencia después, independiente de la verificación.
- **C. Integración:** `User::wantsWhatsAppNotifications()` da prioridad al opt-out sobre cualquier opt-in anterior ("la baja gana siempre") — correcto y explícito.
- **D. Seguridad/Privacidad:** mejora la trazabilidad de consentimiento — relevante porque WhatsApp toca datos de contacto de adultos (padres/profesores), no de menores directamente, pero **la migración** (`2026_08_24_000004`) documenta explícitamente que se decidió **no hacer backfill** de `whatsapp_opt_in_at = phone_verified_at` para usuarios existentes — decisión correcta y bien razonada (evita inferir consentimiento retroactivo), con el costo operativo aceptado y documentado ("usuarios existentes dejan de recibir notificaciones hasta reconfirmar").
- **E. Datos:** no toca ledger.
- **G. Testing:** cubierto indirectamente por `tests/Feature/NotificationSecurityTest.php` (dominio compartido) y pruebas de WhatsApp fuera de mi alcance estricto; el flujo de `PhoneVerificationController` en sí no tiene un test dedicado a la nueva casilla `whatsapp_notifications` visible en mi barrido — ver sección 5.
- **H. Regresión:** el rate-limit por número (`PHONE_RATE_LIMIT_*`, líneas 89-118 del diff de `PhoneVerificationController`) es un añadido nuevo e independiente — cierra un hueco real (el throttle de ruta es por usuario autenticado, no por número; alguien podía crear N cuentas para floodear el mismo teléfono con OTPs). Correcto y no interfiere con el resto.
- **I. Mantenibilidad:** buena — lógica de consentimiento centralizada en el modelo `User`.
- **J. Producción:** seguro.

### Grupo 10 — F-11: Documentación de la máquina de estados de `Lesson` (`status='paid'`)

**Archivo:** `app/Models/Lesson.php` (docblock de clase, líneas 85-122).

- **A. Intención:** documentación pura — clarificar que `'paid'` es una declaración **unilateral del padre**, no un pago verificado por MOVA, y enumerar todas las consecuencias reales que gobierna ese estado.
- **B/C/D/E:** no aplica (no cambia comportamiento).
- **G. Testing:** no aplica.
- **H. Regresión:** ninguna.
- **I. Mantenibilidad:** mejora sustancial — es exactamente el tipo de contexto que evita que un futuro cambio confunda "paid" con "cobrado".
- **J. Producción:** sin riesgo, cambio de solo documentación.

### Grupo 11 — F-07/F-09: Limpieza de columnas/estados muertos (`jitsi_password`, `in_progress`)

**Archivos:** `app/Http/Controllers/LessonController.php` (ya no genera `jitsi_password`), `app/Models/Lesson.php` (`$fillable`/`$hidden`), migraciones `2026_08_24_000001`/`000002`.

- **A. Intención:** eliminar un secreto en texto plano sin uso real (`jitsi_password`, residuo de meet.jit.si, reemplazado por JWT JaaS) y un estado de enum (`in_progress`) que ningún código produce.
- **B. Corrección:** sí — ambas migraciones incluyen guard: `2026_08_24_000002` aborta si existe alguna fila `in_progress` antes de recortar el enum (evita que MySQL las convierta en `''` silenciosamente); `2026_08_24_000001` recrea la columna vacía en el rollback (correctamente documentado que no había valores con significado que preservar).
- **D. Seguridad:** reduce superficie de exposición de un secreto sin función real en un volcado de base de datos.
- **G. Testing:** `tests/Feature/MonetizationIntegrityTest.php::lesson listings never expose jitsi credentials` — PASS.
- **J. Producción:** seguro — ambas migraciones son defensivas (`if (!Schema::hasColumn(...)) return`, guard contra filas existentes).

### Grupo 12 — `app/Support/TextRedactor.php` y `app/Support/FrontendAuditor.php` (utilidades de auditoría)

- **A. Intención:** `TextRedactor` reemplaza un regex único e insuficiente (dos palabras capitalizadas con lookbehind) que dejaba pasar el caso más común en producción ("Juan no entiende las fracciones"); `FrontendAuditor` es una herramienta estática de auditoría de páginas Vue con fixtures propias tras detectar 3 bugs de falsos positivos en su primera versión.
- **B. Corrección:** el propio docblock de `TextRedactor` es honesto sobre sus límites ("redacción best-effort, no anonimización garantizada") — postura correcta dado que la garantía real de privacidad es la minimización (IA desactivada por defecto, no persiste prompt/respuesta).
- **G. Testing:** `tests/Feature/TextRedactionTest.php` (18/18 PASS) y `tests/Feature/FrontendAuditorTest.php` (8/8 PASS) — ambas con casos diseñados específicamente para los bugs previos documentados (comentarios que mencionan `confirm()`, `loading` como guard válido, etc.).
- **J. Producción:** seguro, con la salvedad honesta de que `TextRedactor` no es una garantía absoluta (ya documentada y aceptada como riesgo residual conocido).

---

## 2. Features parcialmente conectadas encontradas

1. **`RechargeRequestPolicy::reverse()` + `RechargeApprovalService::reverse()` — sin consumidor HTTP.**
   Clasificación: **INCOMPLETE FEATURE (documentada / intencional en curso)**.
   Evidencia: `grep` de `reverse` en `routes/web.php` y `routes/api.php` — cero resultados. `app/Http/Controllers/Admin/RechargeController.php` no tiene ningún método `reverse()`. El propio frontend lo documenta explícitamente: `resources/js/Pages/Admin/Recharges/Index.vue:261` — *"F-08: 'reversed' existe en el backend desde la preparación de pagos, pero no [hay UI para activarlo]"*. La lógica de servicio está probada (`PaymentOrderTest`) y es correcta, pero un admin no tiene ninguna forma de invocarla desde la interfaz ni hay una ruta que la exponga. No es un bug latente (nada la llama incorrectamente) — es trabajo pendiente, ya reconocido en el propio código, para cuando exista un flujo real de refund/chargeback vía Culqi.

2. **`config/payments.php` con `'enabled' => false` por defecto y `CulqiPaymentProvider` — capa preparada, inerte hasta credenciales reales.**
   Clasificación: **INTENTIONAL** (documentado explícitamente en el propio `config/payments.php`: *"Mientras 'provider' sea 'fake', ningún profesor puede pagar de verdad por esta vía — solo existe para tests/desarrollo"*). No es dead code: `ProviderGuard`/`AppServiceProvider` lo consumen activamente para decidir el binding, y `PaymentWebhookService` ya lo usa en tests. Simplemente no hay tráfico real todavía.

3. **`WhatsAppWebhookController` y ruta `webhooks/whatsapp` — registrada pero inerte sin credenciales.**
   Clasificación: **INTENTIONAL**, documentado en `routes/api.php:930-933` y en el propio controlador (líneas 16-21): sin `META_WHATSAPP_WEBHOOK_VERIFY_TOKEN`/`APP_SECRET`, ambos métodos rechazan todo por diseño. Fuera del foco estricto de mi dominio (lógica interna de WhatsApp), pero la integración de rutas (mi dominio) es correcta y consistente con el resto del patrón fail-closed del proyecto.

4. **`config/jaas.php: join_grace_after_minutes` — usado, pero con una incertidumbre documentada (`UNKNOWN-03`).**
   Clasificación: **KEEP_WITH_FIX pendiente, ya señalado** — no es un caso "guardado pero nunca leído" (sí se lee y gobierna `LessonController::join()` y el `exp` del JWT), pero el propio código admite que no está confirmado contra JaaS real cómo se comporta un `exp` que vence a mitad de una llamada en curso. No bloqueante para producción con el valor por defecto generoso (120 min), pero cualquiera que quiera *reducir* ese valor debe hacer el smoke test primero.

5. **`CreditController::index()` — nuevo campo `price_per_credit` y `currency` en la respuesta Inertia.**
   Verificado: sí se consumen — no pude confirmar el lado Vue exacto dentro del dominio asignado (Teacher/Credits/Index.vue está fuera de mi alcance de revisión de frontend), pero el cálculo del lado servidor es correcto (`amount_pen / credits`, con guard contra división por cero). Clasificación: **KEEP** (asumiendo consumo en frontend, no verificado línea por línea por estar fuera de dominio).

No se encontraron casos de `config/credits.php` o `config/services.php` con claves sin ningún lector en el código dentro de mi dominio — cada clave nueva (`settlement_mode`, `join_window_before_minutes`, `join_grace_after_minutes`, `whatsapp.provider`, `meta_whatsapp.*`) tiene un consumidor identificado (`SettlementMode`, `LessonController::join()`, `AppServiceProvider`, `PhoneVerificationController`/`MetaCloudApiProvider` respectivamente).

---

## 3. Clasificación por grupo

| Grupo | Clasificación | Motivo |
|---|---|---|
| 1. Guard borrado `User` (F-01) | **KEEP** | Corrige un hueco real, probado, sin regresión, sin duplicación. |
| 2. Soft delete + anonimización `Student` (F-18) | **KEEP** | Corrige un 500/pérdida de datos real, probado exhaustivamente (12 tests), diseño de anonimización correcto para menores. |
| 3. Ventana de acceso JaaS (F-06) | **KEEP** | Cierra una divergencia de autorización real (frontend vs backend) en una superficie que involucra menores; probado; incertidumbre residual ya documentada, no bloqueante. |
| 4. Claim atómico de recordatorios (F-04) | **KEEP** | Razonamiento sobre garantías de entrega correcto y honesto; probado con tests de concurrencia dedicados. |
| 5. Resolución unificada de destinatarios (F-10) | **KEEP** | Elimina duplicación que causaba pérdida silenciosa de avisos; probado. |
| 6. `RechargeApprovalService` + Policy + reversión | **KEEP_WITH_FIX** | El `credit()`/Policy están listos para producción; `reverse()` es correcto pero necesita su ruta/UI antes de ser una feature completa — no bloqueante para mergear (no rompe nada existente), pero no lo des por "terminado" hasta conectar el endpoint. |
| 7. `SettlementMode` (F-02) | **KEEP** | Hace visible y auditable una decisión operativa que antes estaba escondida en código; default seguro preservado. |
| 8. `ProviderGuard` fail-closed (F-03, binding) | **KEEP** | Corrige un fail-open real; probado exhaustivamente. |
| 9. Consentimiento WhatsApp explícito | **KEEP** | Separación correcta de autenticación vs consentimiento; migración sin backfill es la decisión correcta; falta un test específico de la casilla en `PhoneVerificationController` (ver sección 5), pero no es motivo de bloqueo. |
| 10. Docblock `Lesson` (F-11) | **KEEP** | Documentación pura, sin riesgo. |
| 11. Limpieza `jitsi_password`/`in_progress` (F-07/F-09) | **KEEP** | Reduce superficie de secretos muertos; migraciones con guards correctos. |
| 12. `TextRedactor`/`FrontendAuditor` | **KEEP** | Herramientas de soporte a la auditoría, con fixtures y tests propios que corrigen bugs reales de su versión anterior. |

Ningún grupo se clasificó como QA_ARTIFACT, TEMPORARY, OBSOLETE, REVERT o BLOCKED dentro de este dominio.

---

## 4. Barrido de secretos

Se ejecutó una búsqueda de patrones de secreto reconocibles (claves privadas PEM, tokens AWS/GitHub/Slack/Google, `Bearer <token>` largo, y asignaciones literales `secret|token|password|api_key => '...'` de 6+ caracteres que no sean `env(...)`) sobre **todos** los archivos de mi dominio (modificados y nuevos, incluidas las 12 migraciones).

**Resultado: ningún secreto real embebido encontrado.** Todos los valores sensibles (`META_WHATSAPP_ACCESS_TOKEN`, `CULQI_PRIVATE_KEY`, `CULQI_WEBHOOK_SECRET`, `META_WHATSAPP_APP_SECRET`, etc.) se referencian exclusivamente vía `env(...)` en `config/services.php` y `config/payments.php`, nunca como literales.

---

## 5. Qué no se pudo verificar / fuera de alcance estricto

- **`app/Http/Middleware/HandleInertiaRequests.php`** aparece como modificado (`M`) pero no está en la lista de directorios de mi dominio (`Controllers/Models/Policies/Console/Listeners/Providers/config/routes`) ni fue asignado explícitamente — **no se revisó**.
- **`app/Services/DiagnosticAiEnrichmentService.php` y `app/Services/JaasService.php`** están modificados pero `app/Services/**` no forma parte de mi dominio salvo el archivo nuevo explícitamente listado (`RechargeApprovalService.php`). Sí se leyó el diff de `JaasService.php` puntualmente para verificar la integración con `LessonController::join()` (Grupo 3) porque era necesario para juzgar la corrección de mi propio archivo, pero no se hizo una revisión línea por línea completa de ese archivo ni de `DiagnosticAiEnrichmentService.php` bajo los criterios A-J.
- **`app/Console/Commands/ConcurrencyProbe.php`, `ConcurrencyVerify.php`, `ReconcileWhatsApp.php`** son archivos nuevos en `app/Console/Commands/` pero no estaban en la lista explícita de "archivos nuevos relevantes a este dominio" del encargo — parecen pertenecer al dominio de Payments/WhatsApp (nombres y contenido sugieren pruebas de concurrencia de pagos y reconciliación de WhatsApp) y se dejaron fuera deliberadamente para no solaparse con el otro agente. `HealthCheck.php` sí se leyó completo porque es el consumidor directo de `SettlementMode`/`ProviderGuard`, ambos en mi dominio.
- **`app/Support/ProviderGuard.php`** no se leyó línea por línea (pertenece más al dominio Payment/WhatsApp por su rol), pero su contrato se validó indirectamente vía `ProviderGuardTest.php` (14/14 PASS) y su uso desde `AppServiceProvider.php` (sí en mi dominio) se revisó completo.
- **Cobertura de test específica para la casilla `whatsapp_notifications` en `PhoneVerificationController::verify()`**: no se localizó un test que ejercite explícitamente el checkbox nuevo dentro del flujo de verificación de teléfono (los tests de WhatsApp que sí existen — `WhatsAppConsentTest.php`, `PhoneVerificationWhatsAppTest.php` — están fuera de mi dominio estricto y no se ejecutaron en este pase). Recomendación: confirmar con el otro agente o añadir el caso si no existe.
- **Consumo real de `price_per_credit`/`currency` en `resources/js/Pages/Teacher/Credits/Index.vue`**: no verificado (Vue está fuera del dominio backend asignado).
- No se ejecutó una pasada de seguridad dedicada independiente sobre los cambios a `Student`/JaaS más allá de leer el código y correr la suite de tests — dado que CLAUDE.md exige explícitamente una revisión de seguridad para cambios en `Student`/Jitsi, se señala aquí en vez de darlo por hecho.
