# MOVA — Fase 0: Modelo de negocio, flujos funcionales y bug hunt

> Generado el 2026-08-26 mediante lectura directa del código real en el working tree
> (`C:\Users\ABEL\OneDrive\Desktop\ProyectoMOVA\.claude\worktrees\agent-af527c8860654d820`).
> **Nota metodológica importante:** `docs/MOVA_SYSTEM_KNOWLEDGE.md` (citado en las
> instrucciones de esta tarea) **no existe en este árbol de trabajo** — no se pudo leer.
> `MOVA_MASTER_CONTEXT.md` sí existe pero está fechado 2026-07-18 y las migraciones más
> recientes en disco llegan hasta 2026-08-22 (referral codes, `needs_admin_review`,
> `credits_settled_at`, JaaS) — es decir, el código ha avanzado ~1 mes respecto al
> documento maestro. Este informe se basa en el CÓDIGO REAL, no en el documento, y señala
> explícitamente dónde el documento quedó desactualizado.
>
> Alcance de esta fase: modelo de negocio, flujos funcionales, edge cases, bug hunt
> funcional. Seguridad (autorización, secretos, control de acceso a videollamadas) la
> cubre un agente en paralelo y se evita duplicar aquí, salvo cuando un hallazgo de
> seguridad ya documentado en el código (comentarios "hallazgo CRÍTICO... 2026-08-22")
> es necesario para entender el flujo funcional.

---

## 1. Modelo de negocio (re-verificado contra código real)

### 1.1 Actores y entidades

| Actor/Entidad | Verificado en | Notas frente al documento maestro |
|---|---|---|
| `User` (parent/teacher/admin) | `app/Models/User.php`, roles vía Spatie | Sin cambios de fondo |
| `Student` | `app/Models/Student.php` | Sigue sin ser una cuenta (correcto), pero ver **BUG-1** sobre borrado |
| `TeacherProfile` | `app/Models/TeacherProfile.php` | **Nuevo:** `referral_code` (6 chars, alfabeto sin vocales/ambiguos, generado en `booted()`), 3 niveles de tarifa (ver 1.4), no solo 2 como decía el doc maestro |
| `ClassRequest` | `app/Models/ClassRequest.php` | **Nuevo:** puede estar vinculada a un profesor específico vía `teacher_profile_id`/`teacher_referral_code` (Opción A, código de referido) — antes solo emparejaba por oferta/materia |
| `Lesson` (tabla `classes`) | `app/Models/Lesson.php` | **Nuevo estado:** `needs_admin_review`. **Nuevo campo:** `credits_settled_at` (caché derivada, fuera de `$fillable` a propósito). `jitsi_room`/`jitsi_password` ahora están `$hidden` y solo se exponen vía `LessonController::join()` con JWT firmado (JaaS reemplazó el iframe público a `meet.jit.si`) |
| `LessonSettlementService` (nuevo) | `app/Services/LessonSettlementService.php` | Única capa que mueve `credit_transactions` + `teacher_profiles` + `classes.status/credits_settled_at`. Reemplaza lógica que antes vivía duplicada en `TeacherReviewController` |
| `ClassEvent` | `app/Models/ClassEvent.php` | Bitácora inmutable, `actor_id` ahora nullable (para eventos del sistema/scheduler) |
| `RechargeRequest` | migraciones `2026_07_10_*` | Ahora con `package_code`, `payment_method`, `operation_number_normalized` + índice único compuesto |

### 1.2 Modelo de monetización — cambios reales frente al doc maestro

- **Tres niveles de tarifa, no dos.** `TeacherProfile::maxAllowedRate()` (líneas 68-81):
  - Base: S/20 (por defecto)
  - S/25 si `completed_classes_count >= 5` **y** `avgRating() >= 4.0`
  - S/30 ("Élite") si `completed_classes_count >= 20` **y** `avgRating() >= 4.5`
  El documento maestro solo describía 2 niveles (S/20 → S/25 a las 5 clases, sin condición de rating). Este es el comportamiento REAL actual.
