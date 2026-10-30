# WhatsApp con WAHA (proveedor alternativo, deshabilitado por defecto)

Estado (2026-10-29): **código integrado y probado con mocks; sin infraestructura creada; `WHATSAPP_ENABLED=false`.**
Infraestructura propuesta del piloto: [`infra/azure/waha/`](../infra/azure/waha/README.md).

## Integración

```
Notification::toWhatsApp() → WhatsAppChannel → WhatsAppProviderContract (WahaProvider) → WahaClient → WAHA /api/sendText
WAHA → POST /api/webhooks/waha (HMAC-SHA512) → WahaWebhookController → WhatsAppStatusRecorder → whatsapp_messages
```

- **`WhatsAppChannel` es la única puerta.** Aplica interruptor global, teléfono verificado, consentimiento (opt-in/opt-out),
  suspensión y cuenta cerrada. Se eliminaron `WhatsAppService` y `SendWhatsAppNotificationJob`, que se saltaban esas
  verificaciones; el panel de administración ahora solo envía a usuarios registrados que consintieron, por el canal.
- **`WahaProvider`** implementa `WhatsAppProviderContract`. Nunca lanza (así un reintento de la cola no reenvía correo/BD
  de una notificación multicanal). Orden: render → número → cuenta cerrada → lista del piloto → pausa por restricción de
  WhatsApp → tope diario por destinatario → sesión `WORKING` → deduplicado → cupo global por minuto → fila `unknown` ANTES de
  enviar → pausa de «escritura» → `sendText`.
- **Semántica «a lo sumo una vez»**: un timeout o una respuesta 2xx sin id deja la fila en `unknown`; no se reenvía a ciegas.
  «Aceptado por WAHA» = `sent`; solo `message.ack` DEVICE/READ/PLAYED lo lleva a `delivered`/`read`; ERROR → `failed`.
- **Webhook**: HMAC-SHA512 sobre el cuerpo crudo (`X-Webhook-Hmac`), tolerancia de marca de tiempo, límite 256 KB, throttle,
  deduplicado por evento y sin regresión de estado. `session.status` actualiza el estado en caché; `reachoutTimelock` /
  `messageCapping` pausan los envíos.
- **Privacidad**: los textos de WhatsApp no incluyen nombres de menores ni motivos en texto libre. Plantillas usadas:
  `generic_notification`, `phone_verification_code`. Notificaciones con WhatsApp: reprogramación de clase, nueva solicitud de clase,
  recarga aprobada, créditos insuficientes del profesor (1 aviso / 6 h) y verificación de teléfono.
- **Panel** (`/admin/whatsapp`): estado de solo lectura (ya no hace logout+restart en un GET), QR en PNG (GET, throttle),
  reinicio explícito (POST, throttle), envío de prueba por el canal.

## Panel administrativo: Sidebar → WhatsApp (`/admin/whatsapp`)

Vincular y administrar el número dedicado **sin Claude Code, scripts ni localhost**. Es una página nativa de MOVA (Inertia/Vue dentro de `AppLayout`).

- **Qué muestra:** estado real (Conectado · Esperando QR · Iniciando · Desconectado · Error · Número incorrecto · Servicio no disponible), número esperado y conectado,
  estado de WAHA, disponibilidad del servidor, última conexión, ambiente (piloto), tiempo restante del piloto y el estado de los avisos a usuarios.
- **«Conectado» = WAHA en `WORKING` y número igual a `WAHA_EXPECTED_NUMBER`.** Un HTTP 200, o un `me` con el número (WAHA lo guarda al pedir un código sin
  completar la vinculación), no bastan. Con otro número → «Número incorrecto» y se bloquea la prueba.
- **QR real:** Laravel pide `GET /api/{session}/auth/qr` a WAHA por la red privada y entrega el PNG (`Cache-Control: no-store`). Solo con la sesión en
  `SCAN_QR_CODE` y solo si es un PNG válido; cualquier JSON/HTML de error se convierte en un mensaje, nunca en imagen. La página consulta el estado cada 3 s y
  renueva el QR cada 4 s mientras espera; no reinicia nada para conseguir códigos. El navegador no ve la IP privada ni la API key.
- **Acciones (POST, MFA reciente, CSRF, confirmación y tope de 4 acciones/15 min en toda la plataforma):** *Iniciar vinculación* (STOPPED → start),
  *Recuperar sesión* (restart), *Restablecer vinculación* (logout + start; exige escribir `RESTABLECER`; solo si la sesión **no** está conectada), *Vincular mediante
  código* (código de 8 caracteres bajo demanda, nunca en logs) y *Enviar mensaje de prueba*.
