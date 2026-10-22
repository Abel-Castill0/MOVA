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
| Checkout (UI) | `CulqiCheckoutController` + `resources/js/Pages/Teacher/Credits/CheckoutCulqi.vue` + `resources/js/lib/culqi.js` | Culqi **Custom Checkout** (tarjeta, Yape, efectivo/billeteras) y `Culqi3DS`; solo llave pública en el navegador (§9) |
| Selección | `app/Support/CheckoutProvider.php`, `CheckoutAllowlist.php` | Proveedor activo, medios (`CULQI_METHODS`) y allowlist de checkout |
| Redacción | `app/Support/SentryScrubber.php` | Oculta el secreto del webhook en lo que se envía a Sentry (§10) |
| Pruebas | `CulqiPaymentTest` (46), `CulqiCheckoutTest` (32), `CulqiSecretsTest` (6), `tools/culqi-mcp/test.mjs` (19) | Ver §7 |

Arquitectura, comparación con Mercado Pago, flujo de créditos, manual operativo y plan de migración:
[`payments-migration-mp-to-culqi.md`](payments-migration-mp-to-culqi.md).

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
        200 + action_code → exige 3DS: 'awaiting_3ds' (sin cargo); el navegador completa Culqi3DS y el 2.º POST
                            lleva authentication_3DS con el mismo token y device. Sin device id / Yape ⇒ failed('requires_3ds')
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
`CULQI_METHODS` (`card`; subconjunto de `card,yape,order`) · `CULQI_ORDER_TTL_MINUTES` (1440) · `CULQI_3DS_WINDOW_MINUTES` (8) · `CULQI_CHECKOUT_ALLOWLIST` ·
`CULQI_BASE_URL`/`CULQI_SECURE_URL` (solo local/testing; fuera de ahí deben ser los hosts oficiales). Fail-closed al arrancar con `PAYMENTS_ENABLED=true`: llaves ausentes, de ejemplo,
con modos mezclados, distintas de `CULQI_ENV`, live fuera de `APP_ENV=production`, hosts no oficiales fuera de local/testing,
o (con `CULQI_WEBHOOKS_ENABLED=true`) un `CULQI_WEBHOOK_SECRET` de menos de 32 caracteres.
Validar sin ver secretos: `php artisan culqi:diagnose` (y `--remote` para GET de solo lectura en modo test).

## 5. Qué debes configurar a mano (lo único que no se puede hacer por código)

| # | Dónde | Acción |
|---|---|---|
| 1 | CulqiPanel (integración) → **Eventos → Webhooks → +Añadir** | Una entrada por evento: `charge.creation.succeeded`, `charge.creation.failed`, `refund.creation.succeeded`, `refund.creation.failed`, `order.status.changed`. URL: `https://<tu-dominio-público>/api/webhooks/culqi/<CULQI_WEBHOOK_SECRET>` — obtén la URL con `php artisan culqi:webhook-url --reveal` **en tu propia terminal** (no la pegues en chats). Debe ser HTTPS público (staging en Azure; en local, un túnel). |
| 2 | Entorno de staging (Azure) | Cargar `CULQI_*` como secretos del entorno y poner `CULQI_WEBHOOKS_ENABLED=true` solo cuando el paso 1 esté hecho. |
| 3 | CulqiPanel → Desarrollo → **RSA Keys** | Opcional (el cifrado está apagado). Si se quiere: **recrear** el par RSA en el entorno de integración, seleccionar los endpoints (tokens, orders, charges), copiar el ID y la llave (se muestran **una sola vez**) y reprobar con `culqi:sandbox-smoke`. Ver diagnóstico en §11. |
| 4 | CulqiPanel → comercio | Confirmar medios habilitados en **live**. Sandbox: tarjeta, 3DS y órdenes (PagoEfectivo/billeteras/banca móvil/agentes) comprobados en navegador; Yape: el token `ype_test_` se crea, pero no se completó un pago Yape. El pago de una **orden** no se pudo simular (no hay simulador documentado). |
| 5 | Producción | Cuestionario PCI correspondiente a tu modalidad (§12; con Custom Checkout los datos de tarjeta no pasan por MOVA, pero **confírmalo por escrito con Culqi**) y llaves `pk_live_/sk_live_` generadas por ti → solo al almacén de secretos de producción. |
| 6 | Activación (cuando decidas) | `PAYMENT_PROVIDER=culqi`, `PAYMENTS_ENABLED=true`, `CULQI_METHODS=card` (añade `yape`, `order` solo tras habilitarlos) y `CULQI_CHECKOUT_ALLOWLIST` acotada. Plan completo y rollback: `payments-migration-mp-to-culqi.md` §7. |
| 7 | Órdenes | Exigen celular peruano de 9 dígitos en el perfil del profesor (si falta, el checkout lo indica antes de llamar a Culqi). `CULQI_ORDER_TTL_MINUTES` (1440) y `CULQI_3DS_WINDOW_MINUTES` (8) ajustan vigencias. |

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