- **`price_frozen_pen` sí se congela al agendar** (`LessonController::store()` línea 90: `round($teacherProfile->hourly_rate * $creditsNeeded, 2)`), y el frontend (`ParentLessonCard.vue` línea 130-135) ya usa ese campo con fallback al cálculo dinámico solo para lecciones legacy sin el campo. **El hallazgo M6 del documento maestro ("monto se recalcula en el front, no se congela") está RESUELTO** en el código actual.
- **Liquidación automática nueva (C-1):** `app/Console/Commands/SettleLessons.php` + `LessonSettlementService`. Corre por hora (`mova:settle-lessons --dry-run` en `Kernel.php` línea 23 — **nótese que en el Kernel programado sigue con `--dry-run`, es decir, en producción HOY esta liquidación automática NO escribe nada todavía**, solo reporta). Dos rutinas:
  1. Clases `paid`/`pending_parent_confirmation` sin cerrar tras `settlement_grace_days` (7 días) → `consume()` automático.
  2. Clases `scheduled` sin confirmar tras `unconfirmed_days` (7 días) → escaladas a `needs_admin_review` (sin efecto financiero, requiere acción de admin vía `forceCompleteLesson`/`forceRefundLesson`).
- **`specific_rate` es una función muerta.** Ver **BUG-4**.
- Catálogo de recargas y kill-switch `RECHARGES_ENABLED` sin cambios de fondo respecto al doc maestro.

### 1.3 Flujo de descubrimiento — pivote de producto no reflejado en el doc maestro

El commit reciente (`2fb22fd feat(ui): marketplace informativo y flujo de solicitudes por codigo de confianza`) cambió el modelo de descubrimiento:

- `MarketplaceController::index()` (líneas 9-26, comentario explícito) ahora es **puramente informativo**: lista profesores verificados, pero el padre **ya no puede elegir un profesor y solicitarle una clase directamente** desde el marketplace.
- El único atajo directo a un profesor específico es el **código de referido de 6 caracteres** (`teacher_profiles.referral_code`), resuelto en `ClassRequestController::store()`/`lookupTeacherByCode()`.
- El **diagnóstico ya NO recomienda un top-5 de profesores.** `DiagnosticsController::store()` (líneas 27-69) sigue llamando a `DiagnosticRecommendationService::compute()`, pero el resultado no se usa: crea directamente una `ClassRequest` genérica (`class_offer_id = null`) visible para "todos los profesores verificados de la materia". `DiagnosticsController::results()` (línea 86) **hardcodea `'recommendations' => []`** — el algoritmo de scoring determinista que el documento maestro describe como "el corazón del producto" (§1.3 Fase B) sigue corriendo pero su salida es descartada. `requestClass()` (líneas 91-95) es ahora un endpoint muerto que solo redirige con un mensaje.
- **Esto no es necesariamente un bug** — parece un pivote de producto deliberado (evitar que el padre "elija" profesor, forzando el matching por materia o el código de confianza) — pero el documento maestro (y por tanto cualquier stakeholder que lo use como fuente de verdad) describe un producto que ya no existe en este punto. Se recomienda actualizar `MOVA_MASTER_CONTEXT.md` §1.3 Fase B para reflejar el estado real, y evaluar si `DiagnosticRecommendationService::compute()` vale la pena seguir ejecutando si su salida nunca se muestra.

---

## 2. Matriz ACTOR → ACCIÓN → CONDICIONES → RESULTADO → EFECTOS SECUNDARIOS

### 2.1 Padre — Diagnóstico → Solicitud

| Acción | Condiciones | Resultado | Efectos secundarios |
|---|---|---|---|
| `POST /diagnostics` (`DiagnosticsController::store`, throttle 5/min) | Alumno propio, materia válida | Crea `StudentDiagnostic` + `ClassRequest` genérica en la misma request (SIN transacción DB) | Enriquecimiento IA (fallback garantizado), evento `ClassRequestCreated` → notifica a profesores de la materia. **Ver BUG-2 (sin idempotencia).** |
| `POST /class-requests` (throttle 10/min) | Alumno propio; si `teacher_referral_code` viene, debe resolver a un `TeacherProfile` verificado | Crea `ClassRequest` en `open` o `pending_parent_approval` (si `parental_control`) | Si `is_mentorship` + oferta con cupos llenos → 422 antes de crear nada |
| `POST /class-requests/{id}/approve|reject` | Solo si `status === 'pending_parent_approval'`, dueño del alumno (Policy `view`) | `open` o `rejected` | Transacción + `lockForUpdate` — doble aprobación no duplica nada (segundo intento falla el `abort_unless` bajo lock) |

