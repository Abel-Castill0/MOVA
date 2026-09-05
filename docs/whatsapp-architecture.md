> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# Arquitectura de WhatsApp — migración de Twilio a Meta Cloud API directo

## Contexto

MOVA ya tenía WhatsApp funcionando desde una ronda anterior, vía **Twilio**
como BSP (Business Solution Provider) — no vía la WhatsApp Cloud API de
Meta directamente. Esta ronda migra esa integración para hablar
directamente con la Graph API de Meta, por decisión explícita del usuario
(alternativa considerada y descartada: quedarse en Twilio y solo pasar de
su sandbox a producción, que hubiera requerido cero código nuevo).

**Estado actual: sin cuenta de Meta Business todavía.** Todo funciona hoy en
modo `fake` (test/desarrollo). Ver `docs/WHATSAPP_PRODUCTION_NOTES.md` para
el checklist de afiliación pendiente (Meta Business Portfolio, WABA, número,
plantillas).

## Qué cambió y qué NO

**Se reemplazó**: la única pieza que hablaba con Twilio (`WhatsAppChannel`
para notificaciones, y `PhoneVerificationController::sendWhatsAppCode()`
para el código de verificación de teléfono) — ambas instanciaban
`\Twilio\Rest\Client` directamente. Ahora ambas delegan en
`WhatsAppProviderContract`, resuelto según `services.whatsapp.provider`
(`fake`|`meta`). Se quitó la dependencia `twilio/sdk` de `composer.json`
por completo.

**NO se tocó**: ninguna de las 21 clases `App\Notifications\*Notification`
— sus 10 métodos `toWhatsApp(): string` existentes siguen exactamente
igual, generando el mismo texto de siempre. Esto es deliberado (ver más
abajo) y significa que `tests/Feature/NotificationSecurityTest.php` (que
llama `toWhatsApp()` directamente para verificar que ninguna notificación
filtra el token de sala de Jitsi — hallazgo C-3) sigue pasando sin
modificarse un carácter.

## Por qué el texto libre no puede ir directo a Meta

WhatsApp Business Platform exige que un mensaje **iniciado por el negocio**
(no una respuesta dentro de las 24h desde que el cliente escribió) use una
**plantilla pre-aprobada**, no texto libre — Meta simplemente rechaza el
envío si no. Prácticamente todo lo que envía MOVA (recordatorios de clase,
confirmaciones, estado de recargas) es iniciado por MOVA, nunca una
respuesta a un mensaje reciente del usuario. Por eso `MetaCloudApiProvider`
**solo** sabe enviar plantillas — no tiene ningún método de texto libre.

## La solución de esta ronda: una plantilla genérica, no 21 rediseños

Convertir las 21 notificaciones a plantillas individuales (cada una con sus
propias variables posicionales) es un trabajo real y deliberadamente **no**
se hizo en esta ronda — especialmente riesgoso para las que tocan datos de
clases/Jitsi (C-3), que merecen su propia revisión de seguridad antes de
tocarlas, tal como pide `CLAUDE.md`.

En su lugar: se registra en Meta **una sola plantilla "utility" genérica**
(`generic_notification`) con **un solo parámetro de texto libre**. El canal
sigue llamando a `$notification->toWhatsApp($notifiable)` exactamente como
antes, y ese texto completo se manda como el único `{{1}}` de esa plantilla:

```
WhatsAppChannel::send()
        │
        ├── (sin cambios) $notification->toWhatsApp($notifiable) → string
        │
        ▼
WhatsAppProviderContract::sendTemplate($to, 'generic_notification', [$texto])
        │
        ▼
MetaCloudApiProvider → Graph API → plantilla con {{1}} = $texto completo
```

Esto es un patrón real y común para transiciones como esta — no un hack.
Permite tener Meta funcionando de verdad desde el día uno de aprobación de
UNA plantilla, sin bloquear todo detrás de rediseñar 21 mensajes.

**Migrar notificaciones individuales a plantillas propias más ricas
(variables separadas, botones, etc.) queda como trabajo futuro**,
notificación por notificación, con especial cuidado en las que tocan
Jitsi/datos de clase (revisión de seguridad explícita, por
`CLAUDE.md`).

## OTP (código de verificación de teléfono)

