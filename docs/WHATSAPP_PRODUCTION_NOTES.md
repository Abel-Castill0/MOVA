# MOVA — WhatsApp: Estado y notas de producción

## Estado actual (junio 2026)

WhatsApp está **desactivado por defecto** (`WHATSAPP_ENABLED=false`).

El canal funciona técnicamente via Twilio, pero sigue en **modo sandbox**
(`WHATSAPP_MODE=sandbox`), lo que significa que solo usuarios que hayan enviado
previamente "join <código>" al número de sandbox pueden recibir mensajes.

**Email + notificaciones in-app son los canales principales de MOVA.**
WhatsApp es un canal complementario opcional.

---

## Cómo operar sin WhatsApp (configuración actual)

Con `WHATSAPP_ENABLED=false` (valor por defecto en Railway):

- Ninguna notificación intenta enviar WhatsApp.
- Los jobs no fallan por errores de Twilio.
- El flujo de clases, reportes y reseñas funciona completamente sin WhatsApp.
- `failed_jobs` no se incrementa por errores de Twilio.

No es necesario ningún cambio adicional para operar en producción sin WhatsApp.

---

## Activar WhatsApp en sandbox (solo para pruebas)

1. El destinatario debe enviar `join <sandbox-code>` al número `+14155238886` en WhatsApp.
2. En Railway, establecer temporalmente:
   ```
   WHATSAPP_ENABLED=true
   WHATSAPP_MODE=sandbox
   WHATSAPP_REQUIRE_VERIFIED_PHONE=true
   ```
3. Solo usuarios con `phone_verified_at` no nulo recibirán mensajes.
4. Verificar en logs de Railway que no hay errores 63007 (usuario no en sandbox).
5. Volver a `WHATSAPP_ENABLED=false` después de la prueba.

---

## Qué queda pendiente para WhatsApp en producción real

- [ ] Solicitar acceso a WhatsApp Business API con Twilio (fuera de sandbox).
- [ ] Aprobación del número de origen por parte de Meta/WhatsApp.
- [ ] Crear plantillas de mensajes (templates) aprobadas por WhatsApp Business.
- [ ] Actualizar `TWILIO_WHATSAPP_FROM` con el número de producción.
- [ ] Cambiar `WHATSAPP_MODE=production`.
- [ ] Actualizar textos legales para mencionar explícitamente WhatsApp production.

---

## Variables de entorno relevantes

| Variable | Default | Descripción |
|---|---|---|
| `WHATSAPP_ENABLED` | `false` | Activa/desactiva el canal globalmente |
| `WHATSAPP_MODE` | `sandbox` | `sandbox` o `production` |
| `WHATSAPP_REQUIRE_VERIFIED_PHONE` | `true` | Solo envía a números con `phone_verified_at` |
| `TWILIO_SID` | — | Account SID de Twilio |
| `TWILIO_AUTH_TOKEN` | — | Token de autenticación (no imprimir) |
| `TWILIO_WHATSAPP_FROM` | `+14155238886` | Número de origen (sandbox) |

---

## Advertencia importante

**No prometas WhatsApp como canal garantizado mientras siga en sandbox.**

Los mensajes de sandbox solo llegan a números que han hecho el join manual.
En producción real con usuarios nuevos, esto representa una experiencia degradada
y posibles errores 63007 en los logs.

Mientras `WHATSAPP_MODE=sandbox`, los textos públicos de MOVA deben decir:
"notificaciones por email y WhatsApp (si está disponible/verificado)"
— no "recibirás WhatsApp" como promesa incondicional.
