# Entregabilidad del correo de MOVA (auditoría 2026-11-03)

Estado (2026-11-03): **aplicada**. Producción envía por Resend (SMTP) desde `MOVA <notificaciones@movaeduca.me>` con el dominio verificado (DKIM propio). Los mensajes de prueba llegaron a Recibidos en Gmail (confirmado por el titular); las cabeceras de autenticación no se han revisado (§9).

## 1. Cómo envía hoy producción (evidencia: Azure CLI de solo lectura + DNS público)

| Dato | Valor |
|---|---|
| Mailer | `MAIL_MAILER=smtp` → `smtp.gmail.com:587` STARTTLS, contraseña de aplicación (secretos `mail-username` / `mail-password`, también en Key Vault) |
| Remitente (`From`) | `MOVA <m0v4class@gmail.com>` (`MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`) en web y scheduler; sin Reply-To |
| Soporte visible | `LEGAL_SUPPORT_EMAIL` por defecto `m0v4class@gmail.com` (`config/legal.php`) |
| Servidor que transmite | Gmail (la cuenta personal `m0v4class@gmail.com`); `Return-Path` y firma DKIM son de `gmail.com`, no de `movaeduca.me` |
| Cola | `web`: `QUEUE_CONNECTION=sync` (envío dentro de la petición); `scheduler`: `database`, y el mismo contenedor procesa la cola (el app `movap-worker` está **detenido a propósito** desde 2026-10-22, ver `azure-cost-optimization.md` C-1). Mismas variables MAIL_* en ambos: no hay configuración antigua distinta |
| Allowlist | `MAIL_ALLOWLIST` sin definir en producción (correcto) |

## 2. DNS de `movaeduca.me` (consulta pública, 2026-11-03)

| Registro | Valor actual | Lectura |
|---|---|---|
| NS | `dns1.registrar-servers.com`, `dns2.registrar-servers.com` | **La zona autoritativa está en Namecheap (BasicDNS)**: ahí se editan los registros |
| MX | `eforward1..5.registrar-servers.com` (10,10,10,15,20) | Namecheap Email Forwarding: **recibir** alias del dominio. No tocar |
| SPF (apex) | `v=spf1 include:spf.efwd.registrar-servers.com ~all` | Solo autoriza el reenvío de Namecheap. **No autoriza a ningún servicio de envío** (hoy no hace falta: se envía como `gmail.com`) |
| DKIM | `google._domainkey` y `default._domainkey` no existen | Sin firma del dominio |
| DMARC | `_dmarc.movaeduca.me` no existe | Sin política ni informes |

## 3. Causa (comprobada en configuración, falta la prueba de cabeceras)

MOVA se presenta como `MOVA <m0v4class@gmail.com>`: una **cuenta personal de Gmail** que envía mensajes automáticos con enlaces a otro dominio (`movaeduca.me`). El dominio oficial no firma ni autoriza ningún envío, no hay DMARC y el remitente no coincide con el dominio de los enlaces. Para Gmail es un patrón típico de «cuenta personal usada como servidor de correos automáticos» y de baja reputación; un SPF/DKIM `pass` de `gmail.com` no corrige eso. Además `smtp.gmail.com` limita el envío (≈ 500/día) y no ofrece rebotes ni estadísticas.

Lo que **no** he podido comprobar sin una cabecera real: el veredicto exacto de Gmail (SPF/DKIM/DMARC y sus alineaciones) del mensaje que cayó en Spam. Ver §6.

## 4. Solución elegida (coste US$ 0)

Enviar por **SMTP de un proveedor transaccional** con el dominio verificado (DKIM propio + SPF/Return-Path en un subdominio), `From: MOVA <notificaciones@movaeduca.me>` y `Reply-To` a un buzón real de soporte. El código ya soporta `MAIL_MAILER=smtp` sin dependencias nuevas; solo cambian variables y secretos de Azure.