`PhoneVerificationController::sendWhatsAppCode()` usa su **propia** clave
de plantilla, `phone_verification_code`, separada de la genérica —
Meta tiene una categoría de plantilla distinta ("authentication") para
códigos de un solo uso, con su propia política de aprobación (contenido
restringido, botón opcional "Copiar código"). Mezclarla con la plantilla
de notificaciones genéricas habría sido incorrecto de cara a Meta, no solo
una cuestión de organización del código.

## Piezas

| Pieza | Rol |
|---|---|
| `App\WhatsApp\Contracts\WhatsAppProviderContract` | `sendTemplate(string $to, string $templateKey, array $params): bool` — el resto de MOVA nunca conoce el formato HTTP de Meta |
| `App\WhatsApp\MetaCloudApiProvider` | Implementación real (Graph API vía `Http` facade) — no es un stub, ya funciona hoy contra Meta si se configuran credenciales y plantillas reales |
| `App\WhatsApp\FakeWhatsAppProvider` | Test/desarrollo — registra en `$sent` lo que se "envió", nunca toca red |
| `config/services.php` → `meta_whatsapp` | Credenciales + mapa `templateKey → nombre real en Meta`. Un mapeo con `name: null` hace que el provider se salte el envío (log, nunca crashea) |
| `config/services.php` → `whatsapp.provider` | `fake`\|`meta` — un solo punto de decisión, igual que `payments.provider` para Culqi |
| `App\Models\WhatsAppMessage` (`whatsapp_messages`) | Registro de auditoría post-envío — `MetaCloudApiProvider` escribe una fila por intento (éxito o fallo) |
| `App\Http\Controllers\WhatsAppWebhookController` (`/api/webhooks/whatsapp`) | Diseñado, inerte sin credenciales — handshake GET + firma HMAC POST (sobre el body crudo), actualiza `whatsapp_messages.status` respetando el orden real por timestamp, nunca retrocede un estado más avanzado |
| `App\WhatsApp\WhatsAppMessageStatus` (enum) | `sent\|delivered\|read\|failed\|unknown` — `unknown` es un resultado incierto (timeout/excepción), deliberadamente distinto de `failed` (rechazo definitivo de Meta) |
| `App\Support\WhatsAppReconciliation` + `php artisan mova:reconcile-whatsapp` | Solo lectura, mismo espíritu que `mova:reconcile-ledger` — señala mensajes `unknown` o estancados en `sent`, nunca corrige nada |
| `whatsapp_messages.client_reference` | Etiqueta libre (tipo+destinatario, no un evento único — ver más abajo) que arma el llamador para rastrear qué disparó un envío — nunca contiene el código OTP ni datos sensibles |
| `App\Models\WhatsAppWebhookEvent` (`whatsapp_webhook_events`) | Deduplicación a nivel de EVENTO de webhook (`UNIQUE(event_key)`) — capa independiente de la máquina de estados de `whatsapp_messages` |

## Por qué `$params` es una lista posicional, no asociativa

Las variables de una plantilla de Meta son posicionales (`{{1}}`, `{{2}}`...),
así que `sendTemplate()` recibe `array $params` como lista ordenada — el
llamador decide el orden, `MetaCloudApiProvider` solo los mapea 1:1 a
`components[0].parameters`. No hay traducción de nombres de clave a
posición: eso obligaría a codificar en MOVA una suposición sobre el orden
exacto que Meta aprobó para cada plantilla, que solo se confirma al
aprobarla.

## Ronda de auditoría (2026-08-23, segunda pasada)

Una revisión externa de la primera versión de esta migración señaló puntos
reales que se corrigieron aquí — y algunos que ya estaban resueltos y no
requerían cambio:

**Corregido:**
- **Plantilla OTP incompleta.** `MetaCloudApiProvider` solo mandaba el
  código como parámetro del body. Las plantillas AUTHENTICATION con botón
  "Copiar código" (`otp_type=COPY_CODE` al crearlas en Meta) necesitan
  además un componente `button` en el envío. Se agregó
  (`services.meta_whatsapp.templates.phone_verification_code.button`).
  **Advertencia honesta**: el `sub_type` exacto (`url`) se verificó contra
  documentación de dos BSP que reflejan la Cloud API de Meta (MessageBird,
  360dialog) — no se pudo acceder directamente a developers.facebook.com
  para confirmarlo contra la fuente primaria. Confirmar contra la
  documentación vigente de Meta o un envío de prueba real antes de production.
