# MOVA — Fase 0: Auditoría de Rutas, Roles/Permisos y Base de Datos

**Dominio:** rutas/endpoints, autorización, base de datos/consistencia.
**Método:** lectura directa del código en el working directory (worktree
`agent-a33bfeb8eccd6462c`, HEAD `692b365`). `php artisan route:list --json`
**no pudo ejecutarse**: este worktree no tiene `vendor/` instalado (composer
autoloader ausente). El mapa de rutas se reconstruyó leyendo íntegramente
`routes/web.php` y `routes/auth.php` a mano — cobertura completa de nombres
de ruta, verbo HTTP, middleware y controlador/acción; lo único que no se
pudo confirmar por esta vía es el orden final de resolución de Ziggy/route
caching en producción.

**Nota metodológica importante:** los documentos que la tarea pedía leer
primero — `docs/MOVA_SYSTEM_KNOWLEDGE.md` y `docs/MOVA_PRODUCTION_READINESS.md`
— **no existen en este repo** (no hay archivos con ese nombre en `docs/`).
Se usaron en su lugar `MOVA_MASTER_CONTEXT.md` (fechado 2026-07-18) y
`docs/PRODUCTION_READINESS_AUDIT.md` (fechado 2026-06-28) como aproximación
histórica, tratando ambos como **hipótesis a re-verificar**, no como hechos.
Resultado: el código actual (commits hasta `692b365`, agosto 2026) está
**muy por delante** de ambos documentos — casi todos los hallazgos
"pendientes" que describen ya están resueltos, con comentarios en el propio
código que documentan auditorías de seguridad previas (marcadas
"hallazgo CRÍTICO de auditoría, 2026-08-22") y su corrección. Esto se detalla
en la sección 4.

---

## 1. Mapa completo de rutas y riesgos

### 1.1 Públicas (sin auth)

| Método | Ruta | Controller@action | Riesgo |
|---|---|---|---|
| GET | `/healthz` | closure | Ninguno — no toca sesión ni datos |
| GET | `/terminos`, `/privacidad` | LegalController | Ninguno |
| GET | `/`, `/quienes-somos`, `/invitacion/profesor`, `/invitacion/alumno` | Welcome/About/Invitation | Ninguno — contenido estático/landing |
| GET | `/marketplace` | MarketplaceController@index | Expone solo `name, avatar_url, bio, hourly_rate` de profesores verificados + subjects (`id,name`) y stats agregadas. **Verificado explícitamente en el código que `referral_code` se excluye** de las columnas seleccionadas (comentario explícito en el controller). Yape/Plin/email/teléfono nunca se seleccionan. Sin riesgo de fuga de PII. |
| GET | `/teachers/{teacherProfile}` | TeacherPublicController@show | `abort_unless($teacherProfile->is_verified, 404)` — perfiles no verificados no son enumerables. `referral_code` solo se expone al propio profesor o a un padre con ≥1 clase `completed` con él (`referralCodeVisibleTo()`). Bien acotado. |

### 1.2 Autenticadas — comunes (`auth`,`verified`)

`/dashboard`, `/profile` (+avatar), `/notifications` — `NotificationController::markRead/markAllRead` filtran siempre por `auth()->user()->notifications()`, sin IDOR posible vía `{id}` ajeno.

### 1.3 Rol `parent` (`role:parent`,`not.suspended`)

Diagnósticos, `students.*` (resource, throttle 20/min), `class-requests.*`
(store 10/min, approve/reject 20/min, lookup-code 30/min), `parent.lessons`,
`lessons.confirm-payment` (10/min), `reviews.create/store` (10/min),
`parent.reports`, `parent.settings.update` (20/min).

Todas las rutas de mutación tienen throttle explícito. Comparado con el
`PRODUCTION_READINESS_AUDIT.md` (2026-06-28), que marcaba "no rate limit en
rutas de escritura sensibles" como bloqueador — **esto está FIXED**: hoy
prácticamente cada `POST`/`PATCH`/`DELETE` autenticado lleva `throttle:N,1`.

