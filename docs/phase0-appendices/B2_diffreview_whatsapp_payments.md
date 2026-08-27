# Auditoría — Módulos WhatsApp y Payments/Culqi (MOVA)

## (0) Snapshot auditado

**Snapshot auditado: working tree actual, HEAD=692b365, con cambios sin commitear incluidos — NO un checkout aislado.**

`git rev-parse HEAD` = `692b3651d09cb2731865efcb0d83dc83d2a36102` (== origin/master, sin divergencia).

Archivos del dominio auditado (`git status --porcelain=v1 -uall` filtrado):

```
 M app/Channels/WhatsAppChannel.php
 M docs/WHATSAPP_PRODUCTION_NOTES.md
?? app/Console/Commands/ReconcileWhatsApp.php
?? app/Http/Controllers/WhatsAppWebhookController.php
?? app/Models/PaymentOrder.php
?? app/Models/PaymentWebhook.php
?? app/Models/WhatsAppMessage.php
?? app/Models/WhatsAppWebhookEvent.php
?? app/Payment/Contracts/PaymentProviderContract.php
?? app/Payment/CulqiPaymentProvider.php
?? app/Payment/FakePaymentProvider.php
?? app/Payment/Money.php
?? app/Payment/PaymentWebhookEvent.php
?? app/Services/PaymentWebhookService.php
?? app/Support/ProviderGuard.php
?? app/Support/WhatsAppReconciliation.php
?? app/WhatsApp/Contracts/WhatsAppProviderContract.php
?? app/WhatsApp/FakeWhatsAppProvider.php
?? app/WhatsApp/MetaCloudApiProvider.php
?? app/WhatsApp/WhatsAppMessageStatus.php
?? app/WhatsApp/WhatsAppSkipReason.php
?? config/payments.php
?? database/migrations/2026_08_23_000003_create_payment_orders_table.php
?? database/migrations/2026_08_23_000004_create_payment_webhooks_table.php
?? database/migrations/2026_08_23_000005_create_whatsapp_messages_table.php
?? database/migrations/2026_08_23_000006_create_whatsapp_webhook_events_table.php
?? database/migrations/2026_08_24_000004_add_whatsapp_consent_to_users_table.php
?? database/migrations/2026_08_24_000005_add_skipped_status_to_whatsapp_messages.php
?? database/migrations/2026_08_25_000001_add_skip_reason_to_whatsapp_messages.php
?? docs/payments-architecture.md
?? docs/whatsapp-architecture.md
?? tests/Feature/PaymentOrderTest.php
?? tests/Feature/PhoneVerificationWhatsAppTest.php
?? tests/Feature/ProviderGuardTest.php
?? tests/Feature/WhatsAppChannelTest.php
?? tests/Feature/WhatsAppConsentTest.php
?? tests/Feature/WhatsAppMessageStateInvariantTest.php
?? tests/Feature/WhatsAppModelTableNamesTest.php
?? tests/Feature/WhatsAppReconciliationTest.php
?? tests/Feature/WhatsAppWebhookTest.php
```

Adicionalmente tocados por la migración Twilio→Meta y consumidos por el dominio (revisados como contexto, no como entregable principal): `config/services.php` (M), `app/Providers/AppServiceProvider.php` (M, resuelve ambos `Contract` vía `ProviderGuard`), `app/Http/Controllers/Auth/PhoneVerificationController.php` (M), `app/Models/User.php` (M, `wantsWhatsAppNotifications()`/`optIn`/`optOut`), `.env.example` (M), `phpunit.xml` (M), `app/Http/Controllers/NotificationPreferencesController.php` (`??`, endpoint de opt-in/opt-out), `tests/Feature/MetaCloudApiProviderTest.php` (`??`, no coincide con el filtro `--filter=WhatsApp` de PHPUnit por nombre de clase, se ejecutó aparte), `tests/Feature/ReminderClaimRecoveryTest.php`, `tests/Feature/ReminderConcurrencyTest.php`, `tests/Feature/ReminderDeliverySemanticsTest.php` (`??`, dependen del canal WhatsApp para las notificaciones en cola).

---

## (1) Módulo WhatsApp

