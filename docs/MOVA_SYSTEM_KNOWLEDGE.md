# MOVA — Base de Conocimiento del Sistema

**Generado:** 2026-08-24, por inspección directa del repositorio (sin modificar código).
**Método:** lectura completa de `app/`, `routes/`, `database/migrations/`, `config/`; listado completo de `resources/js/` y `tests/`; ejecución de `php artisan test`, `npm run build`, `route:list`, `migrate:status`, `git diff --check`.
**Convención:** toda afirmación cita `archivo:línea` cuando es verificable. `INFERENCIA` marca una deducción razonable no confirmada literalmente en código. `UNKNOWN` marca algo que no se pudo determinar desde el repositorio.

**⚠️ Corrección (2026-08-27):** una auditoría de diff posterior muestreó 6 citas `archivo:línea` de este documento y encontró que 5 eran numéricamente imposibles (hasta 12x el tamaño real del archivo citado) — probablemente un artefacto de cómo se generaron los números en la redacción original, no errores de contenido (lo que las citas describían era correcto, solo el número de línea estaba mal). Esas 6 y otras 4 encontradas al corregirlas ya se repararon con el número real. **No se re-verificó el resto del documento línea por línea** — trata cualquier otra cita `archivo:línea` de aquí en adelante como orientativa hasta confirmarla contra el archivo real, no como una garantía, pese a lo que dice el párrafo de "Convención" de arriba.

---

## 1. Identidad y propósito de MOVA

MOVA es una plataforma de clases particulares online para el mercado peruano, construida en Laravel 10 + Inertia + Vue 3. Conecta **padres/tutores** (que buscan apoyo académico para sus hijos) con **profesores particulares verificados**, con **administradores** de MOVA supervisando calidad y disputas.

**Modelo de negocio real (verificado en código, no el que describía una versión anterior del marketing):** el profesor **le compra créditos a MOVA** (`RechargeRequest`, `app/Models/RechargeRequest.php`) para poder aceptar solicitudes de clase; 1 crédito ≈ 1 hora de clase (`Lesson::creditCostForMinutes()`, `app/Models/Lesson.php:356-361`). El **padre le paga al profesor directamente por fuera de la plataforma** (Yape/Plin, según se declara en `docs/HANDOFF_FINAL.md:150` y en `Terms.vue` §5) — MOVA no cobra al padre ni intermedia ese pago. Confirmado también por la ausencia total de cualquier flujo de cobro al padre en `routes/web.php` o los controladores de `ClassRequest`/`Lesson`.

**Cómo funciona una clase, de punta a punta (ver §27-D a §27-N para el detalle):**
1. El padre completa un diagnóstico o una solicitud abierta (`ClassRequest`, `status='open'` u `pending_parent_approval` si tiene control parental activado).
2. Un profesor verificado la acepta (`LessonController::store()`, `app/Http/Controllers/LessonController.php:23`) — esto crea una `Lesson` (tabla física `classes`) y **reserva** créditos del profesor.
3. La clase ocurre por videollamada Jitsi/JaaS (§13).
4. El padre confirma que la clase ocurrió y que pagó al profesor (`LessonController::confirmPayment()`, línea 222) → `status='paid'`.
5. El profesor sube un reporte de aprendizaje (`LessonReportController::store()`) → `status='pending_parent_confirmation'`.
6. El padre califica al profesor (`TeacherReviewController::store()`) → esto **consume** los créditos reservados (o el sistema los auto-consume tras una ventana de gracia si nadie califica — ver C-1, §8).

**Quiénes son los usuarios:** `parent`, `teacher`, `admin` — 3 roles Spatie (`database/seeders/RoleSeeder` — no leído en detalle en esta pasada, roles confirmados vía `2026_06_29_500000_seed_spatie_roles_and_admin_user.php` y uso extensivo de `hasRole()`/`assignRole()` en todo el código).

**Qué es un "crédito":** una unidad de capacidad del profesor para aceptar clases, NO dinero del padre. Comprado por el profesor (recarga), reservado al aceptar una clase, consumido cuando la clase se liquida (calificación o auto-liquidación), liberado (refund) si la clase se cancela.

---

## 2. Mapa general del sistema

```
MOVA
├── Frontend       Vue 3 (<script setup>) + Inertia.js + Tailwind — SPA servida por Laravel, sin API REST separada para el propio front
├── Backend        Laravel 10.10, PHP ^8.1
├── Database       MySQL en producción (confirmado: config/database.php default, .env real DB_CONNECTION=mysql), SQLite :memory: en tests (phpunit.xml)
├── Auth           Laravel session-based (cookies), Laravel Breeze scaffolding + Google OAuth (Socialite) + Sanctum (solo para /api/user, no usado por el SPA)
├── Authorization  Spatie Permission (roles) + Laravel Policies (por-recurso) — ver §5
├── Payments       app/Payment/* — Culqi NO implementado (stub), FakePaymentProvider activo — ver §10
├── Credits/Ledger credit_transactions (append-only) + teacher_profiles.credits_available/reserved — ver §8
├── Classes        Lesson (tabla `classes`) + ClassRequest + ClassOffer — ver §14
├── Notifications  21 clases App\Notifications\*, canales mail/database/broadcast/WhatsApp — ver §12
├── WhatsApp       app/WhatsApp/* — Meta Cloud API directo, MetaCloudApiProvider real pero sin cuenta Meta activa — ver §11
├── Video          Jitsi vía JaaS (jaas.8x8.vc), JWT firmado con RS256 — ver §13
├── Admin          AdminController + Admin/RechargeController + TeacherReviewController(adminIndex) — ver §17
└── Infraestructura Railway (INFERENCIA: docs mencionan "Railway" repetidamente, sin Dockerfile en el repo), queue driver=database, cache=file, session=file, broadcast=pusher
```

**Cómo se relacionan (flujo de una request típica):** `routes/web.php` → middleware (`auth`, `verified`, `role:X`, `not.suspended`) → Controller (delgado) → Policy (autorización) → Service/Model (lógica + persistencia, con `DB::transaction()` + `lockForUpdate()` en toda mutación financiera) → Inertia::render() devuelve una página Vue con props, o `back()`/`redirect()` para mutaciones.

---

## 3. Estructura del repositorio

| Directorio | Propósito | Archivos clave |
|---|---|---|
| `app/Http/Controllers/` | 33 controladores, delgados — casi toda la lógica de negocio no trivial vive en `Services`, no aquí | `LessonController`, `AdminController`, `Admin/RechargeController` |
| `app/Http/Controllers/Auth/` | 10 controladores de autenticación (scaffolding Breeze + `GoogleAuthController` + `PhoneVerificationController` propios) | |
| `app/Models/` | 22 modelos Eloquent | `User`, `TeacherProfile`, `Lesson` (tabla `classes`), `CreditTransaction` |
| `app/Services/` | Lógica de dominio no trivial: liquidación financiera, IA de diagnóstico, JaaS, email, webhooks de pago | `LessonSettlementService` (única capa de liquidación, C-1), `RechargeApprovalService` |
| `app/Support/` | Utilidades de dominio reutilizadas por 2+ callers | `LedgerReconciliation`, `WhatsAppReconciliation`, `LessonNotifier` |
| `app/Payment/` | Abstracción de proveedor de pagos — Culqi es un stub | `Contracts/PaymentProviderContract.php`, `CulqiPaymentProvider.php` (lanza excepción), `FakePaymentProvider.php` |
| `app/WhatsApp/` | Abstracción de proveedor de WhatsApp — Meta Cloud API es real | `MetaCloudApiProvider.php` (implementación HTTP real), `FakeWhatsAppProvider.php` |
| `app/Policies/` | 7 Policies — autorización por recurso, patrón `before()` que otorga todo a `admin` | `LessonPolicy`, `ClassRequestPolicy` |
| `app/Notifications/` | 21 clases de notificación, canales mail/database/broadcast/WhatsApp | ver tabla en §12 |
| `app/Console/Commands/` | 10 comandos propios — liquidación, recordatorios, reconciliación, utilidades de testing | `SettleLessons`, `ReconcileLedger`, `ReconcileWhatsApp` |
| `app/Events/` + `app/Listeners/` | 2 eventos, 3 listeners (todos `ShouldQueue`) | `ClassConfirmed`→`SendClassConfirmationNotifications` |
| `app/Providers/` | 6 providers estándar de Laravel | `AuthServiceProvider` (mapa modelo→policy), `AppServiceProvider` (bindings de PaymentProvider/WhatsAppProvider) |
| `routes/` | `web.php` (105 rutas totales según `route:list`), `auth.php`, `api.php` (solo `/api/user` y el webhook de WhatsApp), `channels.php`, `console.php` | |
| `config/` | 22 archivos — incluye `credits.php`, `payments.php`, `jaas.php`, `diagnostic.php`, `profanity.php` (propios de MOVA, no boilerplate de Laravel) | |
| `database/migrations/` | 69 migraciones — historial financiero fuertemente endurecido (ver comentarios de C-1/C-2/C-3 en varias) | |
| `resources/js/` | Vue 3 + Inertia — `Pages/` (55 páginas), `Components/` (22), `Layouts/` (3), `Composables/` (2), `utils/` (4) | |
| `tests/Feature/` | 21 archivos de test, 248 tests totales (verificado ejecutando la suite) | |
| `docs/` | Documentación de sesiones de trabajo — `HANDOFF_FINAL.md` (log histórico extenso), `payments-architecture.md`, `whatsapp-architecture.md`, `WHATSAPP_PRODUCTION_NOTES.md` | |
| `public/`, `storage/` | Estándar de Laravel — build de Vite en `public/build/` | |

