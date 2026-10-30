# Evaluación de Culqi — entorno, webhooks, buzón y entrega

Estado: 2026-10-28. Complementa `culqi-commercial-review.md` §6. **No contiene secretos**: la contraseña del evaluador y la URL secreta del webhook viven en archivos protegidos del titular (`C:\Users\TK\.mova-secrets\`, fuera de Git) y el entorno solo guarda su hash / sus valores como secretos de Container Apps.

## 1. Entorno de staging aislado

| Elemento | Valor |
|---|---|
| URL | `https://movaqa-culqi.whitecliff-88cda913.mexicocentral.azurecontainerapps.io` |
| App | Container App `movaqa-culqi` (`mova-prod-rg`, entorno `mova-aca-env`), 1 réplica 0,25 vCPU / 0,5 GiB |
| Pagos | `PAYMENT_PROVIDER=culqi`, llaves **de prueba** (`pk_test_/sk_test_`), checkout limitado por `CULQI_CHECKOUT_ALLOWLIST` al evaluador |
| Base de datos | SQLite **propia**, en el disco del contenedor, con copia consistente en Azure Files (§2). No hay conexión con MySQL de producción |
| Cola y scheduler | `queue:work` y `schedule:work` dentro del contenedor (reintentos del webhook y `culqi:reconcile` cada 5 min) |
| Correo | `MAIL_MAILER=array`: **no envía correos**; la contraseña no se recupera por correo en staging |
| Arranque | `scripts/azure/culqi-staging-startup.sh` (migra, siembra roles, crea al evaluador con el hash de su contraseña, restaura la copia) |

Separación con producción: producción sigue en `PAYMENT_PROVIDER=mercadopago` sin llaves ni webhooks de Culqi; este documento no cambia nada de ello.

## 2. Persistencia

- **Descartado:** SQLite directamente sobre Azure Files (SMB). Medido: el archivo quedó en 0 bytes, `VACUUM INTO` falló con «database is locked» y dos réplicas veían estados distintos. SMB no soporta los bloqueos de SQLite.
- **Implementado:** SQLite en disco local + copia cada 30 s y al recibir SIGTERM (`VACUUM INTO` local → `integrity_check` → copia simple → `qa.sqlite` con respaldo `qa.sqlite.prev`) en el recurso compartido `qa-culqi` de la cuenta `movaqastg225258` (Standard LRS, 1 GiB, ~US$0,06/GiB-mes + transacciones: céntimos al mes). Al arrancar se restaura la copia principal (o la previa).
- **Límite honesto:** un apagado abrupto (OOM, caída del nodo) puede perder hasta 30 s de datos; un reinicio o despliegue ordenado no pierde nada (copia final al apagar). No usar más de 1 réplica.
- **Verificado:** dos reinicios de revisión con aceptación legal, login del evaluador y un evento de webhook recuperados tras cada uno (`[persist] restaurada copia principal` en los logs; `journal_mode=delete`, `integrity_check=ok`).
- Alternativa más robusta (no activada): base MySQL propia — un servidor flexible Burstable B1ms cuesta del orden de US$12–15/mes (verificar en el portal) sobre un crédito de US$86; requiere autorización del titular. Reutilizar el servidor de producción con otro esquema se descartó por la separación pedida.

## 3. Webhooks

- **URL del staging:** `https://movaqa-culqi…azurecontainerapps.io/api/webhooks/culqi/<CULQI_WEBHOOK_SECRET>`. El secreto (48 hex, propio de staging, distinto del de producción) está en `C:\Users\TK\.mova-secrets\culqi-staging-webhook-url.txt`. No la pegues en chats ni tickets; si se expone, rótala (§6).
- **Receptor** (`CulqiWebhookController`): secreto de ≥ 32 caracteres en la ruta, comparación en tiempo constante; tope de 256 KB; persiste solo ids y tipo (sin PII); `UNIQUE(provider,event_id)`; responde 200 y encola; el job consulta `GET /v2/charges|orders/{id}` con la llave secreta — **un evento falso nunca acredita**.
- **Pruebas ejecutadas contra el staging** (eventos sintéticos de un tipo no gestionado, sin efecto financiero): token incorrecto → 401; token corto → 401; JSON inválido o sin `id/type` → 400; cuerpo > 256 KB → 413; GET → 405; evento válido → 200; **mismo evento 3 veces → 3×200 y una sola fila y un solo job**; la fila quedó `processed` tras reiniciar (el job se procesó con el worker). El reintento ante fallo de procesamiento lo da la cola (`tries=5`, backoff 10 s–15 min) y el barrido `culqi:reconcile`.
- **Entrega real de Culqi: pendiente** hasta que el titular registre los webhooks (§4) y haga la compra de prueba. Culqi no publica sus reintentos ni IPs de origen; lo comprobable es que una entrega repetida es idempotente.

## 4. Registrar los webhooks en CulqiPanel (manual, solo modo pruebas)

1. Abre el archivo `culqi-staging-webhook-url.txt` en tu equipo y copia la URL completa.
2. CulqiPanel → selecciona el entorno **Pruebas** (la integración con las llaves `pk_test_/sk_test_` de MOVAV) → **Desarrollo → Eventos → Webhooks → Añadir webhook** (los nombres de menú pueden variar un poco).
3. Crea **cinco** webhooks, todos con esa URL, uno por evento: `charge.creation.succeeded`, `charge.creation.failed`, `refund.creation.succeeded`, `refund.creation.failed`, `order.status.changed`.
4. **No** registres nada en el entorno de producción de CulqiPanel.
5. Haz la compra de prueba (§5). Después avísame: reviso `culqi_list_events` (MCP), el estado de `payment_webhooks` y el ledger.

## 5. Compra de prueba (la hace el titular)

1. Abre la URL del staging → **Iniciar sesión** con `culqi.revision@movaeduca.me` y la contraseña del archivo protegido.
2. Acepta los documentos legales (el evaluador debe hacerlo él mismo).
3. Barra superior **Servicios** → **Esencial** (10 créditos, **S/ 19.80**) → resumen → **Pagar con tarjeta**.
4. En la ventana de Culqi: tarjeta de prueba oficial `4111 1111 1111 1111`, CVV `123`, vencimiento futuro, un correo cualquiera. Es modo pruebas: no hay cargo real.
5. Debe volver a **Mis créditos** con **10 créditos** y una recarga aprobada. Una segunda compra (p. ej. otra vez Esencial) debe sumar otros 10 y un segundo depósito; el historial muestra ambas.
6. Avísame. Compruebo con la herramienta de diagnóstico del contenedor (solo lectura): estado de `payment_orders`, `recharge_requests`, `payment_webhooks`, asientos del ledger (`deposit`), saldo, colas y logs. **Éxito = saldo 10 y exactamente un `deposit` tras la primera compra**; si hay más o menos, no se declara éxito.

## 6. Operación

- Reinicio: `az containerapp revision restart -g mova-prod-rg -n movaqa-culqi --revision <revisión>`.
- Rotar el secreto del webhook: `az containerapp secret set … --secrets culqi-webhook=<nuevo>` + nueva revisión + actualizar la URL en los cinco webhooks.
- Rotar la contraseña del evaluador: nuevo hash bcrypt en el secreto `evaluator-hash` + nueva revisión (el arranque reimpone el hash en cada inicio; un cambio de contraseña hecho desde la app se revierte al reiniciar).
- Terminar: `az containerapp delete -g mova-prod-rg -n movaqa-culqi`, quitar el almacenamiento `qacul` del entorno y borrar la cuenta `movaqastg225258`; eliminar los webhooks de pruebas en CulqiPanel; `mova:culqi-evaluator revoke culqi.revision@movaeduca.me` en producción.

## 7. Buzón `culqi.revision@movaeduca.me` (Namecheap)

El dominio usa **Email Forwarding** de Namecheap (MX `eforward1–5.registrar-servers.com`, SPF `include:spf.efwd.registrar-servers.com`; verificado en DNS). Es solo reenvío: no hay buzón propio ni se puede enviar desde él.

1. Entra a Namecheap → **Domain List** → `movaeduca.me` → **Manage** → pestaña **Advanced DNS** → sección **Mail Settings** → asegúrate de que sea **Email Forwarding** (ya lo es por los MX).
2. Sección **Email Forwarding** (en versiones nuevas: Domain → **Redirect Email**) → **Add Forwarder**.
3. Alias: `culqi.revision` → Forward to: un correo que controles (p. ej. el tuyo). **No** uses catch-all (`*`). Guarda; si Namecheap pide verificar el destino, confirma el correo que te envíe.
4. Verifica: desde otro correo (no el destino) escribe a `culqi.revision@movaeduca.me` con un asunto único; debe llegar al destino en pocos minutos (revisa spam). Mira el encabezado `Received:` con `registrar-servers.com`.
5. Para qué sirve: staging **no envía correos**, así que el alias no es necesario para entrar. Sirve para que Culqi pueda escribir al evaluador y para recuperar la contraseña de la cuenta de **producción** (Olvidé mi contraseña).

## 8. Instrucciones para Culqi (texto para enviar cuando el titular lo autorice)

> **Acceso de evaluación — MOVA (modo pruebas)**
> - Entorno: `https://movaqa-culqi.whitecliff-88cda913.mexicocentral.azurecontainerapps.io` (entorno de pruebas aislado; **no se realizan cobros reales**; llaves de prueba de Culqi).
> - Usuario: `culqi.revision@movaeduca.me` (rol profesor; sin permisos de administración).
> - Contraseña: se entrega por separado (ver abajo).
> - Catálogo público de paquetes (sin iniciar sesión): `/servicios`.
> - Pasos: iniciar sesión → aceptar los documentos legales → **Servicios** → **Esencial** (10 créditos, S/ 19.80) → **Pagar con tarjeta** → tarjeta de prueba oficial de Culqi (`4111 1111 1111 1111`, CVV `123`, vencimiento futuro) → ver **10 créditos** en «Mis créditos». Los otros paquetes: Inicio (S/ 10.00), Impulso (S/ 29.40), Avance (S/ 38.80), Pro (S/ 57.60).
> - Qué comprueba: orden/cargo con `metadata` de MOVA, acreditación única (idempotente) confirmada contra la API de Culqi, webhooks (`charge.creation.succeeded` y demás) y que las llaves nunca llegan al navegador.
> - Términos, privacidad, libro de reclamaciones y datos del comercio: enlaces en el pie de página.
> - Cuenta temporal: se revocará al terminar la revisión.

**Entrega segura de la contraseña** (no por correo ni chat en claro): (a) un gestor con enlace de un solo uso (Bitwarden Send o 1Password): caducidad ≤ 24 h, 1 solo acceso, protegido con una frase; (b) la frase se dicta por un canal distinto (llamada o mensaje a un contacto verificado de Culqi); (c) el enlace va en el correo y la frase no; (d) tras la revisión, rotar o borrar la cuenta (§6). **Nada se envía a Culqi sin autorización expresa del titular.**

## 9. Estado y pendientes

Ver la tabla final del informe de la sesión (2026-10-28). Pendientes del titular: registrar los cinco webhooks (§4), hacer la compra manual (§5), crear el alias (§7) y autorizar el envío a Culqi (§8).

## 10. Actualización 2026-10-29 — recorrido desde «Mis créditos»

- Staging corre la imagen `ghcr.io/abel-castill0/mova@sha256:f40303a8…` (commit `b9e1308`), revisión `movaqa-culqi--0000002`; solo cambió la imagen (secretos `culqi-webhook`/`culqi-webhook-previous` y datos del evaluador intactos).
- Recorrido: login evaluador → dashboard → **Mis créditos** → **Recargar créditos** → 5 paquetes (Inicio 5/S/10,00; Esencial 10/S/19,80; Impulso 15/S/29,40; Avance 20/S/38,80; Pro 30/S/57,60) → **Pagar en línea** → checkout de Culqi (etiqueta «Entorno de pruebas — no se realizarán cargos reales») → tarjeta de prueba → saldo + historial (pestañas «Historial de Transacciones» y «Estado de Recargas»). Alternativa: `/servicios` → `/comprar-creditos/esencial`.
- Cuentas de profesor que no sean el evaluador **no** pueden comprar en staging (allowlist); verán los paquetes en solo lectura.
- **Compra de prueba verificada (2026-10-29, evaluador, Esencial):** recarga 1 `approved`, `payment_order` 1 `paid`, **un solo** depósito (`recharge:1:deposit`, 10 créditos), saldo 10, historial con «Depósito — Recarga de paquete: Esencial». La API de Culqi confirma el cargo de integración `chr_test_Aapq6zin…` por **1980 céntimos PEN**, `venta_exitosa`, Visa de prueba, con `metadata` de MOVA (orden 1, recarga 1). Antes de pagar: recarga `pending`, 0 depósitos, saldo 0. Llegó un webhook real `charge.creation.succeeded` (fila `payment_webhooks` `processed`, 0 jobs fallidos). El formulario de tarjeta lo completó el titular (la automatización no teclea datos de tarjeta).