| Proveedor | Plan gratuito (fuentes de terceros, verificar en su página) | Comentario |
|---|---|---|
| **Resend** (recomendado) | 3.000 correos/mes, **tope de 100/día**, 1 dominio ([Resend Mailer Review](https://wpmailsmtp.com/resend-review/), [pricing](https://www.resend.com/pricing.md)) | SMTP `smtp.resend.com` (usuario `resend`, contraseña = API key). DNS simple. El tope diario es la limitación |
| Brevo | 300 correos/día por SMTP ([Brevo SMTP](https://www.brevo.com/free-smtp-server)) | Más holgura diaria; ver si añade pie de marca en el plan gratuito antes de elegirlo |
| Amazon SES / Azure Communication Services | de pago por uso | No: SES exige cuenta AWS nueva; ACS por SMTP exige una app de Entra y este tenant las prohíbe |

No se contrata ningún plan de pago. Si el volumen real supera los 100/día de Resend, pasar a Brevo (mismo método SMTP).

## 5. Registros DNS (tabla provisional; los valores exactos los muestra el panel del proveedor al añadir el dominio y **no se inventan**)

Subdominio de envío `send.movaeduca.me` (aísla el Return-Path y no toca MX ni SPF del apex).

| Tipo | Host | Valor | TTL | Acción | Motivo |
|---|---|---|---|---|---|
| MX (apex) | `@` | `eforward1..5.registrar-servers.com` | actual | **Conservar** | Reenvío de alias de Namecheap |
| TXT (apex) | `@` | `v=spf1 include:spf.efwd.registrar-servers.com ~all` | actual | **Conservar** (un solo SPF por host) | Reenvío |
| TXT | `resend._domainkey` | clave pública DKIM que muestra Resend | Auto | Añadir | Firma DKIM con `d=movaeduca.me` (alineada) |
| MX | `send` | el que muestra Resend (feedback SES) | Auto | Añadir | Rebotes/Return-Path del subdominio |
| TXT | `send` | SPF que muestra Resend | Auto | Añadir | SPF del Return-Path (alineación relajada con el apex) |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:<buzón real del titular>` | Auto | Añadir | Monitoreo; **no** pasar a `quarantine`/`reject` sin revisar informes |

## 6. Prueba que necesito del titular para confirmar el veredicto actual

En Gmail, abrir uno de los correos que cayeron en Spam → ⋮ → **Mostrar original**. Copiar **solo** estas líneas (ocultando el destinatario y cualquier enlace con token): `Authentication-Results`, `Received-SPF`, `From`, `Return-Path`, `Reply-To`, y `DKIM-Signature` (solo los campos `d=`, `s=` y `h=`). Nunca compartir contraseñas ni el cuerpo con enlaces de restablecimiento.

## 7. Cambios de código preparados

- `config/mail.php`: `reply_to` opcional desde `MAIL_REPLY_TO_ADDRESS` / `MAIL_REPLY_TO_NAME` (Laravel lo aplica solo; un valor no válido se descarta). Prueba: `tests/Feature/MailReplyToTest.php`.
- Variables nuevas en Azure (web y scheduler) cuando exista el proveedor: `MAIL_HOST=smtp.resend.com`, `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`, `MAIL_USERNAME=resend`, `MAIL_PASSWORD` (secreto `mail-password`), `MAIL_FROM_ADDRESS=notificaciones@movaeduca.me`, `MAIL_FROM_NAME=MOVA`, `MAIL_REPLY_TO_ADDRESS=<soporte real>`.
- Rollback: restaurar `MAIL_HOST=smtp.gmail.com`, `MAIL_USERNAME`/`MAIL_PASSWORD` (el valor anterior queda en Key Vault versionado; no se sobrescribe, se crea una versión nueva) y `MAIL_FROM_ADDRESS=m0v4class@gmail.com`.

## 8. Cambios aplicados (2026-11-03)

**Resend** (plan gratuito, US$ 0): cuenta del titular, dominio `movaeduca.me` región São Paulo, estado *Verified*, *Forge* habilitado, tracking de clics/aperturas desactivado (los enlaces no se reescriben), TLS oportunista. Clave API «MOVA produccion SMTP» con permiso *Sending access*; la clave solo existe en Key Vault.

**DNS (Namecheap BasicDNS, zona autoritativa)** — registros añadidos, el resto no se tocó (A `@`, CNAME `www`/`staging`, TXT `asuid*`, MX y SPF del apex de reenvío intactos; verificado después por DNS público):

| Tipo | Host | Valor | Motivo |
|---|---|---|---|
| TXT | `resend._domainkey` | `p=MIGfMA0GCSq…` (clave pública DKIM de Resend, 1024 bits) | Firma DKIM alineada con `movaeduca.me` |
| CNAME | `rsend` | `rsend-sae1.forge.rmta.net` | Return-Path / rebotes |
| CNAME | `send` | `send.forge.rmta.net` | SPF y rebotes del subdominio |
| TXT | `_dmarc` | `v=DMARC1; p=none;` | Monitoreo (sin `rua` todavía) |

No se añadió el MX de recepción de Resend (`Enable receiving` queda apagado): habría desplazado el reenvío de Namecheap.

**Reenvío de correo (Namecheap, «Redirect Email»)**: no había ningún reenviador definido. Se crearon `soporte@movaeduca.me` y `notificaciones@movaeduca.me` → `m0v4class@gmail.com` (el buzón de soporte que ya figuraba en la app), para que las respuestas al remitente lleguen a alguien.

**Azure (sin cambio de imagen)**: Key Vault `kv-movap-192f01`, nuevas versiones de `mail-password` (clave de Resend) y `mail-username` (`resend`); `movap-web` y `movap-scheduler` revisión `0000043` con `MAIL_HOST=smtp.resend.com` y `MAIL_FROM_ADDRESS=notificaciones@movaeduca.me` (`MAIL_PORT=587`, `MAIL_ENCRYPTION=tls` sin cambios). Los secretos son referencias a Key Vault sin versión: una revisión nueva toma la última versión.

**Rollback**: `az containerapp update` de web y scheduler con `MAIL_HOST=smtp.gmail.com` y `MAIL_FROM_ADDRESS=m0v4class@gmail.com`, y volver a publicar en Key Vault los valores de las versiones anteriores (`mail-password` `27c6aa1e…`, `mail-username` `166ac816…`; siguen almacenadas): `az keyvault secret show --id <versión>` y `az keyvault secret set` con ese valor. Luego una revisión nueva.

## 9. Evidencia de las pruebas (2026-11-03)

| Prueba | Resultado observable | Lo que NO demuestra |
|---|---|---|
| SMTP directo a Resend (`smtp.resend.com:587`, STARTTLS) desde `notificaciones@movaeduca.me` | Aceptado (`rechazados: {}`); en Resend: *Delivered* | Carpeta de Gmail |
| Restablecer contraseña real en producción (`/forgot-password`, cuenta del titular) | La página confirmó; en Resend: *Delivered*, remitente `"MOVA" <notificaciones@movaeduca.me>` | Carpeta de Gmail |
| Alerta operativa real «WAHA no responde» (la dispara el vigilante; el piloto WAHA ya no existe) | 2 envíos, uno por administrador, *Delivered*; sin repetición en los minutos siguientes | Carpeta de Gmail |
| Bienvenida, notificación de clase, envío por cola con usuario sintético | **No ejecutadas** en producción: `az containerapp exec` no ejecutó el comando en las réplicas (la conexión abre pero no corre nada) | — |

**Confirmado por el titular (2026-11-03): los tres mensajes (prueba SMTP, restablecer contraseña y alerta de WAHA) llegaron a Recibidos en Gmail.** Las cabeceras `Authentication-Results` (SPF, DKIM, DMARC y su alineación) no se han revisado: no se compartieron.

## 10. Pendiente

- Opcional: cabeceras de uno de los mensajes (⋮ → Mostrar original) para documentar SPF/DKIM/DMARC y la alineación.
- Probar también Outlook (no se ha probado).
- `rua` en DMARC (informes agregados) con un buzón real del titular; hoy `p=none` sin informes. No pasar a `quarantine`/`reject` sin revisar informes.
- Reply-To por código (`MAIL_REPLY_TO_ADDRESS`, commit `9faccf3`): no desplegado porque `master` ya contiene 16 commits de otros cambios (seguridad, Culqi, tests) que no son parte de este trabajo; hasta entonces las respuestas van a `notificaciones@` que reenvía a soporte.
- Límite del plan gratuito de Resend: 100 correos/día, 3.000/mes. Si MOVA lo supera, pasar a Brevo (300/día) por el mismo método SMTP.
- Probar bienvenida, clase y cola cuando exista un mecanismo de ejecución remota que funcione.