### 2.2 Profesor — Aceptar solicitud → Agendar (reserva de créditos)

| Acción | Condiciones | Resultado | Efectos secundarios |
|---|---|---|---|
| `POST /lessons` (`LessonController::store`, throttle 10/min) | Perfil verificado (`ClassRequestPolicy::accept`); `class_request.status === 'open'` (doble check, uno fuera y otro bajo `lockForUpdate`); sin solapamiento de horario (doble check); `credits_available >= creditsNeeded`; si mentoría, cupo disponible | Crea `Lesson` (`scheduled`), genera `jitsi_room`/`jitsi_password`, congela `price_frozen_pen`, mueve `credits_available → credits_reserved`, escribe `CreditTransaction(reservation)` con `idempotency_key = lesson:{id}:reservation`, marca `class_request.status = accepted` | `event(ClassConfirmed)` notifica a ambas partes. Doble-click / doble pestaña: la segunda transacción relee `class_request` bajo lock, ve `status != open`, aborta 403 — **no duplica reserva.** |

### 2.3 Padre — Pago offline → Reporte → Reseña (el "Handshake")

| Transición | Endpoint | Guardas verificadas | Notas |
|---|---|---|---|
| `scheduled → paid` | `POST /lessons/{id}/confirm-payment` (throttle 10/min) | Policy `confirmPayment` (padre dueño); `status === scheduled`; `now() >= end_time`; transacción + lock, doble check | Comentario explícito en código: sin bypass por query param ni entorno — para E2E hay un comando dedicado `mova:testing-backdate-lesson` |
| `paid → pending_parent_confirmation` | `POST /lessons/{id}/report` | Policy `createReport` (profesor dueño); `status === paid`; reporte único (`lessonReport()->exists()` chequeado dos veces, dentro y fuera del lock) | — |
| `pending_parent_confirmation|completed → completed` (reseña) | `POST /lessons/{id}/review` (throttle 10/min) | Policy `createReview`; `assertReviewable()` exige reporte existente Y estado en `[pending_parent_confirmation, completed]`; reseña única | Si la clase ya se auto-liquidó (`credits_settled_at !== null`) la reseña NO dispara `consume()` de nuevo — solo recalcula `hourly_rate` vía `maxAllowedRate()`. **Ver BUG-3 sobre el camino sin reporte.** |

### 2.4 Cancelación / Reprogramación

| Acción | Quién | Condiciones | Efectos |
|---|---|---|---|
| `POST /lessons/{id}/cancel` | Padre, profesor o admin (Policy `cancel`) | Solo `status === scheduled` | Devuelve `reservedCreditAmount()` (del ledger, NUNCA recalculado desde `duration_minutes`), refund con `idempotency_key = lesson:{id}:release`, libera cupo de mentoría, `ClassEvent('class_cancelled')` |
| `POST /lessons/{id}/reschedule` | Padre o profesor (Policy `reschedule`) | Solo `status === scheduled`; **rechaza explícitamente** `duration_minutes` en el request (422 informativo, no ignorado en silencio) | Reutiliza `hasScheduleOverlap()` con lock, excluyendo la propia lección; guarda `original_start_time` la PRIMERA vez únicamente |
| `POST /admin/lessons/{id}/force-complete` | Admin | Estado ∈ `{paid, pending_parent_confirmation, needs_admin_review}` (NUNCA `scheduled` — sin evidencia de que la clase ocurrió) | Vía `LessonSettlementService::consume()`, mismo camino que el scheduler |
| `POST /admin/lessons/{id}/force-refund` | Admin | Estado ∈ `{scheduled, paid, pending_parent_confirmation, needs_admin_review}` | Vía `LessonSettlementService::refund()`, libera cupo de mentoría también en estados que el `cancel()` normal nunca alcanza |

### 2.5 Admin — Recargas y verificación de profesores