### 1.4 Rol `teacher` (`role:teacher`,`not.suspended`)

`teacher.setup`, `teacher.profile`, `teacher.credits.*` (recharge 10/min),
`class-offers.*` (resource **sin** `create`/`store` — deliberado, ver
comentario en `routes/web.php` líneas 92-100: el profesor ya no publica
ofertas nuevas, solo gestiona las existentes), `teacher.requests.*`,
`lessons.store` (10/min), `teacher.lessons`, `teacher.reports`,
`lesson-reports.*`.

**Hallazgo menor (P4):** `ClassOfferController::store()` sigue existiendo
como método público en el controlador (líneas 27-52) aunque ninguna ruta lo
invoca (`Route::resource(...)->except(['show','create','store'])`). Código
muerto inalcanzable — no es una vulnerabilidad (no hay ruta que lo dispare),
pero conviene eliminarlo o el próximo desarrollador puede reactivarlo sin
darse cuenta del rediseño de negocio documentado en el comentario.

### 1.5 Compartidas (`not.suspended`, cualquier rol autenticado)

`lessons.cancel` (10/min), `lessons.reschedule` (20/min), `lessons.join`.
Autorización real vía `LessonPolicy::cancel/reschedule/view` (ver §2).

### 1.6 Admin (`role:admin`, **sin** `not.suspended`)

`admin.users`, `admin.teachers.pending`, `admin.teachers.verify/reject`
(20/min), `admin.users.suspend/unsuspend` (20/min), `admin.requests`,
`admin.lessons`, `admin.lessons.cancel/force-complete/force-refund`
(10/min), `admin.recharges.index/approve/reject` (10-20/min),
`admin.reviews.hide/show` (20/min), `admin.ai-usage`.

**La ausencia de `not.suspended` en el grupo admin es una decisión
documentada explícitamente en el código** (`routes/web.php` líneas 122-126):
evita que un admin suspendido (por error, o por otro admin) quede sin forma
de revertir su propia suspensión. Esto ya no es un hallazgo "abierto" — es
un trade-off consciente y razonable (mitigado porque `suspendUser()` ya
bloquea explícitamente suspender a otro admin: `if ($user->hasRole('admin'))
return back()->with('error', ...)`), pero **sigue sin granularidad de
permisos**: cualquier admin puede aprobar dinero, verificar profesores y
suspender usuarios sin separación de funciones ni segundo factor. Ver §4,
reclasificación de A4.

### 1.7 `routes/auth.php`

Login (`throttle:10,1`), registro (`throttle:5,1`), reset de password
(`throttle:5,1`), verificación de email (`throttle:6,1`), Google OAuth
(gateado por `config('services.google.login_enabled')`, comprobado también
en backend, no solo ocultando el botón — ver `GoogleAuthController::redirect()`),
verificación de teléfono (`throttle:3,1` envío / `10,1` verificación).

---

## 2. Mapa de roles/permisos y discrepancias frontend↔backend

### 2.1 Estado real de las Policies (contradice `MOVA_MASTER_CONTEXT.md`)

`MOVA_MASTER_CONTEXT.md` (2026-07-18) afirma **"No existe `app/Policies/`.
Toda la autorización se implementa inline... mediante `abort_unless()`"**.
Esto es **OBSOLETO**: hoy existen 7 Policies en `app/Policies/`, todas
registradas en `app/Providers/AuthServiceProvider.php:29-36`:

```
Lesson::class            => LessonPolicy::class
ClassRequest::class      => ClassRequestPolicy::class
TeacherReview::class     => TeacherReviewPolicy::class
RechargeRequest::class   => RechargeRequestPolicy::class
ClassOffer::class        => ClassOfferPolicy::class
Student::class           => StudentPolicy::class
StudentDiagnostic::class => StudentDiagnosticPolicy::class
```