- **Sin registro de auditoría de entregas.** Se agregó `whatsapp_messages`
  (`to`, `template_key`, `provider_message_id`, `status`, `error`,
  `delivered_at`, `read_at`) — cada intento de `MetaCloudApiProvider` queda
  registrado, éxito o fallo. Es un log post-envío, no una cola pre-envío
  (ver más abajo por qué no se duplicó el mecanismo de colas de Laravel).
- **Webhook diseñado pero deliberadamente inerte.** `/api/webhooks/whatsapp`
  (`WhatsAppWebhookController`) implementa el handshake GET
  (`hub_challenge`) y la verificación de firma POST
  (`X-Hub-Signature-256`, HMAC-SHA256 contra `META_WHATSAPP_APP_SECRET`) —
  sin esas credenciales configuradas, ambos rechazan todo. Actualiza
  `whatsapp_messages.status` (delivered/read/failed) cuando Meta reporta el
  estado real de un mensaje ya enviado.
- **Cobertura de fallos de `MetaCloudApiProvider`.** Se agregaron pruebas
  para 400/401/429/500/503, JSON malformado, timeout de conexión, y
  respuesta 200 sin `messages[0].id`. Este trabajo encontró un bug real
  (`WhatsAppMessage` apuntaba a la tabla `whats_app_messages` por
  convención de nombres de Eloquent — Laravel parte "WhatsApp" en dos
  palabras — en vez de `whatsapp_messages`; corregido con `$table` explícito
  en el modelo) que había quedado enmascarado porque `logAttempt()` nunca
  debe hacer fallar un envío que ya se resolvió, así que el error se
  tragaba en silencio salvo por el log. Sin estas pruebas nuevas, ese bug
  hubiera llegado a producción.

**Ya estaba resuelto, verificado en vez de asumido:**
- **"El OTP debe ir hasheado, no en texto plano."** Ya lo estaba —
  `PhoneVerificationController::send()` usa
  `Hash::make($code)` desde antes de esta ronda.
- **"El OTP necesita expiración/límite de intentos/cooldown."** Ya existía:
  `CODE_TTL_MINUTES = 10`, `MAX_ATTEMPTS = 5`, bloqueo temporal si se
  exceden. Solicitar un código nuevo ya invalida el anterior (se
  sobrescribe el hash).
- **"Falta rate limiting en el envío de OTP."** Ya existía:
  `Route::post('verify-phone/send', ...)->middleware('throttle:3,1')`,
  combinado con requerir sesión autenticada (el throttle de Laravel ya
  discrimina por usuario+IP en ese caso).
- **"El contrato debería llamarse `sendTemplate()`, no `send()` genérico."**
  Ya se llamaba `sendTemplate(string $to, string $templateKey, array
  $params): bool` desde el diseño original de esta migración.
- **"¿WhatsApp puede romper una transacción financiera?"** Auditado
  explícitamente: en los 11 archivos del repo que usan `DB::transaction()`
  junto a `->notify(...)`, TODAS las llamadas a `notify()` ocurren después
  de que la transacción ya hizo commit — incluido el código nuevo de esta
  ronda (`RechargeApprovalService`, `PaymentWebhookService`). Ningún envío
  de WhatsApp puede revertir una recarga, un pago o una reserva de crédito.
- **"Auditar las 21 notificaciones por fugas de datos sensibles."** Barrido
  completo (no solo el test de Jitsi existente) buscando JWT/token/
  password/secret/reset/credential/URLs de admin en las 21 clases — sin
  hallazgos nuevos. El único enlace a una ruta `/admin/*`
  (`NewRechargeRequestNotification`) va dirigido a un admin, no es una fuga.