### A. Intención
Reemplaza la integración previa por Twilio (sandbox, sin plantillas aprobadas, mensajes de texto libre) por una integración directa con la WhatsApp Cloud API de Meta. Objetivos declarados en el código y en `docs/whatsapp-architecture.md`:
- Enviar notificaciones de negocio (recordatorios, confirmaciones, recargas) y el OTP de verificación de teléfono como plantillas aprobadas por Meta (obligatorio fuera de la ventana de servicio de 24h).
- Auditar cada intento de envío (`whatsapp_messages`) con un ciclo de vida honesto: `sent → delivered → read`, más `failed`, `unknown` (resultado incierto) y `skipped` (MOVA decidió no intentarlo).
- Separar formalmente **consentimiento de notificaciones** (`whatsapp_opt_in_at`/`whatsapp_opt_out_at`) de **verificación de teléfono** (`phone_verified_at`) — antes se fusionaban.
- Gate de cuenta suspendida sobre notificaciones proactivas (jobs en cola), no solo sobre rutas HTTP.
- Webhook de Meta para actualizar estado de entrega, diseñado pero inerte sin credenciales reales.
- Observabilidad de solo lectura (`mova:reconcile-whatsapp`) para mensajes atascados/inciertos.

### B. Corrección
La implementación resuelve lo que se propone, con evidencia:

- **Plantillas, no texto libre**: `app/WhatsApp/MetaCloudApiProvider.php:79-88` arma el payload `type: template` con `components[body]`; nunca envía texto libre.
- **F-23 — "sent" exige wamid correlacionable**: `MetaCloudApiProvider.php:103-141` — un 2xx sin `messages[0].id` se registra como `Unknown`, no `Sent` (línea 123-134), evitando filas "sent" permanentemente inalcanzables por el webhook. Verificado por test `tests/Feature/WhatsAppMessageStateInvariantTest.php` ("a 2xx response without a message id is unknown not sent").
- **Sin reintento ciego ante excepción de red**: `MetaCloudApiProvider.php:90-97,157-166` — clasifica como `unknown`, nunca reintenta automáticamente (razonamiento explícito en el comentario: Meta no expone idempotency key propia).
- **Separación AUTHENTICATION vs UTILITY**: `app/Channels/WhatsAppChannel.php:12-30` documenta la política; el OTP lo envía `PhoneVerificationController::sendWhatsAppCode()` (línea 227-244 del diff) directo al `WhatsAppProviderContract`, sin pasar por `WhatsAppChannel::send()` ni por sus gates.
- **Gate de consentimiento y suspensión en el canal**: `WhatsAppChannel.php:74-113` — chequea `suspended_at` (con auditoría vía `WhatsAppSkipReason::Suspended`) y `wantsWhatsAppNotifications()` (con `WhatsAppSkipReason::OptOut`) antes de invocar al proveedor.
- **Consentimiento explícito, sin backfill**: migración `2026_08_24_000004_add_whatsapp_consent_to_users_table.php` deja `whatsapp_opt_in_at = NULL` para todos los usuarios existentes — documenta explícitamente la consecuencia operativa (usuarios ya verificados dejan de recibir notificaciones hasta reconfirmar).
- **`User::wantsWhatsAppNotifications()`** (`app/Models/User.php`, diff): opt-out gana siempre sobre un opt-in anterior.
- **Doble capa de idempotencia en el webhook**: `WhatsAppWebhookController::recordStatus()` (líneas 143-197) — 1) `whatsapp_webhook_events` con `event_key` UNIQUE a nivel de BD (líneas 206-222) evita reprocesar el mismo evento de entrega; 2) `shouldApply()` (líneas 252-266) impide que un evento fuera de orden retroceda el estado, usando `WhatsAppMessageStatus::deliveryRank()`.
- **Firma del webhook**: `hasValidSignature()` (líneas 92-104) usa `hash_hmac('sha256', ...)` + `hash_equals()` contra `X-Hub-Signature-256`; sin secreto configurado, rechaza siempre (líneas 97-99).

### C. Integración con el resto de MOVA
- **Policies**: no aplica directamente (no hay autorización de recursos de usuario aquí); la autorización relevante es el gate de suspensión, que se aplicó de forma consistente con `EnsureNotSuspended` (documentado explícitamente como una superficie *distinta*: esa protege rutas HTTP, esto protege jobs en cola).
- **Resolución de proveedor centralizada**: `AppServiceProvider::register()` usa `ProviderGuard::resolve()` para bindear `WhatsAppProviderContract` — mismo mecanismo que Payments (ver más abajo), sin ramas `default` silenciosas (`match` exhaustivo, `UnhandledMatchError` si falta un caso).
- **`singleton()` deliberado** para `WhatsAppProviderContract` (comentario en `AppServiceProvider`, diff): `FakeWhatsAppProvider` acumula estado en memoria (`$sent[]`) que los tests necesitan poder inspeccionar; con `bind()` normal cada resolución sería una instancia nueva vacía.
- **Rate limiting cruzado**: `PhoneVerificationController::send()` añade un rate limit *por número de teléfono* (`RateLimiter`, prefijo `whatsapp-otp-phone:`, 5/hora) además del `throttle:3,1` por usuario de la ruta — cierra el hueco de "N cuentas nuevas enviando OTP al mismo número".