| Acción | Condiciones | Resultado |
|---|---|---|
| `POST /admin/recharges/{id}/approve` | `RechargeRequestPolicy` (solo admin vía `before()`); `status !== rejected` | Si ya estaba `approved`, responde "changed: false" sin duplicar (idempotente); si no, deposita créditos + `CreditTransaction(deposit)` |
| `POST /admin/recharges/{id}/reject` | idem | Simétrico |
| `POST /admin/teachers/{id}/verify` \| `/reject` | — | `reject` desactiva TODAS las `class_offers` del profesor (`is_active = false`) para que no queden visibles en el matching |

---

## 3. Comportamiento en cada edge case solicitado

| Edge case | Flujo de créditos/clases (`LessonController`, `LessonSettlementService`, `RechargeController`) | Flujo de diagnóstico (`DiagnosticsController`) |
|---|---|---|
| **Doble click en botón mutante** | Protegido: `lockForUpdate()` + re-chequeo de estado + `idempotency_key` UNIQUE en cada mutación financiera. Confirmado en `store()`, `cancel()`, `confirmPayment()`, `reschedule()`, `RechargeController::approve/reject`. | **NO protegido — ver BUG-2.** Sin transacción, sin unique constraint sobre `class_requests.student_diagnostic_id`, sin idempotency key. Un doble submit crea dos diagnósticos y dos solicitudes reales. |
| **Refresh / back button a mitad de transacción** | Las transacciones son atómicas y cortas (una sola request HTTP); un refresh a mitad de un `DB::transaction()` de PHP no puede "partir" la transacción — o commit completo, o rollback completo. Sin riesgo adicional. | Igual — el riesgo real es el reenvío del formulario (POST duplicado), cubierto por BUG-2, no por un refresh a mitad. |
| **Sesión expirada durante la operación** | Laravel redirige a login; ninguna mutación financiera queda a medias porque todas viven dentro de un único ciclo request/response con transacción explícita. | Igual. |
| **Request repetida o concurrente (dos pestañas)** | Cubierto por `lockForUpdate()` + idempotency keys en todos los flujos de créditos. Verificado explícitamente en comentarios de `LessonSettlementService::consume()`/`refund()` (usa el UNIQUE de `idempotency_key`, no un chequeo de `credits_settled_at IS NULL` leído antes del lock, precisamente para cerrar esta carrera). | No cubierto (BUG-2). |
| **Cambio de estado entre lectura y modificación** (ej. profesor cancela mientras padre confirma pago) | Cubierto: cada acción vuelve a leer la fila con `lockForUpdate()` y re-valida el estado ANTES de escribir, en vez de confiar en la instancia cargada al inicio del método. Ejemplo explícito en el comentario de `reschedule()`: "No confiamos en la instancia cargada antes de la transacción: otra petición pudo cancelarla...". `SettleLessons::escalateUnconfirmed()` también relee bajo lock y no trata un estado ya cambiado como error. | El `ClassRequestController::approve/reject` sí releen bajo lock; correcto. |
| **Error de proveedor externo a mitad del flujo** (WhatsApp/Jitsi/email) | WhatsApp: `WhatsAppChannel` nunca lanza excepción, solo loguea (kill-switch `WHATSAPP_ENABLED=false` por defecto). Mail: `SafeMailChannel` (no leído en detalle en esta pasada, pero documentado como fail-soft en el doc maestro). Jitsi/JaaS: `JaasService::generateToken()` SÍ puede `abort(500)` si faltan `JAAS_APP_ID`/`JAAS_PRIVATE_KEY`/`JAAS_KEY_ID` — pero esto ocurre en `LessonController::join()`, un endpoint de solo lectura que no muta nada financiero; un fallo ahí no dejaría datos a medias, solo impediría entrar a la videollamada. | El fallback de IA está descrito como garantizado en el doc maestro (`ai_used_fallback`); no se re-verificó línea por línea en esta pasada porque no es el foco de negocio, pero el flujo de `store()` NO depende del resultado de `$aiService->enrich()` para continuar (se llama y se descarta cualquier fallo interno). |
| **Error de BD a mitad de una transacción financiera** | Cualquier excepción dentro de `DB::transaction()` hace rollback completo — no hay updates parciales. El único riesgo real documentado en el propio código es una **desincronización preexistente** entre `credits_reserved` y el ledger (detectada, no producida, por `consume()`/`refund()`, que abortan con `RuntimeException` en vez de "fabricar" el faltante). Existe `app/Support/LedgerReconciliation.php` para detectar esto — no se auditó su cobertura en esta pasada. | N/A (sin transacción que proteger, ver BUG-2). |
| **Timeout/abandono a mitad de flujo** (padre nunca confirma pago; profesor nunca reporta) | Ambos casos tienen escalamiento automático: `scheduled` sin confirmar 7 días → `needs_admin_review` (sin efecto financiero, requiere admin). `paid` sin reporte 7 días → auto-`consume()` (sí tiene efecto financiero) — **ver BUG-3**, porque este camino permite que la clase se "complete" sin que el reporte pedagógico obligatorio exista jamás. | Sin timeout/expiración: una `ClassRequest` `open` puede quedar así indefinidamente; existe un recordatorio a las 12h (`sendUnansweredRequestAlerts`) pero sin escalamiento posterior. |