**No hay** `Dockerfile`, `docker-compose.yml`, ni carpeta `.github/workflows` visible en esta pasada — UNKNOWN cómo se despliega exactamente en CI/CD; las menciones a "Railway" son de la documentación (`docs/WHATSAPP_PRODUCTION_NOTES.md`), no de config de infraestructura en el repo.

---

## 4. Stack y versiones (reales, de `composer.json`/`package.json`)

| Paquete | Versión | Uso |
|---|---|---|
| `php` | `^8.1` | — |
| `laravel/framework` | `^10.10` | — |
| `inertiajs/inertia-laravel` | `^0.6.8` | Puente Laravel↔Vue |
| `laravel/sanctum` | `^3.2` | Solo `/api/user` (`routes/api.php:17`) — no protege el SPA en sí |
| `laravel/socialite` | `^5.29` | Login con Google |
| `spatie/laravel-permission` | `^6.25` | Roles (`parent`/`teacher`/`admin`) |
| `pusher/pusher-php-server` | `^7.2` | Broadcasting en tiempo real (notificaciones) |
| `firebase/php-jwt` | `^7.1` | Firma de JWT para JaaS/Jitsi |
| `cloudinary-labs/cloudinary-laravel` | `^2.3` | Avatares (con fallback a disco local si no está configurado) |
| `resend/resend-laravel` | `^1.4` | Un mailer disponible entre varios (ver `SafeMailChannel`) |
| `sentry/sentry-laravel` | `^4.26` | Error tracking |
| `tightenco/ziggy` | `^2.6` | Rutas Laravel disponibles en Vue |
| `doctrine/dbal` | `^3.10` (dev) | Necesario para ciertas migraciones — pero con una limitación real descubierta esta sesión: no sabe introspeccionar `enum` en SQLite (ver §29) |
| `@inertiajs/vue3` | `^1.0.0` | — |
| `vue` | `^3.4.0` | `<script setup>` en todo el frontend |
| `tailwindcss` | `^3.2.1` | — |
| `vite` | `^5.0.0` | Bundler |
| `laravel-echo` + `pusher-js` | `^2.4.0` / `^8.5.0` | Cliente de tiempo real |
| `gsap` | `^3.15.0` | Animaciones (landing, dashboards) |
| `swiper` | `^14.0.6` | Carruseles |

**No hay** SDK de Twilio (eliminado esta sesión, ver §11), ni SDK de Culqi, ni SDK de Meta — WhatsApp/Culqi se integran con el cliente `Http` nativo de Laravel.

---

## 5. Modelo de usuarios, roles y autorización

### Roles (Spatie)
`parent`, `teacher`, `admin` — asignados vía `assignRole()` en registro (`RegisteredUserController::store()`, no re-verificada la línea exacta en esta pasada) o Google OAuth (siempre `parent`, `GoogleAuthController.php:50`).

### Enforcement real — verificado, no asumido

**Middleware por rol** (`routes/web.php`):
- `role:parent` + `not.suspended` → rutas de diagnóstico, solicitudes, hijos, calificaciones (`routes/web.php:63-79`).
- `role:teacher` + `not.suspended` → setup, perfil, créditos, ofertas, aceptar solicitudes (`routes/web.php:82-105`).
- `role:admin` (SIN `not.suspended` — deliberado, comentario en `routes/web.php:127-130`: un admin suspendido debe poder revertir su propia suspensión).
- Rutas compartidas (`cancel`/`reschedule`/`join`) solo llevan `not.suspended`, sin `role:X` — la autorización real está en la Policy, no en el middleware (`routes/web.php:109-113`).

**Policies** (`app/AuthServiceProvider.php:623-631` mapea modelo→policy):

| Policy | `before()` | Reglas propias |
|---|---|---|
| `LessonPolicy` | admin=todo | `view`: profesor dueño O padre del alumno. `cancel`/`reschedule`: igual. `confirmPayment`: solo el padre. `createReport`: solo el profesor dueño. `createReview`: solo el padre. |
| `ClassRequestPolicy` | admin=todo | `view` (aprobar/rechazar): padre dueño del alumno. `accept`/`reject` (profesor): **exige `is_verified=true`** — corregido en auditoría del 2026-08-22 tras encontrarlo ausente (comentario extenso en `ClassRequestPolicy.php:53-62`); si la solicitud tiene `teacher_profile_id` fijado (código de referido), es EXCLUSIVA de ese profesor, sin caer al matching por materia. |
| `RechargeRequestPolicy` | admin=todo | `approve`/`reject`/`reverse` → `false` explícito (todo el permiso real viene de `before()`) |
| `ClassOfferPolicy` | admin=todo | `update`/`delete`: solo el profesor dueño |
| `StudentPolicy` | admin=todo | `update`/`delete`: solo el padre dueño |
| `StudentDiagnosticPolicy` | admin=todo | `view`: solo el padre dueño |
| `TeacherReviewPolicy` | admin=todo | `create`: solo el padre del alumno de esa lección. `moderate` → `false` (solo admin) |

**Matriz ROLE × ACTION (resumen, verificado contra las Policies/middleware de arriba, no inferido de nombres de ruta):**

| Acción | parent | teacher | admin |
|---|---|---|---|
| Crear solicitud de clase | ✅ (propio hijo) | ❌ | — |
| Aceptar solicitud → crear Lesson | ❌ | ✅ (si `is_verified`, y solo si es la solicitud le pertenece o matchea materia/oferta) | — |
| Cancelar clase | ✅ (propio hijo) | ✅ (propia clase) | ✅ (cualquiera, vía `AdminController::cancelLesson`) |
| Reprogramar clase | ✅ | ✅ | ❌ (no hay ruta admin para reschedule) |
| Confirmar pago | ✅ solamente | ❌ | ❌ |
| Subir reporte de clase | ❌ | ✅ (propia clase) | ❌ |
| Calificar profesor | ✅ solamente | ❌ | ❌ |
| Recargar créditos (solicitar) | ❌ | ✅ | — |
| Aprobar/rechazar/revertir recarga | ❌ | ❌ | ✅ solamente |
| Verificar/rechazar profesor | ❌ | ❌ | ✅ solamente |
| Suspender usuario | ❌ | ❌ | ✅ (nunca a otro admin — `AdminController.php:281-296`) |
| force-complete / force-refund clase | ❌ | ❌ | ✅ solamente |
| Ocultar/mostrar reseña | ❌ | ❌ | ✅ solamente |

---

## 6. Autenticación

### Flujos

- **Registro** (`RegisteredUserController::store()`): valida rol (`parent`/`teacher`), crea `User`, si es profesor crea `TeacherProfile` + materias (con filtro de palabras `NotProfane`), dispara `Registered` (→ email de verificación) y `WelcomeParentNotification`/`WelcomeTeacherNotification`.
- **Login**: `LoginRequest::authenticate()` (no leído línea por línea esta pasada — scaffolding estándar de Breeze), `throttle:10,1` (`routes/auth.php:26`).
- **Google OAuth**: `GoogleAuthController` — vincula por **email** (`firstOrCreate`, comentario explicando por qué en `GoogleAuthController.php:30`, llamada real en línea 50), gateado por `config('services.google.login_enabled')` tanto en frontend como backend (`GoogleAuthController.php:24-25`).
- **Verificación de email**: estándar Laravel (`MustVerifyEmail`), tras verificar redirige a verificación de teléfono si aplica (`VerifyEmailController.php`, línea no re-verificada en esta pasada).
- **Verificación de teléfono (OTP)**: propio de MOVA, vía WhatsApp — ver detalle completo en §11 y §27-C. Código de 6 dígitos, `Hash::make()` (nunca texto plano), expira en 10 min, máx. 5 intentos, rate limit por usuario (`throttle:3,1`, ruta) Y por número de teléfono (`RateLimiter`, `PhoneVerificationController.php:22,62,82`, 5/hora cruzando cuentas).
- **Recuperación de password**: estándar Laravel (`Password::sendResetLink`/`Password::reset`).
- **Sesiones**: cookies de sesión Laravel estándar (`session` driver=`file`, `EncryptCookies`, `VerifyCsrfToken` en el grupo `web`).
- **CSRF**: activo en `web`, el grupo `api` NO lo incluye (`Kernel.php` — grupo `api` solo tiene `ThrottleRequests`+`SubstituteBindings`) — relevante para el webhook de WhatsApp, que vive en `api.php` precisamente por eso.
- **Suspensión de cuenta**: `EnsureNotSuspended` middleware, redirige a `/suspended` si `suspended_at !== null` (`app/Http/Middleware/EnsureNotSuspended.php:313-317`).

