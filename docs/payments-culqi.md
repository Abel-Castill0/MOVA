# Culqi en MOVA — integración, webhooks y MCP local

> Estado: CURRENT · 2026-10-22 · API V2 (`https://api.culqi.com`, tokens en `https://secure.culqi.com`).
> Fuente: especificación OpenAPI oficial (`https://apidocs.culqi.com/apiculqi.yaml`) **y pruebas reales contra el
> sandbox** — donde difieren, manda lo observado (tabla §3). Probado en modo **test**; producción no se tocó.
> Convive con Mercado Pago sin cambiarlo: `PAYMENT_PROVIDER` elige UNO (`mercadopago` | `culqi` | `fake`).

## 1. Qué hay

| Pieza | Archivo | Papel |
|---|---|---|
| Proveedor | `app/Payment/CulqiPaymentProvider.php` | `createPaymentAttempt()` (cargo con token de tarjeta/Yape) y `verifyWebhook()` |
| Cliente HTTP | `app/Payment/Culqi/CulqiClient.php` | Cargos, devoluciones, órdenes, eventos, abonos. **Los POST nunca se reintentan** |
| Config/guard | `app/Payment/Culqi/CulqiConfig.php` + `AppServiceProvider` | Llaves coherentes con `CULQI_ENV`; live solo en producción; aborta el arranque si no |
| Estados | `app/Payment/Culqi/CulqiChargeMapper.php` | Cargo de Culqi → `paid`/`failed`/`reversed`/`review` (fail-closed) |
| Conciliación | `app/Services/CulqiPaymentReconciliationService.php` | Única puerta que cambia un `PaymentOrder` y llama a `RechargeApprovalService` |
| Webhook | `CulqiWebhookController` → `ProcessCulqiWebhook` | Persiste (UNIQUE provider+event_id), responde 200 y reconcilia contra la API |
| Comandos | `culqi:diagnose`, `culqi:webhook-url`, `culqi:reconcile`, `culqi:sandbox-smoke` | Diagnóstico, URL del webhook, barrido y prueba E2E de sandbox |
| MCP local | `tools/culqi-mcp/server.mjs` + `.mcp.json` | Herramientas de consulta y escrituras SOLO sandbox (§6) |
| Pruebas | `tests/Feature/CulqiPaymentTest.php` (46), `tools/culqi-mcp/test.mjs` (19) | Ver §7 |

Reutiliza sin cambios la lógica financiera: `PaymentOrder`, `PaymentWebhook`, `PaymentWebhookService::persist()`,
`RechargeApprovalService::credit()/reverse()` (exactamente-una-vez por `RechargeRequest`, ledger append-only). Cambios en
código compartido, mínimos y sin alterar Mercado Pago (los mensajes de MP son idénticos byte a byte):
`RechargeRequest::PROVIDER_MANAGED_METHODS` (`mercadopago`, `culqi`) sustituye al literal `'mercadopago'` en
`RechargeApprovalService` y `RechargeRequestPolicy` — un admin **no puede** aprobar/rechazar/revertir a mano una recarga de Culqi.
No hay migraciones: `payment_orders.provider` y `recharge_requests.payment_method` ya eran cadenas.

## 2. Flujo y garantías

```
token (Culqi.js/Checkout, llave pública) ──► createPaymentAttempt(recharge, token)
   1. PaymentOrder 'prepared' (idempotency_key propio)         ← antes de hablar con Culqi
   2. UPDATE … WHERE submission_status='prepared' → 'submitting' ← solo UNA petición gana
   3. POST /v2/charges {amount, currency, email, source_id, capture, metadata{payment_order_id,
      recharge_request_id, attempt, idempotency_key}}           ← importe/email SIEMPRE del servidor
   4. resultado:
        201  → applyTruth(): valida metadata+importe+moneda+entorno → paid → credit() una vez
        400  → rechazo definitivo: failed (guarda charge_id del cargo denegado)
        200 + action_code → exige 3DS: failed ('requires_3ds'), sin cargo
        timeout/5xx/408/429 → 'uncertain': NO se reintenta; bloquea nuevos cobros hasta reconciliar
webhook ──► 200 rápido; job consulta GET /v2/charges/{id} (verdad) ──► mismo applyTruth()
culqi:reconcile (cada 5 min si PAYMENT_PROVIDER=culqi) ──► pendientes/inciertos contra la API
```