---

## 4. Bugs funcionales encontrados

### BUG-1 — [P1, DATA INTEGRITY] Borrar un `Student` con clases o solicitudes falla con un error de base de datos sin manejar (o, en SQLite/tests, borra en cascada el historial silenciosamente)

- **Dónde:** `app/Http/Controllers/StudentController.php::destroy()` (línea 61-66) — llama a `$student->delete()` sin try/catch.
- **Causa raíz:** la migración `database/migrations/2026_07_10_000002_protect_monetization_history.php` (líneas 8-15) cambió expresamente, **en MySQL**, las foreign keys de `classes.student_id` y `class_requests.student_id` de `CASCADE` a **`RESTRICT`** — por diseño, para proteger el historial financiero de un profesor al borrarlo. Pero esa misma migración deja intacta la relación desde `Student`: **no existe ningún guardia en `StudentController`, `StudentPolicy` ni un evento `deleting` en el modelo `Student`** que impida o maneje el borrado de un alumno con `ClassRequest`/`Lesson` asociados.
- **Impacto verificado:**
  - **En producción (MySQL):** cualquier padre que intente borrar (`DELETE /students/{id}`) un hijo que alguna vez tuvo una solicitud o una clase (lo normal para un alumno activo) recibe una `QueryException` de FK sin capturar → error 500 crudo, en vez de un mensaje de negocio ("no puedes eliminar un alumno con historial"). Esto rompe una acción básica del ciclo de vida (`Students/Index.vue` tiene un botón "Eliminar").
  - **En tests (SQLite):** SQLite no aplica FKs por defecto salvo que se activen explícitamente (`PRAGMA foreign_keys=ON`), así que la suite de 57 tests declarada en el doc maestro casi con certeza **no detecta este bug** — es exactamente el tipo de divergencia motor-de-test-vs-producción que la Fase 0 pide buscar explícitamente.
- **Severidad:** P1 — rompe una acción de usuario común y visible, y el modo de fallo (500 crudo) es peor que simplemente "no dejar borrar".
- **Recomendación:** decidir la regla de negocio (¿un alumno con historial nunca se borra, solo se archiva/oculta? ¿se permite borrar solo si no tiene `Lesson`/`ClassRequest`?) y luego, o bien capturar la excepción en el controlador con un mensaje claro, o bien añadir la validación explícita antes del `delete()` (p. ej. en `StudentPolicy::delete()` o en el controlador).

### BUG-2 — [P2, FUNCTIONAL] El flujo de diagnóstico no tiene protección contra doble envío — un doble-click crea dos diagnósticos y dos solicitudes de clase reales

- **Dónde:** `app/Http/Controllers/DiagnosticsController.php::store()` (líneas 27-69).
- **Causa raíz:** a diferencia de CADA mutación financiera del resto de la app (que usa `DB::transaction()` + `lockForUpdate()` + `idempotency_key` único), este método:
  - No envuelve la creación del `StudentDiagnostic` + `ClassRequest` en una transacción.
  - No tiene ninguna clave de idempotencia (a diferencia del ledger de créditos).
  - `class_requests.student_diagnostic_id` no tiene constraint `UNIQUE` (verificado en `database/migrations/2026_06_26_200002_add_student_diagnostic_id_to_class_requests.php`).
  - El único rate-limit es `throttle:5,1` (5 por minuto) — no evita un doble-click ni un doble-submit por reintento de red dentro de esa ventana.
