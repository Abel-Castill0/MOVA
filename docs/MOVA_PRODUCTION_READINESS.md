# MOVA — Matriz de Production Readiness

> Estado del código y preparación previa a producción. **No constituye aprobación de producción real** — quedan dependencias de terceros (Culqi, Meta, JaaS) y decisiones humanas (Railway, activación de liquidación) fuera del alcance de cualquier auditoría de este repositorio. **Tampoco constituye una garantía de ausencia total de defectos** — cada ✅ READY refleja lo verificado por las auditorías realizadas hasta la fecha de este documento, no una prueba de corrección exhaustiva. F-23 (más abajo) es evidencia reciente de esto: un caso ya cubierto por un test se había clasificado como "comportamiento válido" en la ronda anterior, y resultó ser un defecto real — "está probado" y "es correcto" no son la misma afirmación.

## Audit baseline (única fuente de verdad — no narrar cifras de sesiones anteriores)

**Regla de actualización:** cualquier cambio que modifique tests, migraciones, rutas o páginas debe actualizar esta tabla en el mismo commit/ronda — nunca queda "para después". Si una cifra de aquí no coincide con otro documento (`HANDOFF_FINAL.md`, `MOVA_MASTER_CONTEXT.md`, mensajes de sesiones anteriores), esta tabla gana: es la que se deriva de comandos reales ejecutados en el momento de la fecha de verificación, no de números narrados durante una conversación.

| Campo | Valor | Comando exacto |
|---|---|---|
| Fecha de verificación | 2026-08-25 | — |
| Tests | **454 passed** | `php artisan test` |
| Aserciones | **1328** | `php artisan test` |
| Migraciones aplicadas | **75** (todas en estado `Ran`) | `php artisan migrate:status` |
| Rutas registradas | **118** (filas de `route:list`, incluye la variante `HEAD` automática de cada `GET`; 102 si se cuentan URIs distintos una sola vez) | `php artisan route:list --json` |
| Páginas Vue | **53** | `find resources/js/Pages -name "*.vue" \| wc -l` |
| Git HEAD | `692b365` | `git rev-parse HEAD` |
| Working tree | **sucio** — cambios sin commitear (incluye 3 archivos con secretos reales ELIMINADOS a lo largo de esta auditoría — `qa/test-wizard-flow.mjs`, `qa/auth/admin.json`, `qa/end-to-end-welcome-email.mjs` — ver F-26/F-27, cifra de archivos ya no recontada aquí porque cambia en cada ronda; ver `git status --porcelain` para el número vigente) | `git status --porcelain \| wc -l` |
| Rama | `master` | `git branch --show-current` |
| PHP | 8.1.25 (CLI) | `php -v` |
| Laravel | 10.50.2 | `php artisan --version` |
| Node | v22.23.2 | `node -v` |
| npm | 10.9.8 | `npm -v` |
| Vue | 3.5.35 | `package-lock.json` |
| Inertia (Vue3) | 1.3.0 | `package-lock.json` |
| Vite | 5.4.21 | `package-lock.json` |
| Base de datos — suite automatizada (`php artisan test`) | SQLite en memoria (`:memory:`) | `phpunit.xml` → `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` |
| Base de datos — concurrencia/integración | MySQL real (proceso manual separado, NO parte del conteo de 454 tests ni de CI) | `scripts/concurrency-probe.sh` |

### Composición de la cifra de tests — por qué 454 no es un solo nivel de confianza

| Capa | Cantidad | Motor | ¿Corre con `php artisan test`? |
|---|---|---|---|
| Feature tests (PHPUnit) | **454** | SQLite en memoria | ✅ Sí — es la cifra completa reportada arriba |
| Unit tests aislados (`tests/Unit`) | 0 — no existe esa carpeta | — | — |
| Concurrencia real | 1 script, procesos reales | MySQL real | ❌ No — manual, bajo demanda |
| Browser/E2E de producto (Playwright) | 3 tests, `qa/tests/flujo-completo.spec.js` (las 5 referencias muertas de F-24B ya se eliminaron) | Navegador real | ❌ No — requiere levantar el stack local completo; primer paso real de GAP-08 |
| Infraestructura de QA — targeting (Playwright, sin navegador) | 12 tests, `qa/tests/target-safety.spec.js` (F-24A) | Node puro, sin servidor | ✅ Sí — **12/12 passed** |
| Infraestructura de QA — firewall de mutaciones (Playwright, con navegador) | 13 tests, `qa/tests/mutation-firewall.spec.js` (F-25) | Chromium real + servidor HTTP propio en 127.0.0.1 | ✅ Sí — **13/13 passed** |

**454 significa "454 escenarios de Feature test contra SQLite", no "454 confirmaciones independientes contra producción real".** La evidencia de concurrencia real (6 procesos, MySQL) y la de navegador real (verificación manual del checkbox de consentimiento, capturas de pantalla) son categorías aparte, con su propio nivel de confianza, y ya están citadas explícitamente donde corresponde en las tablas de abajo — no se resumen dentro del número 454 para no inflar su significado.

**Regla de esta matriz:** ningún ítem lleva ✅ READY si depende de una credencial, cuenta o servicio externo sin conectar, o de una decisión de negocio sin tomar — sin importar cuán bien esté el código. **Ningún ítem lleva ✅ READY a secas si una parte esencial del mismo ámbito sigue ❌ OPEN** — se divide en dos filas (p. ej. "implementación" vs "integración/operación") en vez de mezclarlas bajo una sola etiqueta optimista. Y **ningún hallazgo cerrado ("FIXED") se presenta como "cero defectos restantes"** — solo como "sin defectos conocidos identificados hasta ahora en lo auditado".

---

## Cómo leer los estados

| Símbolo | Significado |
|---|---|
| ✅ READY | Verificado, sin dependencias externas pendientes |
| 🟡 MITIGATED | Riesgo real reducido, no eliminado — límite conocido y documentado |
| ⏳ EXTERNAL VALIDATION | El código está listo; falta una cuenta/credencial/prueba de un tercero |
| ⏳ BUSINESS DECISION | El código está listo; falta que alguien decida activarlo |
| ❌ OPEN | No verificado en esta ronda |

---

## Capas de "listo" (nuevo en esta ronda)

Un solo ✅ READY por dominio escondía que "listo" mezclaba preguntas distintas con dueños y evidencia distintos. A partir de esta ronda, cualquier dominio con dependencias externas o de infraestructura se lee en varias capas — cinco en total, aunque la tabla resumen de abajo solo grafica las tres técnicas (Business y Legal son transversales, no por-dominio, y ya tienen su propia compuerta en el Production Gate: A/C para Business, F para Legal):

- **Technical** — ¿existe el código y está probado? Verificable con `php artisan test`, sin salir del repositorio.
- **Operational** — ¿existe la infraestructura que lo hace funcionar en producción (workers corriendo, endpoint HTTP expuesto, credenciales cargadas)? Vive parte en el repo (rutas, controladores) y parte fuera (Railway, cuentas de proveedor).
- **Business** — ¿alguien con autoridad de negocio decidió activarlo? Distinto de Operational: F-02 (liquidación) puede tener toda la infraestructura lista y seguir en `dry_run` porque nadie tomó la decisión, no porque falte algo técnico.
- **Legal** — ¿existe base legal, DPA, y política de privacidad actualizada para cada proveedor externo que toque datos reales, en particular de menores? Independiente de que el código esté "técnicamente controlado" (ver AI/PRIVACY más abajo).
- **Production** — ¿está realmente sirviendo tráfico real, con monitoreo, y con las cuatro capas anteriores ya resueltas?

| Dominio | Technical | Operational | Production |
|---|---|---|---|
| WhatsApp (canal + consentimiento + webhook) | ✅ | ⏳ (sin WABA real) | ⏳ |
| Culqi / pagos | 🟡 (dominio preparado, endpoint HTTP falta) | ❌ | ❌ |
| JaaS / videollamadas | ✅ | ⏳ (sin smoke test real) | ⏳ |
| Liquidación automática (C-1) | ✅ | ⏳ (decisión de negocio) | ⏳ |
| Cola / scheduler | ✅ (configuración) | ⏳ (workers reales sin confirmar) | ⏳ |
| IA / diagnóstico | ✅ (transporte) / 🟡 (semántica) | ✅ (interruptor apagado por defecto) | ⏳ (falta DPA/proveedor real si se activa) |
| Frontend | ✅ (wiring) | 🟡 (auth UX) | ❌ (QA visual, GAP-08) |

Esta tabla es un resumen de navegación, no reemplaza las tablas detalladas de abajo — cada fila de abajo sigue llevando su propia evidencia y test.

---

## FINANCIAL