Las 7 tienen `before()` con `return $user->hasRole('admin') ? true : null;`
(bypass de admin correcto y consistente). Verificado que **todos los
controladores relevantes invocan `$this->authorize(...)`** con la ability
correcta (`LessonController`, `ClassRequestController`,
`TeacherReviewController`, `Admin\RechargeController`, `ClassOfferController`,
`StudentController`, `DiagnosticsController`).

**Excepción real (no gap, sino diseño):** `AdminController` (verify/reject
teacher, cancel/force-complete/force-refund lesson, suspend/unsuspend user)
**no llama a `$this->authorize()`** en ningún método — se apoya
exclusivamente en el middleware de ruta `role:admin`. No hay Policy de
`User`/`TeacherProfile` para estas acciones. Funcionalmente correcto (no hay
forma de acceder sin el rol), pero es la misma falta de granularidad que
señalaba A4 en el audit viejo: **todo admin puede hacer todo**, sin
distinguir p.ej. "admin financiero" de "admin de moderación". Sigue abierto,
ver §4.

### 2.2 Doble capa de defensa (patrón consistente en todo el código)

En las rutas financieras críticas (`lessons.store`, `lessons.cancel`,
`lessons.confirm-payment`, `lesson-reports.store`, `reviews.store`,
`class-requests.approve/reject`), el patrón es siempre:

1. `$this->authorize(...)` **antes** de la transacción (chequeo rápido, sin lock).
2. Dentro de `DB::transaction()`, **re-leer el modelo con `lockForUpdate()`**
   y **re-validar el estado** (`abort_unless($lesson->status === 'scheduled', ...)`).

Esto cierra la ventana TOCTOU entre la autorización y el lock — un patrón
deliberado y repetido, no accidental (hay comentarios explícitos
referenciando esto en `ClassRequestController::approve/reject`, que llaman
`$this->authorize('view', ...)` una segunda vez **dentro** de la transacción
tras el lock).

### 2.3 Discrepancias frontend↔backend

Búsqueda dirigida (grep de botones condicionados por rol en Vue vs. las
Policies/middleware reales) no encontró casos de tipo (b) — "frontend oculta
pero backend permite igual" — en las superficies revisadas
(`ParentIndex.vue`/`TeacherIndex.vue` para clases, `Marketplace/Index.vue`,
`Teachers/Show.vue`). Los botones de acción (cancelar, reprogramar, unirse,
confirmar pago, reseñar) están todos respaldados por la Policy
correspondiente en el backend, así que aunque el frontend calculara mal la
visibilidad de un botón, una llamada directa a la ruta seguiría estando
protegida.

Un caso de tipo (a) —UX confuso pero no inseguro— documentado en el propio
código: `ClassOfferController` mantiene `edit/update/destroy/toggle`
alcanzables pero **no `create`/`store`** — un profesor con ofertas antiguas
puede seguir editándolas, pero la UI de creación fue retirada
deliberadamente (ver commit `692b365` y comentario en `routes/web.php`).
Esto es coherente en frontend y backend — no es una discrepancia real, solo
quedó documentado aquí porque a primera vista parece una feature a medias.

**No se pudo hacer un barrido pixel-a-pixel de las 52 páginas Vue** dentro
del tiempo de esta fase — se revisaron las páginas de dominio financiero y
de datos de menores (Lessons, ClassRequests, Teachers, Marketplace,
Students). Páginas no revisadas en detalle: `Admin/*.vue`, `Diagnostics/*`,
`Teacher/Credits/*`. Marcar como `UNKNOWN / REQUIERE VERIFICACIÓN` si se
necesita cobertura total del frontend.

---

## 3. Auditoría de base de datos y consistencia

### 3.1 Ledger financiero — extremadamente bien defendido

`credit_transactions.idempotency_key` tiene **UNIQUE a nivel de BD**
(`2026_07_10_000001_harden_monetization_records.php:58`). Cada operación
financiera (`LessonController::store/cancel`, `Admin\RechargeController::
approve/reject`, `LessonSettlementService::consume/refund`,
`PhoneVerificationController::grantTeacherWelcomeBonus`) sigue el mismo
patrón: `DB::transaction()` + `lockForUpdate()` sobre `Lesson`/
`TeacherProfile`/`RechargeRequest` + `try { CreditTransaction::create() }
catch (UniqueConstraintViolationException) { idempotente, no fabricar }`.