---

## 7. Modelo de datos

### Tablas centrales (columnas relevantes, no exhaustivo de timestamps estándar)

| Tabla | Columnas clave | FK / Unique | Propósito |
|---|---|---|---|
| `users` | `phone`, `phone_verified_at`, `phone_verified_normalized` (UNIQUE, fuera de `$fillable`), `suspended_at`, `parental_control` | — | Cuenta única para los 3 roles |
| `teacher_profiles` | `hourly_rate` decimal(8,2), `credits_available`/`credits_reserved` integer, `referral_code` (6 chars, alfabeto sin vocales/ambiguos), `is_verified`, `mentorship_slots_total`/`taken` | `user_id` FK | 1:1 con `User` cuando `role=teacher` |
| `classes` (modelo `Lesson`) | `status` enum(`scheduled,in_progress,paid,pending_parent_confirmation,completed,cancelled,needs_admin_review`), `jitsi_room`/`jitsi_password` (`$hidden`), `price_frozen_pen` decimal(8,2), `credits_settled_at` (fuera de `$fillable`) | `teacher_profile_id`, `student_id`, `class_request_id`, `class_offer_id` | La "clase" real — nombre de tabla histórico, modelo se llama `Lesson` |
| `class_requests` | `status` enum(`pending_parent_approval,open,accepted,rejected,teacher_rejected,completed`), `teacher_profile_id`/`teacher_referral_code` (fuera de `$fillable`) | `student_id`, `subject_id`, `class_offer_id` | Solicitud del padre, antes de convertirse en `Lesson` |
| `credit_transactions` | `type` enum(`deposit,reservation,consumption,refund,reversal`), `amount` integer, `idempotency_key` (UNIQUE, nullable) | `teacher_profile_id`, `lesson_id`, `recharge_request_id` | **El ledger** — append-only por convención de aplicación (no hay trigger DB que lo fuerce) |
| `recharge_requests` | `status` enum(`pending,approved,rejected,reversed`), `package_code`, `credits`, `amount_pen` decimal(8,2), `operation_number_normalized` | UNIQUE compuesto `(payment_method, operation_number_normalized)` | Recarga manual del profesor |
| `payment_orders` | `status` enum(`created,pending,paid,failed,expired,cancelled`), `amount_minor` unsignedInteger | UNIQUE `(provider, provider_order_id)`, `recharge_request_id` UNIQUE (1:1) | Base para pago automático — sin uso real todavía (Culqi no implementado) |
| `payment_webhooks` | `event_id`, `payload` json, `payload_hash` | UNIQUE `(provider, event_id)` | Idempotencia de webhooks de pago — sin ruta HTTP real todavía |
| `whatsapp_messages` | `status` enum(`sent,delivered,read,failed,unknown`), `client_reference`, `provider_message_id` | UNIQUE `(provider, provider_message_id)` | Auditoría de cada envío WhatsApp |
| `whatsapp_webhook_events` | `event_key` (sha256 de `wamid:status:timestamp`) | UNIQUE `event_key` | Dedup de reentregas de webhook, capa aparte de `whatsapp_messages` |
| `class_events` | `event_type`, `actor_id` (nullable = sistema), `metadata` json | — | Auditoría genérica de eventos de clase/solicitud |
| `ai_usage_logs` | `status`, `prompt_tokens`/`completion_tokens`/`total_tokens` | — | Auditoría de uso de IA — nunca guarda el prompt/respuesta real |

### ERD textual (relaciones Eloquent reales, no inferidas)

```
User
├── hasMany Student (parent_user_id)
├── hasOne TeacherProfile
└── Notifiable (mail vía email, WhatsApp vía routeNotificationForWhatsApp())

TeacherProfile
├── belongsTo User
├── belongsToMany Subject (pivot teacher_subject, con specific_rate)
├── hasMany ClassOffer
├── hasMany Lesson (classes)
├── hasMany TeacherReview
├── hasMany CreditTransaction
└── hasMany RechargeRequest

Student
├── belongsTo User (parent)
├── hasMany ClassRequest
└── hasMany Lesson (classes)

ClassRequest
├── belongsTo Student, Subject, ClassOffer (nullable)
├── belongsTo TeacherProfile (nullable — código de referido directo)
└── hasOne Lesson

Lesson (tabla classes)
├── belongsTo TeacherProfile, Student, ClassRequest, ClassOffer
├── hasOne LessonReport
└── hasOne TeacherReview

RechargeRequest
├── belongsTo TeacherProfile
├── hasOne CreditTransaction
├── hasOne PaymentOrder
└── belongsTo User (reviewer, reversedBy)

CreditTransaction
├── belongsTo TeacherProfile, Lesson (nullable), RechargeRequest (nullable)
```

---

## 8. Créditos y Ledger — sección crítica

### Balance

`teacher_profiles.credits_available` / `credits_reserved` — columnas directas, **NO derivadas en tiempo real**. El ledger (`credit_transactions`) es la fuente de verdad *documentada*, pero el saldo operativo leído en cada request son estas dos columnas, mutadas junto al asiento del ledger dentro de la misma transacción.

### Tipos de asiento (`credit_transactions.type`)

| Tipo | Quién lo crea | Efecto |
|---|---|---|
| `deposit` | `RechargeApprovalService::credit()` | `+credits_available` |
| `reservation` | `LessonController::store()` | `-credits_available`, `+credits_reserved` |
| `consumption` | `LessonSettlementService::consume()` | `-credits_reserved` (clase se cobra) |
| `refund` | `LessonSettlementService::refund()`, `LessonController::cancel()`, `AdminController::cancelLesson()` | `-credits_reserved`, `+credits_available` |
| `reversal` | `RechargeApprovalService::reverse()` | `-credits_available` (revierte un `deposit` ya aplicado — refund/chargeback futuro de Culqi) |

### Idempotencia — dos capas, no una

1. **Chequeo de estado antes de mutar** (ej. `if ($lesson->credits_settled_at !== null) return $lesson;` en `LessonSettlementService.php:883`) — protege el caso normal.
2. **`UNIQUE(idempotency_key)` en la BD** — la garantía REAL contra doble ejecución bajo concurrencia. Patrón repetido en todo el código: `try { CreditTransaction::create([...]) } catch (UniqueConstraintViolationException) { return $lesson->fresh(); }` (`LessonSettlementService.php:915-926`, `:1017-1028`; `RechargeApprovalService.php`). Claves: `"lesson:{id}:reservation"`, `"lesson:{id}:consumption"`, `"lesson:{id}:release"`, `"recharge:{id}:deposit"`, `"recharge:{id}:reversal"`, `"teacher:{id}:welcome"`.

### `LessonSettlementService` — única capa de liquidación (C-1)

`app/Services/LessonSettlementService.php` — antes de esta clase (según su propio docblock, líneas 814-833), "consumir una clase" tenía 4 implementaciones divergentes. Ahora es el único lugar que mueve `credit_transactions` + `teacher_profiles` + `classes.status/credits_settled_at` juntos, para `consume()` y `refund()`.

**Guard explícito contra fabricar dinero:** si `credits_reserved` del profesor es menor al monto a liquidar, lanza `RuntimeException` en vez de empujar el saldo a negativo en silencio (`LessonSettlementService.php:88,105`) — detecta una desincronización ledger↔saldo real en vez de ocultarla.

**`credits_settled_at` fuera de `$fillable`** (`Lesson.php:63,75`) — solo se escribe vía asignación directa + `save()`, nunca `update()` con mass assignment, para que ningún otro código pueda tocarlo por accidente.

### Ejemplo concreto: aceptar una clase (`LessonController::store()`, línea 23)

```
INPUT: class_request_id, start_time, duration_minutes
  → VALIDATION: solicitud sigue 'open', sin solapamiento de horario (fuera de lock, luego re-chequeado dentro)
  → TRANSACTION (DB::transaction):
      → LOCKS: ClassRequest, TeacherProfile (lockForUpdate)
      → re-chequeo de solapamiento CON lock (protege contra dos aceptaciones simultáneas)
      → créditos suficientes? (abort 422 si no)
      → crea Lesson (status='scheduled'), genera jitsi_room/jitsi_password aparte
      → resta credits_available, suma credits_reserved
      → CreditTransaction type=reservation, idempotency_key="lesson:{id}:reservation"
      → ClassRequest.status = 'accepted'
  → EVENT: ClassConfirmed (fuera de la transacción)
  → LISTENER: SendClassConfirmationNotifications (ShouldQueue) → notifica a profesor y padre
  → OUTPUT: redirect a /teacher/classes
```

### Qué pasa en cada escenario de fallo (verificado, no supuesto)

