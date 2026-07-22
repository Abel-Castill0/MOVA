# MOVA — Documento Maestro de Contexto

> **Estado del documento:** generado el 2026-07-18 mediante análisis directo del código fuente.
> **Rama analizada:** `fix/monetization-integrity`
> **Alcance:** modelos, controladores, servicios, migraciones, configuración, frontend, infraestructura y deuda técnica.
>
> ⚠️ **Este documento no contiene secretos.** Todas las credenciales se referencian por *nombre de variable de entorno*, nunca por valor.

---

## Tabla de contenidos

1. [Resumen del producto y modelo de negocio](#1-resumen-del-producto-y-modelo-de-negocio)
2. [Arquitectura técnica y stack](#2-arquitectura-técnica-y-stack)
3. [Integraciones de terceros](#3-integraciones-de-terceros-apis-y-servicios)
4. [Estado actual del entorno (local y producción)](#4-estado-actual-del-entorno-local-y-producción)
5. [Mapa de deuda técnica y pendientes](#5-mapa-de-deuda-técnica-y-pendientes)
6. [Apéndices](#6-apéndices)

---

## 1. RESUMEN DEL PRODUCTO Y MODELO DE NEGOCIO

### 1.1 ¿Qué es MOVA?

MOVA es una **plataforma de clases particulares (tutorías) online** orientada al mercado peruano. Conecta a **padres/tutores** que buscan apoyo académico para sus hijos con **profesores** verificados que ofrecen clases en materias específicas.

Señales del código que confirman el enfoque peruano:
- Moneda **PEN** (soles) en todo el sistema de créditos (`config/credits.php` → `amount_pen`).
- Normalización de teléfonos **+51** con validación de móvil peruano de 9 dígitos (`User::normalizePhone()`).
- Métodos de pago **Yape / Plin / transferencia bancaria** (billeteras móviles peruanas).
- Zona horaria por defecto `America/Lima` (`config/zoom.php`).
- Interfaz íntegramente en español.

La propuesta de valor no es solo el "match" profesor-alumno, sino un **circuito cerrado de calidad**: diagnóstico inicial del alumno → recomendación de profesores con scoring determinista → clase por videollamada → **reporte pedagógico obligatorio** → calificación del padre. El reporte es el diferenciador: el padre recibe evidencia escrita de qué se trabajó y cuál es el siguiente paso.

### 1.2 Tipos de usuario (roles)

Los roles se gestionan con **`spatie/laravel-permission`** (tabla `roles` + `model_has_roles`). Existen **tres roles reales**:

| Rol | Modelo(s) | Descripción |
|---|---|---|
| **`parent`** (Padre/Tutor) | `User` + `Student[]` | Cuenta con login. Registra a sus hijos, crea diagnósticos y solicitudes, aprueba/rechaza clases, confirma pagos y califica profesores. |
| **`teacher`** (Profesor) | `User` + `TeacherProfile` | Cuenta con login. Publica ofertas, acepta solicitudes, agenda clases, sube reportes pedagógicos. Gestiona su saldo de créditos. |
| **`admin`** (Administrador) | `User` | Cuenta con login. Verifica/rechaza profesores, aprueba recargas de crédito, modera reseñas, suspende usuarios, audita uso de IA. |

**Punto clave de arquitectura — el Alumno NO es un usuario:**
`Student` es una **entidad de datos, no una cuenta de acceso**. No tiene `email`, ni `password`, ni rol. Está atado a su padre vía `students.parent_user_id` (FK con `cascadeOnDelete`). Consecuencia: **los menores nunca inician sesión en MOVA**; toda interacción pasa por la cuenta del padre. Esto es una decisión deliberada de protección de menores y simplifica enormemente el modelo de consentimiento parental.

Campos de `Student`: `parent_user_id`, `first_name`, `last_name`, `birth_date`, `grade_level` (enum: `primaria` | `secundaria` | `universidad`), `school`.

**Control parental adicional:** `users.parental_control` (boolean) — gestionado vía `ParentSettingsController`, gatea si las solicitudes requieren aprobación explícita del padre (estado `pending_parent_approval` en `class_requests`).

### 1.3 Flujo principal end-to-end

#### Fase A — Registro y onboarding

```
Registro (/register) → elige rol: parent | teacher
   │
   ├─ PARENT  → notificación WelcomeParentNotification → redirect a /students/create
   │
   └─ TEACHER → debe declarar ≥1 materia en el registro (validación required_if)
                → se crea TeacherProfile (hourly_rate = 0, is_verified = false)
                → WelcomeTeacherNotification → redirect a /teacher/setup
```

Puertas de acceso, en orden (definidas en `routes/web.php`):
1. **`auth`** — sesión iniciada.
2. **`verified`** — email verificado (`MustVerifyEmail` implementado en `User`).
3. **`role:parent|teacher|admin`** — rol correcto.
4. **`not.suspended`** (`EnsureNotSuspended`) — cuenta no suspendida por admin.

Verificación de teléfono (`/verify-phone`, `PhoneVerificationController`): código de 6 dígitos enviado por **WhatsApp/Twilio**, hasheado en BD (`phone_verification_code_hash`), TTL 10 minutos, máximo 5 intentos, con throttle de ruta (`throttle:3,1` para envío, `throttle:10,1` para verificación).

**El profesor debe verificar su teléfono para desbloquear sus 5 créditos de bienvenida** (ver §1.4).

#### Fase B — Diagnóstico y descubrimiento (lado padre)

```
/diagnostics/create → wizard de diagnóstico del alumno
   ├─ campos: subject_id, level, difficulty_text, school_feedback, goal, urgency
   ├─ goal ∈ {reinforce_topic, prepare_exam, recover_grades, solve_homework, continuous_support}
   │
   ├─ [opcional] DiagnosticAiEnrichmentService → enriquece con Gemini/OpenAI
   │     (keywords, nivel detectado, resumen para el padre, banderas de riesgo)
   │
   └─ DiagnosticRecommendationService → scoring DETERMINISTA → top-5 profesores
         → /diagnostics/{id}/results
```

**Regla de negocio crítica (documentada en `config/diagnostic.php`):**
> La IA **nunca** elige ni rankea profesores. El scoring determinista (`DiagnosticRecommendationService`) siempre corre. La IA solo enriquece la *descripción* del diagnóstico. Si la IA falla, el wizard continúa sin enriquecimiento (fallback garantizado).

Fórmula de scoring determinista (0–100), tomada del docblock del servicio:

| Puntos | Criterio |
|---|---|
| 35 | Base — la oferta pasó los filtros (materia coincide, profesor verificado, bio no vacía, tarifa > 0) |
| 15 | Coincidencia de nivel (`subject.level` ↔ `student.grade_level`) |
| 15 | Historial previo de clases entre ese alumno y ese profesor |
| 15 | Bio detallada (≥ 200 caracteres) |
| 5 | Bio presente pero corta (< 200 chars) |
| 10 | Tarifa por hora definida (> 0) |
| 5 | Título de oferta > 10 caracteres |
| 10 | Disponibilidad coincide con urgencia (`today_or_tomorrow` / `this_week`); `NULL` es neutral |

Ruta alternativa de descubrimiento: **`/marketplace`** (`MarketplaceController`) y perfil público **`/teachers/{teacherProfile}`** (`TeacherPublicController`) — ambas **públicas, sin autenticación**.

#### Fase C — Solicitud y match

Máquina de estados de `class_requests.status` (enum MySQL, ampliado en `2026_07_08_000001`):

```
pending_parent_approval ──(padre aprueba)──> open
        │                                      │
        └──(padre rechaza)──> rejected         ├──(profesor acepta)──────> accepted
                                               ├──(profesor rechaza)─────> teacher_rejected
                                               └──────────────────────────> completed
```

- El padre crea la solicitud (`class-requests.store`, throttle `10,1`).
- Con `parental_control` activo, nace en `pending_parent_approval` y requiere `approve`/`reject` del padre.
- El profesor la ve en `/teacher/requests`, y puede **rechazarla con motivo** (`teacherReject` → `teacher_rejected` + `ClassRequestRejectedNotification`) o **aceptarla** (`/teacher/requests/{id}/accept`).

#### Fase D — Agendamiento (aquí se reservan créditos)

`POST /lessons` → `LessonController::store()` (throttle `10,1`). Todo dentro de una **transacción con `lockForUpdate()`** sobre `ClassRequest` y `TeacherProfile`:

1. Valida propiedad (la solicitud pertenece a una oferta o materia del profesor).
2. Valida solapamiento de horario (`hasScheduleOverlap`, doble chequeo dentro de la transacción).
3. **Verifica saldo:** `credits_available >= 2` → si no, `422 "Créditos insuficientes"`.
4. Si es mentoría (`is_mentorship`), valida cupos disponibles (`hasAvailableMentorshipSlots`).
5. Crea la `Lesson` con `status = 'scheduled'`.
6. **Genera la sala Jitsi:** `jitsi_room = "mova-lesson-{id}-" . Str::random(8)`.
7. **Mueve créditos:** `credits_available -= 2`, `credits_reserved += 2`.
8. Escribe el asiento contable: `CreditTransaction` tipo `reservation`, `idempotency_key = "lesson:{id}:reservation"`.
9. Marca `class_request.status = 'accepted'`.
10. Dispara `event(new ClassConfirmed($lesson))` → notifica a **ambas partes**.

#### Fase E — Clase, pago offline y cierre (el "Handshake")

Máquina de estados de `classes.status` (enum ampliado en `2026_07_17_000001`):

```
scheduled ──(padre: "Ya pagué")──> paid ──(profesor sube reporte)──> pending_parent_confirmation
    │                                                                          │
    │                                                          (padre califica) │
    └──(cancelación)──> cancelled                                               ▼
                                                                           completed
```

| Transición | Endpoint | Actor | Guardas |
|---|---|---|---|
| `scheduled → paid` | `POST /lessons/{id}/confirm-payment` | Padre | Debe ser el padre dueño del alumno; la clase **debe haber terminado** (`now() >= end_time`); estado debe ser `scheduled`. Transacción + `lockForUpdate`. Registra `ClassEvent('payment_confirmed')` y notifica al profesor. |
| `paid → pending_parent_confirmation` | `POST /lessons/{id}/report` | Profesor | Estado debe ser `paid`. Crea `LessonReport` (único por clase) y avanza el estado en la misma transacción. |
| `pending_parent_confirmation → completed` | `POST /lessons/{id}/review` | Padre | Estado debe ser `pending_parent_confirmation`. **Aquí se consume el crédito** (ver §1.4). |

**Diseño clave:** el crédito reservado **no se consume al terminar la clase**, sino cuando el padre califica. Esto alinea el incentivo económico del profesor con el cierre completo del circuito de calidad (reporte + calificación).

Videollamada: el botón **"🎥 Ingresar a la Sala Virtual"** aparece en las tarjetas de clase (`ParentIndex.vue` / `TeacherIndex.vue`) cuando `jitsi_room != null` **y** (`status === 'paid'` **o** `status === 'scheduled'` con ≤ 15 min para el inicio). Abre un `<iframe src="https://meet.jit.si/{jitsi_room}">` dentro de un modal — nunca en pestaña nueva.

#### Fase F — Cancelación y reprogramación

`POST /lessons/{id}/cancel` (padre, profesor **o** admin) — solo si `status === 'scheduled'`:
- Devuelve los créditos: `credits_available += 2`, `credits_reserved -= 2`.
- Asiento `CreditTransaction` tipo `refund`, key `"lesson:{id}:release"`.
- Si era mentoría, libera el cupo (`mentorship_slots_taken -= 1`, con piso en 0).
- Registra `ClassEvent('class_cancelled')` con motivo.

`POST /lessons/{id}/reschedule` — conserva `original_start_time`, `rescheduled_at`, `rescheduled_by`, `reschedule_reason`.

### 1.4 Modelo de monetización

**Quien paga a MOVA es el PROFESOR, no el padre.** El profesor compra créditos; cada clase aceptada le cuesta créditos. El pago de la clase en sí (padre → profesor) ocurre **fuera de la plataforma** vía Yape/Plin.

#### Unidad de cuenta

`config/credits.php`:
```
credit_minutes    = 60      // 1 crédito ≈ 60 minutos (referencia)
credit_price_pen  = 2.00    // 1 crédito = S/ 2.00
fixed_class_cost  = 2       // costo fijo por clase (Fase 1)
```
El costo por clase también está centralizado como constante de dominio: `Lesson::CLASS_CREDIT_COST = 2`.

#### Paquetes de recarga

| Código | Nombre | Créditos | Precio |
|---|---|---|---|
| `inicio` | Inicio | 5 | S/ 10.00 |
| `impulso` | Impulso | 15 | S/ 30.00 |
| `pro` | Pro | 30 | S/ 60.00 |

**El catálogo es autoridad del servidor.** El controlador nunca acepta montos ni cantidades de crédito desde el cliente — solo un `package_code` que resuelve contra `config('credits.packages')`. Existe un test dedicado a esto: `test_recharge_uses_server_catalog_and_ignores_client_financial_values`.

Kill-switch: `RECHARGES_ENABLED` (default `false`) — bloquea las recargas a nivel backend mientras no haya un destino de pago verificado.

#### Ciclo de vida del crédito

```
                    ┌──────────────────┐
    RECARGA         │ credits_available│         CONSUMO
  (admin aprueba)   └──────────────────┘      (padre califica)
        │                  │      ▲                    │
   type=deposit            │      │                    │
        └─────────────────►│      │                    │
                    reserva│      │refund              │
                   (agendar)      (cancelar)           │
                           ▼      │                    │
                    ┌──────────────────┐               │
                    │ credits_reserved │───────────────┘
                    └──────────────────┘        type=consumption
```

Los cuatro tipos de asiento en `credit_transactions.type` (enum): **`deposit`**, **`reservation`**, **`consumption`**, **`refund`**.

#### Ledger e idempotencia — la pieza más defendida del sistema

`credit_transactions` funciona como **libro mayor append-only**. Cada asiento lleva una `idempotency_key` con **índice UNIQUE a nivel de base de datos** (migración `2026_07_10_000001_harden_monetization_records`).

Convención de claves:

| Operación | Clave |
|---|---|
| Bono de bienvenida | `teacher:{profileId}:welcome` |
| Reserva por agendar | `lesson:{lessonId}:reservation` |
| Consumo por completar | `lesson:{lessonId}:consumption` |
| Devolución por cancelar | `lesson:{lessonId}:release` |
| Depósito por recarga | (asociado a `recharge_request_id`) |

Defensas acumuladas:
1. **UNIQUE en BD** sobre `idempotency_key` — la última línea de defensa contra doble procesamiento.
2. **`DB::transaction` + `lockForUpdate()`** en toda mutación financiera (sobre `Lesson`, `TeacherProfile`, `RechargeRequest`).
3. **Foreign keys `RESTRICT`** — la migración `2026_07_10_000002_protect_monetization_history` reescribe las FKs de `CASCADE` a `RESTRICT` en `credit_transactions`, `recharge_requests`, `classes` y `class_requests`. Borrar un profesor **no puede** borrar en cascada su historial financiero.
4. **Rollback bloqueado:** el `down()` de esa misma migración lanza `RuntimeException` si detecta datos, para impedir que revertir el esquema exponga el historial a borrado en cascada.
5. **Manejo de `UniqueConstraintViolationException`** en `RechargeController` → deriva a revisión manual en lugar de duplicar saldo.
6. **Normalización de número de operación** — un mismo `operation_number` no puede reutilizarse, ni siquiera bajo otro método de pago.

#### Bono de bienvenida anti-Sybil

5 créditos gratis, otorgados **exclusivamente al verificar el teléfono** (`PhoneVerificationController::grantTeacherWelcomeBonus`), no al registrarse ni al ser aprobado por admin. Esto ata el incentivo económico a un recurso escaso (un número de teléfono real verificado por WhatsApp), mitigando la creación masiva de cuentas.

Guardas del bono: solo rol `teacher`, con `TeacherProfile` existente, dentro de transacción con `lockForUpdate`, con doble chequeo de idempotencia (por `idempotency_key` y por descripción legacy `'Bono de bienvenida MOVA'`).

#### Pago offline (Yape / Plin)

El profesor publica sus números en su perfil (`teacher_profiles.yape_number`, `teacher_profiles.plin_number`). El padre, en la tarjeta de clase, ve:
- **Monto a pagar** = `hourly_rate × duration_minutes / 60` (calculado en el frontend).
- Los números de Yape y Plin del profesor (con fallback si no los registró).
- Botón **"✓ Ya pagué"** → `POST /lessons/{id}/confirm-payment`.

**MOVA no procesa ni custodia ese dinero.** No hay pasarela de pago (Stripe/Culqi) integrada — es una decisión deliberada de arquitectura, no una omisión.

#### Desbloqueo por experiencia

`TeacherProfile::maxAllowedRate()`: tarifa máxima **S/ 20** hasta completar **5 clases**; a partir de ahí (`completed_classes_count >= 5` o `is_experienced`), se desbloquea **S/ 25**. La validación se aplica en `TeacherProfileController` tanto en `storeSetup` como en `update`.

#### Mentorías (acompañamiento continuo)

`teacher_profiles.mentorship_slots_total` / `mentorship_slots_taken`. Una `ClassRequest` marcada `is_mentorship` consume un cupo al agendarse y lo libera al cancelarse.

---

## 2. ARQUITECTURA TÉCNICA Y STACK

### 2.1 Backend

| Componente | Versión / Detalle |
|---|---|
| **PHP** | 8.1.25 (requisito `^8.1`) |
| **Laravel** | **10.50.2** (estructura clásica: `app/Http/Kernel.php`, `app/Console/Kernel.php`, `config/app.php` con array de providers — **no** es la estructura slim de Laravel 11) |
| Autenticación | Laravel Breeze (scaffolding) + `laravel/sanctum ^3.2` |
| Roles y permisos | `spatie/laravel-permission ^6.25` |
| SPA bridge | `inertiajs/inertia-laravel ^0.6.8` |
| Rutas en JS | `tightenco/ziggy ^2.6` |
| Monitoreo | `sentry/sentry-laravel ^4.26` |
| WhatsApp/SMS | `twilio/sdk ^8.11` |
| WebSockets | `pusher/pusher-php-server ^7.2` |
| Email alternativo | `resend/resend-laravel ^1.4` |
| HTTP client | `guzzlehttp/guzzle ^7.2` |
| Dev | `doctrine/dbal ^3.10` (requerido para alterar columnas en SQLite durante tests), PHPUnit, Pint, Breeze, Faker |

**Estructura de `app/`:**
```
app/
├── Channels/          → GmailApiMailChannel, SafeMailChannel, WhatsAppChannel
├── Console/Commands/  → GmailAuthUrl, GmailExchangeCode, SendClassReminders
├── Events/            → ClassConfirmed, ClassRequestCreated
├── Http/
│   ├── Controllers/   → 33 controladores (incl. Auth/, Admin/, Teacher/)
│   └── Middleware/    → EnsureNotSuspended, HandleInertiaRequests, TrustProxies, …
├── Listeners/         → SendClassConfirmationNotifications, SendClassRequestNotifications,
│                        SendWelcomeAfterVerification
├── Models/            → 15 modelos
├── Notifications/     → 20 notificaciones + Concerns/BuildsAppUrls
├── Providers/         → App, Auth, Broadcast, Event, Route
└── Services/          → DiagnosticAiEnrichmentService, DiagnosticRecommendationService,
                         GmailApiMailService, ZoomService (legacy)
```

**No existe `app/Policies/`.** Toda la autorización se implementa **inline en los controladores** mediante `abort_unless(...)` / `abort_if(...)`. Ver §5 (deuda técnica).

**Middleware personalizado registrado** (`app/Http/Kernel.php`):
- `not.suspended` → `EnsureNotSuspended`
- `role`, `permission`, `role_or_permission` → Spatie

### 2.2 Frontend

| Componente | Versión |
|---|---|
| **Vue** | 3.4 (Composition API, `<script setup>`) |
| **Inertia.js** | `@inertiajs/vue3 ^1.0` |
| **Vite** | ^5.0 + `laravel-vite-plugin ^1.0` |
| **Tailwind CSS** | ^3.2 + `@tailwindcss/forms` |
| **Laravel Echo** | ^2.4 |
| **pusher-js** | ^8.5 |
| E2E (instalado) | `@playwright/test ^1.61` |

**Blade** se usa solo como cascarón: `resources/views/app.blade.php` es la única vista real (root view de Inertia). Todo lo demás es Vue.

**52 páginas Vue** en `resources/js/Pages/`, organizadas por dominio: `Admin/`, `Auth/`, `ClassOffers/`, `ClassRequests/`, `Dashboard/`, `Diagnostics/`, `Landing/`, `Legal/`, `LessonReports/`, `Lessons/`, `Marketplace/`, `Profile/`, `Reviews/`, `Students/`, `Teacher/`, `Teachers/`.

**19 componentes** en `resources/js/Components/` — mezcla del scaffolding de Breeze (`PrimaryButton`, `TextInput`, `Modal`, `Dropdown`…) con componentes de dominio propios (`NotificationBell`, `StatusBadge`, `AvailabilityPicker`, `TimeSlotPicker`, `LandingNavbar`, `LandingFooter`).

**Dos layouts, uno vestigial:**
- **`AppLayout.vue`** — el layout real de la aplicación (sidebar con navegación por rol, topbar, flash messages, toast de tiempo real, `NotificationBell`). Lo usan **34 páginas**.
- **`AuthenticatedLayout.vue`** — scaffolding original de Breeze. Solo lo usan **2 páginas** residuales.

**Datos compartidos con el frontend** (`HandleInertiaRequests::share`): `auth.user` (id, name, email, phone, parental_control, roles, email_verified, phone_verified), `flash` (success/error/status), `ziggy` (rutas + location).

> Nota: `auth.user` expone `phone_verified` como **booleano**, no la fecha `phone_verified_at`. Cualquier condición en el frontend debe usar `phone_verified`.

### 2.3 Base de datos

**Motor:** MySQL (local vía XAMPP/MariaDB; producción vía plugin MySQL de Railway).
**Tests:** SQLite en memoria — de ahí la dependencia `doctrine/dbal` para las migraciones que alteran columnas.

**48 migraciones** versionadas. Tablas principales del dominio:

#### Entidades núcleo

| Tabla | Columnas relevantes |
|---|---|
| `users` | `name`, `email`, `password`, `phone`, `email_verified_at`, `phone_verified_at`, `phone_verification_code_hash`, `phone_verification_expires_at`, `phone_verification_attempts`, `parental_control`, `welcome_notification_sent_at`, `suspended_at`, `suspension_reason` |
| `students` | `parent_user_id` (FK→users, CASCADE), `first_name`, `last_name`, `birth_date`, `grade_level` (enum: primaria/secundaria/universidad), `school` |
| `teacher_profiles` | `user_id` (FK), `bio`, `hourly_rate` (decimal 8,2), `yape_number`, `plin_number`, `is_verified`, `credits_available`, `credits_reserved`, `completed_classes_count`, `is_experienced`, `mentorship_slots_total`, `mentorship_slots_taken`, `rejected_at`, `rejection_reason`, `reviewed_by`, `reviewed_at` |
| `subjects` | `name`, `level` |
| `teacher_subject` | pivote N:M con `specific_rate` |

#### Flujo de clases

| Tabla | Columnas relevantes |
|---|---|
| `class_offers` | `teacher_profile_id`, `subject_id`, `title`, `description`, `is_active` |
| `class_requests` | `student_id`, `subject_id`, `class_offer_id`, `help_needed`, `preferred_times` (JSON), `is_mentorship`, `status` (enum de 6 estados), `student_diagnostic_id`, `request_reminder_sent_at`, `teacher_rejected_at`, `teacher_rejection_reason` |
| **`classes`** (modelo `Lesson`) | `teacher_profile_id`, `student_id`, `class_request_id`, `class_offer_id`, `start_time`, `duration_minutes`, `zoom_meeting_id` *(legacy)*, **`jitsi_room`**, `status` (enum de 6 estados), `reminder_sent`, `reminder_24h_sent_at`, `reminder_2h_sent_at`, `report_reminder_sent_at`, `cancelled_at`, `cancelled_by`, `cancel_reason`, `original_start_time`, `rescheduled_at`, `rescheduled_by`, `reschedule_reason` |
| `lesson_reports` | `lesson_id` (**UNIQUE** — máx. 1 reporte por clase), `teacher_profile_id`, `student_id`, `topic_covered`, `student_performance`, `difficulties_detected`, `homework_assigned`, `teacher_recommendation`, `next_step`, `sent_to_parent_at` |
| `teacher_reviews` | `lesson_id` (**UNIQUE**), `teacher_profile_id`, `parent_id`, `student_id`, `rating` (1–5), `comment`, `is_visible`, `moderated_at`, `moderated_by`, `moderation_reason` |

> ⚠️ **Trampa de nomenclatura:** el modelo se llama `Lesson` pero la tabla es **`classes`** (`protected $table = 'classes'`). Cualquier query SQL cruda debe usar `classes`.

#### Monetización

| Tabla | Columnas relevantes |
|---|---|
| `credit_transactions` | `teacher_profile_id`, **`idempotency_key` (UNIQUE)**, `lesson_id`, `recharge_request_id`, `type` (enum: deposit/reservation/consumption/refund), `amount`, `description` |
| `recharge_requests` | `teacher_profile_id`, `package_name`, `credits`, `amount_pen`, `operation_number`, `status` (pending/approved/rejected), + campos de revisión |

#### Diagnóstico e IA

| Tabla | Columnas relevantes |
|---|---|
| `student_diagnostics` | `parent_user_id`, `student_id`, `subject_id`, `level`, `difficulty_text`, `school_feedback`, `goal`, `urgency`, `status` (draft/completed/converted), `ai_keywords` (JSON), `ai_detected_level`, `ai_summary`, `ai_suggested_goal`, `ai_risk_flags` (JSON), `ai_confidence`, `ai_used_fallback`, `ai_enriched_at` |
| `diagnostic_recommendations` | vinculada al diagnóstico, con `rank` y score |
| `ai_usage_logs` | `user_id`, `student_diagnostic_id`, `provider`, `model`, `status`, `prompt_tokens`, `completion_tokens`, `total_tokens` |

#### Auditoría

| Tabla | Propósito |
|---|---|
| `class_events` | Bitácora inmutable: `lesson_id`, `class_request_id`, `actor_id`, `event_type` (string 60), `reason`, `metadata` (JSON). Escrita vía `ClassEvent::log(...)`. Tipos observados: `payment_confirmed`, `class_cancelled`, … |
| `notifications` | Tabla estándar de Laravel (canal `database`) |

#### Infraestructura Laravel
`cache`, `sessions`, `jobs`, `failed_jobs`, `personal_access_tokens`, `password_reset_tokens`, tablas de Spatie (`roles`, `permissions`, `model_has_roles`, …).

### 2.4 Procesos en segundo plano

#### Colas (`QUEUE_CONNECTION=database`)

Casi todas las notificaciones implementan **`ShouldQueue`**, por lo que el envío de emails/WhatsApp/broadcast ocurre asíncronamente. Requiere un worker activo:
```bash
php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=90 --backoff=5
```
En producción esto es el servicio **`mova-queue`** de Railway (`railway.queue.toml`).

**Fail-soft de correo:** `SafeMailChannel` extiende `MailChannel` y degrada en cascada (Gmail API → fallback SMTP → warning en log) **sin lanzar excepción**, para que un fallo de correo no reviente el job ni dispare reintentos infinitos.

#### Scheduler

`app/Console/Kernel.php` registra **un solo comando**, cada minuto:
```php
$schedule->command('classmate:send-reminders')->everyMinute();
```

`SendClassReminders` ejecuta cinco rutinas en cada tick:

| Rutina | Ventana | Marca anti-duplicado |
|---|---|---|
| `send24hReminders()` | clases entre +23h y +25h | `reminder_24h_sent_at` |
| `send2hReminders()` | clases entre +90min y +150min | `reminder_2h_sent_at` |
| `send10mReminders()` | clases próximas a iniciar | `reminder_sent` |
| `sendPendingReportAlerts()` | clases sin reporte | `report_reminder_sent_at` |
| `sendUnansweredRequestAlerts()` | solicitudes sin respuesta | `request_reminder_sent_at` |

Cada rutina notifica **a ambas partes** (profesor y padre) vía `notifyBoth()`, y persiste un timestamp para no reenviar.

> **Por qué no se usa Railway Cron:** documentado en `docs/DEPLOY_RAILWAY.md` — Railway Cron tiene un mínimo de 5 minutos y hace cold start en cada ejecución. Para recordatorios de 10 minutos se necesita un proceso en loop continuo, de ahí el servicio dedicado `mova-scheduler` con `while true; do … sleep 60; done`.

#### Broadcasting en tiempo real

`BROADCAST_DRIVER=pusher`. `App\Providers\BroadcastServiceProvider` está **activo** en `config/app.php` (fue descomentado en esta fase). Canal privado autorizado en `routes/channels.php`:
```php
Broadcast::channel('App.Models.User.{id}', fn ($user, $id) => (int) $user->id === (int) $id);
```
Endpoint de autorización: `GET|POST /broadcasting/auth`.

---

## 3. INTEGRACIONES DE TERCEROS (APIs Y SERVICIOS)

### 3.1 Twilio — verificación de teléfono por WhatsApp

| | |
|---|---|
| **Paquete** | `twilio/sdk ^8.11` |
| **Config** | `config/services.php` → `services.twilio.{sid,token,whatsapp_from}` |
| **Env** | `TWILIO_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_WHATSAPP_FROM` |
| **Estado** | ✅ Credenciales configuradas en `.env` local |

**Dos usos distintos:**

1. **Verificación de teléfono** (`PhoneVerificationController::sendWhatsAppCode`) — envío directo, **no** pasa por el canal de notificaciones. Código de 6 dígitos, mensaje formateado. Maneja explícitamente el error Twilio **63007** ("número no unido al Sandbox").

2. **Canal de notificaciones** (`App\Channels\WhatsAppChannel`) — para avisos de clase. Tiene **tres puertas de seguridad**:
   - Kill-switch global: `WHATSAPP_ENABLED` (default **`false`**).
   - Modo: `WHATSAPP_MODE` (`sandbox` | `production`).
   - Exigencia de teléfono verificado: `WHATSAPP_REQUIRE_VERIFIED_PHONE` (default `true`).

   Si cualquiera falla, **loguea y retorna** — nunca lanza excepción.

> ⚠️ **No existe `TWILIO_PHONE_NUMBER`.** MOVA no usa SMS: usa exclusivamente WhatsApp vía `TWILIO_WHATSAPP_FROM`.
>
> ⚠️ El canal de notificaciones WhatsApp está **apagado por defecto** (`WHATSAPP_ENABLED=false`) hasta salir del Sandbox de Twilio. Ver `docs/WHATSAPP_PRODUCTION_NOTES.md`.

### 3.2 Pusher Channels — notificaciones en tiempo real

| | |
|---|---|
| **Backend** | `pusher/pusher-php-server ^7.2` |
| **Frontend** | `laravel-echo ^2.4` + `pusher-js ^8.5` |
| **Env (server)** | `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER`, `PUSHER_HOST`, `PUSHER_PORT`, `PUSHER_SCHEME` |
| **Env (cliente)** | `VITE_PUSHER_APP_KEY`, `VITE_PUSHER_APP_CLUSTER`, `VITE_PUSHER_HOST`, `VITE_PUSHER_PORT`, `VITE_PUSHER_SCHEME` |
| **Estado** | ✅ Configurado y **verificado** — evento de prueba disparado sin excepción |

**Implementación:** se usa el sistema **nativo de notificaciones de Laravel**, no eventos crudos. Tres notificaciones declaran el canal `'broadcast'` en `via()` y definen `toBroadcast()`:

| Notificación | Disparador | Destinatario |
|---|---|---|
| `PaymentConfirmedNotification` | Padre confirma pago (`→ paid`) | Profesor |
| `LessonReportPublishedNotification` | Profesor sube reporte (`→ pending_parent_confirmation`) | Padre |
| `ClassConfirmedNotification` | Profesor acepta y agenda | Profesor **y** padre |

Ninguna sobrescribe `broadcastAs()`, así que el evento sigue siendo `Illuminate\Notifications\Events\BroadcastNotificationCreated` — exactamente lo que `Echo.private(...).notification(cb)` escucha de fábrica.

**Frontend** (`AppLayout.vue`): en `onMounted` se suscribe a `App.Models.User.{id}`; el callback hace tres cosas:
1. `router.reload({ only: ['lessons','notifications','auth'], preserveScroll: true })` — refresco parcial de Inertia.
2. Dispara un `CustomEvent('mova:notification')` en `window`.
3. Muestra un toast con `notification.message` (auto-dismiss a 6s).

En `onUnmounted` hace `Echo.leave(...)`.

`NotificationBell.vue` escucha ese `CustomEvent` para refrescar su contador al instante (no depende del `router.reload`, porque hace su propio polling vía `axios.get('/notifications')` cada 30s).

### 3.3 IA / LLMs — enriquecimiento de diagnóstico

| | |
|---|---|
| **Servicio** | `App\Services\DiagnosticAiEnrichmentService` |
| **Config** | `config/diagnostic.php` |
| **Proveedores** | `gemini` (activo) \| `openai` |
| **Env** | `DIAGNOSTIC_AI_ENABLED`, `DIAGNOSTIC_AI_PROVIDER`, `GEMINI_API_KEY`, `OPENAI_API_KEY`, `DIAGNOSTIC_GEMINI_MODEL`, `DIAGNOSTIC_AI_MODEL`, `DIAGNOSTIC_AI_TIMEOUT`, `DIAGNOSTIC_AI_MAX_TOKENS`, `DIAGNOSTIC_AI_DAILY_LIMIT`, `DIAGNOSTIC_AI_MONTHLY_LIMIT`, `DIAGNOSTIC_AI_AUTO_DISABLE_ON_ERROR` |

**Estado verificado por smoke test:**
- ✅ **Gemini** (`gemini-2.5-flash-lite`) — HTTP 200, respuesta correcta. **Proveedor activo.**
- ⚠️ **OpenAI** (`gpt-4o-mini`) — la credencial **autentica correctamente** pero devuelve `429 insufficient_quota`: la cuenta no tiene saldo/billing. No bloquea nada mientras `DIAGNOSTIC_AI_PROVIDER=gemini`.

**Salida esperada (JSON estricto):** `suggested_subject_keywords[]`, `detected_level`, `parent_friendly_summary`, `suggested_goal`, `risk_flags[]`, `confidence_score`.

**Guardas de seguridad y ética (todas verificadas en el código):**

| Guarda | Implementación |
|---|---|
| **La IA nunca rankea profesores** | El scoring determinista siempre corre; la IA solo enriquece texto |
| **Integridad académica** | Si `goal === 'solve_homework'`, la IA **no se invoca** (línea 46) — MOVA no hace la tarea del alumno |
| **Privacidad** | `difficulty_text` se **anonimiza** antes de enviarse (`anonymize()`, línea 364) — se remueven nombres propios |
| **Rate limiting** | Límite diario (50) y mensual (500) configurables; al excederse → fallback determinista |
| **Auto-desactivación** | `DIAGNOSTIC_AI_AUTO_DISABLE_ON_ERROR` — apaga la IA tras 3 errores consecutivos en una hora |
| **Nunca lanza excepción** | Cualquier fallo → `writeLog(..., 'fallback', ...)` + `ai_used_fallback = true`; el wizard continúa |
| **Auditoría de costo** | Cada llamada registra tokens en `ai_usage_logs`; panel admin en `/admin/ai-usage` |

### 3.4 Jitsi Meet — videollamadas

| | |
|---|---|
| **Integración** | Sin SDK ni API — URL directa `https://meet.jit.si/{jitsi_room}` en un `<iframe>` |
| **Generación** | `LessonController::store()` → `jitsi_room = "mova-lesson-{id}-" . Str::random(8)` |
| **Persistencia** | `classes.jitsi_room` (string, nullable) |
| **Credenciales** | **Ninguna** — no requiere cuenta, API key ni OAuth |

**Sustituyó a Zoom.** El `iframe` se declara con `allow="camera; microphone; fullscreen; display-capture"` y `class="w-full h-[80vh] border-0"`, dentro de un `<Modal max-width="7xl">` (se ampliaron los tamaños del Modal de Breeze, que topaba en `2xl`).

**Modelo de seguridad de la sala:** es *security through obscurity* — el nombre incluye 8 caracteres aleatorios (`Str::random(8)`), pero la sala es **pública para quien conozca la URL**. No hay contraseña ni lobby. Ver §5.

**Vestigios de Zoom que permanecen:**
- `app/Services/ZoomService.php` — **sigue existiendo** y sigue inyectado en `LessonController::cancel()` para limpiar reuniones legacy vía `deleteMeeting()`.
- `config/zoom.php` y las variables `ZOOM_*` siguen en `.env`.
- `classes.zoom_meeting_id` sigue en el esquema (solo se eliminaron `zoom_link`, `zoom_password` y `teacher_profiles.zoom_account_id`).

### 3.5 Correo — Gmail (SMTP + API)

Arquitectura de **tres capas de fallback**, orquestada por `SafeMailChannel`:

| Mailer | Cuándo | Env |
|---|---|---|
| **`gmail_api`** | Recomendado en producción/Railway | `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET`, `GMAIL_REFRESH_TOKEN`, `GMAIL_FROM_ADDRESS`, `GMAIL_FROM_NAME` |
| **`smtp`** | Desarrollo local (**activo ahora**) | `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME`, `MAIL_PASSWORD` (app password de 16 chars), `MAIL_ENCRYPTION=tls` |
| **`resend`** | Alternativa con dominio propio | `RESEND_API_KEY` |

**Razón de existir de la Gmail API:** Railway Hobby **bloquea el tráfico SMTP saliente** (puertos 25/465/587). La Gmail API funciona sobre HTTPS/443, que no está bloqueado, y no requiere dominio propio. Límite: 500 emails/día por cuenta gratuita.

**Comandos de apoyo:** `php artisan mova:gmail-auth-url` y `php artisan mova:gmail-exchange-code {code}` para obtener el refresh token.

**Estado verificado:** ✅ envío real de correo probado sin excepción con la configuración SMTP local.

### 3.6 Sentry — monitoreo de errores

| | |
|---|---|
| **Paquete** | `sentry/sentry-laravel ^4.26` |
| **Env** | `SENTRY_LARAVEL_DSN`, `SENTRY_TRACES_SAMPLE_RATE` |
| **Estado** | ✅ **Verificado end-to-end** — excepción de prueba capturada, Sentry devolvió `event_id` |
| **Nota** | En local `SENTRY_TRACES_SAMPLE_RATE=1.0`; en producción debe ser `0.1` para no agotar cuota |

### 3.7 Servicios NO integrados (decisiones deliberadas)

| Servicio | Estado | Razón |
|---|---|---|
| **Stripe / Culqi** | ❌ No integrado | El modelo de pago es **offline vía Yape/Plin**. No hay pasarela por diseño. |
| **Cloudinary** | ❌ No integrado | Sin paquete ni código. Credenciales de respaldo probadas → `401 cloud_name mismatch`. |
| **AWS S3** | ❌ No usado | Variables `AWS_*` presentes pero **vacías**; `FILESYSTEM_DISK=local`. |
| **Redis** | ❌ No usado | Variables presentes; `CACHE_DRIVER=file`/`database`, `SESSION_DRIVER=file`/`database`. |

---

## 4. ESTADO ACTUAL DEL ENTORNO (LOCAL Y PRODUCCIÓN)

### 4.1 Entorno local

| Aspecto | Estado |
|---|---|
| **Rama git** | `fix/monetization-integrity` (⚠️ con ~40 archivos modificados **sin commitear**) |
| **Base de datos** | MySQL local vía **XAMPP/MariaDB**, BD `mova`. ⚠️ **Se arranca a mano** — no hay servicio de Windows registrado. Al momento de generar este documento estaba **apagada**. |
| **Migraciones** | ✅ Todas aplicadas, incluidas las 4 más recientes (`payment_states`, `payment_numbers`, `jitsi_room`, `drop_zoom_columns`) |
| **Tests** | ✅ **57/57 en verde** (192 aserciones) sobre SQLite en memoria |
| **Build frontend** | ✅ `npm run build` sin errores (bundle principal ~318 kB / 109 kB gzip) |
| **Cachés** | `config:clear` y `cache:clear` ejecutados tras la última integración de credenciales |

**Feature flags activos en local:**
```
BROADCAST_DRIVER=pusher          ✅ verificado
DIAGNOSTIC_AI_ENABLED=true       ✅ activado
DIAGNOSTIC_AI_PROVIDER=gemini    ✅ verificado
MAIL_MAILER=smtp                 ✅ verificado
QUEUE_CONNECTION=database
```

**Variables vacías en `.env` local (esperado):** `DB_PASSWORD` (XAMPP sin password), `AWS_*`, `PUSHER_HOST`, `VITE_PUSHER_HOST`, `VITE_APP_NAME`.

**Datos de prueba — `LocalTestDataSeeder`**

Ejecutar con: `php artisan db:seed --class=LocalTestDataSeeder`

| Rol | Email | Password |
|---|---|---|
| Padre | `padre@mova.test` | `password123` |
| Profesor | `profesor@mova.test` | `password123` |

Ambos con `email_verified_at` seteado (saltan verificación de correo). El profesor además con `phone_verified_at` y `credits_available = 5`, `credits_reserved = 4`.

Datos relacionales inyectados:
- 2 hijos del padre (Mateo — secundaria; Valentina — primaria)
- 3 solicitudes en estado `open`
- 2 clases `scheduled` (con asientos `reservation` coherentes en el ledger)
- 1 clase `completed` con `LessonReport` + `TeacherReview` (5★) y asiento `consumption`
- 2 profesores y 2 padres extra aleatorios (`User::factory()`)

> ⚠️ **Idempotencia parcial:** usuarios principales, hijos, reporte y reseña usan `firstOrCreate`/`updateOrCreate` (seguros de re-ejecutar). Las 2 clases `scheduled` y los 4 usuarios de relleno **NO** son idempotentes — se duplican en cada corrida.

Otros seeders: `RoleSeeder` (roles + `admin@mova.test`), `SubjectSeeder` (catálogo de materias), `DatabaseSeeder` (dataset amplio con 6 profesores y 5 padres, password `password`), `ProductionSeeder` (roles + materias + admin desde `ADMIN_EMAIL`/`ADMIN_PASSWORD`, idempotente).

### 4.2 Entorno de producción — Railway

**Proyecto:** `tranquil-creativity` (ID `22dad021-dbcb-473c-a5d2-34c7eaebab1c`), entorno `production`, región `sfo`.

**Topología de servicios (estado verificado vía Railway CLI):**

| Servicio | Estado | Config file | Comando de arranque |
|---|---|---|---|
| **MOVA** (web) | 🟢 **Online** — `https://mova-production-8750.up.railway.app` | `/railway.toml` | `config:clear && migrate --force && serve --host=0.0.0.0 --port=$PORT` |
| **mova-queue** | 🟢 **Online** | `/railway.queue.toml` | `config:clear && queue:work --queue=default --sleep=3 --tries=3 --timeout=90 --backoff=5` |
| **mova-scheduler** | 🔴 **Failed** | `/railway.scheduler.toml` | `while true; do config:clear && schedule:run; sleep 60; done` |
| **MySQL** | 🟢 **Online** | plugin nativo + volumen `mysql-volume` | — |

**Build común a los tres servicios** (Nixpacks, sin Dockerfile):
```bash
composer install --no-dev --optimize-autoloader && \
npm ci && npm run build && \
php artisan config:cache && php artisan route:cache && \
php artisan view:cache && php artisan storage:link
```

**Healthcheck del servicio web:** `/healthz` (ruta sin sesión ni auth), timeout 120s, `restartPolicyType = ON_FAILURE`.

**Conectividad a BD:** `DB_HOST=mysql.railway.internal` (hostname privado del plugin). ✅ Verificado que coincide exactamente con lo que el propio plugin MySQL reporta (`RAILWAY_PRIVATE_DOMAIN`, `MYSQL_URL`).

> ⚠️ **Nota metodológica importante:** `railway run -- php artisan migrate` ejecutado desde una máquina local **siempre fallará** con `getaddrinfo for mysql.railway.internal failed`. `railway run` corre el comando **localmente** inyectando solo las variables de entorno; los hostnames `*.railway.internal` únicamente resuelven **dentro** de la infraestructura de Railway. Esto es un artefacto del método de prueba, **no** evidencia de mala configuración.

**⚠️ Estado de `mova-scheduler` — FALLO ACTIVO SIN RESOLVER**

Síntoma diagnosticado: el build termina **exitoso** (composer, npm, vite, imagen empujada) pero los logs de runtime están **completamente vacíos** — nunca aparece el marcador `Starting Container` que sí emite `mova-queue` (Online, mismo commit, mismo pipeline). El contenedor **nunca llega a arrancar**.

Esto descarta error de PHP, migración rota o excepción de Laravel (todas dejarían stack trace). Apunta a que Railway no resuelve un comando de arranque válido para ese servicio: campo **Config File Path** o **Custom Start Command** en el dashboard.

**Limitación conocida:** el campo *Config File Path* es **exclusivo del dashboard**; la Railway CLI v5.27.0 no lo expone. Tampoco existe mecanismo de auto-detección multi-servicio-mismo-repo: Railway solo auto-detecta **un** archivo `railway.toml` en la raíz, y no se pueden tener tres archivos con el mismo nombre en la misma carpeta. La única alternativa (`Root Directory` con subcarpetas) es **también** dashboard-only.

**Impacto operativo mientras siga caído:** no se envían recordatorios de clase (24h/2h/10min), ni alertas de reportes pendientes, ni avisos de solicitudes sin responder.

**Documentación de despliegue:** `docs/DEPLOY_RAILWAY.md` (guía completa, checklist pre-deploy, matriz de variables). Otros documentos de auditoría en `docs/`: `PRODUCTION_READINESS_AUDIT.md`, `FINAL_PRODUCTION_AUDIT.md`, `FINAL_PRODUCTION_SIGNOFF.md`, `MONETIZATION_AUDIT.md`, `MONETIZATION_INTEGRITY_PHASE1.md`, `BACKUP_RESTORE_PLAYBOOK.md`, `PROFESSIONAL_POLISH_AUDIT.md`, `WHATSAPP_PRODUCTION_NOTES.md`.

---

## 5. MAPA DE DEUDA TÉCNICA Y PENDIENTES

> Clasificado por severidad para un despliegue a producción. Todo lo aquí listado proviene de análisis directo del código, no de suposiciones.

### 🔴 CRÍTICO — resolver antes de producción

#### C1. `mova-scheduler` caído — sin recordatorios ni alertas
Descrito en §4.2. Es la única funcionalidad de la plataforma actualmente **rota en producción**. Bloquea el ciclo completo de recordatorios.
**Acción:** verificar en el dashboard de Railway el *Config File Path* de `mova-scheduler` (`/railway.scheduler.toml`) comparándolo carácter por carácter con el de `mova-queue`, que sí funciona.

#### C2. Secretos reales versionados en el árbol de trabajo
El archivo `.env.claude.local` contiene **credenciales de producción en texto plano**: PAT de GitHub, tokens de Vercel/Render/Railway/Forge, claves de Stripe, Cloudinary, Sentry (personal + org), OpenAI, Gemini, y **la contraseña del administrador**.

Aunque `.gitignore` cubre `.env*` (líneas 8-11 y 26), el archivo **existe en disco** dentro del proyecto y en una carpeta sincronizada con **OneDrive**.
**Acción:** rotar todo lo que haya podido exponerse, y mover el archivo fuera del árbol del proyecto y fuera de OneDrive.

#### C3. Salas Jitsi sin control de acceso
`https://meet.jit.si/mova-lesson-{id}-{8 chars}` es **públicamente accesible para cualquiera que conozca o adivine la URL**. No hay contraseña, ni lobby, ni JWT de autorización. Dado que **hay menores de edad en esas videollamadas**, esto es un riesgo de seguridad y de cumplimiento serio.
**Acción recomendada:** migrar a JaaS (Jitsi as a Service) con tokens JWT firmados, o self-hostear Jitsi con `lobby` + `password` por sala. Como mínimo, elevar la entropía del nombre (`Str::random(32)`).

#### C4. Sin backups automatizados verificados de la BD de producción
Existe `docs/BACKUP_RESTORE_PLAYBOOK.md`, pero no hay evidencia en el código ni en la configuración de Railway de un job de backup automatizado ni de una restauración probada. Con un ledger financiero de por medio, la pérdida de `credit_transactions` sería irrecuperable.

### 🟠 ALTO — riesgo lógico o de seguridad

#### A1. Autorización dispersa, sin capa de Policies
**No existe `app/Policies/`.** Cada controlador reimplementa sus reglas con `abort_unless(...)`. Ejemplo de `LessonController::cancel()`:
```php
$isTeacher = $profile && $lesson->teacher_profile_id === $profile->id;
$isParent  = $user->hasRole('parent') && $user->students()->where('id', $lesson->student_id)->exists();
$isAdmin   = $user->hasRole('admin');
abort_unless($isTeacher || $isParent || $isAdmin, 403);
```
Este mismo patrón se repite (con variaciones sutiles) en `LessonController`, `LessonReportController`, `TeacherReviewController` y `ClassRequestController`. **Riesgo:** una regla nueva puede olvidarse en uno de los sitios. **Acción:** extraer a `LessonPolicy`, `ClassRequestPolicy`, etc.

#### A2. Rutas financieras sin rate limiting
Solo 4 rutas tienen `throttle`: `diagnostics.store` (5,1), `class-requests.store` (10,1), `lessons.store` (10,1), y las de verificación de teléfono. **No tienen throttle:**
- `POST /lessons/{id}/confirm-payment` — confirmación de pago
- `POST /teacher/credits/recharge` — solicitud de recarga
- `POST /admin/recharges/{id}/approve` — aprobación de recarga
- `POST /lessons/{id}/review` — reseña

Aunque la idempotencia protege contra doble contabilización, la ausencia de throttle abre la puerta a abuso/DoS de estos endpoints.

#### A3. El grupo `admin` no aplica `not.suspended`
En `routes/web.php`, los grupos de `parent` y `teacher` llevan `['role:X', 'not.suspended']`, pero el grupo admin solo lleva `role:admin`. Un administrador suspendido conservaría acceso completo al panel. Puede ser deliberado (evitar auto-bloqueo), pero conviene documentarlo explícitamente o corregirlo.

#### A4. Rol `admin` sin granularidad de permisos
Aunque `spatie/laravel-permission` está instalado con soporte completo de permisos, **solo se usan roles**. No hay permisos finos: cualquier admin puede aprobar dinero (recargas), suspender usuarios y moderar reseñas. Sin separación de funciones ni trazabilidad diferenciada.

#### A5. `ProductionSeeder` lee credenciales con `env()` en runtime
```php
$email = env('ADMIN_EMAIL'); $password = env('ADMIN_PASSWORD');
```
Con `config:cache` activo en producción (que el build de Railway ejecuta), las llamadas a `env()` fuera de archivos de configuración **devuelven `null`**. El seeder tiene un guard que aborta con mensaje claro, así que no corrompe datos — pero fallará silenciosamente si se corre tras el cacheo.
Mismo patrón en `DiagnosticAiEnrichmentService`: `env('OPENAI_API_KEY')` y `env('GEMINI_API_KEY')` (líneas 199 y 242) en lugar de `config(...)`. **Esto sí es un bug latente en producción**: con `config:cache`, la IA quedaría permanentemente en modo fallback sin que nadie lo note (solo un `Log::warning`).

### 🟡 MEDIO — deuda estructural

#### M1. Vestigios de Zoom sin limpiar
- `app/Services/ZoomService.php` (141 líneas) sigue existiendo e inyectado en `LessonController::cancel()`.
- `config/zoom.php` y las 5 variables `ZOOM_*` siguen en `.env` y en la configuración de Railway.
- `classes.zoom_meeting_id` sigue en el esquema.
- `resources/js/Pages/Dashboard/Parent.vue` y `Dashboard/Teacher.vue` **todavía renderizan botones "Entrar a Zoom"** leyendo `l.zoom_link` y `l.zoom_password` — campos **que ya no existen en la BD** tras `drop_zoom_columns`. Esos bloques quedarán siempre ocultos (`v-if` sobre `undefined`), pero son código muerto y confuso.

#### M2. Dos layouts coexistiendo
`AuthenticatedLayout.vue` (Breeze) solo lo usan 2 páginas residuales frente a las 34 de `AppLayout.vue`. Duplica navegación y lógica de sesión. **Nota:** el listener de Echo se implementó en `AppLayout.vue` — las páginas que aún usen `AuthenticatedLayout` **no reciben notificaciones en tiempo real**.

#### M3. Cobertura de tests desbalanceada
57 tests, pero concentrados: `MonetizationIntegrityTest` (30 tests, excelente) y el scaffolding de Breeze (auth/profile). **Sin cobertura:**
- `DiagnosticRecommendationService` — el algoritmo de scoring, corazón del producto, **no tiene un solo test**.
- `DiagnosticAiEnrichmentService` — ni los guardrails (`solve_homework`, anonimización, rate limits).
- `SendClassReminders` — las 5 rutinas del scheduler.
- `WhatsAppChannel` / `SafeMailChannel` — la lógica de degradación.
- `MovaCriticalFlowTest` solo tiene 2 tests.
- Playwright está instalado pero **no hay specs E2E**.

#### M4. `LocalTestDataSeeder` parcialmente no idempotente
Detallado en §4.1. Re-ejecutarlo duplica clases y usuarios de relleno.

#### M5. `credit_minutes` sin usar
`config/credits.php` define `credit_minutes = 60` sugiriendo facturación por duración, pero el sistema cobra `fixed_class_cost = 2` sin importar `duration_minutes` (que acepta 30–240 min). El propio comentario lo admite: *"Phase 1 keeps the existing fixed reservation until duration billing is introduced."* **Consecuencia de negocio:** una clase de 240 min cuesta lo mismo que una de 30 min.

#### M6. Cálculo de monto a pagar duplicado en el frontend
`amountToPay(l)` en `ParentIndex.vue` calcula `hourly_rate × duration/60` **en JavaScript**. El backend nunca valida ni persiste ese monto. Si cambia la tarifa del profesor entre el agendamiento y el pago, el padre ve un monto distinto al pactado. **Sugerencia:** congelar el precio en la `Lesson` al agendar.

#### M7. `OPENAI_API_KEY` sin cuota
La credencial autentica pero devuelve `429 insufficient_quota`. Si alguien cambia `DIAGNOSTIC_AI_PROVIDER=openai`, la IA caerá silenciosamente en fallback.

### 🟢 BAJO — higiene

- **B1.** ~40 archivos modificados sin commitear en `fix/monetization-integrity`; la rama no está mergeada a `master`.
- **B2.** `bootstrap/cache/.gitignore` aparece **borrado** en git status, y `packages.php`/`services.php` como untracked — puede romper despliegues limpios.
- **B3.** Carpeta espuria `.railway-config-pull-22248/` en la raíz.
- **B4.** `SENTRY_TRACES_SAMPLE_RATE=1.0` en local (debe ser `0.1` en producción).
- **B5.** `.env.example` desactualizado: comenta *"Pusher (no utilizado actualmente)"* cuando Pusher **sí** está en uso.
- **B6.** `npm audit` reporta 3 vulnerabilidades (1 moderada, 2 altas) sin atender.
- **B7.** El bundle principal pesa **318 kB** (109 kB gzip) tras añadir Echo/Pusher; sin code-splitting de esas librerías.
- **B8.** Modelos sin factories: solo existe `UserFactory`. `TeacherProfile`, `Lesson`, `Student`, etc. declaran `HasFactory` pero no tienen factory definida — los tests construyen todo a mano.

### Resumen de pendientes funcionales (features a medias)

| Módulo | Estado |
|---|---|
| Recargas de crédito | ✅ Backend completo, ⚠️ **desactivado** por `RECHARGES_ENABLED=false` (falta destino de pago verificado) |
| WhatsApp (notificaciones) | ✅ Canal implementado, ⚠️ **apagado** por `WHATSAPP_ENABLED=false` (falta salir del sandbox de Twilio) |
| IA de diagnóstico | ✅ Activada con Gemini en local, ⚠️ pendiente de activar y validar en producción |
| Tiempo real (Pusher) | ✅ Verificado en local, ⚠️ credenciales pendientes de configurar en Railway |
| Facturación por duración | ❌ No implementada (`credit_minutes` sin uso) |
| Pasarela de pago online | ❌ No existe — **por diseño** (modelo offline Yape/Plin) |
| Tests E2E | ❌ Playwright instalado, sin specs |

---

## 6. APÉNDICES

### 6.1 Mapa rápido de rutas por rol

**Públicas:** `/healthz`, `/`, `/quienes-somos`, `/terminos`, `/privacidad`, `/invitacion/profesor`, `/invitacion/alumno`, `/marketplace`, `/teachers/{id}`

**Autenticadas (todos):** `/dashboard`, `/profile`, `/notifications*`

**Padre** (`role:parent` + `not.suspended`):
`/diagnostics/*`, `/students` (resource), `/class-requests/*`, `/my-classes`, `/lessons/{id}/confirm-payment`, `/lessons/{id}/review*`, `/my-reports`, `/settings/parental-control`

**Profesor** (`role:teacher` + `not.suspended`):
`/teacher/setup`, `/teacher/profile`, `/teacher/credits*`, `/class-offers` (resource), `/teacher/requests*`, `/lessons` (store), `/teacher/classes`, `/teacher/reports`, `/lessons/{id}/report*`

**Compartidas** (`not.suspended`): `/lessons/{id}/cancel`, `/lessons/{id}/reschedule`

**Admin** (`role:admin`, prefijo `/admin`):
`/users`, `/pending-teachers`, `/teachers/{id}/verify|reject`, `/users/{id}/suspend|unsuspend`, `/requests`, `/lessons`, `/lessons/{id}/cancel`, `/recharges*`, `/reviews*`, `/ai-usage`

### 6.2 Catálogo de notificaciones (20)

| Notificación | Destinatario | Canales |
|---|---|---|
| `WelcomeParentNotification` | Padre | database, mail |
| `WelcomeTeacherNotification` | Profesor | database, mail |
| `WelcomeEmailNotification` | Usuario | mail |
| `ClassConfirmedNotification` | Ambos | database, **broadcast**, mail, WhatsApp |
| `ClassReminderNotification` | Ambos | database, mail, WhatsApp |
| `ClassCancelledNotification` | Ambos | database, mail |
| `ClassRescheduledNotification` | Ambos | database, mail |
| `PaymentConfirmedNotification` | Profesor | database, **broadcast**, mail |
| `LessonReportPublishedNotification` | Padre | database, **broadcast**, mail, WhatsApp |
| `PendingReportReminderNotification` | Profesor | database, mail |
| `TeacherReviewReceivedNotification` | Profesor | database, mail |
| `NewClassRequestNotification` | Profesor | database, mail |
| `ClassRequestRejectedNotification` | Padre | database, mail |
| `UnansweredRequestNotification` | Profesor | database, mail |
| `ParentApprovalRequestNotification` | Padre | database, mail |
| `TeacherVerifiedNotification` | Profesor | database, mail |
| `TeacherRejectedNotification` | Profesor | database, mail |
| `NewRechargeRequestNotification` | Admin | database, mail |
| `RechargeApprovedNotification` | Profesor | database, mail |
| `RechargeRejectedNotification` | Profesor | database, mail |

Trait compartido: `App\Notifications\Concerns\BuildsAppUrls` (construcción consistente de URLs absolutas).

### 6.3 Comandos operativos frecuentes

```bash
# Desarrollo
php artisan serve
npm run dev

# Tests
php artisan test
php artisan test --filter=MonetizationIntegrityTest

# Build de producción
npm run build

# Migraciones
php artisan migrate
php artisan migrate:status

# Datos de prueba
php artisan db:seed --class=LocalTestDataSeeder

# Cachés (tras cambiar .env)
php artisan config:clear && php artisan cache:clear

# Cola (necesaria para emails/WhatsApp/broadcast)
php artisan queue:work

# Scheduler (recordatorios)
php artisan schedule:run

# Gmail API — obtención de refresh token
php artisan mova:gmail-auth-url
php artisan mova:gmail-exchange-code {CODE}

# Railway
railway status
railway logs -s MOVA --lines 100
railway redeploy -y --service mova-scheduler
```

### 6.4 Glosario de nombres engañosos

| Nombre | Realidad |
|---|---|
| Modelo `Lesson` | Tabla **`classes`** |
| "Alumno" / `Student` | **No es un usuario** — no tiene login |
| `ClassRequest` | Solicitud del padre, **previa** a que exista una `Lesson` |
| `ClassOffer` | Anuncio publicado por el profesor (catálogo) |
| `ZoomService` | **Legacy** — solo limpia reuniones antiguas; las nuevas son Jitsi |
| `credits_reserved` | Créditos comprometidos, **aún no consumidos** |
| `AuthenticatedLayout.vue` | Scaffolding de Breeze; el layout real es `AppLayout.vue` |

---

**Fin del documento.**