### D. Seguridad
- **Firma del webhook**: verificada con HMAC-SHA256 + `hash_equals` (comparación de tiempo constante) — correcto, no usa `===`. Sin `app_secret` configurado, el webhook rechaza *todo* (no hay bypass).
- **Token de verificación** (`GET /webhooks/whatsapp`): comparado con `hash_equals()` (línea 48) — correcto.
- **Límite de tamaño de payload**: 1MB (`MAX_BODY_BYTES`, línea 67) antes incluso de verificar la firma — barato contra abuso.
- **Replay/duplicados**: cubierto en dos capas independientes (ver B) — `whatsapp_webhook_events.event_key` UNIQUE + `shouldApply()`. Confirmado por test `duplicated event is a no op` y `a redelivered identical webhook event is recorded only once`.
- **Credenciales**: `access_token`/`app_secret` nunca se loguean (`MetaCloudApiProvider::safeErrorFromResponse` sólo serializa `error` de Meta, nunca el payload/token completo — comentario explícito línea 174-176). Log de errores trunca a 500 chars (`Str::limit`).
- **OTP nunca en el campo `error` de auditoría**: verificado por test `otp code never ends up in the audit log error field` (`MetaCloudApiProviderTest.php`).
- **Middleware de suspensión NO bloquea el envío/verificación del OTP** — deliberado y documentado (`WhatsAppChannel.php:17-24`): una cuenta suspendida puede necesitar verificar su teléfono para apelar. Correcto para el propósito, pero es una decisión de producto/seguridad con matices — señalarla explícitamente al negocio no está de más (no es un hallazgo, es una confirmación del diseño).

### E. Datos — estados/transiciones de `whatsapp_messages`
- Enum de aplicación (`WhatsAppMessageStatus`) + enum a nivel de BD (migraciones `2026_08_23_000005` y `2026_08_24_000005` para añadir `skipped`). `deliveryRank()` fuerza el orden `sent(1) < delivered(2) < read(3)`; `failed`/`unknown`/`skipped` sin rango, tratados como casos aparte.
- `skip_reason` (migración `2026_08_25_000001`) separado de `error` — un mensaje `skipped` nunca toca a Meta, así que `error` (reservado a fallos reales del proveedor) queda `null` en ese caso. Verificado por `WhatsAppMessageStateInvariantTest`.
- No hay ledger de créditos involucrado en este módulo (WhatsApp no toca `credit_transactions`).

### F. (no aplica — el prompt no numera una sección F separada de E; ver E)

### G. Testing — resultado real de ejecutar los tests hoy

Ejecutado contra el working tree real (no un checkout aislado), PHP 8.1.25:

```
php artisan test --filter=WhatsApp
→ 98 passed (213 assertions), 29.68s
   (incluye WhatsAppChannelTest, WhatsAppConsentTest, WhatsAppMessageStateInvariantTest,
    WhatsAppModelTableNamesTest, WhatsAppReconciliationTest, WhatsAppWebhookTest,
    PhoneVerificationWhatsAppTest, ProviderGuardTest — estos dos últimos coinciden
    con el filtro por nombre de método de test, no de clase)

php artisan test tests/Feature/MetaCloudApiProviderTest.php tests/Feature/ReminderClaimRecoveryTest.php \
    tests/Feature/ReminderConcurrencyTest.php tests/Feature/ReminderDeliverySemanticsTest.php
→ 44 passed (75 assertions), 14.62s
```

**Total dominio WhatsApp: 142 tests, 288 aserciones, 0 fallos.** No se asumió que pasaran — se corrieron.

Nota metodológica: `MetaCloudApiProviderTest` no coincide con `--filter=WhatsApp` por nombre de clase (no contiene la subcadena "WhatsApp"), así que se ejecutó en una segunda pasada explícita junto con los tests de `Reminder*` (que dependen del canal WhatsApp para el envío en cola). Esto evita el error metodológico de asumir cobertura por un filtro que en realidad no las alcanzó.