- **Por qué existe «Restablecer»:** si se pide un código de emparejamiento y no se usa, WAHA queda con credenciales a medias; cada «restart» intenta *iniciar sesión*
  con ellas y falla («Connection Failure»), dejando la sesión en `FAILED` en bucle. Solo restablecer (descartar esas credenciales, que aún no vinculan nada) la recupera.
  WAHA además detiene la espera tras ~2–3 min sin escaneo: por eso hay un botón explícito para iniciar una nueva vinculación (no se reinicia sola).
- **Mensaje de prueba:** destinatario y texto FIJOS por configuración (`WAHA_ADMIN_TEST_RECIPIENT`; no hay campo de destinatario), solo con la sesión conectada al número
  correcto y el destinatario en `WAHA_ALLOWED_RECIPIENTS`. Pasa por `WahaProvider` (lista autorizada, deduplicado 30 min, ritmo, estado de sesión) y queda en
  `whatsapp_messages` (`client_reference = admin_test#…`); el panel muestra solicitud → aceptado por WAHA (id) → entregado (ACK) → leído, sin confundir «aceptado» con «entregado».
  Es la **única** excepción al guardián «solo `WhatsAppChannel` llama al proveedor» (`WhatsAppConsentTest`).
- **`WHATSAPP_ENABLED` vs `WAHA_ADMIN_CONTROL_ENABLED`:** el primero sigue siendo el interruptor de **avisos a usuarios** (false = no se notifica a nadie). El segundo solo
  habilita este panel. Con `ADMIN_CONTROL=true` y `WHATSAPP_ENABLED=false` un administrador puede vincular y probar, y la plataforma **no** envía nada a usuarios.
- **Acceso:** rol `admin` + `admin.mfa`; QR, acciones, código y prueba exigen además `admin.mfa:sensitive` (step-up). Padres, profesores e invitados no ven ni llaman nada.
- **Webhook:** WAHA debe apuntar a `POST /api/webhooks/waha` del entorno que administra el número (HMAC-SHA512). Para recibir el `message.ack` en producción se cambia
  `WHATSAPP_HOOK_URL` en la VM (`/etc/waha/waha.env`) y se reinicia el contenedor.
- **Comprobación sin iniciar sesión:** `php artisan mova:waha-check --qr` (solo lectura) confirma el alcance, el estado y que WAHA entrega un PNG válido sin imprimirlo.

## Configuración (`config/services.php → waha`)

`WAHA_BASE_URL` (en producción: https o IP privada), `WAHA_API_KEY`, `WAHA_HOOK_HMAC_KEY`, `WAHA_DEFAULT_SESSION`, `WAHA_ADMIN_CONTROL_ENABLED`, `WAHA_ADMIN_TEST_RECIPIENT`, `WAHA_EXPECTED_NUMBER`, `WAHA_ENV_LABEL`, `WAHA_PILOT_EXPIRES_AT`,
`WAHA_ALLOWED_RECIPIENTS`, `WAHA_RATE_PER_MINUTE` (8), `WAHA_MAX_PER_RECIPIENT_PER_DAY` (12) y tiempos de escritura/dedupe.
Con `WHATSAPP_ENABLED=true` y `WHATSAPP_PROVIDER=waha`, falta de URL/clave/HMAC **falla cerrado** al resolver el proveedor.

## Desactivar / revertir

`WHATSAPP_ENABLED=false` (y/o `WHATSAPP_PROVIDER=fake`): el canal omite todo. No afecta a Culqi, ledger, scheduler ni colas.
Para retirar la VM: `infra/azure/waha/pilot-teardown.sh --confirm` y comprobar con `pilot-verify.sh` (la limpieza principal es EXTERNA: el workflow `waha-pilot-janitor` borra `mova-waha-pilot-rg` a las 47 h aunque la VM falle; la VM lo hace como respaldo a las 47 h 30 min). Alcance desde MOVA: `php artisan mova:waha-check` (solo lectura).

## Pruebas

`WahaProviderTest`, `WahaWebhookTest`, `WahaChannelIntegrationTest`, `WahaNotificationsTest` + ajustes en
`MonetizationIntegrityTest` y `AccountLifecycleTest` (todo con `Http::fake`; **no hay prueba contra un WAHA real** hasta el piloto).

## Pendiente de validar en el piloto (no demostrado)

Vinculación por QR, persistencia de la sesión tras reinicio, entrega y `message.ack` reales, RAM/CPU reales de NOWEB en 1 GiB,
arranque automático, ausencia de exposición de la API y **costo facturado real** (incluida la elegibilidad de las 750 h gratuitas,
que no se puede comprobar antes de crear la VM).

## Riesgos conocidos

Protocolo no oficial (riesgo de bloqueo del número y de términos de WhatsApp); Azure for Students es no comercial;
una VM única sin alta disponibilidad. La vía oficial es Meta Cloud API (ya implementada, `WHATSAPP_PROVIDER=meta`).