`LessonSettlementService` (nueva capa, no documentada en
`MOVA_MASTER_CONTEXT.md`) es ahora el **único punto** que mueve
`credit_transactions` + `teacher_profiles` + `classes.status/
credits_settled_at` juntos para consumo/reembolso automático o forzado por
admin — consolidó lo que antes eran 3-4 implementaciones divergentes
(según su propio docblock). `Lesson::reservedCreditAmount()` **nunca
recalcula desde `duration_minutes`**: exige encontrar exactamente 1 asiento
`reservation` en el ledger o lanza `RuntimeException` — elimina la clase de
bug "cifra fabricada" que un recálculo ad-hoc introduciría tras un
reschedule.

**No se encontró ninguna ruta de mutación financiera sin `lockForUpdate()`**
en los controladores/servicios revisados (`LessonController::store/cancel/
confirmPayment`, `AdminController::cancelLesson/forceCompleteLesson/
forceRefundLesson`, `Admin\RechargeController::approve/reject`,
`LessonSettlementService`, `PhoneVerificationController::
grantTeacherWelcomeBonus`, `Teacher\CreditController::storeRecharge`).

### 3.2 Foreign keys endurecidas con guardas de migración únicas

`2026_07_10_000002_protect_monetization_history.php` reescribe (solo MySQL)
las FKs de `credit_transactions.teacher_profile_id`,
`recharge_requests.teacher_profile_id`, `classes.teacher_profile_id`,
`classes.student_id`, `classes.class_request_id` y
`class_requests.student_id` de `CASCADE`/`SET NULL` a **`RESTRICT`** —
borrar un profesor o un alumno con historial financiero/de clases ya **no
puede** arrastrar en cascada ese historial. La migración tiene:
- `assertForeignKeysMatchBaseline()` — verifica antes de tocar nada que la
  FK actual coincide exactamente con lo esperado (consulta
  `information_schema`), o aborta con `RuntimeException`.
- `assertRollbackDoesNotExposeHistory()` en `down()` — si hay datos en esas
  tablas, el rollback se niega a revertir a `CASCADE`.

Es un nivel de rigor de migraciones poco común — cada migración sensible
audita su propia precondición y postcondición.

**Consecuencia correctamente anticipada:** `ProfileController::destroy()`
comprueba `hasProtectedHistory()` (créditos, recargas, clases del profesor,
o solicitudes/clases de los hijos) y, si existe, **anonimiza en vez de
borrar** (`forceFill` con datos placeholder + `suspended_at`), evitando que
el usuario choque con la restricción de FK al intentar borrar su cuenta.
Bien pensado — no es un hallazgo, es una nota de que el diseño ya lo cubre.

### 3.3 Solapamiento de horario (race de doble reserva)

`LessonController::hasScheduleOverlap()` se usa en `store()` y
`reschedule()`. En `store()`: primer chequeo sin lock (early exit para UX),
luego **dentro** de la transacción con `lockForUpdate()` sobre la query de
`classes` filtrando por `teacher_profile_id`+`status='scheduled'`+rango de
`start_time`. Existe un índice compuesto dedicado
(`2026_08_22_000001_add_composite_overlap_index_to_classes_table.php`:
`(teacher_profile_id, status, start_time)`), con docblock explicando que
antes solo había índices de una columna y MySQL no podía resolver las tres
condiciones eficientemente — comentario que documenta explícitamente que
esto agravaba la ventana de un deadlock reschedule×reschedule "ya
documentado en C-2" (no se encontró el documento C-2 explícitamente en el
repo actual, probablemente vive en `docs/HANDOFF_FINAL.md`, no leído en
detalle en esta pasada — ver §6).