Un `credit()` por recarga aunque lleguen N webhooks, N barridos o N `applyTruth` (probado con 5 repeticiones, 3 eventos distintos
del mismo cargo y reentrada concurrente). Devolución **total** ⇒ `reverse()`; **parcial**, disputa, cargo sin capturar o
resultado desconocido ⇒ `review` + alerta operativa, sin acción financiera.

## 3. Evidencia de sandbox (2026-10-22) — lo que difiere de la documentación

| Aspecto | Documentación OpenAPI | Sandbox real | Decisión |
|---|---|---|---|
| Venta exitosa | `paid:true`, `outcome.code="succesfull_charge"` | HTTP 201, `paid:false`, `outcome.type="venta_exitosa"`, `code="AUT0000"`, `capture:true` | `paid` **no** se usa para decidir; se exige `venta_exitosa`+`AUT0000`+`capture` (la variante documentada también se acepta) |
| Rechazo | `outcome.code="card_declined"` | HTTP **400** `{type:"operacion_denegada"\|"venta_denegada", code:"DNGE0015"…, charge_id}` y el cargo denegado existe | Intento `failed`; se guarda `charge_id` para que `charge.creation.failed` lo encuentre |
| Idempotencia | no documentada | no hay cabecera; **reusar un token tras una venta exitosa es denegado** (`venta_denegada`) | Protección propia (claim atómico, sin reintento de POST, `uncertain`, metadata) |
| Webhooks | sin firma/HMAC/auth documentados | — | Secreto largo en la URL + **verdad siempre vía API** antes de acreditar |
| Filtros de listado | `creation_date_from/to` “UNIX” | segundos y milisegundos aceptados; `amount`, `email` filtran | Recuperación de intentos inciertos por email+importe+ventana y `metadata` |
| Devolución | `status: Completa` | `completa` | Se compara por importe (`amount_refunded`), no por texto |
| Abonos | `GET /v2/balance/deposits` | exige `currency_code`, `date_from`, `date_to` | Cliente y MCP los envían (30 días por defecto) |
| Cifrado AES/RSA | ejemplo con `x-culqi-rsa-id` | con el RSA ID + llave pública entregados Culqi responde `crypto_error` (4 variantes OAEP/IV/tag probadas) | **No activar** (`CULQI_ENCRYPT_PAYLOADS=false`); ver §8 |

Escenarios ejecutados en el sandbox real vía backend (`culqi:sandbox-smoke`, BD SQLite desechable): aprobado ⇒ `paid`, 1 depósito,
1 crédito, conciliado 2× y webhook 2× sin duplicar; fondos insuficiente y tarjeta robada ⇒ `failed`; aprobado + devolución total
(`POST /v2/refunds`) ⇒ `reversed`, saldo 0.

## 4. Configuración (variables; nunca valores en el repo)

`CULQI_ENV` (`test`|`live`) · `CULQI_PUBLIC_KEY` · `CULQI_SECRET_KEY` (alias antiguo `CULQI_PRIVATE_KEY`) ·
`CULQI_WEBHOOK_SECRET` · `CULQI_WEBHOOKS_ENABLED` (false) · `CULQI_ENCRYPT_PAYLOADS` (false) · `CULQI_RSA_ID` ·
`CULQI_RSA_PUBLIC_KEY` (PEM con `\n` literales) · `CULQI_SANDBOX_MAX_AMOUNT_MINOR` (1000) ·
`CULQI_BASE_URL`/`CULQI_SECURE_URL` (solo local/testing; fuera de ahí deben ser los hosts oficiales). Fail-closed al arrancar con `PAYMENTS_ENABLED=true`: llaves ausentes, de ejemplo,
con modos mezclados, distintas de `CULQI_ENV`, live fuera de `APP_ENV=production`, hosts no oficiales fuera de local/testing,
o (con `CULQI_WEBHOOKS_ENABLED=true`) un `CULQI_WEBHOOK_SECRET` de menos de 32 caracteres.
Validar sin ver secretos: `php artisan culqi:diagnose` (y `--remote` para GET de solo lectura en modo test).