**Evaluado y descartado a propósito, no por omisión:**
- **Transactional Outbox propio.** `ShouldQueue` de Laravel y un Outbox
  transaccional resuelven problemas DISTINTOS, no el mismo — vale la pena
  ser precisos aquí: `ShouldQueue` resuelve "procesar este trabajo de forma
  asíncrona, con reintentos"; un Outbox resuelve específicamente "una
  transacción de base de datos y un evento externo deben quedar
  consistentes entre sí" (la fila del outbox se escribe en la MISMA
  transacción que el resto del cambio, garantizando que si el commit
  ocurre, el evento eventualmente se despacha, y si el commit falla, el
  evento nunca existió).
  MOVA no necesita esa segunda garantía todavía porque, ya verificado en
  esta misma ronda, las notificaciones se despachan DESPUÉS de que la
  transacción financiera/de reserva ya hizo commit (nunca dentro) — no hay
  una atomicidad DB+evento que resolver, porque el evento nunca depende de
  que la transacción no se revierta. Mientras eso siga siendo cierto, el
  modelo de jobs de Laravel ya cubre el requisito real, y `whatsapp_messages`
  aporta la trazabilidad del resultado del envío que sí faltaba. Si algún
  día una notificación necesitara despacharse DENTRO de la misma
  transacción que el cambio que la origina, ahí sí valdría reconsiderar un
  Outbox — no antes.
- **Retry automático tras timeout.** Deliberadamente NO se agregó. Meta no
  ofrece una idempotency key propia para mensajes — reintentar tras un
  timeout/excepción de red es precisamente el escenario de doble envío que
  se quiere evitar. Se prefiere un mensaje genuinamente perdido (raro) a
  uno duplicado.
- **Botón `ONE_TAP` de OTP.** Requiere configuración adicional (package
  name/signature hash de una app Android que MOVA no tiene). `COPY_CODE`
  es suficiente para una web.
- **Rediseñar las 21 `toWhatsApp()` a un modelo `Notification →
  ChannelIndependentPayload`.** Riesgo real (especialmente para las que
  tocan Jitsi) sin beneficio inmediato mientras solo existe una plantilla
  genérica. Queda como deuda técnica explícita, no como omisión.

## Ronda de auditoría (2026-08-23, tercera pasada — cierre)

Última pasada antes de dar por cerrada la base de código (pendiente solo
de credenciales reales de Meta). 4 puntos pedidos explícitamente:

**1. Constraints de idempotencia en BD — ya existían, corregido un
reclamo falso de la revisión.** `UNIQUE(provider, provider_message_id)`
está en `whatsapp_messages` desde la migración original de esta tabla —
nunca se quitó. Lo que sí faltaba era una SEGUNDA capa: deduplicación a
nivel de EVENTO de webhook, no solo de mensaje. Se agregó
`whatsapp_webhook_events` (`UNIQUE(event_key)`, hash de
`wamid+status+timestamp`) — Meta puede reentregar el mismo evento si no
recibió 200 a tiempo, y eso ahora se corta en base de datos antes incluso
de evaluar la máquina de estados, no solo porque el rango ya esté cubierto.

**2. Queue retry + timeout — verificado contra el código fuente de
Laravel, no asumido.** Hallazgo real al leer
`Illuminate\Notifications\NotificationSender::queueNotification()`: Laravel
despacha **un job de cola POR CANAL**, no un job compartido con todos los
canales de una notificación. Esto significa que un fallo en el canal de
mail (por ejemplo) NUNCA puede provocar un reintento del job de
WhatsApp — cada canal ya está aislado por diseño de Laravel, no algo que
MOVA tuviera que resolver. El único vector real de "reintento" que queda
es a nivel de infraestructura: si el WORKER del canal WhatsApp muere a
mitad de un envío (deploy, OOM), el driver de cola (`database`,
`retry_after=90s`, ver `config/queue.php`) liberaría la reserva del job y
otro worker lo re-procesaría — pero `Http::timeout(10)` en
`MetaCloudApiProvider` es muy inferior a esos 90s, así que en operación
normal (sin que el proceso muera literalmente a mitad del request) esto no
se dispara. Documentado aquí en vez de "solucionado" con código porque no
hay nada que el código de MOVA pueda hacer sobre un worker que muere a
mitad de una llamada HTTP — es una garantía de configuración de
infraestructura (mantener `retry_after` > timeout de Meta), no de dominio.

**3. Política de vida de `unknown` — antes ausente, ahora explícita.**
Antes, cualquier mensaje `unknown` se señalaba de inmediato en
`mova:reconcile-whatsapp`, incluso uno de hace 10 segundos que
legítimamente está esperando el webhook. Ahora:
`unknown` con edad < `stuck-minutes` → aparece como `unknown_still_waiting`,
NO cuenta como `needs_attention`. `unknown` con edad ≥ `stuck-minutes` →
sí se señala. Nunca hay una transición automática `unknown → failed`: MOVA
no sabe si Meta lo aceptó, y no va a inventar que sí lo sabe.