### H. Regresión — qué podría romperse en el resto del sistema
- **21 notificaciones existentes** dependen de `WhatsAppChannel::send()` sin haber sido reescritas — todas ahora se envían como el único parámetro de una plantilla `generic_notification` (documentado, no es una regresión de esta ronda sino una decisión explícita de alcance).
- **Usuarios ya verificados antes de este cambio** dejan de recibir notificaciones WhatsApp (sin opt-in retroactivo) — impacto operativo real, ya cuantificado según el comentario de la migración en `docs/MOVA_PHASE6_AUDIT.md` (no verificado por esta auditoría si ese archivo existe con ese contenido — ver sección 6).
- **`settlement`**: no hay relación directa entre este módulo y `SettleLessons`; los recordatorios de clase (`SendClassReminders`) sí pasan por este canal — los tests `Reminder*` (44 pasan) cubren que un fallo de dispatch libera el "claim" correctamente y no bloquea el ciclo de recordatorios.
- **Cambio de proveedor de notificación (Twilio→Meta) es un cambio de comportamiento en producción**: si `WHATSAPP_ENABLED=true` y `WHATSAPP_PROVIDER` no se define explícitamente como `meta` con credenciales reales, `ProviderGuard` aborta el arranque (fail-closed) — esto es una mejora de seguridad, pero significa que un despliegue con variables de entorno incompletas **no arranca en absoluto**, no solo que WhatsApp falle silenciosamente. Vale la pena confirmarlo en el checklist de despliegue.

### I. Mantenibilidad
Muy alta. El código está documentado extensamente en español, con justificación explícita de cada decisión de diseño (incluyendo por qué NO se hizo algo — p. ej. por qué `client_reference` nunca debe usarse para correlación). Los enums (`WhatsAppMessageStatus`, `WhatsAppSkipReason`) documentan candidatos futuros ya identificados y por qué no se añadieron todavía, reduciendo el riesgo de que alguien reinvente la decisión. Riesgo de mantenibilidad real: la verificación del componente `button` para plantillas AUTHENTICATION (`MetaCloudApiProvider.php:61-77`) está basada en documentación de terceros (MessageBird, 360dialog), no en `developers.facebook.com` directamente — el propio código lo marca como "NO tratar como confirmado hasta probarlo contra un envío real".

### J. Producción — ¿listo para activarse?
**Deliberadamente apagado/stub, no listo para producción con credenciales reales sin un paso adicional de validación:**
- `WHATSAPP_ENABLED=false` por defecto (`config/services.php`, `.env.example`).
- `WHATSAPP_PROVIDER=fake` por defecto.
- El webhook (`WhatsAppWebhookController`) está "diseñado pero deliberadamente INERTE" (comentario línea 16-21) — sin `META_WHATSAPP_WEBHOOK_VERIFY_TOKEN`/`META_WHATSAPP_APP_SECRET` configurados, rechaza todo.
- `ProviderGuard` impide activar `meta` en producción sin que la variable esté correctamente definida, y prohíbe `fake` en producción con la feature habilitada (fail-closed, no fail-open).
- Punto de incertidumbre real señalado por el propio código: el componente `button` de las plantillas OTP no está confirmado contra documentación oficial de Meta — recomendación explícita del código de probarlo contra un envío real antes de confiar en él.
- Conclusión: la arquitectura está lista (contratos, auditoría, idempotencia, reconciliación); lo que falta es exclusivamente credenciales reales de una cuenta de Meta Business + plantillas aprobadas + una prueba real del componente `button`, no trabajo de ingeniería adicional en el núcleo.

---

## (2) Módulo Payments/Culqi

### A. Intención
Añadir una capa de pagos automáticos (Culqi u otro PSP) **encima** del sistema de recargas manual existente (`RechargeRequest` + aprobación de admin), sin reemplazarlo. Objetivo: que un profesor pueda pagar y que el abono de créditos se dispare automáticamente vía webhook, reutilizando el mismo mecanismo de acreditación (`RechargeApprovalService::credit()`) que ya usa la aprobación manual.