## 5. Qué debes configurar a mano (lo único que no se puede hacer por código)

| # | Dónde | Acción |
|---|---|---|
| 1 | CulqiPanel (integración) → **Eventos → Webhooks → +Añadir** | Una entrada por evento: `charge.creation.succeeded`, `charge.creation.failed`, `refund.creation.succeeded`, `refund.creation.failed`. URL: `https://<tu-dominio-público>/api/webhooks/culqi/<CULQI_WEBHOOK_SECRET>` — obtén la URL con `php artisan culqi:webhook-url --reveal` **en tu propia terminal** (no la pegues en chats). Debe ser HTTPS público (staging en Azure; en local, un túnel). |
| 2 | Entorno de staging (Azure) | Cargar `CULQI_*` como secretos del entorno y poner `CULQI_WEBHOOKS_ENABLED=true` solo cuando el paso 1 esté hecho. |
| 3 | CulqiPanel → Desarrollo → **RSA** | Comprobar que el **RSA ID** y la **llave pública** son del mismo par y del entorno de integración (hoy Culqi responde `crypto_error`). Hasta entonces el cifrado queda desactivado: no es obligatorio. |
| 4 | CulqiPanel → comercio | Confirmar qué medios están habilitados (tarjeta funciona; **Yape** y **PagoEfectivo/órdenes** no se han probado: requieren habilitación y, para Yape, un celular de prueba `900 000 001` + OTP). |
| 5 | Producción | Cuestionario PCI **SAQ-D** firmado por el representante legal (obligatorio para usar la API) y llaves `pk_live_/sk_live_` generadas por ti → solo al almacén de secretos de producción. |
| 6 | Decisión de producto | Qué proveedor usa el checkout y la UI de Culqi: `CreditCheckoutController` y `Checkout.vue` siguen siendo de Mercado Pago (no se tocaron). Falta cablear Culqi.js/Checkout v4 (hoy el backend está listo para recibir `token` + `recharge`). |
| 7 | 3DS | Por defecto un cargo que exige 3DS queda `failed('requires_3ds')`. Si el comercio lo exige, hay que integrar el reto (tarjetas de prueba `4456 53…` con `review@culqi.com`). |

No inicié sesión en CulqiPanel (no figura entre los paneles autorizados en `AGENTS.md`); todo lo anterior se verificó por API.

## 6. MCP local `mova-culqi`

Registrado en `.mcp.json` (Claude Code pedirá aprobarlo la primera vez). Sin dependencias; lee `CULQI_*` del `.env` local y no los devuelve nunca.

- **Lectura** (siempre, con llaves test): `culqi_status`, `culqi_get_charge`, `culqi_list_charges`, `culqi_get_refund`, `culqi_list_refunds`,
  `culqi_get_order`, `culqi_list_orders`, `culqi_get_event`, `culqi_list_events`, `culqi_list_deposits`, `culqi_reconcile_report`
  (cada cargo con su estado MOVA y su `payment_order_id`). Llaves live: lecturas desactivadas salvo `CULQI_MCP_ALLOW_LIVE_READ=true`.
- **Escritura de sandbox** (`culqi_sandbox_charge`, `culqi_sandbox_refund`): apagadas por defecto; exigen `CULQI_MCP_ALLOW_WRITES=true`,
  `CULQI_ENV=test` y llaves `*_test_`. **Con llaves live no existe forma de escribir.** Importe 100…`CULQI_SANDBOX_MAX_AMOUNT_MINOR`; solo
  tarjetas de prueba publicadas, elegidas por nombre (el modelo nunca maneja un PAN); devoluciones solo de `chr_test_…` y sin exceder lo devolvible.
- **Idempotencia**: `request_id` obligatorio, ledger append-only en `tools/culqi-mcp/.state/ledger.jsonl` (ignorado por Git). Misma petición ⇒ resultado
  guardado sin llamar a Culqi; mismo `request_id` con otros parámetros ⇒ error; “iniciado sin resultado” (corte) ⇒ se rechaza, no se reenvía. Los POST nunca se reintentan;
  resultado incierto ⇒ se reporta y se concilia por `metadata.mcp_request_id`.