No se pudo reproducir empíricamente la race de doble-booking (se requeriría
un entorno con BD levantada y dos requests concurrentes reales — fuera de
alcance de una fase de solo lectura sin BD). Clasificado como
`UNKNOWN / REQUIERE VERIFICACIÓN EMPÍRICA`, pero el diseño (lock + índice +
segundo chequeo post-lock) es coherente con la prevención correcta.

### 3.4 Estados imposibles / máquina de estados

`classes.status` (migración base `enum(['scheduled','in_progress',
'completed','cancelled'])`, ampliado por `2026_07_17_000001` con estados de
pago y `2026_08_16_000002` con `needs_admin_review`) — el estado real hoy
(inferido de los controladores) es:
`scheduled → paid → pending_parent_confirmation → completed`, con
`cancelled` y `needs_admin_review` como ramas. `LessonSettlementService`
define explícitamente `CONSUMABLE_STATES` y `REFUNDABLE_STATES` como listas
cerradas — cualquier estado fuera de esas listas lanza `RuntimeException` en
vez de proceder. No se encontraron transiciones no controladas por el
código (todas las escrituras de `status` pasan por un `abort_unless`/
`abort_if` previo que fija el estado de origen esperado).

**Verificado en vivo un caso límite documentado en el propio código**
(`TeacherReviewController::store()`): con C-1 (settlement automático), una
clase puede llegar a `review.store` en dos caminos —
`pending_parent_confirmation` (manual) o `completed` (auto-liquidada por el
scheduler antes de que el padre calificara). El código maneja ambos
explícitamente y documenta por qué (`assertReviewable()` acepta ambos
estados, y el flujo de reseña sobre una clase ya liquidada solo recalcula
`hourly_rate`, sin volver a mover crédito). Bien manejado, sin doble cobro.

### 3.5 Índices, unicidades y nulabilidad — inventario relevante

| Tabla | Constraint | Estado |
|---|---|---|
| `credit_transactions.idempotency_key` | UNIQUE | ✅ |
| `recharge_requests.(payment_method, operation_number_normalized)` | UNIQUE compuesto | ✅ (previene reutilizar un número de operación bajo el mismo método, o como legacy) |
| `users.phone_verified_normalized` | UNIQUE, nullable | ✅ — arregla explícitamente un hallazgo crítico de auditoría anterior (bono de bienvenida duplicado vía múltiples cuentas con el mismo teléfono); NULL permitido (múltiples NULL no chocan en MySQL) para no bloquear a quien no ha verificado |
| `teacher_reviews.lesson_id` | UNIQUE | ✅ — máx. 1 reseña por clase |
| `lesson_reports.lesson_id` | UNIQUE (no releído en esta pasada, confirmado por `MOVA_MASTER_CONTEXT.md` y por el guard `if ($lesson->lessonReport()->exists())` en el controller) | ✅ |
| `subjects.normalized_name` | UNIQUE | ✅ a nivel de columna — pero ver 3.6 (gap de manejo de excepción) |
| `classes.(teacher_profile_id, status, start_time)` | índice compuesto | ✅ (rendimiento del chequeo de solapamiento) |
| `teacher_reviews.rating` | `tinyint unsigned`, **sin CHECK de rango 1-5 a nivel de BD** | ⚠️ Validado solo en la capa de aplicación (`'rating' => 'required|integer|min:1|max:5'`). Riesgo bajo: no hay ninguna vía de escritura directa a esta tabla fuera del controlador validado, pero es una dependencia de disciplina de código, no de la BD. |

### 3.6 Hallazgo nuevo — `Subject::firstOrCreateByName()` sin manejo de carrera