### B. Corrección
- **`RechargeApprovalService::credit()` es el único punto de abono**, compartido entre aprobación manual (`$reviewerId` = admin) y automática (`$reviewerId = null`, ver `PaymentWebhookService.php:78`) — evita que las dos rutas diverjan (motivo explícito: mismo problema estructural que causó el hallazgo histórico C-1, según `docs/HANDOFF_FINAL.md §17`).
- **`amount_minor` se congela en el momento de creación** de la orden (`Money::solesToMinor()`, `CulqiPaymentProvider`/`FakePaymentProvider::createOrder()`) — nunca se recalcula del catálogo de paquetes después.
- **`CulqiPaymentProvider` es un stub explícito** (`app/Payment/CulqiPaymentProvider.php:11-23`): ambos métodos lanzan `RuntimeException` inmediatamente, con mensaje explicando qué falta (cuenta comercial real). Esto es correcto por diseño — no es un bug, es un placeholder documentado.
- **`FakePaymentProvider`** simula el flujo completo para tests: `createOrder()` deja la orden en `pending`; `simulatePaidEvent()`/`simulateFailedEvent()` fabrican el evento normalmente producido por un webhook ya verificado.

### C. Integración con el resto de MOVA
- **Resolución vía `ProviderGuard`** en `AppServiceProvider::register()` (mismo patrón que WhatsApp) — `match` exhaustivo sin `default`, aborta con `UnhandledMatchError` si se añade un proveedor a `ProviderGuard` sin instanciarlo aquí.
- **Ledger**: reutiliza `credit_transactions` con `idempotency_key = "recharge:{id}:deposit"` — el mismo esquema append-only que usa el resto del sistema financiero de MOVA (no introduce un mecanismo de idempotencia paralelo).
- **Policies**: no hay controlador HTTP nuevo en este módulo (ver sección 3), así que no hay superficie de autorización HTTP que revisar todavía — la autorización relevante (`RechargeRequestPolicy`) sigue aplicando en el flujo manual existente, sin cambios.

### D. Seguridad
- **Verificación de firma de webhook**: `PaymentProviderContract::verifyWebhook(string $rawPayload, array $headers): ?PaymentWebhookEvent` — el contrato exige explícitamente que la implementación real verifique firma/secreto y devuelva `null` si no es válida (docblock línea 28-34). `CulqiPaymentProvider::verifyWebhook()` real **no existe todavía** (lanza excepción) — es decir, la verificación de firma de Culqi en sí **no se puede auditar como código porque no está implementada**. `FakePaymentProvider::verifyWebhook()` es trivialmente JSON plano sin firma, y el propio comentario advierte "jamás debe exponerse detrás de una ruta pública" (línea 18).
- **Manejo de credenciales**: `config/payments.php` solo lee de `env()` (`CULQI_PUBLIC_KEY`, `CULQI_PRIVATE_KEY`, `CULQI_WEBHOOK_SECRET`) — ninguna hardcodeada. `.env.example` deja los valores vacíos con advertencia explícita en comentario ("Nunca commitear llaves reales").
- **Replay/duplicados**: `PaymentWebhookService::handle()` usa `payment_webhooks.UNIQUE(provider, event_id)` (capturado como `UniqueConstraintViolationException`, líneas 40-56) — un duplicado devuelve el registro original sin reprocesar. Segunda capa: `credit_transactions.idempotency_key` UNIQUE impide doble acreditación incluso si dos `event_id` distintos apuntan a la misma orden (verificado por test `two different paid events for the same order still credit once`).
- **No hay endpoint HTTP expuesto todavía** (ver sección 3) — es decir, hoy no existe superficie de ataque real de "webhook falso" porque no hay ninguna ruta pública que reciba tráfico de Culqi. Esto es una mitigación por ausencia, no por control activo — aceptable mientras no haya proveedor real, pero es la primera cosa que debe revisarse con una pasada de seguridad explícita (regla del CLAUDE.md del proyecto: "cambios sensibles de dinero requieren revisión de seguridad explícita") cuando se implemente el endpoint real.

### E. Datos — idempotencia del ledger / estados de `payment_orders`
- `payment_orders.status`: `created → pending → paid|failed|expired|cancelled` (enum a nivel de BD, migración `2026_08_23_000003`). `unique(['provider', 'provider_order_id'])` y `unique('recharge_request_id')` (1:1 real).
- `payment_webhooks`: log crudo append-only, `unique(['provider', 'event_id'])`, incluye `payload_hash` (sha256) para detectar si el mismo `event_id` llegara alguna vez con contenido distinto (no se usa activamente para bloquear, solo se persiste — ver sección 3, posible mejora futura no implementada).
- **Doble idempotencia confirmada por test**: `duplicate webhook event id never credits twice`, `two different paid events for the same order still credit once` (`PaymentOrderTest.php`) — ambos pasan.
- **Reversión (`reverse()`)**: crea un asiento `reversal` con `amount` negativo, nunca edita/borra el `deposit` original; permite `credits_available` negativo de forma deliberada si el profesor ya gastó el saldo (política de negocio explícitamente pendiente de definir, no un bug).