- **Dos requests simultáneas aceptando la misma solicitud:** la segunda falla el re-chequeo de `status='open'` bajo lock (`abort_if`, línea 49) — 403.
- **Transacción falla a mitad:** Laravel hace rollback automático de todo el `DB::transaction()` — ninguna escritura parcial persiste.
- **Un job de liquidación se repite** (`mova:settle-lessons` corriendo dos veces sobre la misma lección): el `UNIQUE(idempotency_key)` en `credit_transactions` lo detiene — la segunda llamada cae al `catch (UniqueConstraintViolationException)` y devuelve la lección tal cual (`LessonSettlementService.php:124`).
- **Una notificación falla:** nunca revierte la transacción financiera — todas las notificaciones se disparan DESPUÉS del `DB::transaction()`, nunca dentro (verificado explícitamente en 11 archivos con `DB::transaction()` esta sesión — ver `docs/HANDOFF_FINAL.md` §23-24).
- **Un pago se duplica** (aplica a Culqi futuro, no operativo hoy): `UNIQUE(provider, event_id)` en `payment_webhooks` + `UNIQUE(idempotency_key)` en `credit_transactions` — doble capa, igual que WhatsApp.
- **Rollback de una migración financiera:** varias migraciones tienen guards explícitos que ABORTAN el rollback si destruiría historial real (`2026_07_10_000002_protect_monetization_history.php`, `2026_08_16_000002_...::assertRollbackDoesNotRewriteLessonStates()`).

### C-1 / C-2 / C-3 (documentados en `docs/HANDOFF_FINAL.md` §17, referenciados en 32+ archivos)

- **C-1**: antes, `completed` solo se alcanzaba tras una reseña voluntaria del padre — créditos podían quedar reservados para siempre si nadie calificaba. Solucionado con `LessonSettlementService`, `mova:settle-lessons` (auto-consume tras `settlement_grace_days`), `credits_settled_at`, `LedgerReconciliation`.
- **C-2**: `reschedule()` permitía cambiar `duration_minutes` sin recalcular créditos/precio — bloqueado explícitamente (`LessonController.php:1428-1432`, rechaza cualquier `duration_minutes` en el request).
- **C-3**: la URL de sala de Jitsi se guardaba en el payload de notificaciones, exponiendo un token de acceso a videollamada con un menor. Corregido; `mova:purge-jitsi-urls` limpia el histórico; `NotificationSecurityTest.php` lo protege permanentemente.

### `mova:reconcile-ledger` (`app/Console/Commands/ReconcileLedger.php` + `app/Support/LedgerReconciliation.php`)

Solo lectura, nunca corrige. Clasifica cada `Lesson` en `HEALTHY_CONSUMED`/`HEALTHY_REFUNDED`/`OPEN_RESERVATION`/`NO_LEDGER`/`INVALID` según sus asientos de ledger, y compara `credits_reserved`/`credits_available` almacenados contra lo derivado del ledger. Exit code ≠0 si hay anomalías.

---

## 9. Recargas (RechargeRequest) — actual vs. futuro

### ACTUALMENTE IMPLEMENTADO (funcional hoy)

Flujo 100% manual: profesor elige paquete de `config/credits.php` (`inicio`=5cr/S10, `impulso`=15cr/S30, `pro`=30cr/S60), escribe `payment_method` + `operation_number` de un pago hecho FUERA de MOVA → `Teacher/CreditController::storeRecharge()`. Backend decide `credits`/`amount_pen` desde el catálogo del servidor, nunca del cliente (validado explícitamente en `MonetizationIntegrityTest::test_recharge_uses_server_catalog_and_ignores_client_financial_values`). Admin aprueba/rechaza (`Admin/RechargeController`), delegando el abono a `RechargeApprovalService::credit()`. Gateado por `RECHARGES_ENABLED` + `RECHARGE_PAYMENT_DESTINATION` en `.env` (`CreditController::rechargesEnabled()`, línea 3686).

### PREPARADO PARA FUTURO PAYMENT PROVIDER (código existe, sin conexión real)

`RechargeRequest.status='reversed'` + `reversed_at/by/reason` — para cuando un pago automático se revierta (refund/chargeback). `RechargeApprovalService::reverse()` ya implementado y testeado, sin ninguna ruta HTTP que lo dispare todavía.

---

## 10. Payments / Culqi

| Pieza | Estado |
|---|---|
| `PaymentProviderContract` (`app/Payment/Contracts/PaymentProviderContract.php`) | **IMPLEMENTADO** (interfaz) |
| `FakePaymentProvider` | **IMPLEMENTADO**, único activo (`config('payments.provider')` default `fake`) |
| `CulqiPaymentProvider` | **STUB** — ambos métodos lanzan `RuntimeException` explícita (`app/Payment/CulqiPaymentProvider.php:26-38`) |
| `PaymentOrder` / `PaymentWebhook` (modelos + migraciones) | **PREPARADO** — tablas existen, sin tráfico real |
| `PaymentWebhookService` | **PREPARADO** — lógica completa y testeada, sin ruta HTTP que la invoque |
| Ruta de webhook de Culqi | **NO EXISTE** — deliberado, sin credenciales que verificar |
| `RechargeApprovalService` | **IMPLEMENTADO Y EN USO REAL** (la aprobación manual de admin ya pasa por aquí) |

**BLOQUEADO POR CREDENCIALES/AFILIACIÓN REAL:** todo lo anterior — MOVA no tiene cuenta comercial de Culqi (confirmado por el usuario en esta sesión, no inferido). Ver `docs/payments-architecture.md`.

---

## 11. WhatsApp

| Pieza | Estado |
|---|---|
| `WhatsAppProviderContract` | IMPLEMENTADO |
| `MetaCloudApiProvider` | **IMPLEMENTACIÓN REAL** (llamadas HTTP genuinas a `graph.facebook.com`, no un stub) — pero nunca probada contra Meta real |
| `FakeWhatsAppProvider` | IMPLEMENTADO, activo por default (`WHATSAPP_PROVIDER=fake`) |
| `WhatsAppChannel` (canal de Notification) | IMPLEMENTADO — envuelve el texto de `toWhatsApp()` de cada notificación en UNA plantilla genérica (`generic_notification`) |
| `WhatsAppMessage` / `WhatsAppWebhookEvent` | IMPLEMENTADO — auditoría de envío + dedup de eventos de webhook |
| `WhatsAppWebhookController` | IMPLEMENTADO pero **INERTE** — sin `META_WHATSAPP_WEBHOOK_VERIFY_TOKEN`/`APP_SECRET` reales, rechaza todo por diseño |
| OTP (`phone_verification_code`) | IMPLEMENTADO — plantilla AUTHENTICATION con componente `button` (sub_type='url', **no confirmado contra Meta directamente**, solo contra documentación de 2 BSP) |
| `mova:reconcile-whatsapp` | IMPLEMENTADO — solo lectura |

**Flujo:** `Notification::toWhatsApp()` (texto ya existente, sin cambios) → `WhatsAppChannel::send()` → `WhatsAppProviderContract::sendTemplate()` → `MetaCloudApiProvider` (HTTP real) → Meta → WhatsApp del usuario → (futuro) webhook → `whatsapp_messages.status` actualizado respetando orden real (`sent<delivered<read`, nunca retrocede).

**Requiere Meta real:** cuenta de Meta Business, WABA, número verificado, plantillas aprobadas (incluida confirmación del formato exacto del botón OTP), credenciales de producción. Ver checklist completo en `docs/WHATSAPP_PRODUCTION_NOTES.md`.

**Migrado desde Twilio esta sesión** — `twilio/sdk` eliminado de `composer.json`, cero referencias residuales verificadas.

**Nota de vigencia (añadida en una ronda posterior, sin reescribir la tabla de arriba):** desde que se escribió esta sección, `whatsapp_messages` ganó un sexto estado (`skipped`, con `skip_reason` estructurado — ver `App\WhatsApp\WhatsAppSkipReason`) y dos bugs reales fueron encontrados y corregidos (F-22: una cuenta suspendida podía seguir recibiendo notificaciones; F-23: un 2xx de Meta sin `messages[0].id` se registraba como `sent` en vez de `unknown`). El detalle completo y actualizado vive en `docs/MOVA_PRODUCTION_READINESS.md` (sección WHATSAPP) — esta tabla se deja como estaba para no mezclar una actualización parcial con una reescritura completa que no era el alcance de esa ronda.

**Write-paths de `whatsapp_messages` — exactamente 3, verificado por búsqueda exhaustiva en `app/`, ninguno más:**
1. `WhatsAppChannel::logSkip()` — crea filas `skipped`.
2. `MetaCloudApiProvider::logAttempt()` — crea filas `sent`/`failed`/`unknown`.
3. `WhatsAppWebhookController::recordStatus()` — actualiza a `delivered`/`read`/`failed`.

Cero `DB::table('whatsapp_messages')` fuera de migraciones, cero Observers, cero Jobs/Listeners adicionales. `WhatsAppReconciliation` es estrictamente de solo lectura (confirmado contra el código, no solo su docblock) — nunca escribe.

---

## 12. Notificaciones (21 clases, `app/Notifications/`)