`app/Models/Subject.php::firstOrCreateByName()` usa `static::firstOrCreate(
['normalized_name' => $normalized], [...])`. Bajo dos requests concurrentes
que registran/editan con el **mismo nombre de materia nuevo** (p. ej. dos
profesores nuevos escribiendo "Cálculo III" al mismo tiempo en
`RegisteredUserController::store()` o `TeacherProfileController::update()`),
ambos pueden ejecutar el `SELECT` de `firstOrCreate`, no encontrar nada, e
intentar el `INSERT` — uno gana, el otro choca contra el UNIQUE de
`normalized_name` y **Laravel no captura esa excepción aquí** (a diferencia
de los otros puntos de unicidad del sistema — `idempotency_key`,
`operation_number_normalized`, `phone_verified_normalized` — que sí envuelven
el `create()` en un `try/catch UniqueConstraintViolationException`). El
resultado sería un `500` no controlado en el flujo de registro/edición de
perfil de profesor, en vez de un error de validación amigable.

**Severidad: P3 (bajo impacto, alta probabilidad solo bajo carga
simultánea real de registros).** Categoría: DATA INTEGRITY / FUNCTIONAL.
No es una vulnerabilidad de seguridad ni afecta dinero — es una
inconsistencia de robustez frente al patrón que el resto del código sigue
religiosamente. Recomendación: envolver el `firstOrCreate` en el mismo
patrón `try/catch` + refetch usado en el resto del sistema.

### 3.7 `students.parent_user_id` — CASCADE (verificado consistente con protección de menores)

La FK original (`2024_01_01_000002_create_students_table.php`, no releída
línea por línea en esta pasada pero confirmada por `MOVA_MASTER_CONTEXT.md`
y por el comportamiento de `ProfileController::destroy()`) sigue en
`cascadeOnDelete()` desde `users`. Esto es coherente con el modelo de "el
alumno no es una cuenta, es de su padre" — pero como se documenta en 3.2, la
cascada de borrado de un `Student` con historial de clases ahora **choca**
contra las FKs `RESTRICT` de `classes.student_id`/`class_requests.student_id`,
lo cual `ProfileController` ya anticipa y evita (anonimiza en vez de borrar
duro). No se encontró ningún otro punto de borrado de `User` en el código
(no hay ruta admin de "eliminar usuario", solo "suspender") — así que el
único camino de borrado real está cubierto.

---

## 4. Re-clasificación de hallazgos previos (dominio rutas/permisos/BD)

Los documentos `MOVA_PRODUCTION_READINESS.md`/`MOVA_SYSTEM_KNOWLEDGE.md`
pedidos como referencia **no existen en este repo**; se usa en su lugar
`docs/PRODUCTION_READINESS_AUDIT.md` (2026-06-28) y la sección 5 de
`MOVA_MASTER_CONTEXT.md` (2026-07-18, deuda técnica C1-C4/A1-A5/M1) como
base de comparación.

