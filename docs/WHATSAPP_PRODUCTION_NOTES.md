> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# MOVA — WhatsApp: Estado y notas de producción

## Estado actual (2026-08-23)

WhatsApp está **desactivado por defecto** (`WHATSAPP_ENABLED=false`).

El canal migró de Twilio a la **WhatsApp Cloud API de Meta directa**
(`WHATSAPP_PROVIDER=meta`) — ver `docs/whatsapp-architecture.md` para el
detalle completo de la arquitectura y por qué se dejó Twilio. Mientras
`WHATSAPP_PROVIDER=fake` (default), nada se envía de verdad — es el modo de
test/desarrollo.

**Email + notificaciones in-app son los canales principales de MOVA.**
WhatsApp es un canal complementario opcional.

---

## Cómo operar sin WhatsApp (configuración actual)

Con `WHATSAPP_ENABLED=false` (valor por defecto en Railway):

- Ninguna notificación intenta enviar WhatsApp.
- Los jobs no fallan por errores del proveedor.
- El flujo de clases, reportes y reseñas funciona completamente sin WhatsApp.
- `failed_jobs` no se incrementa por errores de WhatsApp.

No es necesario ningún cambio adicional para operar en producción sin WhatsApp.

---

## Qué queda pendiente para WhatsApp en producción real (Meta directo)

Todo esto es trabajo de negocio/afiliación, no de código — ver
`docs/whatsapp-architecture.md` para el detalle técnico de cada pieza:

- [ ] Crear el Meta Business Portfolio para MOVA.
- [ ] Crear la WhatsApp Business Account (WABA) dentro de ese portfolio.
- [ ] Comprar/asignar un número de teléfono exclusivo para MOVA (no el
      personal) y verificarlo (SMS/llamada) ante Meta.
- [ ] Crear la app de Meta Developer asociada y obtener `App ID`/`App Secret`.
- [ ] Generar un token de acceso permanente (system user token, no el
      temporal de pruebas que caduca en 24h).
- [ ] Redactar y enviar a aprobación las plantillas de mensaje necesarias
      (mínimo: una plantilla "utility" genérica para notificaciones, y una
      plantilla "authentication" para el código de verificación de
      teléfono). Sin plantillas aprobadas, `MetaCloudApiProvider` no envía
      nada — se salta el envío con un log claro (ver
      `config/services.php`).
- [ ] Configurar en el panel de Meta el webhook apuntando a
      `https://<tu-dominio>/api/webhooks/whatsapp` — la ruta **ya existe**
      (`WhatsAppWebhookController`), pero rechaza todo hasta que
      `META_WHATSAPP_WEBHOOK_VERIFY_TOKEN`/`META_WHATSAPP_APP_SECRET` estén
      configurados con los valores reales que Meta te asigne.
- [ ] Cambiar `WHATSAPP_PROVIDER=meta`, `WHATSAPP_ENABLED=true`, y llenar
      `META_WHATSAPP_*` en producción con las credenciales reales.
- [ ] Actualizar textos legales/públicos para mencionar WhatsApp como canal
      real (hoy los textos no deben prometerlo incondicionalmente).

---

## Variables de entorno relevantes

| Variable | Default | Descripción |
|---|---|---|
| `WHATSAPP_ENABLED` | `false` | Activa/desactiva el canal globalmente |
| `WHATSAPP_PROVIDER` | `fake` | `fake` (test/dev, no envía nada real) o `meta` |
| `WHATSAPP_REQUIRE_VERIFIED_PHONE` | `true` | Solo envía a números con `phone_verified_at` |
| `META_WHATSAPP_PHONE_NUMBER_ID` | — | ID del número en el Business Manager de Meta |
| `META_WHATSAPP_ACCESS_TOKEN` | — | Token de acceso (system user), nunca en Git |
| `META_WHATSAPP_APP_ID` / `META_WHATSAPP_APP_SECRET` | — | Credenciales de la app de Meta Developer |
| `META_WHATSAPP_WEBHOOK_VERIFY_TOKEN` | — | Para cuando exista la ruta de webhook |
| `META_WHATSAPP_API_VERSION` | `v20.0` | Versión de la Graph API |
| `META_WHATSAPP_TEMPLATE_GENERIC` | — | Nombre real de la plantilla "utility" ya aprobada por Meta |
| `META_WHATSAPP_TEMPLATE_OTP` | — | Nombre real de la plantilla "authentication" ya aprobada por Meta |

---

## Advertencia importante

**No prometas WhatsApp como canal garantizado mientras no haya plantillas
aprobadas por Meta.** Con `META_WHATSAPP_TEMPLATE_GENERIC`/`_OTP` vacíos,
`MetaCloudApiProvider` se salta cada envío (log de advertencia, nunca
crashea un job) — funcionalmente equivalente a tener WhatsApp apagado,
aunque `WHATSAPP_ENABLED=true`.