| Notification | Trigger | Destinatarios | Canales | WhatsApp | Riesgo Jitsi |
|---|---|---|---|---|---|
| `ClassConfirmedNotification` | `ClassConfirmed` event (clase aceptada) | profesor + padre | database, broadcast, mail (si verificado), WhatsApp (si tel. verificado) | ✅ texto propio | Auditado, sin `jitsi_room` (`NotificationSecurityTest`) |
| `ClassReminderNotification` | `SendClassReminders` (24h/2h/10m) | profesor + padre | database, mail, WhatsApp | ✅ | Auditado |
| `ClassCancelledNotification` | cancelación (parent/teacher/admin) | la otra parte (o ambas si admin) | mail, WhatsApp | ✅ | Sin jitsi_room |
| `ClassRescheduledNotification` | `LessonController::reschedule()` | ambas partes | mail | UNKNOWN (no confirmado en esta pasada si tiene `toWhatsApp`) | — |
| `ClassRequestRejectedNotification` | rechazo de profesor | padre | — | — | — |
| `NewClassRequestNotification` | `ClassRequestCreated` event | profesor(es) elegibles | — | — | — |
| `ParentApprovalRequestNotification` | control parental activo | padre | mail, WhatsApp | ✅ | — |
| `PaymentConfirmedNotification` | `confirmPayment()` | profesor | — | — | — |
| `LessonReportPublishedNotification` | reporte subido | padre | — | — | — |
| `LessonSettledNotification` | auto-liquidación (`notify:true` exclusivo del sistema) | profesor + padre (`LessonNotifier::notifyBoth`) | mail, WhatsApp | ✅ | **Auditado explícitamente** — docblock dedicado a nunca incluir jitsi_room/password (`LessonSettledNotification.php:29-30`) |
| `PendingReportReminderNotification` | clase paid sin reporte, dentro de gracia | profesor | mail, WhatsApp | ✅ | — |
| `UnansweredRequestNotification` | solicitud abierta >12h | profesor | mail, WhatsApp | ✅ | — |
| `RechargeApprovedNotification` | admin aprueba recarga | profesor | mail (**sin `toWhatsApp()`** — confirmado, causó un bug de test en esta sesión) | — | — |
| `RechargeRejectedNotification` | admin rechaza recarga | profesor | mail | — | — |
| `NewRechargeRequestNotification` | profesor solicita recarga | todos los admins | mail | — | — |
| `TeacherVerifiedNotification` | admin verifica profesor | profesor | — | — | — |
| `TeacherRejectedNotification` | admin rechaza profesor | profesor | — | — | — |
| `TeacherReviewReceivedNotification` | padre califica | profesor | — | — | — |
| `WelcomeParentNotification` / `WelcomeTeacherNotification` | registro | el usuario | mail, WhatsApp | ✅ | — |
| `WelcomeEmailNotification` | verificación de email completada | el usuario | mail | — | — |

**Aislamiento de fallos verificado:** `SafeMailChannel` (`app/Channels/SafeMailChannel.php`) envuelve el canal de mail para que un error de mailer nunca haga fallar el job completo. `WhatsAppChannel` nunca lanza excepción (siempre `try/catch` interno en `MetaCloudApiProvider`) — verificado con test explícito (`WhatsAppChannelTest::test_a_failed_provider_send_never_throws...`).

**Job por canal, no por notificación** (hallazgo de esta sesión, `vendor/laravel/framework/.../NotificationSender.php`): Laravel despacha un job de cola SEPARADO por cada canal de una notificación — un fallo en `mail` no puede reintentar `WhatsApp` de la misma notificación.

---

## 13. Jitsi / Video

- **Creación de sala:** `LessonController::store()` genera `jitsi_room = "mova-lesson-{id}-" . Str::random(32)` y `jitsi_password = Str::random(10)` (línea 1185-1187) — al aceptar la clase, no antes.
- **JWT de JaaS:** `JaasService::generateToken()` (`app/Services/JaasService.php`) — firma RS256 con `JAAS_PRIVATE_KEY` (nunca sale del backend), incluye `room`, `user.name`, `user.moderator`, expira en 24h (`exp: $now + 86400`), deshabilita explícitamente `livestreaming`/`recording`/`transcription`.
- **Quién lo obtiene:** solo `LessonController::join()` (`app/Http/Controllers/LessonController.php:1251`) — único punto que revela `jitsi_room` (oculto por `$hidden` en el modelo) y emite el JWT, tras `LessonPolicy::view()` + chequeo de estado (`scheduled`/`paid`/`pending_parent_confirmation`). Respuesta con `Cache-Control: no-store, private`.
- **Nunca en emails/WhatsApp:** verificado — `ClassConfirmedNotification`/`ClassReminderNotification`/`LessonSettledNotification` construyen su texto sin tocar `jitsi_room`/`jitsi_password`, protegido por `NotificationSecurityTest.php` (busca la cadena real de la sala, no solo "meet.jit.si", para que cambiar de proveedor no invalide la protección por accidente).
- **Limpieza histórica:** `mova:purge-jitsi-urls` — elimina `jitsi_url` de payloads de notificaciones antiguas (C-3), con `--dry-run`.

---

## 14. Clases / Bookings — máquina de estados de `Lesson` (tabla `classes`)

```
[ClassRequest: open] --LessonController::store()--> scheduled
scheduled --confirmPayment() (solo padre, tras end_time)--> paid
paid --LessonReportController::store() (solo profesor)--> pending_parent_confirmation
pending_parent_confirmation --TeacherReviewController::store() (solo padre)--> completed (consume créditos)
                              --mova:settle-lessons (auto, tras gracia)--> completed (consume créditos)
scheduled --cancel() (padre/profesor/admin)--> cancelled (refund)
paid|pending_parent_confirmation|needs_admin_review --AdminController::forceCompleteLesson--> completed
scheduled|paid|pending_parent_confirmation|needs_admin_review --AdminController::forceRefundLesson--> cancelled
scheduled --mova:settle-lessons (auto, tras unconfirmed_days sin señal)--> needs_admin_review (SIN efecto financiero)
```

`in_progress` existe en el enum de la BD pero **CONFIRMADO como valor muerto** (búsqueda global, 2026-08-24): ningún controlador, servicio, comando, job ni listener lo asigna jamás. Solo vive en las migraciones, en `resources/js/utils/statusColors.js:7` (UI viva que nunca se renderiza) y en un data provider de `RescheduleTest.php:100`. Ver `MOVA_FULL_AUDIT.md` F-09.

**Quién ejecuta cada transición:** ver §5 (matriz de permisos) y `LessonPolicy`.

---

## 15. Reschedule

`LessonController::reschedule()` (línea 1415) — **solo `start_time`**, `duration_minutes` explícitamente rechazado con 422 si viene en el request (línea 1428-1432, comentario C-2). Reutiliza `hasScheduleOverlap()` (el mismo método que `store()`) bajo `lockForUpdate()`, con `$excludeLessonId` para no chocar consigo misma. Guarda `original_start_time` (solo la primera vez), `rescheduled_at/by/reason`. Protección contra manipular precio/créditos: como `duration_minutes` es inmutable, `price_frozen_pen` y los créditos ya reservados nunca se recalculan — no hay superficie para manipularlos vía reschedule.

---

## 16. Cancelaciones y reembolsos

Tres caminos, todos delegando el cálculo del reembolso a `Lesson::reservedCreditAmount()` (lee el ledger, nunca recalcula desde `duration_minutes` — protege contra un reschedule previo):

- `LessonController::cancel()` — parent/teacher/admin, solo desde `scheduled`.
- `AdminController::cancelLesson()` — mismo alcance que el anterior, ruta separada.
- `LessonSettlementService::refund()` — usado por `forceRefundLesson`, alcanza además `paid`/`pending_parent_confirmation`/`needs_admin_review` (terreno que los otros dos NUNCA cubren).

Todos liberan el cupo de mentoría (`mentorship_slots_taken - 1`, clamped a 0) si `classRequest.is_mentorship`.

---

## 17. Admin

Panel disperso en `AdminController` + `Admin/RechargeController` + `TeacherReviewController` (métodos `adminIndex`/`hide`/`showReview`) + `AiUsageController`. Acciones peligrosas identificadas: `verifyTeacher`/`rejectTeacher`, `suspendUser`/`unsuspendUser` (nunca a otro admin), `cancelLesson`/`forceCompleteLesson`/`forceRefundLesson`, `approve`/`reject`/`reverse` de recargas. Todas logueadas con `Log::info('ADMIN_*', [...])` incluyendo `admin_id`. **No hay impersonation** (no encontrado ningún mecanismo de "iniciar sesión como" otro usuario).

---

## 18. Frontend / Vue / Inertia

**Layouts:** `AppLayout.vue` (autenticado, sidebar + topbar), `GuestLayout.vue` (login/registro, panel de marca con copy dependiente de rol), `PublicPageLayout.vue` (marketplace/perfil público, auth-aware).

**55 páginas** bajo `resources/js/Pages/`, organizadas por dominio (`Admin/`, `Auth/`, `ClassOffers/`, `ClassRequests/`, `Dashboard/`, `Diagnostics/`, `Landing/`, `Legal/`, `LessonReports/`, `Lessons/`, `Marketplace/`, `Profile/`, `Reviews/`, `Students/`, `Teacher/`, `Teachers/`).

