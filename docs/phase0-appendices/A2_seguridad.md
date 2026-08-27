# MOVA — Auditoría de Seguridad, Fase 0 (Investigación y documentación)

**Fecha:** 2026-08-26
**Alcance:** solo lectura. No se modificó ningún archivo del proyecto, no se hicieron commits, no se ejecutó ningún comando destructivo, no se accedió a ninguna base de datos ni credencial real, no se tocó git history.

---

## 0. ADVERTENCIA METODOLÓGICA CRÍTICA — leer antes que el resto del documento

Esta sesión corrió aislada en un **git worktree** (`...\.claude\worktrees\agent-a72530c32f41f2ab0`), creado como checkout limpio de `HEAD` (`692b365`, la misma rama `master` que cita `MOVA_PRODUCTION_READINESS.md` como baseline). Un worktree solo refleja el **estado commiteado** — no hereda cambios sin commitear que existan en el checkout principal (`C:\Users\ABEL\OneDrive\Desktop\ProyectoMOVA`).

Al empezar la re-verificación se encontró que **el worktree aislado y el checkout principal están en estados radicalmente distintos**:

| Aspecto | Worktree aislado (`HEAD` limpio, `692b365`) | Checkout principal (working tree, sin commitear) |
|---|---|---|
| Proveedor de WhatsApp | **Twilio** (`app/Channels/WhatsAppChannel.php` usa `Twilio\Rest\Client` directamente) | **Meta Cloud API** (`MetaCloudApiProvider`, `WhatsAppWebhookController`, gate de suspensión F-22, `skip_reason`) |
| `qa/test-wizard-flow.mjs`, `qa/end-to-end-welcome-email.mjs`, `qa/auth/admin.json`, `qa/global-setup.js` (F-26/F-27) | **Presentes y tracked**, con la contraseña RAW de MySQL de producción en texto plano | Ausentes (eliminados del working tree, no commiteados) |
| `qa/lib/enforce-safe-target.mjs`, `enforce-read-only.mjs`, `mutation-firewall.spec.js` (F-24A/F-25) | No existen | Presentes |
| `app/Payment/CulqiPaymentProvider.php`, `payment_webhooks` migration | No existen | Presentes |
| Migraciones | Última: `2026_08_22_...` | Incluye `2026_08_23_...add_reversal_type...`, `2026_08_24_...whatsapp_skipped_status...` |

**Conclusión operativa:** todo lo descrito en `MOVA_PRODUCTION_READINESS.md` como "código listo"/"✅ FIXED" corresponde a **cambios sin commitear en el checkout principal**, no al `HEAD` real de `master`. Dado que el entorno de despliegue (Railway) construye desde git, **ninguno de esos fixes existe hoy en un artefacto desplegable** — solo en el disco de quien tiene ese checkout abierto.

Ante esto, y dado que las herramientas de lectura (Read/Grep/Glob, y `grep`/`node` vía Bash sin `cd`) sí pudieron acceder al checkout principal (a diferencia de comandos `git`, bloqueados explícitamente por el aislamiento de este agente hacia ese directorio), **la re-verificación de abajo se hizo contra el checkout principal** (el estado real que un desarrollador ve y edita hoy), no contra el worktree vacío — porque es la única forma de producir un hallazgo útil. Se marca explícitamente en cada fila cuándo la evidencia viene de ahí. **No se ejecutó ningún comando `git` contra el checkout principal** (bloqueado por diseño de aislamiento); los hechos de nivel git-history citados de `MOVA_CREDENTIAL_EXPOSURE.md` no se re-verificaron de forma independiente esta ronda.

**Este hallazgo — código de seguridad crítico (F-22, F-24A–F-27, arquitectura completa de WhatsApp) viviendo únicamente como cambios sin commitear — es en sí mismo el hallazgo más importante de esta fase**, ver P0-1 más abajo.

---

## 1. Re-clasificación de F-01 a F-27

Fuente de los IDs: `docs/MOVA_FULL_AUDIT.md` (F-01–F-20) y `docs/MOVA_PRODUCTION_READINESS.md` (F-21–F-27). Estado verificado contra el **checkout principal** (working tree actual), no contra el worktree aislado — ver §0.