Resultado final (2026-10-22, árbol sin commit): suite completa SQLite **1571 tests OK** (7 omitidos); perfil MySQL 8.4 (`phpunit.mysql.xml`, filtro Culqi/PaymentOrder/Recharge/Monetization/Ledger/MercadoPago) **452 OK**; `node --test` MCP 19/19; `check:*` y `npm run build` con código 0.

`CulqiCheckoutTest` (32): ownership, importe/créditos solo del servidor, campos prohibidos (PAN/CVV/importe) rechazados, token de otro entorno, 3DS (inicio, completar, cancelar, ventana vencida, token distinto), órdenes (teléfono, creación, webhook `order.status.changed`, pagada/expirada/cancelada, desajustes), polling de solo lectura, reembolso total con créditos gastados, errores de red ⇒ incierto (nunca “fallido”). `CulqiSecretsTest` (6): salida del comando sin secreto, `--reveal` interactivo+confirmado+auditado, scrubber de Sentry (evento, trazas y spans).

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
- Órdenes (PagoEfectivo/billeteras): creación, estados `created/pending/expired/deleted` y mapeo a MOVA verificados en sandbox; el estado `paid` **solo está probado con dobles** (nunca observado). Suscripciones: ignoradas. No hay `closeRejectedPayment` para Culqi: un `review` se resuelve con `php artisan culqi:reconcile --order=<id>` o por decisión humana.
- Cifrado AES/RSA implementado y probado localmente (ida y vuelta con phpseclib v4), **no validado por Culqi** (§3).
- El MCP con `CULQI_MCP_ALLOW_LIVE_READ=true` leería datos live desde el `.env` local: las llaves live deben vivir solo en producción, no en equipos de desarrollo.

## 9. Checkout en MOVA (Culqi Custom Checkout)

- **Qué se usa:** `CulqiCheckout` en modo *Custom* (`https://js.culqi.com/checkout-js`) y `Culqi3DS` (`https://3ds.culqi.com`). **No** Checkout V4 ni formularios propios de tarjeta: el PAN/CVV se teclean dentro del iframe de Culqi y a MOVA solo llega un `token` (`tkn_`/`ype_`).
- **Qué ve el navegador:** únicamente la llave **pública**, el importe y la descripción calculados por el servidor. El controlador rechaza (`prohibited`) cualquier campo `amount`, `credits`, `email`, `card_number`, `cvv`… en la petición de pago.
- **Rutas** (`routes/web.php`, rol profesor): `checkout.store/show/pay/status/refresh`, `checkout.three-ds` (+`.cancel`), `checkout.order` (throttle 10/min). `status` es de solo lectura; `refresh` reconcilia bajo demanda con debounce.
- **Medios** (`CULQI_METHODS`): `card` (tarjeta + 3DS), `yape` (token `ype_`), `order` (efectivo, banca móvil, billeteras y agentes vía PagoEfectivo). Los no habilitados no se muestran.
- **Estados mostrados al usuario:** `idle | pending | uncertain | review | approved | failed`. Un error de red nunca se presenta como “fallido”: se muestra “verificando” y se reconcilia.
- **Evidencia en navegador (sandbox real, 2026-10-22)**: ver `payments-migration-mp-to-culqi.md` §8.

## 10. Seguridad del webhook y de los secretos