| Ítem | Estado | Evidencia | Test |
|---|---|---|---|
| Ledger append-only | ✅ READY | Definición verificada en dos capas, no solo una: (1) cero `update`/`delete` sobre filas de `credit_transactions` con `UNIQUE(idempotency_key)`; (2) el borrado del propietario **financiero** (`CreditTransaction::belongsTo(TeacherProfile::class)` — no `Student`, que no tiene relación directa con el ledger) tampoco puede arrastrar sus filas — protegido con FK `RESTRICT` en MySQL + guard de aplicación, no solo con la ausencia de un método `::delete()`. Corrección de precisión de esta ronda: la versión anterior decía "usuario/alumno", que mezclaba al alumno (sin ledger propio) con el profesor (dueño real de `credit_transactions`) | `LedgerInfrastructureTest`, `MonetizationIntegrityTest`, `FinancialHistoryDurabilityTest` |
| Concurrencia financiera | ✅ READY — **validado hasta N=6 procesos concurrentes** | 6 procesos PHP paralelos contra MySQL real, exactamente 1 mutación en cada operación (accept, settlement, refund, approve recharge, reminder claim). Evidencia empírica con una concurrencia concreta, no una prueba matemática de exclusión mutua bajo carga arbitraria — la garantía real es el diseño (`lockForUpdate()` + `UNIQUE`), que no depende de N; la prueba es la confirmación práctica de ese diseño a la escala probada. 100 o 1000 procesos simultáneos son una pregunta de rendimiento/contención de locks, no de corrección — no evaluada | `scripts/concurrency-probe.sh` |
| Integridad de borrado (usuario/alumno) | ✅ READY | FK RESTRICT en MySQL + guard de aplicación; regresión de soft-delete detectada y corregida | `FinancialHistoryDurabilityTest`, `StudentDeletionIntegrityTest` |
| **C-1 (liquidación automática)** | ⏳ **BUSINESS DECISION** | Código completo, `--dry-run --json` con preflight de anomalías. `LESSON_SETTLEMENT_MODE=dry_run` en este entorno | `LessonSettlementScenariosTest` |
| Culqi — dominio de pagos (arquitectura) | 🟡 **PREPARADO** | Cuatro responsabilidades ya separadas siguiendo la misma filosofía que WhatsApp (estado del pago / evento recibido / aplicación del evento / endpoint externo): `PaymentOrder` (estado), `PaymentWebhook` (evento recibido, con `UNIQUE(provider, event_id)`), `PaymentWebhookService` (aplicación — delega a `RechargeApprovalService::credit()`, nunca toca `credit_transactions` directamente), `CulqiPaymentProvider` (stub deliberado). Falta la cuarta pieza: el endpoint — ver fila de abajo | `PaymentOrderTest` |
| Idempotencia de webhook de pago (código) | ✅ READY | `UNIQUE(provider, event_id)` a nivel de BD (no solo chequeo en código) descarta reintentos/duplicados del proveedor; orden en estado terminal (`failed`/`expired`/`cancelled`) rechaza el evento en vez de reabrirla | `PaymentOrderTest` |
| **F-21 — Endpoint HTTP de webhook de Culqi** | ❌ **OPEN — código pendiente, prioridad 🟠 HIGH** | Distinto de "falta la cuenta real": **no existe ruta HTTP registrada todavía** — `PaymentWebhookService::handle()` solo se invoca hoy desde tests, no desde un controlador. Marcado HIGH (no solo "externo") porque construir `PaymentWebhookController` es código de este repositorio, no una dependencia de terceros — y cuando exista la cuenta Culqi, este será el punto crítico del flujo financiero. Pendiente para la próxima ronda de código (explícitamente NO construido en esta, para no exceder el alcance pedido): HTTPS + verificación de firma + validación de payload + idempotencia (ya la tiene el servicio) + límite de tamaño + manejo de JSON inválido + respuesta rápida + delegación a `PaymentWebhookService`, con cobertura de: webhook válido, duplicado, firma inválida, firma ausente, JSON malformado, evento desconocido, pago en estado terminal, evento desordenado, payload grande. **Construir sin conectar a producción** — mismo patrón que `WhatsAppWebhookController`, que existe y está probado contra payloads sintéticos sin que exista aún tráfico real de Meta | — |
| Culqi — cuenta real (pagos automáticos) | ⏳ EXTERNAL VALIDATION | Sin cuenta comercial, sin credenciales de prueba/producción, sin webhook configurado en el panel de Culqi | — |

## AUTH / AUTORIZACIÓN

| Ítem | Estado | Evidencia |
|---|---|---|
| OTP (verificación de teléfono) | ✅ READY | Hasheado, TTL, rate-limit por cuenta y por número, `UNIQUE(phone_verified_normalized)` |
| IDOR entre roles (backend) | ✅ READY | 14 escenarios reales (profesor↔profesor, padre↔padre, suspendido, admin) — cero encontrados |
| Policies (7) (backend) | ✅ READY | `before()` uniforme, `is_verified` en `ClassRequestPolicy::accept()` (hallazgo crítico previo, cerrado) |
| Consistencia frontend↔backend de permisos | 🟡 **MITIGATED** | El backend es la autoridad real y está probado (fila de arriba); lo que NO está probado es la experiencia cuando el frontend se equivoca — no existe hoy una matriz sistemática de "botón visible/oculto × rol × estado del recurso × respuesta 403 real del backend". Que un botón esté oculto no prueba que el endpoint esté protegido (sí lo está, por las policies), pero tampoco hay evidencia de qué ve un usuario si accede a una URL directamente sin el botón — pendiente de GAP-08 |

## WHATSAPP

| Ítem | Estado | Evidencia | Test |
|---|---|---|---|
| Arquitectura (Meta Cloud API) | ✅ READY | `MetaCloudApiProvider` implementación real, sin SDK de Twilio | `MetaCloudApiProviderTest` |
| Consentimiento explícito | ✅ READY | Casilla real, sin backfill, verificado en navegador (click → BD); persiste tras reload completo (opt-in y opt-out) | `WhatsAppConsentTest` (30) |
| OTP independiente del opt-in | ✅ READY | Nunca pasa por `WhatsAppChannel`; probado con consentimiento/sin él/tras baja | 3 tests dedicados |
| Único punto de envío (sin bypass del gate de consentimiento) | ✅ READY | Búsqueda recursiva en `app/` confirma que solo `WhatsAppChannel` y el OTP de verificación llaman a `sendTemplate()`; convertido en regresión permanente, no solo verificación puntual | `test_only_whatsapp_channel_and_the_otp_call_the_provider_directly` |
| Estado `skipped` (no confundir opt-out con fallo) | ✅ READY | Enum de estado + `skip_reason` estructurado (`App\WhatsApp\WhatsAppSkipReason`: `opt_out`/`suspended`) + auditoría; los 4 invariantes verificados juntos: `provider_message_id` null, `isProviderFailure()` false, cero envíos al proveedor fake, reconciliación sigue healthy | `test_the_four_skip_invariants_hold_together`, `test_skip_reason_distinguishes_opt_out_from_suspended` |
| Matriz de invariantes de `whatsapp_messages` (skip_reason / error / provider_message_id por estado), formalizada | ✅ READY | Ver tabla justo debajo — corregida DOS veces contra el código real (F-23 incluido), nunca copiada de una tabla asumida | `WhatsAppMessageStateInvariantTest` (12) |
| **F-23 — `sent` con id null era operacionalmente inútil** | ✅ **FIXED** | Ver ficha formal justo debajo de la tabla de invariantes | Ver ficha |
| `skipped` es realmente terminal ante un webhook (no solo por diseño de rango) | ✅ READY — **bug real encontrado y corregido en esta ronda** | `shouldApply()` calculaba el rango de `skipped` como `deliveryRank() ?? 0` — el mismo valor que `unknown`, que SÍ debe ser superable por cualquier evento real. Eso trataba `skipped` como "siempre reemplazable" en vez de terminal. En la práctica era inalcanzable (todo mensaje `skipped` tiene `provider_message_id = null`, y el lookup del webhook busca por un wamid real, que nunca iguala a `NULL` en SQL) — protegido por accidente, no por diseño. Corregido en [`WhatsAppWebhookController.php`](../app/Http/Controllers/WhatsAppWebhookController.php) añadiendo el mismo guard explícito que ya tenía `failed`. **Invariante formal:** `skipped` no puede transicionar a `sent`, `delivered`, `read` ni `failed` por vía automática de webhook — los 4 casos están probados individualmente. Una eventual corrección administrativa manual (fuera del webhook) queda fuera de este invariante a propósito | `test_skipped_does_not_advance_to_{delivered,read,sent,failed}` |
| Race de cola: opt-out con notificación ya encolada | ✅ READY | El consentimiento se evalúa contra el estado ACTUAL en BD al ejecutar el job, no el de cuando se encoló — probado en ambos sentidos (opt-out cancela un envío pendiente; un opt-in posterior a un opt-out anterior SÍ se honra si llega antes de que el worker procese el job) | `test_an_opt_out_between_queueing_and_processing_prevents_the_send`, `test_a_prior_opt_out_does_not_prevent_a_later_opt_in_from_being_honored` |
| Usuarios verificados antes de que existiera este feature | ✅ READY | Un usuario con `phone_verified_at` antiguo y sin fila de consentimiento previa puede llegar al toggle desde su perfil y activarlo — no quedó nadie sin acceso a la preferencia. **Invariante explícito: no se otorga consentimiento retroactivo** — sin backfill, sin `whatsapp_opt_in_at` implícito por el solo hecho de tener el teléfono verificado, y sin excepción para cuentas antiguas | `test_an_account_verified_long_before_this_feature_existed_can_still_opt_in` |
| **F-22 — Cuenta suspendida seguía recibiendo notificaciones UTILITY** | ✅ **FIXED** | Ver ficha formal justo debajo de esta tabla | Ver ficha |
| Política formal AUTHENTICATION vs. UTILITY para cuentas suspendidas | ✅ READY | El OTP (AUTHENTICATION) nunca pasa por `WhatsAppChannel` ni por el gate de suspensión — lo envía `PhoneVerificationController` directamente, y su ruta HTTP (`verify-phone/send`) deliberadamente NO lleva el middleware `not.suspended` (una cuenta suspendida puede necesitar verificar su teléfono en un proceso de apelación/soporte). Todo lo demás (UTILITY: recordatorios, confirmaciones, recargas) pasa por `WhatsAppChannel` y SÍ respeta el gate de suspensión. Documentado como comentario formal en la clase, no solo implícito | — (comportamiento ya cubierto por los tests de OTP existentes + los nuevos de F-22) |
| Race de cola para el gate de suspensión (mismo patrón que opt-in/opt-out) | ✅ READY | Probado en ambos sentidos: una suspensión aplicada DESPUÉS de encolar el job cancela el envío; una reactivación DESPUÉS de encolar (con el usuario suspendido en el momento de encolar) SÍ permite el envío — el estado se evalúa en el momento de ejecución, misma filosofía que ya regía para opt-in/opt-out | `test_a_suspension_between_queueing_and_processing_prevents_the_send`, `test_an_unsuspension_between_queueing_and_processing_is_honored` |
| Cambio de número de teléfono verificado | 📝 **No es una funcionalidad existente — invariante de diseño fuerte para cuando se construya** | Investigado explícitamente esta ronda: no existe ningún flujo, ni de autoservicio ni de administrador, para cambiar el teléfono de una cuenta ya verificada. `PhoneVerificationController::send()`/`verify()` retornan de inmediato si `phone_verified_at` ya está fijado; `ProfileController` no expone `phone` como editable. El único lugar donde `phone` cambia después de verificado es el borrado/anonimización de cuenta (lo pone en `null`). **Invariante actual, real, y verificado hoy:** mientras `phone_verified_at != null`, `phone` no puede cambiar — no porque haya un guard explícito que lo impida, sino porque no existe ningún camino de código que lo intente. **Si se construye esta funcionalidad en el futuro, la cadena obligatoria es:** cambiar el número → invalidar `phone_verified_at` (vuelve a `null`) → exigir una nueva verificación con OTP → invalidar el consentimiento de WhatsApp existente (`whatsapp_opt_in_at` a `null`, no arrastrarlo al número nuevo) → exigir opt-in explícito otra vez. Riesgo concreto si se omite el paso de invalidar el consentimiento: un futuro desarrollador implementa "cambiar teléfono" tocando solo `phone`, y el sistema queda con consentimiento válido para un número que la cuenta ya no controla — mensajes de WhatsApp dirigidos al número VIEJO (que puede haber pasado a otra persona) bajo el consentimiento que dio el dueño ORIGINAL de la cuenta | — (no aplica; nada que probar sin la funcionalidad — este invariante debe convertirse en tests el día que se construya) |
| Firma del webhook de Meta (código) | ✅ READY | HMAC-SHA256 sobre el body crudo (no reconstruido), rechaza sin `app_secret` configurado, límite de tamaño de payload, ruta registrada en `routes/api.php` (`/api/webhooks/whatsapp`) | `WhatsAppWebhookTest` (26) |
| Contrato del payload de Meta (código, contra payload sintético) | ✅ READY — **alcance: probado en tests, no contra tráfico real** | La lógica de `extractStatuses()`/`recordStatus()` funciona correctamente contra el formato documentado (`entry[].changes[].value.statuses[]`) construido a mano en los tests | `WhatsAppWebhookTest` |
| Orden real de entrega / reintentos de Meta | ⏳ EXTERNAL VALIDATION | La lógica de no-regresión y de-duplicación está probada contra escenarios sintéticos de desorden y reintento — pero si el WABA real de Meta entrega eventos con una forma, cadencia o campo distinto al documentado, eso solo se descubre con tráfico real | — |
| Tráfico real de Meta / WABA / plantillas aprobadas | ⏳ EXTERNAL VALIDATION | `WHATSAPP_PROVIDER=fake` — el endpoint existe y está probado contra payloads sintéticos, pero cero eventos reales de Meta han pasado por él | — |