- **Impacto:** un doble-click en "Enviar diagnóstico" (o un reintento automático del navegador/Inertia ante una respuesta lenta) crea **dos** `ClassRequest` `open` idénticas, visibles para los profesores de la materia. Si dos profesores distintos aceptan cada una, el padre terminaría con dos clases agendadas (y dos cobros offline esperados) para una sola necesidad real. Como mínimo, genera ruido y confusión visible en `ClassRequests/Index.vue`.
- **Severidad:** P2 — no hay pérdida de dinero de la plataforma (el profesor paga por la clase que acepta, cada aceptación es legítima en sí misma), pero sí una experiencia rota y datos duplicados con consecuencias reales aguas abajo (dos profesores contactando al mismo padre).
- **Recomendación:** envolver `store()` en `DB::transaction()`, y añadir un guardia de idempotencia razonable (p. ej. deduplicar por `parent_user_id + student_id + subject_id` dentro de una ventana corta, o deshabilitar el botón del lado del cliente mientras la request está en vuelo — lo segundo es necesario pero no suficiente).

### BUG-3 — [P2, BUSINESS LOGIC] Una clase puede "completarse" automáticamente sin que el reporte pedagógico (obligatorio, el diferenciador de MOVA) exista jamás — y la notificación automática promete al padre una acción que el sistema luego rechaza

- **Dónde:**
  - `app/Services/LessonSettlementService.php` línea 38: `CONSUMABLE_STATES = ['paid', 'pending_parent_confirmation', 'needs_admin_review']` — incluye `paid` sin exigir que exista un `LessonReport`.
  - `app/Console/Commands/SettleLessons.php` líneas 58-62: candidatas a liquidación automática = `whereIn('status', ['paid', 'pending_parent_confirmation'])` — de nuevo, sin filtrar por existencia de reporte.
  - `app/Notifications/LessonSettledNotification.php` línea 67: al padre se le dice *"La clase de {materia} de {alumno} se completó automáticamente. **Puede calificar al profesor cuando quiera.**"*
  - `app/Http/Controllers/TeacherReviewController.php::assertReviewable()` línea 127: `abort_unless($lesson->lessonReport()->exists(), 403, 'Solo se puede calificar una clase con el reporte del profesor listo.')`.
- **Secuencia del bug:** el padre confirma el pago (`scheduled → paid`). El profesor **nunca sube el reporte** (olvido, o simplemente no le conviene). Pasan `settlement_grace_days` (7 días por defecto) sin que nadie actúe. El scheduler (`mova:settle-lessons`, agendado por hora) llama a `LessonSettlementService::consume()`, que ve `status === 'paid'` (una de las `CONSUMABLE_STATES`) y **liquida la clase igual** — cobra el crédito al profesor, la marca `completed`, y (`notify: true`) envía `LessonSettledNotification` al padre diciéndole que puede calificar. El padre intenta calificar y el backend le devuelve un 403 porque `lessonReport()->exists()` es `false` — la clase jamás tendrá reporte, porque el estado ya es `completed` y no hay ningún camino de vuelta a `paid` para forzar al profesor a subirlo.
- **Por qué importa para el negocio:** el propio `MOVA_MASTER_CONTEXT.md` (§1, línea 35) describe el reporte pedagógico obligatorio como **"el diferenciador"** del producto. Este camino de auto-liquidación permite que un profesor perezoso se salga gratis de esa obligación — la única consecuencia es que pierde el crédito reservado (que de todos modos iba a perder si completaba correctamente), así que no hay ningún incentivo económico real para no dejar que esto pase. Y el padre recibe una promesa de UI ("puede calificar") que el propio backend no cumple.
- **Severidad:** P2 (BUSINESS LOGIC + FUNCTIONAL) — no es una pérdida de dinero ni un problema de seguridad, pero sí una contradicción visible entre lo que el sistema promete y lo que permite, y una manera de eludir silenciosamente la garantía de calidad central del producto.
- **Recomendación:** decidir explícitamente la regla de negocio (¿debería el auto-settlement de un `paid` sin reporte escalar a `needs_admin_review` en vez de auto-completarse, igual que un `scheduled` sin confirmar? ¿o generar un reporte "vacío"/marcador para que la reseña sea posible?), y en cualquier caso corregir el texto de `LessonSettledNotification` para no prometer una calificación que puede no estar disponible.