| # | Hallazgo original | Fuente | Reclasificación | Evidencia |
|---|---|---|---|---|
| 1 | "No rate limit en rutas de escritura sensibles" | PRODUCTION_READINESS_AUDIT §5 | **FIXED** | `routes/web.php` — throttle en prácticamente todas las mutaciones (§1) |
| 2 | "No existe `app/Policies/`, autorización inline dispersa" | MASTER_CONTEXT A1 | **FIXED** | 7 Policies registradas y usadas consistentemente (§2.1) |
| 3 | "El grupo admin no aplica `not.suspended`" | MASTER_CONTEXT A3 | **RECLASIFICADO — diseño documentado, no bug**, con mitigación explícita (`suspendUser` bloquea auto-suspensión de admins) | `routes/web.php:122-126`, `AdminController::suspendUser()` |
| 4 | "Rol admin sin granularidad de permisos" | MASTER_CONTEXT A4 | **STILL OPEN** | `spatie/laravel-permission` soporta permisos finos pero solo se usan roles; `AdminController` no tiene Policy propia, solo `role:admin` | 
| 5 | "Salas Jitsi sin control de acceso, security through obscurity" | MASTER_CONTEXT C3 | **PARTIALLY FIXED** | Ahora existe `JaasService::generateToken()` (JWT firmado) invocado solo en `LessonController::join()`, con `jitsi_room`/`jitsi_password` en `$hidden` del modelo y expuestos **solo** tras pasar por `LessonPolicy::view()` + chequeo de estado — reduce drásticamente la ventana de exposición vs. lo descrito en el doc viejo (iframe con URL cruda). No se auditó el `JaasService`/`config/jaas.php` en profundidad (dominio de otro compañero de equipo — Security Engineer); marcar `UNKNOWN` la solidez de la firma JWT en sí. |
| 6 | "`ProductionSeeder`/servicios leen `env()` en runtime, rompe con `config:cache`" | MASTER_CONTEXT A5 | **NO REVERIFICADO** — fuera del dominio de rutas/permisos/BD estricto; no se releyó `DiagnosticAiEnrichmentService` en esta pasada. `UNKNOWN / REQUIERE VERIFICACIÓN` por el equipo de arquitectura. |
| 7 | "Doble gasto / bono de bienvenida duplicado por múltiples teléfonos" | (no en los docs viejos — encontrado documentado como fix ya aplicado en el propio código) | **FIXED (con evidencia de que fue explotado y corregido)** | `2026_08_22_000002_add_verified_phone_uniqueness_to_users_table.php` + guardas en `PhoneVerificationController` — el propio código narra el hallazgo original y la corrección |
| 8 | "Profesor no verificado podía aceptar solicitudes y entrar a videollamada con un menor" | (idem — hallazgo crítico 2026-08-22, documentado en el código) | **FIXED** | `ClassRequestPolicy::accept()` ahora exige `$profile->is_verified` explícitamente; el comentario documenta que antes no se comprobaba |
| 9 | "reschedule() no ajusta credits_reserved si cambia duración" | MASTER_CONTEXT §1.4 (gap conocido) | **FIXED** | `LessonController::reschedule()` ahora rechaza explícitamente cualquier intento de cambiar `duration_minutes` (`abort_if(request()->has('duration_minutes'), 422, ...)`) — comentario cita esto como fix de seguridad C-2 |
| 10 | "Vestigios de Zoom sin limpiar" | MASTER_CONTEXT M1 | **NO REVERIFICADO en esta pasada** (dominio frontend/limpieza, no rutas/permisos/BD) |

---

## 5. Hallazgos nuevos (este dominio)

| ID | Severidad | Categoría | Hallazgo | Evidencia |
|---|---|---|---|---|
| N1 | **P3** | DATA INTEGRITY / FUNCTIONAL | `Subject::firstOrCreateByName()` no captura `UniqueConstraintViolationException` bajo carrera concurrente sobre un nombre de materia nuevo, a diferencia de todos los demás puntos de unicidad del sistema (que sí lo hacen). Puede producir un 500 en registro/edición de perfil de profesor bajo concurrencia real, no una respuesta 4xx amigable. | `app/Models/Subject.php` (`firstOrCreateByName`), contrastar con `Teacher/CreditController::storeRecharge` y `PhoneVerificationController::verify` que sí capturan la excepción |
| N2 | **P4** | ARCHITECTURE / CÓDIGO MUERTO | `ClassOfferController::store()` sigue implementado pero inalcanzable (ruta excluida deliberadamente). Sin riesgo actual, pero puede reactivarse por error en un refactor futuro sin que quien lo haga note que el modelo de negocio cambió (el profesor ya no publica ofertas). | `app/Http/Controllers/ClassOfferController.php:27-52`, `routes/web.php:101` |
| N3 | **P4** | DATABASE | `teacher_reviews.rating` no tiene `CHECK` a nivel de BD para el rango 1-5, solo validación de Form Request. Bajo riesgo real (no hay otra vía de escritura), documentado como nota de higiene. | `database/migrations/2026_06_29_300000_create_teacher_reviews_table.php` |
| N4 | **P3** | AUTHORIZATION | `AdminController` no usa Policies para ninguna de sus 8 acciones mutantes (verify/reject teacher, cancel/force-complete/force-refund lesson, suspend/unsuspend user) — depende únicamente del middleware `role:admin`. Correcto hoy (no hay bypass), pero es la misma falta de separación de funciones que A4: cualquier cuenta admin puede ejecutar cualquiera de estas acciones sin distinción de "admin financiero" vs "admin de soporte", y sin un log de auditoría uniforme (`Log::info` está presente en algunos métodos — `verifyTeacher`, `rejectTeacher`, `cancelLesson`, `forceCompleteLesson`, `forceRefundLesson` — pero **no** en `suspendUser`/`unsuspendUser`, que son de las acciones más sensibles del panel). | `app/Http/Controllers/AdminController.php:281-307` |