### Matriz de invariantes de `whatsapp_messages` (formalizada esta ronda)

Una revisión propuso esta matriz como base de un test formal. Antes de escribirlo, se verificó campo por campo contra el código real (`MetaCloudApiProvider::logAttempt()`, `WhatsAppWebhookController::recordStatus()`, `WhatsAppChannel::logSkip()`). El resultado son dos rondas de corrección, no una lista copiada tal cual en ninguna de las dos:

| status | `skip_reason` | `error` | `provider_message_id` |
|---|---|---|---|
| `sent` | siempre null | siempre null | **siempre no-null** (endurecido por F-23 — ver ficha abajo) |
| `delivered` | siempre null | siempre null | no-null (heredado de `sent`; el webhook lo usa para encontrar la fila) |
| `read` | siempre null | siempre null | no-null (mismo motivo) |
| `failed` | siempre null | **siempre no-null** | **siempre null** — `logAttempt()` recibe `null` literal como tercer argumento en la rama de respuesta no exitosa |
| `unknown` | siempre null | **siempre no-null** | **siempre null** — mismo motivo que `failed`, más el nuevo origen de F-23 (2xx sin id) |
| `skipped` | **siempre no-null** | siempre null | siempre null |

**Historial de esta fila (`sent`), para que quede visible que se corrigió dos veces, no una:** la primera versión de esta tabla decía "normalmente presente, pero NO garantizado", con una nota que llamaba "excepción documentada, no un bug" a un `sent` con `provider_message_id = null` — apoyándose en que ya existía un test que lo cubría. Una revisión posterior señaló, correctamente, que la existencia de un test no demuestra que el comportamiento sea correcto: un `sent` sin id es operacionalmente inútil (ningún webhook puede encontrar esa fila jamás) e indistinguible de un envío sano que solo está esperando su webhook. Ver F-23 justo debajo — la fila de arriba ya refleja la versión corregida, no la original.

### Ficha formal — F-23

| Campo | Valor |
|---|---|
| **ID** | F-23 |
| **Título** | `sent` con `provider_message_id = null` era operacionalmente inútil, aunque sintácticamente válido |
| **Severidad** | 🟠 MEDIUM (no mueve dinero ni afecta a menores directamente; sí puede dejar filas de auditoría permanentemente sin resolver e indistinguibles de un envío sano, degradando la observabilidad y el soporte) |
| **Estado** | ✅ FIXED |
| **Causa raíz** | `MetaCloudApiProvider::sendTemplate()` clasificaba cualquier respuesta 2xx como `sent`, incluso cuando el body no traía `messages[0].id` — sin ese id, `WhatsAppWebhookController` nunca podría encontrar esa fila para actualizarla a `delivered`/`read` (busca por `provider_message_id`) |
| **Por qué no se detectó antes** | La ronda anterior de este mismo documento llegó a describir el caso con precisión (encontró el comportamiento real contra el código, en vez de asumir una arquitectura ideal) pero clasificó el hallazgo como "excepción documentada, no un bug" apoyándose en que ya existía un test cubriéndolo — confundiendo "está probado" con "es correcto" |
| **Evidencia del fix** | [`MetaCloudApiProvider.php`](../app/WhatsApp/MetaCloudApiProvider.php): un 2xx sin id ahora se registra como `unknown` (no `sent`) y `sendTemplate()` devuelve `false`. Definición de `unknown` ampliada en [`WhatsAppMessageStatus.php`](../app/WhatsApp/WhatsAppMessageStatus.php) para cubrir explícitamente "resultado externo indeterminable", no solo "hubo una excepción de red" |
| **Riesgo de reintento evaluado y descartado** | Un 2xx sin id PODRÍA significar que Meta sí procesó el envío — por eso se eligió `unknown` (no reintenta, `isProviderFailure()` sigue siendo `false`) y no `failed` (que sugeriría que es seguro reintentar) |
| **Impacto en `mova:reconcile-whatsapp`** | Ninguno — su política para `unknown` (esperar el threshold antes de señalar) ya era correcta para cualquier origen del estado; verificado con test dedicado, no se tocó el reconciliador |
| **Tests** | `MetaCloudApiProviderTest::test_returns_false_when_response_has_no_message_id_but_is_successful` (corregido — antes afirmaba `assertTrue` pese a su propio nombre), `test_a_2xx_response_with_a_message_id_is_recorded_as_sent`, `WhatsAppMessageStateInvariantTest::test_sent_always_has_a_non_null_provider_message_id`, `test_a_2xx_response_without_a_message_id_is_unknown_not_sent`, `test_a_2xx_without_message_id_does_not_retry_and_is_not_reported_as_a_provider_failure`, `test_the_new_unknown_source_is_picked_up_by_the_existing_reconciliation_policy`, `test_a_webhook_for_an_unrelated_wamid_never_matches_an_existing_unknown_row` (7) |
| **Correlación, documentada explícitamente** | `provider_message_id` es la ÚNICA clave de correlación válida con un webhook de Meta. `client_reference` NUNCA debe usarse para eso — no es único (dos envíos del mismo tipo al mismo destinatario lo comparten) — documentado como comentario formal en la migración de `whatsapp_messages` y en el docblock de `WhatsAppWebhookController`, para que una futura "recuperación" de filas `unknown` no intente adivinar por ahí. Blindado con un test que crea dos filas con el MISMO `client_reference` y confirma que el webhook actualiza solo la que corresponde por `provider_message_id` |
| **Mapa de write-paths verificado, no asumido** | Búsqueda exhaustiva en `app/`: solo 3 puntos escriben en `whatsapp_messages` en todo el código de aplicación — `WhatsAppChannel::logSkip()` (crea `skipped`), `MetaCloudApiProvider::logAttempt()` (crea `sent`/`failed`/`unknown`), `WhatsAppWebhookController::recordStatus()` (actualiza a `delivered`/`read`/`failed`). Cero `DB::table('whatsapp_messages')` fuera de migraciones, cero Observers, cero Jobs/Listeners adicionales. `WhatsAppReconciliation` (el reconciliador) confirmado de código real como estrictamente de solo lectura — cero verbos de escritura — consistente con su propio docblock ("SOLO LECTURA, nunca corrige nada, solo señala qué necesita revisión humana"); nunca convierte `unknown` en `sent`/`failed` automáticamente |

### Cobertura de cada write-path — confirmada contra tests existentes, ninguno nuevo creado para esto

