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

## Configuración (`config/services.php → waha`)

`WAHA_BASE_URL` (en producción: https o IP privada), `WAHA_API_KEY`, `WAHA_HOOK_HMAC_KEY`, `WAHA_SESSION`,
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