| ID | Título | Estado documentado | Re-verificación (esta ronda) | Evidencia |
|---|---|---|---|---|
| F-01 | `DELETE` en `users` destruía el ledger por cascada | 🟡 MITIGATED | **CONFIRMADO MITIGATED** | `app/Models/User.php` guard `booted()`/`hasProtectedHistory()`; migración `2026_07_10_000002_protect_monetization_history.php` (FK RESTRICT) |
| F-02 | C-1 (liquidación) implementado pero inactivo | ⏳ DECISIÓN DE NEGOCIO | **SIN CAMBIOS** — sigue en `--dry-run` por diseño (`Kernel.php`) | `app/Console/Kernel.php` |
| F-03 | Proveedores Fake fail-open | ✅ CORREGIDO | **CONFIRMADO FIXED** | `app/Support/ProviderGuard.php` existe |
| F-04 | Race condition en recordatorios | ✅ FIXED | **NO RE-EJECUTADO** (requeriría prueba de concurrencia real, fuera de alcance no-destructivo de esta ronda) — código de claim revisado por encima, consistente con lo documentado | `app/Console/Commands/SendClassReminders.php` |
| F-05 | Anonimización que no cumple su contrato (menores) | 🟡 MITIGATED | **CONFIRMADO MITIGATED** — best-effort, no garantía de anonimización irreversible | `app/Support/TextRedactor.php` |
| F-06 | JWT 24h sin ventana temporal | 🟡 + ⏳ EXTERNAL VALIDATION | **CONFIRMADO** — `JaasService::generateToken()` acepta `$expiresAt`, `LessonController::join()` lo acota a la ventana de la clase; smoke test contra JaaS real sigue sin poder verificarse desde aquí | `app/Services/JaasService.php` |
| F-07 | `jitsi_password`: columna muerta | ✅ CORREGIDO | **CONFIRMADO** — no aparece en fillable de `Lesson`/`ClassOffer` en el código revisado | — |
| F-08–F-17 | Hallazgos UI/estado menores (ver `MOVA_FULL_AUDIT.md`) | ✅ mayoría CORREGIDO | **NO RE-VERIFICADOS individualmente esta ronda** — bajo impacto de seguridad, fuera del foco de Fase 0; se aceptan como documentados salvo evidencia en contrario | — |
| F-18 | Borrar alumno destruía historial de clases | ✅ FIXED | **CONFIRMADO FIXED** | `app/Http/Controllers/StudentController.php::destroy()` (anonymize + soft delete), `app/Policies/StudentPolicy.php` (ownership) |
| F-19 | 7 superficies sin visualización de error | ✅ FIXED | **NO RE-VERIFICADO** (frontend UX, fuera de foco de seguridad pura) | — |
| F-20 | `--timeout` == `retry_after` (doble ejecución de job) | ✅ FIXED | **CONFIRMADO FIXED** | `railway.queue.toml` (`--timeout=60`, comentario explícito citando F-20) |
| F-21 | Endpoint HTTP de webhook de Culqi | ❌ OPEN | **CONFIRMADO STILL OPEN** — `app/Payment/CulqiPaymentProvider.php` existe (stub), pero no hay `PaymentWebhookController` ni ruta registrada en `routes/api.php`/`routes/web.php` | Búsqueda `find`/`grep` sin resultados para `PaymentWebhookController` o ruta `culqi`/`webhook` de pagos |
| F-22 | Cuenta suspendida seguía recibiendo WhatsApp UTILITY | ✅ FIXED | **CONFIRMADO FIXED — solo en checkout principal, sin commitear** (ver §0). Ausente en el worktree aislado (`HEAD`) | `app/Channels/WhatsAppChannel.php` (checkout principal): gate `suspended_at` antes del gate de opt-in, con `logSkip(..., WhatsAppSkipReason::Suspended)` |
| F-23 | `sent` con `provider_message_id=null` operacionalmente inútil | ✅ FIXED | **NO RE-VERIFICADO directamente** (no se leyó `MetaCloudApiProvider.php` línea por línea esta ronda); consistente con la existencia de `WhatsAppSkipReason` y el resto de la arquitectura Meta en el checkout principal | — |
| F-24A | Playwright apuntaba a producción por defecto sin guard | Technical ✅ / Operational ❌ | **CONFIRMADO** — `qa/lib/enforce-safe-target.mjs` existe en checkout principal; **ausente en el worktree aislado**, y el `.gitignore` del worktree aislado NO tiene la regla `qa/auth/` (solo `auth.json`, que no matchea `qa/auth/admin.json`) | Ver §0 y hallazgo P0-2 |
| F-24B | `qa/package.json` con 5 specs muertos | ✅ FIXED | **CONFIRMADO en checkout principal** (`test:local`, `test:security`, sin scripts `01`–`05`). **REGRESADO/nunca commiteado en el worktree aislado**: su `qa/package.json` todavía referencia `tests/01-admin-flow.spec.js` … `05-admin-verify-teacher.spec.js`, que no existen en `qa/tests/` (solo `flujo-completo.spec.js`) | `qa/package.json` del worktree aislado |
| F-25 | Firewall de mutaciones no existía para override remoto | ✅ FIXED | **CONFIRMADO en checkout principal** (`qa/lib/enforce-read-only.mjs`, `mutation-firewall.spec.js`, `playwright.production-readonly.config.js`). **Ausente en el worktree aislado** | Ver §0 |
| F-26 | Contraseña RAW de MySQL de producción en 6 archivos, git history | ❌ OPEN (rotación pendiente) | **CONFIRMADO OPEN, y peor de lo que el propio documento asume en su capa "Working tree/HEAD"**: en el checkout principal SÍ están eliminados (✅, consistente con el doc); pero **en el worktree aislado — que es un checkout real de `HEAD`/`master` — `qa/test-wizard-flow.mjs` y `qa/end-to-end-welcome-email.mjs` siguen tracked y presentes, con la contraseña en texto plano**, confirmando que el HEAD real de git (no solo "el historial") sigue exponiendo la credencial hoy, no solo en commits antiguos | Visto directamente en `qa/test-wizard-flow.mjs` y `qa/end-to-end-welcome-email.mjs` del worktree — **no se reproduce el valor en este documento** |
| F-27 | Cookies de sesión admin de producción trackeadas | ✅ eliminado del tree / ❌ historia persiste | **CONFIRMADO — mismo patrón que F-26**: `qa/auth/admin.json` sigue tracked y presente en el worktree aislado (`HEAD`), con cookies (ya expiradas, verificado: `2026-06-25`, consistente con lo documentado) | `qa/auth/admin.json` del worktree, campo `expires` leído sin exponer el resto del contenido |