| Write-path / escenario | ¿Cubierto? | Test(s) |
|---|---|---|
| Envío exitoso (2xx + wamid) → `sent` | ✅ | `test_a_2xx_response_with_a_message_id_is_recorded_as_sent`, `test_sent_has_no_skip_reason_and_no_error` |
| Envío rechazado por Meta → `failed` | ✅ | `test_returns_false_and_logs_failure_on_meta_error_statuses` (5 códigos HTTP), `test_failed_always_has_a_null_provider_message_id_and_a_non_null_error` |
| Timeout/excepción de red → `unknown` | ✅ | `test_returns_false_without_retry_on_connection_timeout`, `test_unknown_always_has_a_null_provider_message_id_and_a_non_null_error` |
| 2xx sin wamid (F-23) → `unknown` | ✅ | `test_a_2xx_response_without_a_message_id_is_unknown_not_sent` |
| Skip por opt-out → `skipped` | ✅ | `test_an_opt_out_skip_is_recorded_with_its_own_status`, `test_the_four_skip_invariants_hold_together` |
| Skip por suspensión (F-22) → `skipped` | ✅ | `test_a_suspended_users_skip_is_audited_with_its_own_structured_reason` |
| Webhook `delivered` | ✅ | `test_sent_can_advance_to_delivered`, `test_delivered_keeps_the_provider_message_id_and_stays_without_skip_reason_or_error` |
| Webhook `read` | ✅ | `test_delivered_can_advance_to_read`, `test_read_keeps_the_provider_message_id_and_stays_without_skip_reason_or_error` |
| Webhook duplicado (mismo evento reenviado) | ✅ | `test_duplicated_event_is_a_no_op`, `test_a_redelivered_identical_webhook_event_is_recorded_only_once` |
| Webhook para un wamid no registrado localmente | ✅ | `test_handle_ignores_a_status_for_an_unknown_message_without_erroring`, `test_a_webhook_for_an_unrelated_wamid_never_matches_an_existing_unknown_row` |
| Webhook con `client_reference` duplicado entre dos filas | ✅ | `test_client_reference_never_decides_which_row_a_webhook_updates` |

Los 11 escenarios pedidos como checklist de cobertura ya estaban cubiertos por tests existentes de rondas anteriores — no se necesitó escribir ninguno nuevo para cerrar esta verificación, solo confirmarlo.

### Ficha formal — F-22

Con la misma identidad formal que F-21, a pedido explícito de revisión (antes solo estaba como nota narrativa):

| Campo | Valor |
|---|---|
| **ID** | F-22 |
| **Título** | Cuenta suspendida seguía recibiendo notificaciones UTILITY por WhatsApp |
| **Severidad** | 🟠 HIGH (divergencia clásica request-time authorization vs. background-job authorization; un usuario bajo suspensión — posible investigación de abuso/fraude — seguía recibiendo comunicación normal de la plataforma) |
| **Estado** | ✅ FIXED |
| **Causa raíz** | `EnsureNotSuspended` protege las rutas HTTP que el propio usuario suspendido visita; no protegía los jobs en segundo plano que le escriben A él |
| **Evidencia del fix** | Gate de cuenta añadido en [`WhatsAppChannel.php`](../app/Channels/WhatsAppChannel.php), evaluado en el momento del envío (no al encolar), antes del gate de consentimiento |
| **Tests** | `test_a_suspended_user_does_not_receive_whatsapp_notifications_even_with_consent`, `test_a_suspended_users_skip_is_audited_with_its_own_structured_reason`, `test_a_suspension_between_queueing_and_processing_prevents_the_send`, `test_an_unsuspension_between_queueing_and_processing_is_honored` (4) |
| **Consecuencia arquitectónica** | Introdujo un segundo motivo real de `skipped` (antes solo existía "sin consentimiento") — ver corrección de `skip_reason` abajo |
| **Alcance NO cubierto por este fix** | Solo WhatsApp UTILITY. No se evaluó ni se cambió el comportamiento de otros canales (email, notificaciones in-app, broadcast) para cuentas suspendidas — ver nota al final de esta sección |

### Decisiones documentadas (no cambios de código, para que no se pierdan)

- **¿Guardar el número (`to`) en la fila de auditoría de un skip por opt-out es un problema de privacidad?** No agrava la superficie de exposición: ese mismo número ya vive en `users.phone` y `users.phone_verified_normalized`, con los mismos controles de acceso (solo consultable por quien ya podía ver ese usuario). La fila de `skipped` no crea un nuevo lugar donde el número exista, y sin ella el `client_reference` sería la única pista de "a quién no se le envió esto", lo cual es peor para auditar por qué alguien no recibió un aviso. Decisión: mantenerlo. Documentado aquí explícitamente en vez de dejarlo solo implícito en los comentarios de [`WhatsAppChannel.php`](../app/Channels/WhatsAppChannel.php).
- **[SUPERADA por F-22 — ver bullet "[RESUELTO]" más abajo]** ¿Vale la pena una columna `skip_reason` estructurada en vez de reutilizar el campo `error` (texto libre)? La respuesta de la ronda anterior era "no, mientras exista un único motivo de skip auditado". Se deja esta entrada tachada en vez de borrarla para que quede visible que la condición cambió, no que se ignoró la decisión previa.
- **No se otorga consentimiento retroactivo.** Un usuario verificado antes de que existiera el opt-in queda con `whatsapp_opt_in_at = null` para siempre hasta que él mismo lo active — nunca se infiere consentimiento de la verificación de teléfono ni de ningún backfill. Esto es una decisión de producto/legal, no un detalle de implementación, y debe permanecer así aunque sea operacionalmente más lento alcanzar adopción.
- **Historial de consentimiento más rico (mejora futura, no gap actual).** Hoy `whatsapp_opt_in_at`/`whatsapp_opt_out_at` dan estado actual + un historial básico de dos eventos. No registran interfaz de origen, versión del texto de consentimiento mostrado, ni actor. No es necesario mientras exista un solo punto de entrada (perfil web) y un solo texto de consentimiento; si MOVA agrega app móvil o un flujo de administración que pueda tocar el consentimiento en nombre de otro, ese es el momento de migrar a una tabla `consent_events` con `source`/`policy_version`/`actor_id`. Anotado aquí para no perderlo, no priorizado.
- **[RESUELTO en esta ronda] `skip_reason` estructurado.** La decisión anterior de reutilizar `error` (texto libre) se documentó explícitamente como válida "mientras exista un solo motivo de skip auditado". F-22 introdujo un segundo motivo real y semánticamente distinto (cuenta suspendida) — la condición que sostenía esa decisión dejó de cumplirse. Implementado: enum `App\WhatsApp\WhatsAppSkipReason` (`opt_out` / `suspended`), columna `whatsapp_messages.skip_reason` (migración `2026_08_25_000001`), `error` ahora reservado exclusivamente para fallos reales del proveedor (siempre `null` en un skip). Verificado con `test_skip_reason_distinguishes_opt_out_from_suspended`.
- **Pregunta abierta, NO resuelta esta ronda: política de suspensión para otros canales.** F-22 solo tocó WhatsApp. Ni el canal de email ni las notificaciones in-app/broadcast consultan `suspended_at` hoy — no se investigó ni se cambió su comportamiento. La pregunta correcta no es "¿todos los canales deberían bloquear a un suspendido?" sino cuál es la política de comunicación POR TIPO de mensaje (utility vs. seguridad/legal) y por canal — sugerido como dirección futura: centralizar esa decisión en un servicio único (`NotificationEligibilityService` o similar) que resuelva cuenta-activa/teléfono-verificado/canal-habilitado/opt-in/tipo-de-notificación, y que cada canal consulte en vez de reimplementar su propio gate. No se refactoriza ahora — el sistema está estabilizándose, no es el momento de introducir una abstracción nueva sin una segunda necesidad concreta que la justifique.

## JITSI / JaaS

| Ítem | Estado | Evidencia |
|---|---|---|
| Aislamiento (`$hidden`, política de acceso) | ✅ READY | `jitsi_room` nunca en listados; `join()` único punto de exposición |
| Ventana temporal (backend + frontend) | ✅ READY | `join()` autoritativo; JWT acotado a la ventana, no 24h fijas |
| Comportamiento real de JaaS con `exp` corto | ⏳ **EXTERNAL VALIDATION** | Sin smoke test contra credenciales reales — la gracia de 2h es deliberadamente conservadora hasta probarlo |

## AI / PRIVACY

Dos capas distintas, a propósito no mezcladas: **seguridad del transporte/payload** (qué viaja por la red, qué se loguea) es una cosa binaria y verificable — o un campo va en el body o no va. **Privacidad semántica** (si el texto libre redactado sigue permitiendo reidentificar a alguien por combinación de datos) es un espectro sin garantía posible de un sistema léxico. Mezclarlas bajo un solo READY sería la sobreafirmación exacta que esta matriz existe para evitar.

**Aclaración explícita sobre qué significa "READY" en esta sección:** significa **técnicamente controlado** — se sabe exactamente qué campos viajan y adónde. NO significa **legalmente autorizado** — el proveedor de IA (`config('diagnostic.ai_provider')`, hoy `gemini`/`openai` según entorno) sigue siendo un tercero externo que recibe información educativa sobre menores en cuanto `DIAGNOSTIC_AI_ENABLED=true`. Antes de activar en producción con datos reales, falta documentar — no resuelto aquí, señalado para que no se active sin hacerlo — el proveedor exacto, región de procesamiento, política de retención, si el proveedor entrena modelos con el contenido enviado, y el estado del DPA (Data Processing Agreement) con ese proveedor. F-05 permanece MITIGATED también por esta razón, no solo por la redacción de texto libre.