**Componentes reutilizables:** `NotificationBell.vue`, `JitsiModal.vue`, `WeeklyCalendar.vue`, `AvailabilityPicker.vue`, `TimeSlotPicker.vue`, `StatusBadge.vue`. **Composables:** `useJitsiMeet.js`, `useLessonsViewMode.js`.

**No se auditó línea por línea el frontend en esta pasada** (fuera del trabajo ya hecho en sesiones anteriores sobre `LandingNavbar`/`AppLayout`/`GuestLayout`/`PublicPageLayout`/`Marketplace`/`Teachers/Show`/`Dashboard/Parent`) — cualquier afirmación de "componente gigante" o "lógica de negocio en Vue" queda **UNKNOWN** hasta una pasada dedicada.

---

## 19-20. Performance y Seguridad

**No se realizó auditoría dedicada de performance ni de seguridad activa en esta pasada** (el prompt pidió una fotografía, no una auditoría de hallazgos nuevos) — lo que sigue son observaciones que surgieron naturalmente al leer el código, no un barrido sistemático:

- **Corrección a una nota anterior de esta misma sección**: la fila de
  arriba solía afirmar que `MarketplaceController::index()` "selecciona
  columnas explícitas para nunca exponer `referral_code`" como si eso
  fuera un hecho verificado — no lo era. Una auditoría posterior
  (2026-08-27, ver `docs/MOVA_DESIGN_AUDIT_FINAL.md`, sección P0) encontró
  con `json_encode()` real que el código SÍ tenía la intención documentada
  en un comentario, pero el mecanismo real (`paginate($n, $columns)` tras
  `withAvg()`/`withCount()`) no la cumplía — el modelo completo, incluido
  `referral_code`, sí llegaba al público. Corregido en `0b1bc13`/`904ea07`/
  `607f9ec`/`1c1ac31`. Lección: un comentario que describe la intención de
  seguridad de un query no es evidencia de que el query la cumpla —
  siempre verificar con la respuesta real, no con el comentario.
- **Política arquitectónica establecida a partir de ese incidente**:
  ninguna superficie pública debe depender de la serialización implícita
  de un modelo Eloquent. Todo endpoint público (o `role`-gated con datos
  ajenos, como el caso de `AdminController::pendingTeachers()`) debe:
  (1) fijar `->select([...])` explícito ANTES de cualquier `with()`/
  `withCount()`/`withAvg()` — nunca pasar el allow-list como argumento
  tardío de `get()`/`paginate()`, que no es determinista una vez que un
  agregado ya estableció una proyección; (2) si carga una relación
  `belongsToMany` con `withPivot()`, ocultar el pivot explícitamente
  (`$collection->each->makeHidden('pivot')`) salvo que el pivot sea
  genuinamente parte del contrato público — restringir columnas del
  modelo relacionado (`'subjects:id,name'`) NO suprime el pivot, son
  mecanismos independientes; (3) para superficies con más de un
  consumidor real, un test de allowlist forward-looking (las claves del
  payload son subconjunto de las permitidas) además de los tests de lista
  negra — ver `tests/Feature/PublicTeacherProfileExposureContractTest.php`
  como referencia del patrón.
- El OTP y los secretos de WhatsApp/Culqi están confirmados NO logueados en texto plano (verificado con tests en esta sesión: `MetaCloudApiProviderTest::test_otp_code_never_ends_up_in_the_audit_log_error_field`).
- `LessonController::confirmPayment()` bloquea confirmar antes de que la clase termine, sin bypass por query param ni entorno (comentario explícito, línea 1281-1284) — el único bypass es un comando de consola gateado a `local`/`testing`.
- No se auditaron N+1, índices, ni bundle size del frontend en esta pasada.

---

## 21. Configuración y secretos

`.env`/`.env.example` — variables sensibles confirmadas como REDACTADAS correctamente en `.env.example` (vacías, sin valores reales). `APP_DEBUG`/`APP_ENV` — UNKNOWN el valor real de producción (no se leyó `.env` real, solo se confirmó que `.env.example` no contiene secretos). Config propia de MOVA en `config/credits.php`, `config/payments.php`, `config/jaas.php`, `config/diagnostic.php`, `config/profanity.php`, `config/services.php` (bloque `meta_whatsapp`).

---

## 22-23. Deployment, Jobs, Queues, Commands

**Queue:** `QUEUE_CONNECTION=database` (confirmado en sesiones anteriores), `retry_after=90` (`config/queue.php:41`) — mayor que cualquier timeout HTTP usado en el código (`Http::timeout(10)` en `MetaCloudApiProvider`).

**Scheduler** (`app/Console/Kernel.php`):
- `classmate:send-reminders` → cada minuto.
- `mova:settle-lessons --dry-run` → cada hora, `withoutOverlapping()` — **agendado con `--dry-run` por diseño** (comentario explícito: activar la liquidación real es decisión de negocio, no de despliegue).

**Comandos propios (10):**

| Comando | Propósito | Muta BD | Seguro en producción |
|---|---|---|---|
| `classmate:send-reminders` | Recordatorios 24h/2h/10m + alertas de reporte/solicitud sin responder | Sí (marca `*_sent_at`) | Sí |
| `mova:settle-lessons {--dry-run}` | Auto-liquidación C-1 + escalado a revisión | Sí (sin `--dry-run`) | Sí, con `--dry-run` primero |
| `mova:reconcile-ledger {--json}` | Auditoría financiera | **No** (solo lectura) | Sí, siempre |
| `mova:reconcile-whatsapp {--json} {--stuck-minutes}` | Auditoría de entregas WhatsApp | **No** | Sí, siempre |
| `mova:purge-jitsi-urls {--dry-run}` | Limpieza histórica C-3 | Sí (sin `--dry-run`) | Sí, con `--dry-run` primero |
| `mova:gmail-auth-url` / `mova:gmail-exchange-code` | Setup manual de OAuth de Gmail | No | Sí (solo imprime) |
| `mova:testing-backdate-lesson` / `mova:testing-verify-phone` | Bypasses de testing | Sí | **Solo `local`/`testing`** — guard explícito, aborta en producción |

---

## 24. Tests

**248 tests, 21 archivos, todos `Feature`** (no hay `tests/Unit/`). Ejecutado en esta pasada: **248/248 GREEN**.

**Gaps de cobertura identificados (no exhaustivo):**
- `ClassRescheduledNotification` — UNKNOWN si tiene test de `toWhatsApp()`.
- `in_progress` (estado de `Lesson`) — sin ningún test que lo ejercite (consistente con la sospecha de que es un valor muerto).
- No hay test de concurrencia real (dos requests HTTP simultáneas) para `LessonController::store()` — la protección de `lockForUpdate()` está razonada en comentarios pero no ejercitada con hilos/procesos paralelos reales en PHPUnit (limitación típica del framework, no necesariamente un gap de MOVA).
- Frontend: no hay tests unitarios de Vue/Inertia, pero **sí existe cobertura E2E con Playwright** en `qa/` (`playwright.config.js`, `qa/tests/flujo-completo.spec.js`) — cobertura estrecha (un único spec) pero no nula. *(Corregido 2026-08-24: una versión anterior de este documento afirmaba "cero tests de frontend"; era incorrecto, se basó en inspeccionar solo `tests/`. Ver `MOVA_FULL_AUDIT.md` FP-05.)*

---

## 25. Comandos Artisan — ver tabla completa en §23.

---

## 26. Estados del sistema — tabla global

| Entidad | Estados | Transiciones válidas | Quién |
|---|---|---|---|
| `Lesson.status` | scheduled, in_progress(¿?), paid, pending_parent_confirmation, completed, cancelled, needs_admin_review | ver §14 | ver §5 |
| `ClassRequest.status` | pending_parent_approval, open, accepted, rejected, teacher_rejected, completed | pending→open (padre aprueba)/rejected; open→accepted (profesor acepta)/teacher_rejected | padre, profesor |
| `RechargeRequest.status` | pending, approved, rejected, reversed | pending→approved/rejected; approved→reversed | admin |
| `PaymentOrder.status` | created, pending, paid, failed, expired, cancelled | sin tráfico real todavía | sistema (futuro) |
| `WhatsAppMessage.status` | sent, delivered, read, failed, unknown | sent→delivered→read (nunca retrocede); →failed (terminal, solo si no delivered/read) | sistema/webhook |
| `TeacherProfile` (verificación) | pendiente (`is_verified=false, rejected_at=null`) → verificado / rechazado | ver `isPending()`/`isRejected()` (`TeacherProfile.php:940-948`) | admin |
| `User.suspended_at` | null / timestamp | admin suspende/reactiva (nunca a otro admin) | admin |

---

## 27. Flujos completos (diagramas ASCII)

### A. Registro
```
Padre/Profesor → POST /register → RegisteredUserController::store()
  → valida rol, crea User, (si teacher) crea TeacherProfile + Subjects
  → event(Registered) → SendEmailVerificationNotification
  → notify(WelcomeParent|TeacherNotification)
  → Auth::login() → redirect a /students/create ó /teacher/setup
```