### F. (ver E)

### G. Testing — resultado real de ejecutar los tests hoy

```
php artisan test --filter=Payment
→ 22 passed (63 assertions), 6.74s
   (PaymentOrderTest: 13 tests — creación de orden, unicidad 1:1, webhook paid/failed,
    duplicado de evento, dos eventos paid para la misma orden, reverse + idempotencia,
    reverse deja balance negativo, no se puede revertir una recarga nunca aprobada,
    "culqi provider is a stub that refuses to run", resolución del contenedor
    fake/culqi;
    ProviderGuardTest: 2 tests de pagos — fake en producción con pagos habilitados
    aborta, proveedor desconocido aborta;
    más CrossTenantAccessTest/FrontendAuditorTest/MonetizationIntegrityTest,
    que coinciden con el filtro por nombre de método "...payment...", no forman
    parte del dominio pero se listan por transparencia)
```

**Total dominio Payments: 22 tests, 63 aserciones, 0 fallos.** No se asumió que pasaran.

### H. Regresión — qué podría romperse
- **Ninguna ruta HTTP nueva** → no hay superficie de regresión operativa hoy (nadie puede invocar este código en producción salvo por comando de consola o test).
- El sistema de recargas manual (`RechargeController::approve()`) sigue funcionando exactamente igual — este módulo se añade *al lado*, no lo modifica (verificado: `RechargeApprovalService::credit()` ya existía extraído antes de esta ronda según su propio docblock, y ambos flujos comparten método).
- Riesgo real de regresión futura: cuando se implemente `CulqiPaymentProvider` de verdad y se agregue el endpoint HTTP, ese es el momento de mayor riesgo (firma real, primera vez que tráfico externo no confiable llega a `PaymentWebhookService`) — el CLAUDE.md del proyecto ya exige una revisión de seguridad explícita para cambios de dinero, aplica directamente ahí.

### I. Mantenibilidad
Alta, mismo estilo de documentación exhaustiva que WhatsApp. `docs/payments-architecture.md` enumera explícitamente "Lo que falta para producción" en 7 puntos concretos (cuenta Culqi, implementar el provider, endpoint HTTP, UI de profesor, comando de reconciliación, expiración automática de órdenes, panel admin de refunds) — reduce el riesgo de que alguien intente activar esto sin pasar por esa lista.

### J. Producción — ¿listo para activarse?
**Es un stub deliberado, explícitamente no funcional, no un bug:**
- `PAYMENTS_ENABLED=false` y `PAYMENT_PROVIDER=fake` por defecto.
- `CulqiPaymentProvider::createOrder()`/`verifyWebhook()` lanzan `RuntimeException` inmediatamente — **no hay ningún camino en el que `PAYMENT_PROVIDER=culqi` funcione hoy**, ni siquiera parcialmente. Confirmado por el propio test `culqi provider is a stub that refuses to run`.
- `ProviderGuard` bloquea `fake` en producción con pagos habilitados (fail-closed).
- No existe endpoint HTTP de webhook (ver sección 3) — aunque se implementara `CulqiPaymentProvider` hoy mismo, **no habría forma de que un webhook real llegara al sistema** sin trabajo adicional (un controlador + ruta).
- Conclusión: listo como *fundación* (contratos, ledger, idempotencia, tests), deliberadamente incompleto como *producto activable* — coincide exactamente con lo que documenta `docs/payments-architecture.md`.

---

## (3) Features parcialmente conectadas