| Ítem | Capa | Estado | Evidencia | Test |
|---|---|---|---|---|
| Campos enviados (body) | Transporte | ✅ READY | Solo `subject.name`, `level`, texto redactado, `goal`, `urgency` | `AiPayloadContractTest` |
| URL / headers | Transporte | ✅ READY | Endpoint fijo sin query string; cabeceras genéricas | `test_no_identifier_travels_...`, `test_request_headers_...` |
| Logs de error | Transporte | ✅ READY | Solo `diagnostic_id`/`provider`/`status`, nunca el prompt | `test_exception_logging_never_includes_the_prompt` |
| Sentry | Transporte | ✅ READY | Verificado contra el paquete instalado: solo `body.size`, `send_default_pii=false` | `test_sentry_http_breadcrumbs_...` |
| `ai_usage_logs` | Transporte | ✅ READY | 11 columnas, todas metadata — cero texto libre | `test_ai_usage_logs_has_no_free_text_columns` |
| **Redacción de texto libre** | Semántica | 🟡 **MITIGATED** | Best-effort (nombre solo, inicio de frase, teléfonos, emails, DNI). Identificadores indirectos (colegio+curso+condición rara) **no** son detectables por diseño — ningún sistema léxico lo garantiza. **La implementación reduce la exposición de PII conocida; no constituye anonimización irreversible ni garantía de no reidentificación** — frase que debe permanecer en este documento tal cual, dado que MOVA maneja datos educativos de menores | `TextRedactionTest` (18) |
| Interruptor | — | ✅ READY | `DIAGNOSTIC_AI_ENABLED=false` por defecto | `test_the_ai_is_disabled_by_default` |

## QUEUE / SCHEDULER

Separación deliberada entre **configuración** (vive en este repositorio, verificable con `php artisan test`) y **operación en runtime** (vive en Railway; puedes tener `timeout=60`/`retry_after=90` perfectamente configurados en el código y aun así tener `workers=0` corriendo en producción — son hechos independientes).

### Queue configuration (✅ verificable en este repositorio)

| Ítem | Estado | Evidencia |
|---|---|---|
| Atomicidad claim+despacho | ✅ READY | Transaccional; probado con fallo real de despacho en los 5 barridos |
| Semántica de entrega documentada | ✅ READY | Exactly-once solo en el encolado; at-least-once en la entrega externa, dicho explícitamente en código y tests |
| Timeout worker vs `retry_after` | ✅ READY | 60s/90s, 30s de margen; vigilado en cada `health-check` leyendo `railway.queue.toml` |
| Timeouts HTTP salientes | ✅ READY | Máximo 15s, muy por debajo del worker; matriz completa, vigilada automáticamente |

### Queue runtime operations (⏳ fuera del repositorio)

| Ítem | Estado | Evidencia |
|---|---|---|
| Workers realmente corriendo (cuántos, escalado) | ⏳ **EXTERNAL VALIDATION** | Vive en la configuración de Railway, no en el repositorio — no verificable desde aquí |
| Concurrencia de workers (cuántos jobs en paralelo) | ⏳ **EXTERNAL VALIDATION** | No confirmado contra la infraestructura real |
| Reinicio de workers tras deploy (recogen el código nuevo) | ⏳ **EXTERNAL VALIDATION** | Los workers de Laravel son procesos persistentes — si Railway no los reinicia en cada deploy, siguen ejecutando el código de la versión anterior indefinidamente. No confirmado |
| Apagado ordenado del worker (`--stop-when-empty` / señal de terminación) | ⏳ **EXTERNAL VALIDATION** | No confirmado si el proceso recibe SIGTERM y drena el job en curso antes de morir, o si Railway lo mata en seco |
| Comportamiento real ante reinicio/deploy del scheduler | ⏳ **EXTERNAL VALIDATION** | No observado contra la infraestructura real |
| Backlog y latencia de la cola en producción | ⏳ **EXTERNAL VALIDATION** | `mova:health-check` reporta `queue_backlog` localmente; no hay dato real de cuánto tarda un job en procesarse en producción |
| `failed_jobs` — recuperación, no solo conteo | ⏳ **EXTERNAL VALIDATION** | El comando (`queue:failed`, `mova:health-check`) existe y funciona; nadie ha confirmado que algo mire su salida en producción ni que exista un proceso para reintentar/descartar los fallidos |

## FRONTEND

**Nota de clasificación de esta ronda:** las tres filas siguientes llevaban ✅ READY sin calificar en la versión anterior de este documento, lo cual sobreafirmaba — cada una es un READY real pero **acotado a un alcance específico** ("wiring estructural", no "experiencia completa"). Separadas explícitamente para que nadie lea "Frontend: listo" cuando lo que está probado es solo la mitad de la historia.

| Ítem | Estado | Evidencia |
|---|---|---|
| Frontend mutation **wiring** (guard/error/modal por botón) | ✅ READY — alcance: implementación estructural | 53 páginas inventariadas, 31 con acciones, cero sin guard de doble envío tras dos rondas. Prueba que cada acción mutante TIENE un guard — no prueba cómo se ve, ni si el mensaje de error es comprensible |
| Frontend mutation **UX** (¿el guard se ve bien? ¿el error es accionable?) | ❌ **OPEN** | No auditado visualmente — ver HTTP error UX más abajo |
| Autorización backend (policies + IDOR) | ✅ READY | Ver tabla AUTH — antes de esta ronda esto estaba mezclado con la fila de abajo |
| Consistencia de autorización **frontend** (botón oculto ↔ backend protegido ↔ 403 real) | 🟡 **MITIGATED** | Navegación gateada por rol (`AppLayout.vue`); ninguna ruta admin visible fuera de su rol. Pero que un botón esté oculto no es lo mismo que probar la cadena completa botón→ruta→controlador→policy→403 visible para cada acción — eso es justamente lo que GAP-08 dejaría cerrado |
| Consentimiento WhatsApp, verificación visual real | ✅ READY | Verificado en navegador: casilla desmarcada por defecto, click persiste en BD, persiste tras reload completo |
| Analizador estático (`FrontendAuditor`) | ✅ READY | Con tests propios tras 3 falsos positivos encontrados y corregidos |
| **QA visual sistemática (53 páginas × viewport)** | ❌ **OPEN** | No ejecutado esta ronda — ver §"Alcance no cubierto" |
| **Accesibilidad** (labels, foco, aria, contraste) | ❌ **OPEN** | No auditado |
| **Multi-tab / stale state** | ❌ **OPEN** | No probado |
| **HTTP error UX** (403/404/419/422/429/500 traducidos a mensajes accionables) | ❌ **OPEN** | No auditado sistemáticamente en las 31 páginas con acciones — solo se corrigieron los casos que el analizador estático encontró (F-19), no se buscó exhaustivamente el resto |

## QA TOOLING

Deliberadamente separada de FRONTEND/GAP-08: esto es sobre si la **herramienta** con la que eventualmente se va a auditar el frontend es en sí misma confiable — no sobre el frontend mismo. Ver [`docs/MOVA_QA_BASELINE.md`](MOVA_QA_BASELINE.md) para el inventario, y **[`docs/MOVA_CREDENTIAL_EXPOSURE.md`](MOVA_CREDENTIAL_EXPOSURE.md)** — documento dedicado para F-26/F-27, con la metodología de búsqueda y qué se descartó.

Cuatro hallazgos independientes tras varias rondas de revisión — no son el mismo tipo de problema y mezclarlos dificultaba el seguimiento de cada uno: F-24A (targeting), F-24B (tooling roto), F-25 (firewall de mutaciones), F-26/F-27 (credenciales reales expuestas — el más grave de los cuatro).

### Ficha formal — F-24A (el hallazgo importante — NO cerrado del todo)

**Estado en capas — no una sola etiqueta:**

| Capa | Estado |
|---|---|
| **Technical** (código de tooling) | ✅ COMPLETE — guard de targeting + firewall de mutaciones, verificados en ejecución real |
| **Operational** (credenciales reales ya expuestas — ver F-26/F-27) | ❌ OPEN — 🔴 ACTION REQUIRED |
| **Production** | ❌ **BLOCKED** — con una contraseña de base de datos de producción potencialmente comprometida en git history (F-26), "producción" como concepto no puede declararse lista independientemente de que el código de la aplicación funcione perfectamente |

F-24A **no se marca FIXED como conjunto** mientras la capa Operational siga abierta.

| Campo | Valor |
|---|---|
| **ID** | F-24A |
| **Título** | La configuración de Playwright apuntaba por defecto a producción real, con credenciales reales, sin ningún guard a nivel de configuración |
| **Severidad** | 🔴 HIGH — no se ejecutó ninguna prueba destructiva contra producción (verificado), pero el propio *default* de `npm test` facilitaba modificar datos reales, enviar WhatsApp/mail reales, o ejecutar acciones administrativas si un spec futuro las incluyera |
| **Causa raíz** | Una suite anterior (`01-admin-flow.spec.js` … `05-admin-verify-teacher.spec.js` + `global-setup.js`) diseñada para correr contra producción con cuentas reales fue removida sin corregir el `BASE_URL` por defecto que dejaba — la protección dependía enteramente de que cada spec nuevo copiara el guard interno del único spec real |
| **Corrección de targeting — ✅ COMPLETE, verificada en ejecución real** | Guard central [`qa/lib/enforce-safe-target.mjs`](../qa/lib/enforce-safe-target.mjs) — allowlist `localhost`/`127.0.0.1`/`[::1]` (retirado `0.0.0.0`: es una dirección de bind, no un target real). Override remoto renombrado a `I_UNDERSTAND_E2E_REMOTE_TARGET_IS_DANGEROUS` (antes `E2E_ALLOW_REMOTE_TARGET`, demasiado genérico), y ahora exige HTTPS. `qa/.env.qa`: `BASE_URL` a local, **y las credenciales de producción se ELIMINARON del archivo**, no solo se dejaron con un valor distinto. `global-setup.js` (huérfano) **eliminado** |
| **Test de infraestructura, ejecutado de verdad** | `qa/tests/target-safety.spec.js` — **12/12 passed**: incluye IPv6, `0.0.0.0` rechazado, normalización de URL, y HTTPS obligatorio en el override. **Un bug real se encontró corriendo este test, no leyéndolo**: `new URL('http://[::1]:8000').hostname` devuelve `'[::1]'` con corchetes — la allowlist inicial no lo contemplaba |
| **❌ OPEN — 🔴 ACTION REQUIRED: credenciales reales expuestas — más graves de lo documentado antes** | Además de la contraseña compartida de las cuentas QA (~14 commits), una búsqueda exhaustiva de referencias muertas encontró dos artefactos **peores, y actualmente TRACKEADOS en HEAD hasta esta ronda**: (1) `qa/auth/admin.json` — cookies reales de sesión de admin de producción (`storageState` de Playwright), pusheado en el commit `117dc36`; expiración verificada con precisión: 2026-06-25, ya pasada; (2) `qa/test-wizard-flow.mjs` — **la contraseña RAW de la base de datos MySQL de producción**, hardcodeada, pusheada en 5 commits — un compromiso de alcance mayor que cualquier cuenta de aplicación individual. Ambos archivos **eliminados del working tree** en esta ronda; `qa/auth/` añadido a `.gitignore`. Todo esto sigue recuperable desde el historial de `origin` (repositorio privado confirmado, `HEAD` sincronizado 0/0) |
| **Explícitamente NO hecho, y por qué** | No se rotó ninguna credencial (ni la de las cuentas QA ni la de la base de datos), no se reescribió el historial de git, y no se intentó verificar si alguna credencial sigue activa — todo esto son acciones sobre cuentas/infraestructura reales que corresponden al dueño, no a esta sesión. El estado correcto de "¿sigue activa?" es `UNKNOWN`, no "probablemente no" |
| **Orden de rotación recomendado** | 1) contraseña de la base de datos MySQL (mayor alcance), 2) contraseña compartida de las cuentas QA (usar una distinta por rol al recrearla), 3) solo después, evaluar purgar el historial de git |
| **Ver también** | [`docs/MOVA_QA_SECURITY_POLICY.md`](MOVA_QA_SECURITY_POLICY.md), [`docs/MOVA_QA_BASELINE.md`](MOVA_QA_BASELINE.md), [`docs/MOVA_CREDENTIAL_EXPOSURE.md`](MOVA_CREDENTIAL_EXPOSURE.md) — fichas formales F-26/F-27 más abajo |