### C. OTP (verificación de teléfono)
```
Usuario → POST /verify-phone/send → PhoneVerificationController::send()
  → valida teléfono no verificado en OTRA cuenta (chequeo amistoso)
  → RateLimiter por-teléfono (5/hora, cruza cuentas) + throttle:3,1 por-ruta
  → genera código 6 dígitos, Hash::make(), expira 10min
  → WhatsAppProviderContract::sendTemplate('phone_verification_code', [code])
  → MetaCloudApiProvider → Meta Graph API (o FakeWhatsAppProvider en dev)
Usuario → POST /verify-phone {code} → verify()
  → Hash::check(), máx 5 intentos, expiración
  → phone_verified_at=now(), UNIQUE(phone_verified_normalized) como garantía real
  → si teacher: grantTeacherWelcomeBonus() (+5 créditos, idempotente)
```

### E. Reservar clase (profesor acepta solicitud)
Ver diagrama completo en §8 ("Ejemplo concreto").

### M. Webhook WhatsApp
```
Meta → POST /api/webhooks/whatsapp → WhatsAppWebhookController::handle()
  → límite de tamaño (1MB) → firma HMAC-SHA256 sobre raw body (X-Hub-Signature-256)
  → extractStatuses(payload) → por cada status:
      → recordNewEvent() [UNIQUE(event_key) en whatsapp_webhook_events — dedup de entrega]
      → DB::transaction + lockForUpdate sobre WhatsAppMessage
      → shouldApply() [nunca retrocede sent<delivered<read; failed es terminal]
      → update status/delivered_at/read_at
  → responde 200 rápido (Meta reintenta si no recibe 2xx a tiempo)
```

---

## 28. Invariantes del sistema (descubiertos leyendo código, no supuestos)

1. Un `CreditTransaction` con un `idempotency_key` dado nunca se crea dos veces (UNIQUE de BD).
2. `credits_reserved`/`credits_available` de un `TeacherProfile` nunca se empujan a negativo por `LessonSettlementService` — se aborta con `RuntimeException` en vez de fabricar el faltante.
3. Un evento de webhook de WhatsApp (`wamid:status:timestamp`) nunca se aplica dos veces (UNIQUE `event_key`).
4. El estado de entrega de un `WhatsAppMessage` nunca retrocede (`sent<delivered<read`), sin importar el orden de llegada del webhook.
5. Un profesor sin `is_verified=true` no puede aceptar/rechazar ninguna solicitud (`ClassRequestPolicy::accept()`).
6. `duration_minutes` de una `Lesson` es inmutable tras crearse — `reschedule()` lo rechaza explícitamente.
7. `jitsi_room`/`jitsi_password` nunca salen de `LessonController::join()` — ocultos por defecto en el modelo, nunca en notificaciones.
8. `phone_verified_normalized` es único entre cuentas — un mismo número no puede verificarse en dos `User` distintos.
9. `credits_settled_at` (Lesson) y `referral_code` (TeacherProfile) solo se escriben desde su capa autorizada — fuera de `$fillable` a propósito.
10. Un admin nunca puede suspender a otro admin.

---

## 29. Deuda técnica (separada estrictamente de bugs/vulnerabilidades)

### Categoría de riesgo registrada: DATABASE CONTRACT / ENVIRONMENT PARITY DRIFT

No es una abstracción de código — es una categoría de auditoría a
comprobar cuando se toque cualquier columna de estado/enum en el futuro.
Dos casos reales ya encontrados y corregidos (2026-08-27), en direcciones
opuestas del mismo problema de raíz:

| Columna | Síntoma en SQLite | Efecto | Corregido en |
|---|---|---|---|
| `class_requests.status` | `CHECK` **demasiado estricto** — rechazaba `teacher_rejected`, un valor que MySQL sí aceptaba | escritura real de producción fallaba con 500 si corría contra la BD de test | `2026_08_27_000002` |
| `classes.status` | **sin `CHECK` alguno** — VARCHAR libre, aceptaba cualquier string | un typo pasaría silenciosamente toda la suite (100% SQLite) y solo se descubriría contra MySQL real | `2026_08_27_000003` |

**Ambas comparten la misma causa raíz**: una migración que amplía un `ENUM`
de MySQL con un `if (driver === 'mysql')` sin rama SQLite equivalente (o,
en el caso de `classes.status`, que resolvió el problema de Doctrine DBAL
convirtiendo la columna en un string libre en vez de reconstruir el
`CHECK`). El patrón de corrección establecido (`rebuildStatusColumn()` +
guard "fail loud" de drift en MySQL vía `SHOW COLUMNS`) es ahora el
estándar a seguir — ver los propios archivos de migración citados arriba
para el código exacto.

**Inventario de riesgo pendiente — comprobación barata ejecutada ahora
(2026-08-27), auditoría completa de escritores NO ejecutada todavía:**

Antes de dejar esto como "sin auditar" sin más, se hizo la comprobación
más barata posible (fresh migrate + lectura de `sqlite_master`, sin
rastrear escritores) para los tres dominios de estado restantes de §26 —
resultado real, no supuesto:

| Columna | CHECK real en SQLite (verificado ahora) | Riesgo de esta categoría |
|---|---|---|
| `recharge_requests.status` | `('pending','approved','rejected','reversed')` — coincide con §26 | Bajo — CHECK ya existe y ya coincide |
| `payment_orders.status` | `('created','pending','paid','failed','expired','cancelled')` — coincide con §26 | Bajo — CHECK ya existe y ya coincide; además "sin tráfico real todavía" (§26) |
| `whatsapp_messages.status` | `('sent','delivered','read','failed','unknown','skipped')` — coincide con §26 (incluye `skipped`, migración `2026_08_24_000005`) | Bajo — CHECK ya existe y ya coincide |

**Ninguno de los tres reproduce el síntoma** (`CHECK` ausente o
desincronizado) que sí tenían `class_requests.status` y `classes.status` —
los dos casos reales parecen haber sido los únicos de su tipo en el
repositorio, no la punta de un problema mayor. Esto reduce el riesgo de
"auditoría infinita" que motivaría seguir revisando tabla por tabla antes
de continuar con producto.

**Lo que esta comprobación NO cubre** (a diferencia del barrido completo
de `class_requests`/`classes`): no se rastreó cada escritor real de estas
tres columnas en el código, no se buscaron estados muertos, y no se
verificó el guard de MySQL "fail loud" (estas migraciones son anteriores
al patrón y pueden no tenerlo). Si se toca cualquiera de estos tres
dominios por otro motivo, repetir el procedimiento completo en ese momento
— no se cierra la categoría de riesgo entera solo por esta comprobación,
solo se descarta la variante "CHECK ausente" que ya causó los dos bugs
anteriores.

**CRITICAL:** ninguna identificada en esta pasada (fuera de alcance auditar activamente).

**HIGH:**
- `LessonController::cancel()` y `AdminController::cancelLesson()` duplican la lógica de refund que `LessonSettlementService::refund()` ya centraliza — reconocido explícitamente en el propio código (`LessonSettlementService.php:831-833`: "Deliberadamente NO se migran aquí... queda como deuda técnica explícita").
- Las 21 clases de `Notification` conocen demasiado del canal (`toWhatsApp(): string` en vez de un payload independiente de canal) — documentado como deuda deliberada en `docs/whatsapp-architecture.md`, no una omisión.

**MEDIUM:**
- Doctrine DBAL no puede alterar columnas `enum` en SQLite — cada migración que amplía un enum necesita una reconstrucción manual de columna (patrón ya usado 3 veces esta sesión — `recharge_requests.status`, `class_requests.status`, `classes.status` — documentado en los propios archivos de migración; los dos últimos casos además añaden un guard que hace fallar la migración si el `ENUM` real de MySQL no coincide con lo que la rama MySQL asume, en vez de un no-op ciego).
- `in_progress` en `classes.status` — confirmado exhaustivamente que es un valor muerto (ningún controlador/servicio/job/listener/modelo lo asigna); ya eliminado del enum de MySQL en `2026_08_24_000002_remove_in_progress_from_classes_status_enum.php`, y el `CHECK` nuevo de SQLite (`2026_08_27_000003`) lo rechaza igual que MySQL.
- `completed` en `class_requests.status` — mismo patrón, recién confirmado (2026-08-27): está en el enum desde la migración original de 2024 pero ningún punto del código actual lo escribe (la finalización real se rastrea en `classes.status`, tabla distinta). No roto, solo muerto — no se ha limpiado.
- `teacher_subject.specific_rate` (columna pivote de `TeacherProfile::subjects()`, distinta de `class_offers.specific_rate`, que sí está viva) — confirmado (2026-08-27, durante el cierre de la auditoría de exposición pública) que los 3 escritores reales (`RegisteredUserController`, `TeacherProfileController::storeSetup()`/`update()`) siempre la sincronizan como `null`, y ningún backend/frontend la lee. Parece el vestigio de una funcionalidad de "tarifa por materia" que se abandonó en favor de `ClassOffer.specific_rate`. No roto (de hecho motivó ocultar el pivot de la serialización pública, ver `docs/MOVA_DESIGN_AUDIT_FINAL.md`), solo muerto — no se ha limpiado ni se ha decidido si reactivar o eliminar la columna.
- Plantilla OTP de WhatsApp (`sub_type='url'` del botón) sin confirmar contra Meta directamente — solo contra documentación de 2 BSP.