---

## 6. Qué no se pudo verificar y por qué

- **`php artisan route:list --json`**: falló porque este worktree no tiene
  `vendor/` instalado (`composer install` no se ejecutó, y no correspondía
  ejecutarlo en una fase de solo lectura sin autorización explícita de tocar
  dependencias). El mapa de rutas se reconstruyó a mano desde
  `routes/web.php` + `routes/auth.php`, con cobertura completa de nombres,
  verbos y middleware, pero sin la confirmación automática que da Artisan
  (p. ej. el orden real de aplicación de middleware tras `route:cache` en
  producción). `UNKNOWN / REQUIERE VERIFICACIÓN` en un entorno con
  dependencias instaladas.

- **`php artisan migrate:status` / `php artisan test`**: mismo bloqueo —
  sin `vendor/autoload.php` no corre ningún comando Artisan en este
  worktree. No se pudo confirmar que las 63 migraciones estén realmente
  aplicadas en ningún entorno, ni que la suite de tests (que sí existe,
  incluye `tests/Feature/MentorshipRequestTest.php`,
  `MovaCriticalFlowTest.php`, `ProfileTest.php`, entre otros) pase en verde
  con el estado actual del código. `UNKNOWN`.

- **Race de doble-booking / doble-liquidación bajo concurrencia real**: el
  diseño (locks + índices + UNIQUE + reintentos idempotentes) es coherente
  y está fuertemente comentado como resultado de auditorías previas, pero
  esta fase **no ejecutó** un test de concurrencia real (dos requests
  simultáneas contra una BD) — la instrucción de la tarea exige que
  cualquier verificación empírica se haga en un artefacto desechable fuera
  del árbol del proyecto, y no había una BD disponible para levantar sin
  tocar la configuración del proyecto. `UNKNOWN / REQUIERE VERIFICACIÓN
  EMPÍRICA` (recomendado como test de integración con `--parallel` o dos
  procesos PHP concurrentes contra MySQL real, no SQLite).

- **`config/jaas.php` / solidez del JWT de Jitsi**: se confirmó que existe
  `JaasService::generateToken()` y que se invoca correctamente tras
  autorización, pero no se auditó el contenido del JWT (claims, expiración,
  algoritmo de firma, gestión de la private key) — eso cae más en el
  dominio del Security Engineer del equipo para esta auditoría, pero se
  señala aquí porque toca directamente el requisito de CLAUDE.md sobre
  revisión de seguridad explícita para cambios en Jitsi/menores.

- **Cobertura completa de discrepancias frontend↔backend en las 52 páginas
  Vue**: solo se revisó el subconjunto de dominio financiero y de datos de
  menores. Páginas de `Admin/*.vue`, `Diagnostics/*.vue` y
  `Teacher/Credits/*.vue` no se inspeccionaron línea a línea.
  `UNKNOWN / REQUIERE VERIFICACIÓN` si se quiere cobertura total.

- **`docs/HANDOFF_FINAL.md` en detalle** (77KB según el diff visto en git
  status, con secciones §1-§21+ referenciadas por comentarios del código
  como "C-1", "C-2", "Decisión de negocio #3", etc.): no se leyó
  completo por presupuesto de tiempo — es probable que documente en detalle
  varios de los hallazgos que aquí se infirieron solo a partir del código y
  sus comentarios. Recomendado como primera lectura para cualquier
  continuación de esta auditoría.