### Ficha formal — F-25 (nuevo)

| Campo | Valor |
|---|---|
| **ID** | F-25 |
| **Título** | El guard de F-24A protege el *target*, no la *acción* — un override remoto no impedía una mutación |
| **Severidad** | 🟠 HIGH conceptualmente (si algún día existiera un smoke test de producción, nada le impedía hacer POST/PUT/PATCH/DELETE) — mitigado por completo hoy porque **no existe ningún spec ni script que use un target remoto** |
| **Estado** | ✅ FIXED — construido como defensa preventiva, verificado con navegador real, sin que exista todavía un caso de uso que lo requiera |
| **Corrección aplicada** | [`qa/lib/enforce-read-only.mjs`](../qa/lib/enforce-read-only.mjs): `installMutationFirewall(page)` intercepta toda request, deja pasar GET/HEAD, aborta el resto. [`qa/lib/fixtures.mjs`](../qa/lib/fixtures.mjs): lo instala vía fixture, no a discreción del spec. [`qa/playwright.production-readonly.config.js`](../qa/playwright.production-readonly.config.js): tres capas independientes verificadas fallando cerrado una por una (override de host + HTTPS; segunda variable `E2E_CONFIRM_PRODUCTION` con frase exacta; firewall de mutaciones activo) |
| **Test, ejecutado con navegador real** | `qa/tests/mutation-firewall.spec.js` — **13/13 passed** contra un servidor HTTP propio en `127.0.0.1`: GET/HEAD/OPTIONS pasan (política explícita — OPTIONS nunca ejecuta la mutación en sí, es preflight CORS), POST/PUT/PATCH/DELETE se abortan **vía `fetch`, `XMLHttpRequest`, `<form>` HTML nativo multipart, y `navigator.sendBeacon`**, y tres controles negativos confirman que el bloqueo es real, no un artefacto del entorno |
| **Capa de protección distinta, no una laguna del firewall** | `page.request.*` (API de peticiones directas de Playwright, corre desde el proceso Node del runner, nunca desde el navegador) **requiere una capa de protección separada** — no es tráfico que `page.route()` esté diseñado para ver, así que no es un hueco en el firewall existente sino una superficie distinta que necesitaría su propio mecanismo si algún día se usara. Confirmado empíricamente con un test dedicado, no asumido. Sin impacto real hoy: ningún spec de producto usa `page.request` para simular una acción de usuario — si alguno lo hiciera, quedaría fuera de esta protección y necesitaría la suya propia |
| **Deliberadamente NO hecho** | No se añadió ningún script `test:production*` a `package.json` — no existe ningún spec que lo use, y hacerlo repetiría el error de F-24B (referencias que apuntan a nada) |

### Ficha formal — F-26 (🔴 CRITICAL — el hallazgo más grave de toda la auditoría)

| Campo | Valor |
|---|---|
| **ID** | F-26 |
| **Título** | Contraseña RAW de la base de datos MySQL de producción, hardcodeada en un script tracked y pusheado |
| **Severidad** | 🔴 **CRITICAL** — acceso a base de datos es un compromiso total: bypasea Auth, Policies, Middleware, CSRF, rate limiting — toda la superficie de autorización de la aplicación. Mayor alcance que cualquier credencial de cuenta individual |
| **Estado** | ❌ **OPEN — rotación no ejecutada** (no es competencia de esta sesión) |
| **Hallazgo** | La contraseña RAW de conexión directa a la base de datos MySQL de producción (host, puerto, usuario, contraseña — proxy de Railway, hostname deliberadamente no repetido en ningún documento) apareció en **6 archivos distintos** a lo largo del historial, no solo en el que una ronda anterior de esta auditoría había documentado. Repartidos en 5 commits, todos confirmados ancestros de `origin/master` (dos de ellos también de `origin/Elias`). **Uno de los 6 archivos (`qa/end-to-end-welcome-email.mjs`) seguía tracked en HEAD y presente en el working tree hasta esta misma ronda** — la afirmación previa de "ya eliminado del working tree" era incompleta. Ver la sección "Corrección de alcance" en `MOVA_CREDENTIAL_EXPOSURE.md` para el detalle honesto de cómo se descubrió el subconteo |
| **Corrección aplicada — CORREGIDA** | Los 6 archivos ya están eliminados del **disco** (working tree). Una verificación posterior con `git cat-file -e HEAD:<ruta>` (comparando además que `HEAD` == `origin/master`, sin divergencia) mostró que esto NO se propagó a HEAD ni a `origin/master` — los 3 archivos que seguían tracked en algún momento de esta serie de rondas (`qa/test-wizard-flow.mjs`, `qa/auth/admin.json`, `qa/end-to-end-welcome-email.mjs`) siguen presentes con contenido completo en el commit que cualquiera clona hoy. Una afirmación anterior de este documento de que la exposición "hacia adelante" ya estaba contenida era incorrecta. **No se intentó conectar a la base de datos** para verificar si la contraseña sigue activa |
| **Estado por capas** | Ver la tabla de 4 filas (Working tree / Índice de git / HEAD actual-`origin/master` / Git history / Credencial real) en `MOVA_CREDENTIAL_EXPOSURE.md` — solo la primera está en ✅; F-26 no se considera cerrado hasta que las demás lo estén |
| **Acción requerida, no ejecutable por esta sesión** | Rotar la contraseña vía el panel de Railway; reiniciar web/queue/scheduler después (pueden mantener conexiones persistentes con la credencial anterior). Checklist numerado completo de 14 pasos en `MOVA_CREDENTIAL_EXPOSURE.md` |
| **Ver también** | [`docs/MOVA_CREDENTIAL_EXPOSURE.md`](MOVA_CREDENTIAL_EXPOSURE.md) — inventario completo, metodología de búsqueda, y la corrección de alcance de esta ronda |

### Ficha formal — F-27

| Campo | Valor |
|---|---|
| **ID** | F-27 |
| **Título** | `storageState` de Playwright con cookies reales de sesión de admin de producción, tracked y pusheado |
| **Severidad** | 🟠 HIGH histórico / 🟢 bajo actual — las cookies específicas de este archivo ya expiraron |
| **Estado — CORREGIDO** | ✅ Artefacto eliminado del working tree (disco); ❌ **sigue presente en HEAD y en `origin/master`** — no se comiteó la eliminación (corrección de una afirmación anterior de este documento, que decía "y de HEAD" incorrectamente); ❌ exposición histórica en git permanece; ✅ credencial real de bajo riesgo (cookie expirada, verificado) — ver ficha enriquecida en `MOVA_CREDENTIAL_EXPOSURE.md` (campos Exposición/Validez actual/Riesgo actual/Causa raíz/Corrección preventiva) |
| **Hallazgo** | `qa/auth/admin.json` — `mova_session` (httpOnly) y `XSRF-TOKEN` reales de `mova-production-8750.up.railway.app`, producto de `global-setup.js` (ya eliminado). Tracked y pusheado en el commit `117dc36`, confirmado ancestro de `origin/master` |
| **Verificación de expiración — precisa, no asumida** | El timestamp `expires` de las cookies corresponde a **2026-06-25T05:12:32Z**, ya pasado respecto a la fecha de esta ronda (2026-08-26). El riesgo de secuestro de sesión vía ESTE archivo específico está neutralizado por el paso del tiempo — no porque se haya hecho nada al respecto. Un archivo equivalente generado con un `SESSION_LIFETIME` más largo sí sería explotable de inmediato |
| **Corrección aplicada / preventiva** | Archivo eliminado; `qa/auth/` añadido a `.gitignore` para impedir que un `storageState` futuro vuelva a entrar en git |
| **Ver también** | [`docs/MOVA_CREDENTIAL_EXPOSURE.md`](MOVA_CREDENTIAL_EXPOSURE.md) |

### Ficha formal — F-24B