**LOW:**
- `docs/HANDOFF_FINAL.md` es un log histórico extenso (>1600 líneas) sin índice — difícil de navegar para alguien nuevo (mitigado parcialmente por este mismo documento).

---

## 30. Mapa de dependencias — componentes centrales

```
LessonSettlementService          ← el más central: usado por TeacherReviewController,
                                    AdminController (force-complete/refund), mova:settle-lessons
RechargeApprovalService          ← usado por Admin/RechargeController::approve() Y
                                    (preparado, sin uso real) PaymentWebhookService
WhatsAppProviderContract         ← usado por WhatsAppChannel (todas las notificaciones)
                                    Y PhoneVerificationController (OTP) — punto de alto
                                    acoplamiento si el proveedor cambia
ClassEvent::log()                ← auditoría transversal, llamado desde 6+ controladores/servicios
Lesson::reservedCreditAmount()   ← usado por los 3 caminos de cancelación/refund — single
                                    source of truth del monto a devolver
```

**Puntos de alto riesgo:** `LessonSettlementService` (toda la liquidación financiera pasa por 2 métodos), `WhatsAppProviderContract` (single point of failure de notificaciones externas — pero con fallback seguro: nunca lanza excepción).

---

## 31. ~50 archivos más importantes (curados por rol, no por tamaño)

| Path | Rol | Riesgo |
|---|---|---|
| `app/Services/LessonSettlementService.php` | Única capa de liquidación financiera | CRÍTICO |
| `app/Http/Controllers/LessonController.php` | Reserva/cancela/reprograma clases, revela Jitsi | CRÍTICO |
| `app/Services/RechargeApprovalService.php` | Único camino de abono/reversión de créditos | CRÍTICO |
| `app/Support/LedgerReconciliation.php` | Auditoría financiera de solo lectura | ALTO |
| `app/Services/JaasService.php` | Firma JWT de acceso a videollamada con menores | CRÍTICO |
| `app/Policies/ClassRequestPolicy.php` | Gate de acceso profesor↔solicitud (menores involucrados) | CRÍTICO |
| `app/Models/Lesson.php` | Modelo central, oculta Jitsi, calcula costo de crédito | ALTO |
| `app/Models/CreditTransaction.php` | El ledger | ALTO |
| `app/Http/Controllers/Admin/RechargeController.php` | Aprobación de dinero por admin | ALTO |
| `app/Console/Commands/SettleLessons.php` | Auto-liquidación programada | ALTO |
| `app/WhatsApp/MetaCloudApiProvider.php` | Único emisor real de WhatsApp | MEDIO |
| `app/Http/Controllers/WhatsAppWebhookController.php` | Único receptor externo autenticado por firma | MEDIO |
| `app/Http/Middleware/EnsureNotSuspended.php` | Enforcement de suspensión | MEDIO |
| `app/Providers/AuthServiceProvider.php` | Mapa completo modelo→policy | ALTO (single source) |
| `routes/web.php` | Mapa completo de superficie HTTP autenticada | ALTO |
| `database/migrations/2026_07_10_000001_harden_monetization_records.php` | Endurecimiento histórico del ledger | REFERENCIA |
| `config/credits.php` | Catálogo de paquetes — única fuente de precio real | ALTO |
| `app/Services/DiagnosticAiEnrichmentService.php` | Único punto que envía datos a IA externa — contrato de privacidad | MEDIO |

*(Lista no exhaustiva — se priorizaron los ~18 de mayor riesgo real en vez de forzar 50 por cuota.)*

---

## 32. "Dónde mirar si aparece un bug"

**"Créditos incorrectos" / balance no cuadra:**
`app/Support/LedgerReconciliation.php` (correr `mova:reconcile-ledger --json` primero) → `app/Services/LessonSettlementService.php` → `app/Http/Controllers/LessonController.php` (reserva) → `app/Http/Controllers/Admin/RechargeController.php` (abono).

**"WhatsApp no envía":**
`.env` (`WHATSAPP_ENABLED`, `WHATSAPP_PROVIDER`) → `app/Channels/WhatsAppChannel.php` (¿phone_verified? ¿kill switch?) → `app/WhatsApp/MetaCloudApiProvider.php` (credenciales/plantilla) → tabla `whatsapp_messages` (¿qué status quedó?) → `mova:reconcile-whatsapp --json`.

**"Profesor puede acceder a datos ajenos":**
`app/Policies/ClassRequestPolicy.php` (`accept`/`reject`) → `app/Policies/LessonPolicy.php` → `app/AuthServiceProvider.php` (¿está la Policy realmente mapeada al modelo?).

**"Clase duplicada" / doble reserva:**
`LessonController::store()` → `hasScheduleOverlap()` (¿se llama con `lock=true` dentro de la transacción?) → `credit_transactions.idempotency_key` (¿hay 2 filas con la misma clave? no debería ser posible por el UNIQUE).

**"Se filtró la sala de Jitsi":**
`app/Models/Lesson.php` (`$hidden`) → cualquier `Notification::toMail()/toWhatsApp()/toArray()` que use `$lesson` → `tests/Feature/NotificationSecurityTest.php` (¿sigue pasando?).

**"El webhook de WhatsApp/Culqi hizo algo raro":**
`app/Http/Controllers/WhatsAppWebhookController.php` (firma, dedup, orden) / `app/Services/PaymentWebhookService.php` → tabla `whatsapp_webhook_events` / `payment_webhooks` (¿el evento ya existía?).

**"Un profesor no verificado dio clase":**
`app/Policies/ClassRequestPolicy.php:63-84` (`accept()`) — si esto alguna vez deja de chequear `is_verified`, es el hallazgo CRÍTICO que ya se corrigió una vez (2026-08-22).

---

## 33. Resumen ejecutivo

**MOVA en una frase:** plataforma peruana de clases particulares online donde el profesor compra créditos para aceptar solicitudes de padres, con videollamada Jitsi/JaaS y un ledger financiero append-only fuertemente endurecido tras 3 rondas de auditoría (C-1/C-2/C-3).

**Stack:** Laravel 10 + Inertia + Vue 3 + Tailwind, MySQL, sesiones de cookie, Spatie Permission, Pusher/Echo, Jitsi vía JaaS.

**Usuarios:** parent, teacher, admin.

**Núcleo financiero:** `credit_transactions` (append-only, idempotente por UNIQUE) + `teacher_profiles.credits_available/reserved`, orquestado casi en su totalidad por `LessonSettlementService` + `RechargeApprovalService`.

**Núcleo de clases:** `Lesson` (tabla `classes`) con 7 estados, `LessonController` + `LessonSettlementService`.

**Núcleo de notificaciones:** 21 clases `Notification`, 4 canales (mail/database/broadcast/WhatsApp), aisladas de fallos entre sí.

**Integraciones externas:** Jitsi/JaaS (activo, en producción), Cloudinary (opcional, con fallback), Gmail API (opcional), Google OAuth (opcional, feature-flagged), WhatsApp Meta Cloud API (código real, sin cuenta Meta), Culqi (stub, sin cuenta), IA de diagnóstico OpenAI/Gemini (opcional, feature-flagged, con fallback determinista garantizado).

**Principales riesgos:** dependencia de que `is_verified` en `ClassRequestPolicy` nunca se olvide (ya pasó una vez); el enum SQLite/Doctrine es una fuente recurrente de bugs silenciosos en migraciones nuevas; ningún test de concurrencia real con procesos paralelos.

**Principales deudas técnicas:** refund duplicado en 3 lugares (reconocido, no urgente); las 21 notificaciones acopladas al formato de texto libre de WhatsApp.

**Partes más críticas:** `LessonSettlementService`, `JaasService`, `ClassRequestPolicy`.

**Partes más frágiles:** integración de Culqi/WhatsApp con Meta (código listo, cero tráfico real probado).

**Partes más maduras:** el ledger financiero (`credit_transactions` + `LessonSettlementService` + `LedgerReconciliation`) — 3 rondas de auditoría, guards explícitos contra fabricar dinero, tests extensos.

**Listo para producción:** flujo completo de clases/créditos/reseñas/reportes; autenticación; Jitsi/JaaS.

**No listo para producción:** pagos automáticos (Culqi) y WhatsApp real — ambos bloqueados por credenciales externas, no por código pendiente.

---

## 34-35. Verificación final ejecutada en esta sesión

| Comando | Resultado |
|---|---|
| `php artisan test` | **248/248 GREEN** |
| `npm run build` | Limpio |
| `php artisan route:list --except-vendor` | 105 rutas propias, todas resueltas a controladores existentes |
| `php artisan migrate:status` | 69/69 migraciones `Ran` |
| `git diff --check` | Limpio |

No se modificó ningún archivo de código durante esta tarea — solo se creó este documento.