**Nota sobre F-26/F-27 en esta ronda:** el documento `MOVA_CREDENTIAL_EXPOSURE.md` afirma "Working tree: ✅ Limpio" y "HEAD actual: ✅ Limpio" basándose en el checkout principal. Esta ronda encontró que **esa afirmación es falsa para el HEAD real de `master`** tal como lo ve cualquier otro clon/checkout del repositorio (el worktree aislado es exactamente eso) — la limpieza existe solo como cambios sin commitear en una máquina. Esto se eleva a severidad 🔴 CRITICAL como hallazgo nuevo (P0-1/P0-2 abajo), no como una simple corrección de la tabla F-26.

---

## 2. Hallazgos nuevos por categoría (clasificación THEORETICAL / PROVEN / LIKELY / NOT APPLICABLE)

### 2.1 Gestión de secretos y estado del repositorio (la categoría más grave esta ronda)

**[N-01] La remediación de F-26/F-27 nunca se commiteó — el `HEAD` real de git sigue exponiendo la contraseña de producción de MySQL — PROVEN**
El checkout principal tiene los archivos peligrosos eliminados del disco, pero `git status`/commit nunca se ejecutó sobre esa limpieza (consistente con el propio `MOVA_PRODUCTION_READINESS.md`: "Working tree: sucio — cambios sin commitear"). Un checkout independiente del mismo `HEAD` (`692b365`) — que es literalmente lo que este worktree aislado es — **contiene y trackea** `qa/test-wizard-flow.mjs` y `qa/end-to-end-welcome-email.mjs` con la contraseña RAW de la base de datos MySQL de producción en texto plano, más `qa/auth/admin.json` con cookies de sesión de admin (expiradas). Cualquier colaborador que haga `git clone`/`git pull` hoy, o cualquier pipeline de CI/CD que construya desde `origin/master`, recibe estos archivos.
Severidad: **🔴 P0 — CRITICAL.**
Evidencia: contenido de `qa/test-wizard-flow.mjs` y `qa/end-to-end-welcome-email.mjs` en el worktree (`C:\...\worktrees\agent-a72530c32f41f2ab0\qa\`), confirmados vía `git ls-files` como tracked en ese `HEAD`. **El valor de la contraseña no se reproduce en este documento** (cumpliendo la misma disciplina que `MOVA_CREDENTIAL_EXPOSURE.md`).
Acción recomendada: (1) commitear la eliminación de estos archivos en el checkout principal AHORA, y hacer push; (2) tratar esto como una razón adicional, independiente de git-history, para rotar la contraseña de MySQL de inmediato — no es solo "recuperable del historial", está en el HEAD actual; (3) revisar si `origin/master` en GitHub ya refleja este HEAD sin la limpieza (si es así, cualquiera con acceso de lectura al repo ya tiene la contraseña en texto plano en el archivo más reciente, sin necesidad de bucear en el historial).

**[N-02] Todo el trabajo de seguridad de esta ronda de auditoría (F-22, F-24A, F-25, arquitectura Meta completa) existe solo sin commitear — riesgo de pérdida de trabajo y de una falsa sensación de "ya está arreglado" — PROVEN**
Si la máquina/sesión que tiene el checkout principal se pierde antes de commitear, **todo el trabajo de remediación de esta serie de auditorías desaparece**, y el próximo despliegue desde git parte de la versión pre-fix (Twilio, sin gate de suspensión, sin firewall de QA). Esto también significa que cualquier afirmación de "producción está protegida por X" en los documentos de auditoría es, en rigor, una afirmación sobre un directorio de trabajo, no sobre lo que Railway desplegaría hoy.
Severidad: **🟠 P1 — HIGH** (proceso/operacional, no una vulnerabilidad de código en sí, pero bloquea que cualquier otro hallazgo "FIXED" de este documento sea válido para producción real).
Acción recomendada: commitear por áreas (el propio `MOVA_PRODUCTION_READINESS.md` ya sugiere el agrupamiento en su última sección) y hacer push antes de considerar cerrado cualquier hallazgo de esta u otras rondas.

**[N-03] `.gitignore` inconsistente entre el worktree y el checkout principal para `qa/auth/` — LIKELY (relacionado con N-01)**
El worktree aislado tiene solo la línea `auth.json` en `.gitignore` (no matchea `qa/auth/admin.json`, que vive en un subdirectorio con otro nombre de archivo). El checkout principal sí tiene `qa/auth/`. Esto es consistente con N-01/N-02 (la corrección vive solo sin commitear) pero se lista aparte porque es, en sí, la causa de que un futuro `storageState` regenerado por error en el worktree/CI vuelva a quedar trackeable.
Severidad: 🟡 P2.

### 2.2 Autenticación / Autorización / IDOR / BOLA

**[N-04] Mass assignment — protección consistente — NOT APPLICABLE (verificado, no vulnerable)**
Los 18 modelos en `app/Models/*.php` usan `protected $fillable` explícito (ninguno usa `$guarded = []`). Se buscó exhaustivamente `::create($request->all())`, `->fill($request->all())`, `->update($request->all())` en `app/Http/Controllers` — cero resultados. El único `->fill()` con datos de request (`ProfileController::update()`) usa `$request->validated()` de `ProfileUpdateRequest`, cuyo `rules()` solo valida `name`/`email` — aunque `User::$fillable` incluye campos sensibles (`phone_verified_at`, `suspended_at`, `suspension_reason`), `validated()` los excluye por no estar en las reglas, así que no son asignables por esta vía. **Verificado, no vulnerable.**

**[N-05] IDOR — controladores muestreados con autorización consistente — NOT APPLICABLE (muestra verificada)**
Se inspeccionaron `LessonController` (join/confirmPayment/cancel/reschedule), `LessonReportController` (create/store/show), `StudentController` (edit/update/destroy), `NotificationController` (markRead vía relación scopeada), `AdminController::suspendUser` (bloquea auto-suspensión de otro admin), y las Policies `LessonPolicy`, `StudentPolicy`, `ClassRequestPolicy`, `RechargeRequestPolicy`. Todos usan `$this->authorize()` o comprobación explícita de propiedad (`parent_user_id === $user->id`, `teacher_profile_id === $profile->id`) antes de mutar. `ClassRequestPolicy::accept()` confirma el fix crítico previo (chequeo de `is_verified`). No se encontró ningún endpoint mutante sin verificación de propiedad en la muestra revisada — **no es una cobertura exhaustiva de las ~30 páginas con acciones**, es una muestra de las superficies de mayor riesgo (financiero, menores).

**[N-06] `role:admin` protege correctamente todo el grupo `/admin/*` — NOT APPLICABLE (verificado)**
`routes/web.php` confirma `Route::middleware('role:admin')->prefix('admin')` envolviendo todas las rutas admin. `RegisteredUserController::store()` valida `role: required|in:parent,teacher` — imposible auto-asignarse `admin` vía registro público.

### 2.3 Inyección (SQL / Command / SSRF / Path Traversal)

**[N-07] SQL injection — no encontrado — NOT APPLICABLE**
Búsqueda de `DB::raw`, `whereRaw`, `selectRaw`, `DB::statement`, `DB::select` en `app/`. Todos los usos con datos externos usan bindings parametrizados (`?`), nunca interpolación de string (ej. `Lesson::scopeEndedBefore()`/`scopeEndedAfter()` con `whereRaw('DATE_ADD(...) < ?', [$moment])`). Los `selectRaw`/`DB::raw` restantes son agregaciones fijas (`count(*) as total`), sin input de usuario.

**[N-08] Command Injection / SSRF — sin superficie encontrada — NOT APPLICABLE (alcance limitado de la búsqueda)**
No se encontró uso de `exec()`, `shell_exec()`, `proc_open()`, `system()` en `app/` durante la exploración realizada, ni URLs construidas desde input de usuario en llamadas `Http::` salientes (WhatsApp/Gmail/JaaS usan endpoints fijos de configuración). **No se hizo un grep final exhaustivo dedicado de estas funciones sobre todo el árbol — clasificar como verificado por muestreo, no por barrido completo.**

### 2.4 XSS

**[N-09] Tres usos de `v-html` en frontend — LIKELY NOT EXPLOTABLE (verificado por patrón, no por prueba en navegador)**
`resources/js/Pages/Admin/Users.vue:68`, `Admin/Recharges/Index.vue:156`, `Marketplace/Index.vue:105` — los tres usan `v-html="link.label"` dentro de lo que aparenta ser un componente de paginación reutilizado. `link.label` en Laravel proviene del paginator (`LengthAwarePaginator`), que genera únicamente strings fijos (`&laquo; Previous`, números de página, `Next &raquo;`) — no contenido controlado por usuario. **No se leyó el componente de paginación compartido para confirmar 100% que ningún dato de usuario llega a `label`** — clasificado LIKELY NOT EXPLOTABLE, no PROVEN NOT APPLICABLE, por esa razón.
Severidad si se confirmara controlable: 🟠 P1. Con la lectura actual: 🟢 P3 (verificar el componente de paginación como cierre).

### 2.5 CSRF / CORS / Cookies / Sesión

**[N-10] CORS — configuración por defecto, sin riesgo real — NOT APPLICABLE**
`config/cors.php`: `allowed_origins: ['*']` pero `supports_credentials: false` — con credenciales deshabilitadas, un origin wildcard no permite a un sitio de terceros leer respuestas autenticadas (las cookies de sesión no se envían/leen cross-origin bajo esta config). Alcance limitado a `api/*` y `sanctum/csrf-cookie`. Configuración estándar de Laravel, consistente con una SPA Inertia same-origin.

**[N-11] `SESSION_SECURE_COOKIE` no está definida en `.env.example` — LIKELY**
`config/session.php` usa `'secure' => env('SESSION_SECURE_COOKIE')` sin valor por defecto (→ `null`/falsy si no se define). `.env.example` no incluye esta variable en absoluto — a diferencia de otras variables sensibles que sí aparecen vacías como plantilla. Si el `.env` real de producción tampoco la define explícitamente en `true`, la cookie de sesión no llevaría el flag `Secure`, permitiendo que viaje sobre una conexión HTTP no cifrada si alguna vez existe una ruta de acceso no-HTTPS al dominio de producción.
No se pudo confirmar el valor real de producción (correctamente fuera de alcance leer el `.env` real). `same_site: 'lax'` y `http_only: true` sí están bien configurados por defecto.
Severidad: 🟡 **P2** (mitigado en la práctica si Railway fuerza HTTPS y el dominio nunca sirve por HTTP plano, pero es una configuración explícita ausente, no verificada).
Acción recomendada: confirmar `SESSION_SECURE_COOKIE=true` en el `.env` real de producción y añadirlo a `.env.example` con ese valor recomendado comentado.

**[N-12] CSRF — sin hallazgos, protección estándar de Laravel/Inertia asumida intacta — NOT APPLICABLE (no se auditó explícitamente `VerifyCsrfToken` ni excepciones)**
No se revisó `app/Http/Middleware/VerifyCsrfToken.php` para confirmar que no tenga rutas excluidas (`$except`) que no deberían estarlo. **Marcado como REQUIERE VERIFICACIÓN**, ver §4.

### 2.6 Webhooks / Firma / Replay

**[N-13] Webhook de WhatsApp — firma HMAC-SHA256 con `hash_equals`, fail-closed — NOT APPLICABLE (verificado, correcto)**
`app/Http/Controllers/WhatsAppWebhookController.php` (checkout principal): límite de tamaño (1MB) antes de parsear, `hasValidSignature()` rechaza si falta `app_secret` o header, comparación con `hash_equals()` (constant-time, previene timing attack), verificación de `hub_verify_token` también con `hash_equals()`. Bien construido.

**[N-14] Webhook de Culqi — no existe — CONFIRMADO (ver F-21 en tabla §1)**. No es una vulnerabilidad activa (no hay endpoint que atacar), pero es la ausencia de un control que eventualmente será crítico. Severidad si se implementa sin firma: sería 🔴 P0; hoy, NOT APPLICABLE porque no existe.

### 2.7 OTP / Rate limiting / Brute force

**[N-15] Flujo OTP (verificación de teléfono) — bien construido — NOT APPLICABLE (verificado, correcto)**
`app/Http/Controllers/Auth/PhoneVerificationController.php`: código de 6 dígitos hasheado (`Hash::make`), TTL 10 min, máx. 5 intentos, rate limit por-usuario (`throttle:3,1` en la ruta) Y por-número-de-teléfono (5/hora, cruza cuentas — mitigación explícita contra abuso multi-cuenta hacia el mismo número), `UNIQUE(phone_verified_normalized)` como garantía real (no solo un chequeo amistoso). Bono de bienvenida protegido con `lockForUpdate()` + `idempotency_key` único.

**[N-16] Login — rate limiting en dos capas — NOT APPLICABLE (verificado, correcto)**
`routes/auth.php`: `throttle:10,1` a nivel de ruta. `LoginRequest::ensureIsNotRateLimited()` (scaffolding Breeze estándar, intacto): 5 intentos por combinación email+IP con lockout. Password reset y registro también llevan `throttle:5,1`.

**[N-17] Rutas financieras (`/teacher/credits/recharge`, `/lessons`, `/admin/recharges/*`) — todas con `throttle` — NOT APPLICABLE (verificado)**
Revisión de `routes/web.php` confirma `throttle` en prácticamente cada ruta `POST`/`PATCH`/`DELETE` mutante, incluidas las financieras. No se encontró ninguna ruta de mutación financiera sin throttle en la muestra revisada.

### 2.8 Carga de archivos

**[N-18] Único punto de subida de archivos (avatar) — validado correctamente — NOT APPLICABLE (verificado)**
`ProfileController::updateAvatar()`: `'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']`. Es el único `->file(...)` encontrado en todo `app/Http/Controllers`. `CloudinaryService::uploadAvatar()` usa un `public_id` determinístico (`avatars/user-{id}`), sin tomar ningún nombre de archivo del usuario — sin riesgo de path traversal.

### 2.9 Notificaciones / Logging / Exposición de datos

**[N-19] Logging — sin fugas de secretos/PII sensible encontradas en la muestra — NOT APPLICABLE (verificado por muestreo)**
Grep de `Log::` cerca de `password|code|token|otp|dni` en `app/`: los únicos resultados registran nombres de variables de entorno faltantes o códigos de estado HTTP, nunca el valor real. Consistente con lo que `MOVA_SYSTEM_KNOWLEDGE.md` ya documentaba (test dedicado `test_otp_code_never_ends_up_in_the_audit_log_error_field`).

**[N-20] Notificaciones — `NotificationController` correctamente scopeado — NOT APPLICABLE (verificado)**
`index()`/`markRead()`/`markAllRead()` operan exclusivamente sobre `auth()->user()->notifications()`/`unreadNotifications` — imposible leer o marcar como leída una notificación de otro usuario vía ID arbitrario, porque el query ya está acotado por la relación del usuario autenticado antes de aplicar el `findOrFail($id)`.

### 2.10 Configuración / Dependencias

**[N-21] `APP_DEBUG`/`APP_ENV` reales de producción — UNKNOWN (no verificable desde este entorno)**
`.env.example` tiene `APP_ENV=local`, `APP_DEBUG=true` — correcto como plantilla de desarrollo. No se leyó ningún `.env` real (correctamente fuera de alcance). Ya documentado como UNKNOWN en `MOVA_SYSTEM_KNOWLEDGE.md` §21; esta ronda no aporta nueva evidencia.

**[N-22] Dependencias — no se ejecutó `composer audit`/`npm audit` — UNKNOWN**
`composer.json`: `laravel/framework: ^10.10`, `guzzlehttp/guzzle: ^7.2`, `spatie/laravel-permission: ^6.25` — rangos de versión razonables, sin indicio de versión fija antigua con CVE conocida a simple vista, pero no se ejecutó ninguna herramienta de auditoría de dependencias (sin `vendor/`/`node_modules` instalados en el worktree aislado, y ejecutar `composer install`/`npm audit` no es una acción de solo-lectura trivial que se haya considerado necesaria para esta fase). **Clasificado explícitamente como REQUIERE VERIFICACIÓN**, ver §4.

**[N-23] `ADMIN_PASSWORD` en `.env.example` — NOT APPLICABLE (verificado, sin backdoor)**
`database/seeders/ProductionSeeder.php` usa `config('app.admin_password')`, falla explícitamente (`$this->command->error(...)` y `return`) si `ADMIN_EMAIL`/`ADMIN_PASSWORD` no están definidas — sin contraseña por defecto hardcodeada.

---

## 3. Severidad de hallazgos nuevos (P0–P4)

| Hallazgo | Severidad | Clasificación |
|---|---|---|
| N-01 — Contraseña de MySQL de producción tracked en el `HEAD` real de git (no solo en historial) | 🔴 **P0** | PROVEN |
| N-02 — Todos los fixes de seguridad de esta serie de auditorías sin commitear | 🟠 **P1** | PROVEN |
| N-03 — `.gitignore` de `qa/auth/` inconsistente entre checkouts | 🟡 P2 | PROVEN (consecuencia directa de N-01/N-02) |
| N-11 — `SESSION_SECURE_COOKIE` no definida en `.env.example` | 🟡 P2 | LIKELY |
| N-09 — `v-html` en 3 páginas (paginación) | 🟢 P3 (si se confirma no explotable) / 🟠 P1 (si se confirma controlable) | LIKELY NOT EXPLOTABLE |
| N-22 — Dependencias sin auditar con herramienta | 🟡 P2 (desconocido — podría ser P0 si hay CVE activo) | UNKNOWN |
| N-08 — Command injection/SSRF sin barrido final exhaustivo | 🟢 P3 | NOT APPLICABLE (muestreo), REQUIERE VERIFICACIÓN para cierre total |
| N-12 — `VerifyCsrfToken` sin revisar excepciones | 🟢 P3 | REQUIERE VERIFICACIÓN |
| F-21 (re-confirmado) — endpoint de Culqi sigue sin existir | 🟠 P1 (bloqueante de negocio, no explotable hoy) | STILL OPEN |
| F-26/F-27 (re-confirmado, agravado por N-01) | 🔴 P0 | STILL OPEN |

Ningún hallazgo nuevo de esta ronda alcanzó el nivel PROVEN mediante explotación reproducida en vivo (no se atacó ninguna instancia corriendo) — PROVEN aquí significa "confirmado leyendo el artefacto real" (el archivo con la contraseña, el `git ls-files` mostrando que está tracked), no "explotado contra un servidor en ejecución". Ninguna prueba se ejecutó contra producción real, consistente con las reglas de esta fase.

---

## 4. Qué no se pudo verificar, y por qué (UNKNOWN / REQUIERE VERIFICACIÓN)

- **Estado real de `origin/master` en GitHub** — si ya incluye o no los 6 archivos de F-26/F-27 en el commit más reciente pusheado, y si el checkout principal ya está sincronizado con o adelantado a `origin`. Requeriría `git fetch`/`git log origin/master`, bloqueado por el aislamiento de este agente hacia el checkout principal.
- **Si la contraseña de MySQL de producción sigue activa** — deliberadamente no verificado (requeriría conectarse con una credencial potencialmente comprometida), igual que en `MOVA_CREDENTIAL_EXPOSURE.md`.
- **`APP_DEBUG`/`APP_ENV` reales en el `.env` de producción** — no se leyó ningún `.env` real.
- **Auditoría de dependencias con herramienta (`composer audit`, `npm audit`)** — no ejecutada; `vendor/`/`node_modules` no están instalados en el entorno aislado y no se consideró justificado instalarlos para esta fase de solo-lectura.
- **`app/Http/Middleware/VerifyCsrfToken.php`** — no revisado por excepciones (`$except`) indebidas.
- **Barrido final dedicado de `exec/shell_exec/proc_open/system`** sobre todo `app/` — se hizo una revisión por muestreo, no un grep final de cierre.
- **F-04 (race condition de recordatorios) y F-23 (`sent`/`provider_message_id`)** — no releídos línea por línea esta ronda; se aceptan como documentados por falta de tiempo, no por evidencia propia de esta ronda.
- **F-08 a F-17 y F-19** — hallazgos de UI/estado de bajo impacto en seguridad, no re-verificados individualmente.
- **Componente de paginación compartido** (origen exacto de `link.label` en los 3 `v-html`) — no se leyó el archivo del componente en sí, solo se infirió el origen por el patrón estándar de Laravel.
- **Smoke test contra JaaS/Meta/Culqi reales** — no realizable sin credenciales de producción, fuera de alcance por diseño.
- **Todo hecho sobre `git log`/`git show` del checkout principal** (para confirmar en qué commits exactos aparece cada archivo problemático) — bloqueado por el aislamiento del agente; se confió en la lectura directa de archivos, no en el historial.

---

## Resumen para cierre de fase

Esta Fase 0 confirma que la arquitectura de autorización, ledger financiero, OTP y webhooks de MOVA está, en el código que efectivamente existe hoy (commiteado o no), bien construida y sin IDOR/mass-assignment/SQLi encontrados en la muestra revisada. El hallazgo dominante de esta ronda no es una vulnerabilidad de lógica de negocio nueva, sino un **problema de proceso con consecuencias de seguridad reales**: el trabajo de las últimas rondas de auditoría —incluida la eliminación de una contraseña de base de datos de producción— nunca se commiteó, por lo que el `HEAD` real de git (lo que cualquier otro clon del repositorio recibe hoy) sigue conteniendo esa contraseña en texto plano en dos archivos tracked, no solo "recuperable del historial" como asumía la documentación previa.