| Campo | Valor |
|---|---|
| **ID** | F-24B |
| **Título** | `qa/package.json` referenciaba 5 specs de Playwright inexistentes |
| **Severidad** | 🟢 LOW — los scripts rotos fallan de inmediato con "no tests found", sin riesgo de ejecución silenciosa; el problema era documentación/tooling engañoso, no un riesgo de seguridad |
| **Estado** | ✅ FIXED |
| **Evidencia** | `npx playwright test --list` confirmó, de forma verificable y no asumida: 3 tests reales en 1 archivo — cero coincidencias con los nombres `01`-`05` |
| **Corrección aplicada** | [`qa/package.json`](../qa/package.json): eliminados los 5 scripts muertos; añadido `test:local`, que wirea correctamente el único spec real contra la config local. Ningún spec ficticio creado |

## DEPLOYMENT / LEGAL

| Ítem | Estado | Evidencia | Test |
|---|---|---|---|
| `railway.*.toml` coherentes con la documentación | ✅ READY (F-20 cerrado) | — | — |
| Política de privacidad ↔ proveedores reales | ✅ READY (Twilio→Meta corregido) | — | — |

`mova:health-check` dividido en cuatro preguntas independientes — la anterior versión de dos filas todavía dejaba espacio para leer "READY" y asumir que el pipeline ya está protegido:

| Ítem | Estado | Evidencia | Test |
|---|---|---|---|
| Health-check — **código** | ✅ READY | Settlement, proveedores, cola, timeouts, `APP_DEBUG`, adopción de WhatsApp con numerador/denominador explícitos (`X/Y verificados con opt-in activo (Z%)`). Modelo de severidad ERROR/WARNING/INFO: lo informativo nunca afecta al exit code | `SchedulerConfigurationTest` (19) |
| Health-check — **ejecución** (corre y da el resultado correcto) | ✅ READY | Defensivo por diseño: cada sección corre en su propio `try/catch`, `ProviderGuard::resolve()` nunca instancia el proveedor real (solo valida el string), y se probó explícitamente que un fallo catastrófico en una sección (tabla `jobs` inexistente) no impide que el resto del reporte aparezca | `test_a_broken_queue_backlog_query_still_reports_other_sections` |
| Health-check — **puerta de despliegue** (bloquea un deploy con warning real) | ❌ **OPEN** | El comando devuelve exit code 1 ante configuración peligrosa — pero nadie confirmó que Railway lo ejecute como paso previo al deploy. Sin ese enganche, "el comando funciona" y "el comando protege el despliegue" son afirmaciones distintas | — |
| Health-check — **monitoreo/alertas en producción** | ⏳ **EXTERNAL VALIDATION** | Aunque se enganchara al deploy, nadie confirmó que algo notifique a un humano cuando reporta rojo en producción entre despliegues (no solo en el momento del deploy) | — |

---

## Alcance explícitamente NO cubierto en esta ronda, y por qué

**GAP-08 = frontend QA, y solo eso.** En la ronda anterior de este documento se había colado el endpoint de Culqi dentro de esta lista — corregido: es un hallazgo financiero de backend (F-21, ver FINANCIAL), no una tarea de QA de frontend. Mezclarlos hacía más difícil hacer seguimiento de cada uno por separado, que es justo lo que esta sección existe para evitar.

Cuatro bloques de frontend requieren herramientas y tiempo que esta ronda no dedicó — decirlo es más útil que fabricar una cobertura que no existe:

1. **QA visual en 5 viewports × 53 páginas.** Se verificaron visualmente en el navegador las dos superficies nuevas de mayor riesgo de esta fase (consentimiento en verificación de teléfono, toggle en perfil) con captura de pantalla y confirmación en base de datos. El resto de páginas no se recorrió visualmente — el análisis estático (`FrontendAuditor`) cubre estructura y guards, no maquetación ni overflow.
2. **Accesibilidad.** Ningún test de labels, `aria-*`, foco de modal, contraste o navegación por teclado.
3. **Multi-tab / datos obsoletos entre pestañas.** No probado — el backend es autoritativo (verificado), pero la experiencia de ver un saldo desactualizado tras una mutación en otra pestaña no se auditó.
4. **Traducción de errores HTTP a UX.** No se verificó sistemáticamente que un 422/403/500 se traduzca a un mensaje accionable en cada una de las 31 páginas con acciones — se corrigieron los casos encontrados por el analizador (F-19), no se buscaron exhaustivamente el resto.

Estos cuatro bloques son la definición correcta de un GAP-08 cerrado, y no lo están. **F-21 (webhook de Culqi) se rastrea aparte** — no como parte de ningún QA de frontend, sino como código de backend pendiente, prioridad HIGH, prerequisito de negocio antes de activar Culqi.

---

## NO hacer (checklist operacional, no solo documental)

Pensado para pegarse literalmente en el runbook de despliegue — cada línea es un error real que ya casi ocurrió en rondas anteriores de esta auditoría (C-1 llevaba meses en dry-run silencioso sin que 248 tests lo detectaran):

```text
NO activar LESSON_SETTLEMENT_MODE=live
  sin antes correr --dry-run --json contra producción y revisar el preflight de anomalías.

NO cambiar PAYMENT_PROVIDER=fake → culqi en producción
  sin que exista el endpoint HTTP del webhook (hoy no existe) y sin credenciales reales.

NO cambiar WHATSAPP_PROVIDER=fake → meta en producción
  sin cuenta Meta/WABA real, plantillas aprobadas, y sin haber hecho un smoke test del webhook con tráfico real.

NO activar DIAGNOSTIC_AI_ENABLED=true
  sin releer la clasificación MITIGATED de F-05 y confirmar que la política de datos de menores lo permite.

NO modificar queue timeout / retry_after en railway.queue.toml
  sin correr `php artisan mova:health-check` después del cambio (detecta la colisión worker_timeout >= retry_after).

NO eliminar usuarios ni alumnos directamente en base de datos
  — usa los flujos de la aplicación; el borrado directo salta el guard de integridad del ledger (FK RESTRICT + guard de aplicación).

NO modificar una migración ya aplicada en producción
  — con 75 migraciones ya corridas, editar una existente desincroniza el historial entre entornos. Crear una migración nueva, siempre.

NO ejecutar `php artisan migrate:fresh` en producción
  — borra y recrea todas las tablas. Usa `migrate` (incremental) o un rollback específico si algo salió mal.

NO truncar ni vaciar manualmente `credit_transactions`, `payment_webhooks`, `whatsapp_webhook_events` o `recharge_requests`
  — son el rastro de auditoría append-only del sistema financiero y de mensajería; truncarlas destruye la única fuente de verdad para reconciliar disputas.

NO hacer INSERT/UPDATE/DELETE manual sobre `credit_transactions`
  — salvo un procedimiento de recuperación explícitamente documentado y aprobado. Cualquier ajuste financiero pasa por el flujo de la aplicación (que mantiene `idempotency_key` único y el resto de invariantes del ledger), nunca por SQL directo contra la tabla.

NO asumir que "Frontend: READY" en este documento significa QA visual completo
  — significa que los botones tienen guard estructural. Ver GAP-08.
```

---

## Production Gate (el paso siguiente real — no otra auditoría de backend)

El núcleo financiero, de autorización, y la arquitectura de WhatsApp/IA/Jitsi están en el estado más sólido de todas las rondas de esta auditoría. Seguir tocándolos ahora sin que GAP-08 o una validación externa hayan encontrado un defecto concreto solo aumenta el riesgo de regresión. Lo que queda no es una "Fase 8" de alcance abierto — son siete compuertas concretas, cada una con un criterio de salida verificable:

| Compuerta | Qué falta exactamente | Criterio de salida |
|---|---|---|
| **A. F-02** | Decisión de negocio + ejecución real | `--dry-run --json` corrido contra producción → revisión humana del preflight → `LESSON_SETTLEMENT_MODE=live` → reconciliar → monitorear el primer ciclo real |
| **B. F-06** | Smoke test JaaS real | Una sala real creada con credenciales de producción, JWT con `exp` corto verificado contra el comportamiento real del servicio (no contra documentación) |
| **C. Railway** | Operación real, no configuración narrada | Confirmar cuántos workers corren y con qué concurrencia, si se reinician tras un deploy, si drenan ordenadamente al apagarse, qué pasa con `failed_jobs`, si el scheduler sobrevive a un redeploy, y si algo realmente alerta cuando `mova:health-check` reporta rojo |
| **D. GAP-08 (frontend QA, solo eso)** | Los 4 bloques de frontend de la sección de arriba | QA visual en 53 páginas × 5 viewports, accesibilidad, multi-tab, HTTP error UX. **Ya no incluye Culqi** — ver F-21 en FINANCIAL, rastreado aparte |
| **E. Regresión final** | Confirmar que nada de A-D rompió lo ya verificado | `php artisan test` en verde, `npm run build`, `migrate:status`, `route:list`, `mova:reconcile-ledger --json`, `mova:reconcile-whatsapp --json`, `mova:health-check --json` |
| **F. Legal / Data Processing** | Ninguna de las tablas técnicas de arriba responde esto | MOVA procesa datos de **menores** a través de WhatsApp (Meta), IA (proveedor externo cuando se active), videollamadas (JaaS/8x8), y usa Cloudinary/Resend/Pusher como infraestructura de terceros, con Culqi como futuro procesador de pagos. Un ✅ READY técnico en la tabla de arriba **no implica** que exista DPA firmado, base legal documentada para cada proveedor, ni política de privacidad actualizada por proveedor real (ya corregida para WhatsApp Meta; pendiente revisar para el resto en cuanto se activen). Esta compuerta es explícitamente responsabilidad legal/negocio, no de ingeniería — pero debe cerrarse antes de producción real, no asumirse resuelta porque el código esté listo |
| **G. 🔴 Seguridad de credenciales (F-26/F-27) — BLOCKING** | Rotar la contraseña de la base de datos MySQL de producción (F-26, CRITICAL) y la contraseña compartida de las cuentas QA (F-24A) | Contraseña de BD rotada → verificado que la antigua ya no autentica → web/queue/scheduler reiniciados con la credencial nueva propagada desde Railway → contraseña de cuentas QA rotada (separada por rol) → decisión formal sobre purgar el historial de git. **Ninguna otra compuerta de este gate compensa que esta siga abierta** — un ✅ en A-F no significa "listo para producción" mientras una contraseña de base de datos de producción siga potencialmente comprometida |