### BUG-4 — [P3, BUSINESS LOGIC] `specific_rate` (tarifa por oferta) se valida y se guarda, pero nunca se usa para calcular lo que realmente se cobra

- **Dónde:** `app/Http/Controllers/ClassOfferController.php::store/update` (valida `specific_rate` contra `maxAllowedRate()` y lo persiste en `class_offers.specific_rate`) vs. `app/Http/Controllers/LessonController.php::store()` línea 90 y `app/Http/Controllers/ClassRequestController.php::accept()` línea 241, que **siempre** usan `$teacherProfile->hourly_rate` (la tarifa general del perfil), nunca `$classOffer->specific_rate`.
- **Adicionalmente:** `teacher_subject.specific_rate` (pivote materia↔profesor) se fija explícitamente a `null` en los tres lugares donde se sincroniza (`TeacherProfileController.php` líneas 42 y 82, `RegisteredUserController.php` línea 75) — es decir, ese campo nunca tiene un valor real hoy.
- **Impacto:** un profesor puede configurar en `ClassOffers/Edit.vue` una tarifa específica distinta para una oferta concreta (p. ej. una materia más avanzada a S/25 mientras su tarifa general es S/20), creyendo que esa es la tarifa que se cobrará — pero al agendarse la clase, el sistema congela `price_frozen_pen` usando la tarifa general del perfil, ignorando por completo el valor que el profesor configuró. Es una función visible en la UI que no tiene ningún efecto real en el dinero.
- **Severidad:** P3 — no rompe nada ni genera inconsistencia de datos, pero es una función "fantasma" que puede generar expectativas incorrectas en el profesor sobre cuánto cobrará.
- **Recomendación:** o bien conectar `specific_rate` al cálculo de `price_frozen_pen` (usando `class_offer->specific_rate ?? teacherProfile->hourly_rate`), o retirar el campo de la UI si fue reemplazado deliberadamente por la tarifa única de perfil.

### BUG-5 — [P3, FUNCTIONAL/TIMEZONE] Estadísticas y límites "de hoy" usan el día calendario en UTC, no en hora de Lima

- **Dónde:**
  - `app/Http/Controllers/DashboardController.php` línea 28: `Lesson::whereDate('start_time', today())->count()` (stat `classes_today` del panel admin).
  - `app/Http/Controllers/AiUsageController.php` línea 16: `AiUsageLog::whereDate('created_at', $today)`.
  - `app/Services/DiagnosticAiEnrichmentService.php` línea 123: `->whereDate('created_at', today())` (para el límite diario `DIAGNOSTIC_AI_DAILY_LIMIT`).
  - `config/app.php` línea 73: `'timezone' => 'UTC'` — la app corre enteramente en UTC; no hay ningún `config('app.timezone', 'America/Lima')`.
- **Impacto:** Perú es UTC-5 todo el año (sin horario de verano). El corte de "día" que usa PHP (`today()`, medianoche UTC) ocurre a las **7:00 p.m. hora de Lima**, no a medianoche de Lima. Esto significa:
  - El contador `classes_today` del dashboard de admin puede subestimar o sobrestimar las clases del día real de Lima según la hora en que se consulte (una clase de las 8pm de Lima del día 25 ya cuenta como día 26 en UTC).
  - El límite diario de uso de IA (`DIAGNOSTIC_AI_DAILY_LIMIT`) se resetea a las 7pm hora de Lima en vez de a medianoche — no es un error de cálculo, pero si alguien asume que el límite se resetea a medianoche local, la ventana real es distinta a la esperada.