| Observación | Evidencia | Clasificación |
|---|---|---|
| `PaymentWebhookService::handle()` no tiene ningún consumidor HTTP real — solo se invoca desde `tests/Feature/PaymentOrderTest.php` | `grep` sobre `routes/` no encuentra ninguna referencia a `PaymentWebhookService`, `PaymentOrder`, `culqi`, ni una ruta `payments/webhook`; `docs/payments-architecture.md:122-126` lo confirma explícitamente ("deliberadamente NO se agregó... exponer una ruta pública sin verificación real de firma sería una superficie de ataque sin ningún beneficio") | **INTENTIONAL** — documentado como decisión de diseño, no un olvido. |
| `payment_orders`/`payment_webhooks` se pueden escribir (vía `FakePaymentProvider` + tests) pero nadie los lee desde ninguna UI/admin todavía (no hay panel de pagos) | No existe controlador Admin de pagos; `RechargeController` (admin) solo gestiona `RechargeRequest`, no `PaymentOrder` | **INCOMPLETE FEATURE** (documentada como pendiente en `docs/payments-architecture.md` punto 7 — panel admin de pagos/refunds) — no es DEAD CODE porque el modelo tiene un propósito claro y probado, simplemente el consumidor de lectura (UI/admin) todavía no existe. |
| `payment_webhooks.payload_hash` (sha256) se calcula y persiste pero no se usa para ninguna comparación/validación activa en el código actual | `PaymentWebhookService.php:46` calcula el hash al crear el registro; no hay ningún `where('payload_hash', ...)` ni comparación posterior en el código | **DEAD-ISH / FORWARD-LOOKING** — no es dañino (es un campo de auditoría adicional, columna barata), pero hoy no tiene lector. Clasificado como **INTENTIONAL** de baja prioridad (auditoría defensiva), no como bug — pero vale señalarlo si se busca reducir superficie. |
| El componente `button` de las plantillas AUTHENTICATION de Meta (`sub_type='url'`) está basado en documentación de terceros, no confirmado contra Meta directamente | `MetaCloudApiProvider.php:61-67`, `config/services.php` (comentario en `templates.phone_verification_code.button`) | **UNKNOWN** (marcado explícitamente por el propio código como no confirmado — riesgo real si se activa sin probar primero) |
| `docs/MOVA_PHASE6_AUDIT.md` es referenciado por la migración de consentimiento de WhatsApp como fuente de cuántas cuentas quedan afectadas, pero no se verificó su existencia/contenido en esta auditoría | Comentario en `database/migrations/2026_08_24_000004_add_whatsapp_consent_to_users_table.php` | Ver sección (6) — **UNKNOWN**, no verificado. |

No se encontró ningún caso claro de **BUG** dentro del alcance auditado (WhatsApp/Payments/ProviderGuard): toda la lógica revisada tiene tests que la ejercitan y pasan hoy contra el working tree real.

---

## (4) Clasificación final

| Módulo | Clasificación | Motivo |
|---|---|---|
| **WhatsApp** (`app/WhatsApp/*`, `WhatsAppChannel`, `WhatsAppWebhookController`, modelos, migraciones, `ReconcileWhatsApp`, tests) | **KEEP** | Arquitectura completa, consistente con los patrones de MOVA (idempotencia por capas, auditoría, fail-closed), 142/142 tests pasan hoy. Apagado por defecto (`WHATSAPP_ENABLED=false`) — no representa riesgo mientras no se active. Único punto abierto real (button OTP no confirmado contra Meta) ya está documentado como tal en el propio código; no bloquea el merge, sí debe resolverse antes de un envío OTP real. |
| **Payments/Culqi** (`app/Payment/*`, `PaymentOrder`, `PaymentWebhook`, `PaymentWebhookService`, `config/payments.php`, migraciones, `PaymentOrderTest`) | **KEEP** | Fundación financiera sólida y ya probada (ledger reutilizado, idempotencia de dos capas, 22/22 tests pasan). `CulqiPaymentProvider` es un stub *intencional y documentado* — no confundir con código roto. No introduce ningún riesgo nuevo porque no hay endpoint HTTP expuesto ni forma de activarlo accidentalmente (`ProviderGuard` + flags en `false` por defecto). |
| **`ProviderGuard`** (`app/Support/ProviderGuard.php`) | **KEEP** | Pieza de seguridad transversal correcta y bien probada (`ProviderGuardTest`, 4 tests entre ambos dominios pasan). Cierra un fail-open real y documentado del código anterior (`match ... default => new Fake...`). Sin este archivo, ambos módulos serían más arriesgados de activar por error. |

Ningún módulo del dominio auditado se clasifica como QA_ARTIFACT, TEMPORARY, OBSOLETE, REVERT o BLOCKED — todo lo revisado es código de producto deliberado, documentado, y probado.

---

## (5) Barrido de secretos