- Ejemplo: “con mova-culqi, ¿qué cargos de las últimas horas están en review?” → `culqi_reconcile_report`.

## 7. Pruebas

`vendor/bin/phpunit --filter 'CulqiPaymentTest|PaymentOrderTest|ProviderGuardTest'` (en Docker `php_qa`, PHP 8.3) · `npm run check:culqi-mcp` (CI)
· `php artisan culqi:sandbox-smoke --confirm-sandbox [--scenario=refund]` (solo `local`/`testing`, BD sqlite o `mova_qa`, `sk_test_`, tope de importe).
Cobertura de `CulqiPaymentTest`: confirmado, rechazado, 3DS, error de autenticación, token de otro entorno/forma, error de red (POST una sola vez), 5xx,
recuperación de incierto (encontrado / no encontrado ⇒ review), reentrada concurrente, importe/metadata/entorno que no coinciden, resultados ambiguos,
replays, devolución parcial/total, webhook (404/401/400, duplicado, “dice paid pero la API dice rechazado”, eventos distintos del mismo cargo,
tipos no gestionados, carrera webhook-vs-respuesta), recargas gestionadas (no aprobables a mano; mensajes de MP intactos), configuración, cliente y cifrado.

## 8. Revisión independiente y riesgos

Antes de cerrar se revisó con `mova-laravel-reviewer` y `mova-security-finance` (solo lectura). Veredictos: *OK con reservas* y *APTO con condiciones*; **no** se
encontró ningún camino de doble crédito ni de crédito sin verdad server-to-server. Corregido a raíz de la revisión (con prueba): solo se cobran recargas `culqi` en
estado `pending` (un cobro confirmado sobre una recarga ya rechazada/revertida va a *review*, no lanza excepción); un POST en vuelo (doble clic) no consume el presupuesto de
búsquedas ni genera alertas falsas; un fallo de cifrado no deja el intento atascado en `submitting`; el barrido ignora órdenes en revisión y `prepared`, y re-verifica pagos
ya `paid` (14 días) para detectar devoluciones sin webhook; secreto de webhook ≥ 32 caracteres; payload de webhook mínimo (sin PII del pagador); eventos de cargos ajenos
a MOVA se cierran sin alerta crítica; hosts oficiales obligatorios fuera de local/testing; la sonda ya no renombra usuarios; botones Aprobar/Revertir ocultos para `culqi` en el admin;
ledger del MCP con bloqueo entre procesos.

Pendiente / aceptado (documentado, no bloquea el uso en sandbox):
- **Webhook sin firma**: quien conozca la URL puede *encolar* trabajo, no acreditar (siempre se re-consulta la API). El secreto viaja en la ruta y puede quedar en logs de acceso/ingress
  de Azure: **excluir `/api/webhooks/culqi/` de la telemetría** y rotar el secreto (cambiar `CULQI_WEBHOOK_SECRET` y la URL del panel) si se sospecha. Sin rotación con dos secretos simultáneos ni allowlist de IP de Culqi (no publicada).
- `paid:false` en ventas exitosas es lo observado en **test**; confirmar con la prueba live final (un cargo mínimo y devolución, según `AGENTS.md`).
- Heredado de Mercado Pago y compartido: `credit()` notifica dentro de la transacción exterior (con cola `sync` y mail caído se revierte y el barrido reintenta; con cola asíncrona puede
  leerse antes del commit — conviene `afterCommit` en `RechargeApproved/ReversedNotification`), y el orden de locks Recarga→Orden (`resolveAttemptRow`) vs Orden→Recarga (`applyTruth`).
- Pruebas de concurrencia reales (dos procesos) en MySQL: ver §7; en SQLite solo se simula la reentrada.
- Órdenes (PagoEfectivo) y suscripciones: solo lectura/ignoradas. No hay `closeRejectedPayment` para Culqi: un `review` se resuelve con `php artisan culqi:reconcile --order=<id>` o por decisión humana.
- Cifrado AES/RSA implementado y probado localmente (ida y vuelta con phpseclib v4), **no validado por Culqi** (§3).
- El MCP con `CULQI_MCP_ALLOW_LIVE_READ=true` leería datos live desde el `.env` local: las llaves live deben vivir solo en producción, no en equipos de desarrollo.