**4. Deduplicación de eventos de webhook — implementada (ver punto 1).**

### Otras precisiones de esta ronda

- **`failed` es terminal para el `WhatsAppMessage` (una fila, un intento de
  envío concreto), no para el evento de negocio que lo originó.** Nada le
  impide a MOVA crear un `WhatsAppMessage` #2 más adelante para el mismo
  recordatorio si la política de negocio lo permitiera — eso sería
  simplemente otra fila nueva, no una reapertura de la que falló. No se
  implementó ningún mecanismo de "reintento de negocio" en esta ronda
  (no se pidió, y decidir esa política — cuántas veces, con qué backoff —
  es una decisión de producto, no algo que deba inventarse aquí).
- **`client_reference` no es y no debería ser `UNIQUE`.** Representa
  "tipo de notificación + destinatario" (p. ej.
  `"ClassReminderNotification#42"`), no un evento único — la MISMA
  notificación puede enviarse legítimamente varias veces al mismo usuario
  (un recordatorio de 24h, uno de 2h y uno de 10 minutos para la misma
  clase comparten client_reference). Forzar unicidad ahí bloquearía envíos
  legítimos. Si se necesita en el futuro un id verdaderamente único por
  evento de negocio (p. ej. por lección/recarga concreta), tendría que
  construirse con más contexto del que `WhatsAppChannel` tiene hoy
  (`class_basename($notification).'#'.$notifiable->getKey()` no incluye el
  id de la lección/recarga) — deuda técnica anotada, no implementada.
- **Prueba de la regla "el timestamp no decide, el rango semántico sí".**
  Se agregó el escenario literal pedido: un `delivered` con timestamp
  POSTERIOR a un `read` ya aplicado sigue sin poder retroceder el estado
  (`test_a_later_timestamp_does_not_override_a_lower_semantic_rank`).
- **`WhatsAppMessageStatus` como enum de columna DB.** Ya lo era desde la
  ronda anterior (`enum('status', [...])` en la migración) — la revisión
  pedía verificarlo, no algo que faltara agregar.
- **`whatsapp_webhook_events` cometió el MISMO bug de nombre de tabla que
  `WhatsAppMessage`** (Eloquent adivina `whats_app_webhook_events`, no
  `whatsapp_webhook_events`) — detectado de inmediato porque los tests
  fallaron al ejecutarlos, no en producción. Se agregó
  `tests/Feature/WhatsAppModelTableNamesTest.php` como guarda de
  regresión explícita para que un tercer modelo `WhatsApp*` futuro no
  repita el mismo error dos rondas después.
- **`template_version` (mejora sugerida, no crítica) — no implementado a
  propósito.** La propia revisión la califica de "no imprescindible ahora".
  Queda anotado: cuando se migren notificaciones individuales a
  plantillas propias (deuda técnica ya documentada arriba), ese es el
  momento natural de agregar versión/idioma junto al nombre de plantilla.
- **Nada nuevo se guarda del payload completo de Meta.** Ya se verificaba
  esto en la ronda anterior (`test_otp_code_never_ends_up_in_the_audit_log_error_field`);
  esta ronda no agregó ningún campo que capture el payload crudo.
- **Límite de 1MB del webhook.** Sigue siendo defensa de nivel aplicación,
  no la primera línea real — `post_max_size` de PHP a nivel de servidor
  corta antes de que Laravel siquiera arranque para un payload realmente
  grande. El chequeo en `WhatsAppWebhookController` es una segunda capa
  para el caso en que `post_max_size` esté configurado de forma más
  permisiva que lo que MOVA quiere aceptar aquí específicamente — no algo
  que reemplace la configuración de servidor.

## Lo que falta para producción

Ver el checklist completo en `docs/WHATSAPP_PRODUCTION_NOTES.md` — depende
enteramente de la afiliación de Meta Business (no bloqueado por código).
Una vez exista cuenta y plantillas aprobadas, lo único que cambia es
configuración (`WHATSAPP_PROVIDER=meta`, `META_WHATSAPP_*`,
`WHATSAPP_ENABLED=true`) — cero cambios de código necesarios para que
`MetaCloudApiProvider` empiece a enviar de verdad.