Búsqueda ejecutada sobre todos los archivos del dominio (código + tests + migraciones + docs + config), con patrones para: claves Culqi/Stripe-like (`sk_live`, `pk_live`), SIDs de Twilio (`AC[0-9a-f]{32}`), tokens de Meta/Facebook, `whsec_`, bloques de clave privada RSA, tokens de Slack (`xox...`), claves de Google (`AIza...`):

```
grep -RniE "(sk_live|pk_live|AC[0-9a-f]{32}|EAACEdEose0cBA...|whsec_|BEGIN (RSA|PRIVATE) KEY|xox[baprs]-|AIza[0-9A-Za-z_-]{35})"
  app/WhatsApp app/Payment app/Channels/WhatsAppChannel.php app/Http/Controllers/WhatsAppWebhookController.php
  app/Console/Commands/ReconcileWhatsApp.php app/Support/WhatsAppReconciliation.php app/Support/ProviderGuard.php
  app/Models/WhatsAppMessage.php app/Models/WhatsAppWebhookEvent.php app/Models/PaymentOrder.php
  app/Models/PaymentWebhook.php app/Services/PaymentWebhookService.php config/payments.php config/services.php
  tests/Feature/*WhatsApp* tests/Feature/PaymentOrderTest.php tests/Feature/MetaCloudApiProviderTest.php
  tests/Feature/PhoneVerificationWhatsAppTest.php tests/Feature/ProviderGuardTest.php tests/Feature/Reminder*.php
  database/migrations/2026_08_2*.php docs/whatsapp-architecture.md docs/payments-architecture.md
→ Sin resultados.
```

Revisión manual adicional de valores de config/tests que parecían credenciales:
- `tests/Feature/MetaCloudApiProviderTest.php:33` → `'services.meta_whatsapp.access_token' => 'test-token'` — valor de prueba, no real.
- `tests/Feature/WhatsAppWebhookTest.php:25,40,48,58` → `'app_secret' => 'test-secret'`, `'webhook_verify_token' => 'correct-token'` — valores de prueba, no reales.
- `.env.example` (diff) → todas las variables nuevas (`META_WHATSAPP_*`, `CULQI_*`) quedan **vacías**, con comentario explícito "Nunca credenciales reales aquí ni en Git" / "Nunca commitear llaves reales".
- `phpunit.xml` (diff) → elimina las variables `TWILIO_*` (ya no aplican), no introduce ninguna nueva con valor real.

**No se encontró ningún secreto real. Nada que reportar como "DO NOT COMMIT".**

---

## (6) Qué no se pudo verificar

- **No se conectó a ningún servicio externo real** (WhatsApp/Meta, Culqi) — instrucción explícita del encargo. La corrección de `MetaCloudApiProvider` contra el comportamiento real de la Graph API de Meta (formato exacto de plantillas, componente `button` para OTP) sigue sin confirmación contra tráfico real — el propio código ya lo señala como pendiente.
- **`docs/MOVA_PHASE6_AUDIT.md`**, referenciado por la migración de consentimiento de WhatsApp como fuente del impacto cuantificado en usuarios existentes, no se abrió ni se verificó en esta auditoría (fuera del listado de archivos del encargo). No se puede confirmar si ese documento existe con ese contenido exacto.
- **No se ejecutó la suite completa de MOVA** (`php artisan test` sin filtro) — solo los subconjuntos WhatsApp/Payments/Reminder pedidos explícitamente. No se puede afirmar que otros módulos no relacionados sigan pasando; tampoco era el encargo.
- **No se auditó el frontend** (`resources/js/Pages/Auth/PhoneVerification.vue`, mencionado en el diff de `PhoneVerificationController` como el lugar donde vive la casilla de consentimiento) — está fuera de la lista de archivos del dominio asignado, aunque es directamente relevante para "consentimiento explícito, no preseleccionado". Se recomienda una pasada específica de UI si no se ha hecho ya.
- **No se verificó en runtime** que `ProviderGuard::resolve()` efectivamente detenga el arranque de `php artisan serve`/`queue:work` en un entorno con variables mal configuradas — la validación aquí es por lectura de código + los 2 tests que ejercitan `ProviderGuardTest`, no un arranque real contra `APP_ENV=production`.
- **No se revisó el histórico de commits** más allá de `HEAD` para confirmar que ninguno de estos archivos estuviera previamente commiteado con contenido distinto (relevante solo si se sospechara de un rebase/squash oculto — no había motivo para sospecharlo aquí).