1. **El secreto va en la ruta** porque Culqi no firma: ≥ 32 caracteres, comparado con `hash_equals`; el cuerpo se limita a 256 KB; solo se guarda un payload mínimo (sin PII del pagador).
2. **Nada se acredita por el webhook**: el job vuelve a leer `GET /v2/charges|orders/{id}` con la llave secreta; un evento falso solo encola trabajo.
3. **Redacción:** `SentryScrubber` (registrado como callable-array para que `config:cache` funcione) sustituye `/webhooks/culqi/<secreto>` por `/webhooks/culqi/[REDACTADO]` en URL, transacción, mensaje, breadcrumbs y excepciones. **Pendiente de infraestructura:** excluir esa ruta de los logs de acceso/ingress de Azure (no se tocó Azure).
4. **`culqi:webhook-url`** muestra la URL enmascarada y no revela ni la longitud; `--reveal` exige terminal interactiva + confirmación y deja una línea de auditoría sin el secreto. Un agente/CI no puede obtenerlo.
5. **Rotación / exposición:** generar un secreto nuevo (`openssl rand -hex 24`), cargarlo como secreto del entorno, actualizar la URL de cada webhook en CulqiPanel y reiniciar; mientras no haya rotación con dos secretos simultáneos, hacerlo en una ventana corta (los eventos perdidos los recoge `culqi:reconcile`, que corre cada 5 min). Si el secreto apareció en un chat/log: tratarlo como comprometido y rotar.
6. **Llaves:** nunca en el repo ni en `.env` comentadas; `culqi:diagnose` valida presencia/formato/modo sin imprimirlas. Las `sk_live_` solo existen en el gestor de secretos de producción.

## 11. Diagnóstico RSA `crypto_error` (cerrado sin más experimentos)

- **Hechos:** el par entregado es de 1024 bits con id UUID; con él Culqi responde `crypto_error` en **tokens y órdenes** (los cargos con token sin cifrar funcionan). Se probaron 4 variantes de OAEP/IV/tag, sin éxito. La documentación indica que las llaves RSA son por entorno, se muestran una sola vez, se asocian a endpoints concretos en su creación y vencen en ≤ 1 año.
- **Distinción:** no es un fallo de TLS ni de tokenización (sin cifrado, ambos funcionan); apunta al **par RSA** (entorno equivocado, endpoints no seleccionados, par de otra integración o caducado) o a una exigencia de formato no documentada.
- **Decisión:** el cifrado es opcional y queda **apagado** (`CULQI_ENCRYPT_PAYLOADS=false`); no bloquea ninguna operación. No se intenta más por código.
- **Acción del propietario (única):** inventario manual en CulqiPanel → Desarrollo → RSA Keys del entorno de integración: recrear el par, marcar endpoints (tokens, orders, charges), copiar ID y llave (una vez) a los secretos, y reprobar con `culqi:sandbox-smoke`. No imprimir las llaves, ni siquiera comentadas.

## 12. Alcance PCI (no asumido)

Culqi indica que usar la API de tokens directamente exige SAQ-D. Con **Custom Checkout** los datos de tarjeta se capturan en el iframe/dominio de Culqi y nunca pasan por el servidor ni el JS de MOVA (verificado: el backend rechaza campos de tarjeta y el JS solo maneja el token). Eso apunta a un cuestionario más ligero, **pero la determinación es de Culqi/el adquirente**: pedir confirmación por escrito antes de producción.

## 13. Build de Vite (`vite: not found`)

Diagnóstico con evidencia: `rm -rf node_modules && npm ci` en el contenedor (Node 22) termina con código 0 en ~38 s, deja `node_modules/.bin/vite` y `npm run build` termina con 0. El fallo anterior **no se reproduce en limpio**; la causa observada fue una instalación interrumpida (`node_modules` parcial, carpeta `vite` vacía) y que el comando original encadenaba `npm ci … | tail && npm run build`, donde el pipe ocultaba el código de salida de `npm ci`. Regla: ejecutar `npm ci` y `npm run build` por separado y comprobar `$?`/`set -o pipefail`; no hay `NODE_ENV=production` ni `omit` que excluya devDependencies.