- **Severidad:** P3 — no afecta dinero ni datos críticos, es un desajuste de reporting/UX, pero encaja exactamente en lo que la Fase 0 pide detectar ("problemas de timezone").
- **Recomendación:** o fijar `config/app.php.timezone` a `America/Lima` (afecta a toda la app, requiere análisis más amplio porque el resto del sistema ya asume UTC para timestamps de BD), o al menos envolver estas 3 consultas puntuales en `now()->timezone('America/Lima')->startOfDay()` / `endOfDay()` para que reflejen el día calendario percibido por usuarios y admins en Perú.

### Observación adicional (no clasificada como bug): flujo de diagnóstico parcialmente vestigial

`DiagnosticRecommendationService::compute()` sigue ejecutándose en cada `POST /diagnostics` pero su resultado nunca se muestra (`DiagnosticsController::results()` hardcodea `recommendations: []`), y `DiagnosticsController::requestClass()` es un endpoint muerto. No es un bug funcional (no rompe nada), pero es trabajo de cómputo desperdiciado y superficie de código muerta que vale la pena limpiar o re-conectar, dependiendo de si el pivote de producto (marketplace informativo, sin elección directa de profesor) es definitivo.

---

## 5. Qué no se pudo verificar en esta pasada (requiere interacción manual, credenciales reales, o excede el alcance funcional)

- **`docs/MOVA_SYSTEM_KNOWLEDGE.md`** — el documento que las instrucciones de esta tarea pedían leer como base (secciones 14-16, 27) **no existe en este árbol de trabajo**. No se pudo re-verificar contra él; todo el análisis de máquina de estados se hizo directamente contra `Lesson.php`, `LessonController.php`, `LessonSettlementService.php` y las migraciones.
- **Reproducción empírica de doble-click / carrera real:** no se levantó un servidor local ni se ejecutaron dos requests concurrentes reales contra la BD para confirmar en vivo que los locks funcionan como el código sugiere (el análisis es por lectura de código, no por prueba dinámica) — las instrucciones de la tarea permitían un artefacto desechable fuera del árbol para esto, pero se priorizó cobertura amplia de código real dado el volumen de superficie a revisar; si se requiere, un siguiente paso sería levantar MySQL local (para que las FKs de BUG-1 se manifiesten) y ejecutar dos requests simultáneas contra `POST /lessons`.
- **Comportamiento real de Twilio/WhatsApp, Jitsi/JaaS, Gmail API en producción** — se verificó el manejo de errores en código (fail-soft, sin excepciones no capturadas), pero no se probó contra las APIs reales (requiere credenciales de producción que esta fase no debía tocar).
- **`app/Support/LedgerReconciliation.php`** — se detectó su existencia (referenciado en comentarios de `LessonSettlementService` como "lo que `mova:reconcile-ledger` existe para detectar") pero no se auditó su lógica interna ni su cobertura de casos en esta pasada — puede ser relevante para un hallazgo de DATA INTEGRITY adicional.
- **Cobertura real de tests:** el doc maestro afirma "57/57 tests en verde" a fecha 2026-07-18; no se ejecutó la suite de tests en esta pasada (fuera de alcance de una fase de solo-lectura sin tocar el entorno), por lo que no se puede confirmar si BUG-1 (dependiente de FKs de MySQL, no reproducible en SQLite) tiene o no un test que lo cubra hoy.
- **Frontend: conversión de timezone en los selectores de horario** (`AvailabilityPicker.vue`, `TimeSlotPicker.vue`, formularios de agendamiento) — se confirmó que el backend siempre convierte `start_time` a UTC antes de guardar (`Carbon::parse(...)->utc()`), pero no se verificó en el navegador si el JS de esos componentes asume correctamente `America/Lima` como zona del usuario al construir el datetime que envía — requeriría interacción manual en el navegador para confirmar si hay algún doble-offset.
- **Reportes/paginación/filtros de `Admin/Users.vue`, `Admin/Requests.vue`, `Admin/Lessons.vue`** — se confirmó en backend que usan `paginate()->withQueryString()` consistentemente (buena señal), pero no se probó interactivamente que los filtros de estado y la paginación combinados no rompan (p. ej. ir a página 3 y luego cambiar de filtro) — requeriría el navegador.
