# Entregabilidad del correo de MOVA (auditoría 2026-11-03)

Estado: **diagnóstico hecho, corrección pendiente de dos accesos del titular** (cuenta del proveedor transaccional y sesión de Namecheap). Nada de DNS ni de Azure se ha modificado todavía.

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