Nota importante sobre F-21 (webhook de Culqi): no es una compuerta de este Production Gate — es un hallazgo de código de backend, prioridad HIGH, que debe resolverse antes de que la compuerta A o cualquier activación de Culqi tenga sentido. Se rastrea en la tabla FINANCIAL, no aquí, para no diluir el foco de este gate (F-02/F-06/Railway/frontend/regresión/legal/credenciales).

Cuando estas siete compuertas estén cerradas, y solo entonces, este documento puede reescribirse distinguiendo "listo por código", "listo por operación" y "listo para usuarios reales" — algo que hoy, honestamente, todavía no puede afirmarse.

---

## Respuesta directa: ¿qué falta para producción?

**No "nada".** Concretamente, las siete compuertas de arriba, más un hallazgo de código que no es una compuerta:

1. **F-02** — decisión de negocio: ejecutar el dry-run contra producción, revisar, activar `LESSON_SETTLEMENT_MODE=live`.
2. **F-06** — smoke test contra una cuenta JaaS real antes de confiar en la ventana de expiración.
3. **F-21 — endpoint HTTP del webhook de Culqi** — esto es código pendiente de este repositorio, no una dependencia externa. Prioridad HIGH: constrúyase sin conectar a producción, siguiendo el patrón ya probado de `WhatsAppWebhookController`.
4. **WhatsApp/Culqi — cuentas comerciales reales** — sin ellas, ambos sistemas operan en modo `fake` por diseño (correcto y seguro, pero no funcional). Independiente del punto 3: aunque el endpoint de Culqi se construya, sigue sin poder recibir tráfico real sin la cuenta.
5. **GAP-08** — los cuatro bloques de frontend, como fase dedicada con herramientas de QA visual. Ya no incluye Culqi.
6. **Operación real de Railway** — cuántos workers, alertas, comportamiento en despliegue — vive fuera del repositorio y no se puede verificar sin acceso a la infraestructura real.
7. **Legal / Data Processing (Gate F)** — DPA y base legal por proveedor externo que toque datos de menores, antes de activar cualquiera en producción real.
8. **🔴 Gate G / F-26 CRITICAL / F-24A ACTION REQUIRED — rotar, en orden: (1) la contraseña de la base de datos MySQL de producción (F-26), (2) la contraseña compartida de las cuentas QA (admin/teacher/parent, F-24A)** — ambas confirmadas recuperables desde el historial de git de un repositorio privado ya en GitHub (`origin/master`, sincronizado). F-26 en particular resultó tener un alcance mayor de lo documentado en la ronda anterior — 6 archivos distintos a lo largo del historial, no 1, uno de los cuales (`qa/end-to-end-welcome-email.mjs`) seguía **tracked en el estado actual hasta esta misma ronda** (ya eliminado; ver corrección de alcance en `MOVA_CREDENTIAL_EXPOSURE.md`). Lo mismo aplica al `storageState` de admin (F-27, ya expirado). El código de tooling ya no puede apuntar a producción por accidente, ni ejecutar una mutación aunque alguien autorizara un target remoto (F-25, firewall de mutaciones, verificado contra fetch/XHR/formularios nativos/sendBeacon) — todo verificado con ejecución real, no solo escrito. **Este firewall es una salvaguarda de la herramienta de QA, no un sustituto de la autorización real de la aplicación** — Auth, Policies, Middleware y validación de negocio siguen siendo la única defensa real contra una mutación indebida; el firewall solo evita que el propio tooling de pruebas dispare una por accidente. Pero las credenciales en sí siguen siendo una acción pendiente sobre cuentas e infraestructura reales que no le corresponde a esta sesión ejecutar. Ni F-24A ni F-26 se consideran cerrados mientras esto siga abierto — usar la tabla de 4 capas (Working tree / HEAD actual / Git history / Credencial real) en `MOVA_CREDENTIAL_EXPOSURE.md` para juzgar el estado real, no un resumen de una sola línea.

El núcleo financiero, de autorización, y la arquitectura de WhatsApp/IA/Jitsi están en el estado más sólido de todas las rondas de esta auditoría — con tres bugs reales encontrados y corregidos en el backend (F-22: `skipped` no era realmente terminal ante un webhook; F-22: una cuenta suspendida seguía recibiendo WhatsApp; F-23: `sent` sin `provider_message_id` era operacionalmente inútil), más un hallazgo de seguridad operacional en la infraestructura de QA (F-24A: targeting de producción por defecto, mitigado en código; credenciales expuestas en git history, rotación pendiente). Lo que queda es, en su mayoría, dependencia externa, decisión humana, revisión legal, o una acción operativa sobre una cuenta real — con una excepción de código explícita: el endpoint de Culqi (punto 3). Ningún hallazgo cerrado implica que no queden otros por encontrar — implica que las auditorías realizadas hasta ahora no encontraron más.

**Advertencia explícita sobre cómo leer la cifra de tests:** "454 passed" (ver Audit baseline arriba) es evidencia de que el comportamiento cubierto por esos tests es correcto — no es una afirmación de que MOVA "es seguro" en ningún sentido más amplio. F-26 es la prueba directa de esto en esta misma auditoría: la suite pasaba en verde mientras una contraseña de base de datos de producción viajaba en texto plano en 6 archivos del repositorio. Una suite de tests y una postura de seguridad son dos preguntas distintas; una no sustituye a la otra.

**El backend permanece congelado a partir de esta revisión.** Cualquier nuevo cambio en el núcleo (ledger, settlement, auth, concurrencia, arquitectura de WhatsApp) requiere uno de estos tres motivos, no una idea de mejora: un defecto reproducible, una vulnerabilidad confirmada, o una necesidad real de integración externa (Meta/Culqi/JaaS). Un hallazgo de GAP-08 que resulte ser un problema de frontend puro no reabre esta congelación.

---

## Alcance previsto de GAP-08 (planificación, NO ejecutado en esta ronda)

Con el backend congelado, este es el orden y las dimensiones acordadas para la siguiente fase — anotado aquí para que no se pierda, no como trabajo ya hecho:

**Orden de páginas por riesgo (Nivel 1 primero):** Auth → créditos/recargas → clases → solicitudes de clase → estudiantes → reviews → admin → perfil → notificaciones → el resto (dashboards, marketplace, diagnósticos, legal/estático).

**Cinco dimensiones por página, no solo "QA visual":**
1. **Funcional** — botón → handler → ruta → método HTTP → policy → éxito → fallo.
2. **Estados HTTP y UX de error** — 403/404/419/422/429/500 traducidos a un mensaje accionable, nunca un código crudo. Prioridad especial en páginas financieras (`Teacher/Credits`, recargas, lecciones, `ClassRequests`, `Admin/Recharges`): doble clic, estado obsoleto, reintento tras fallo — el backend ya protege la mutación, pero una mala UX puede confundir al usuario y generar tráfico duplicado igual.
3. **Responsive** — 360/390/768/1024/desktop, con foco en modales, tablas, calendario y formularios.
4. **Accesibilidad** — foco inicial y trampa de foco en modales, Escape/Enter, `aria-labelledby`/`aria-describedby`, labels, contraste.
5. **Interacción/estado** — atrás/adelante, refresh, doble pestaña (específicamente: pestaña A gasta un crédito, pestaña B con vista obsoleta intenta actuar — el backend debe ganar, la UI debe reflejarlo), red lenta/offline/reconexión.

**Dos verificaciones específicas heredadas de hallazgos de esta serie de rondas:**
- Los estados nuevos que ahora existen (`unknown`, `skipped`, `reversed`, `needs_admin_review`, etc.) deben mostrarse en la UI traducidos a español accionable — nunca como el string crudo del enum.
- Las páginas de estudiantes/clases/reportes/reviews deben probarse también contra un estudiante con soft-delete/anonimizado, no solo contra uno activo — F-18 (ronda previa) ya demostró que una relación nula tras un soft-delete puede fallar en silencio.

**Ver F-24 más abajo (sección "QA TOOLING") — resuelto en una fase corta separada de GAP-08, no como parte de esta planificación.**

**Adicionalmente, tres criterios explícitos para GAP-08 que no deben quedar solo implícitos en "QA visual":**
1. **Botones sin destino** — para cada botón mutante, confirmar la cadena completa click → handler → ruta → controlador. Clasificar cualquier hallazgo como (a) botón sin handler, (b) ruta que responde 404, o (c) funcionalidad de backend sin ningún camino de UI que la alcance (la más difícil de encontrar por inspección visual sola).
2. **Estados visuales huérfanos** — buscar en `resources/js` badges/labels/colores/filtros que referencien un valor de estado que el backend ya no emite o nunca emitió (mismo espíritu que el hallazgo previo de `in_progress`).
3. **Valores de negocio hardcodeados en Vue** — búsqueda dirigida de literales tipo `S/`, montos de créditos, duraciones, roles o límites escritos directamente en el frontend en vez de venir del backend como fuente de verdad.

## Nota sobre el commit final (pendiente, no ejecutado)

131 archivos sin commitear representan varias rondas de auditoría distintas. Antes del commit definitivo, dividir por área en vez de un solo commit masivo — la agrupación aproximada que ya se puede leer en el propio historial de esta auditoría: (1) integridad financiera/ledger, (2) WhatsApp/notificaciones, (3) IA/privacidad, (4) health-check/operación, (5) frontend (una vez cerrado GAP-08), (6) documentación. No es una cifra obligatoria de commits — es la idea de que el historial cuente una historia legible, para poder revertir un área sin arrastrar las demás si algo falla después.
