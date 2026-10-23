# Culqi en MOVA — integración, webhooks y MCP local

> Catálogo actualizado 2026-10-22: Inicio 1000, Esencial 1980, Impulso 2940, Avance 3880, Pro 5760 centavos PEN.
> Créditos 5/10/15/20/30; descuentos permanentes 0/1/2/3/4%. Precio y créditos siguen congelados en cada recarga.
> La regresión de Pro nuevo (57.60) y antiguo (60.00) comprueba depósito/reversión únicos, sin recálculo desde el catálogo.
> [Detalle comercial, estimaciones de comisión y riesgos heredados](culqi-commercial-review.md). No implica activación live.

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
`CULQI_METHODS` (`card`; subconjunto de `card,yape,order`) · `CULQI_ORDER_TTL_MINUTES` (1440) · `CULQI_THREE_DS_WINDOW_MINUTES` (8) · `CULQI_CHECKOUT_ALLOWLIST` ·
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
| 7 | Órdenes | Exigen celular peruano de 9 dígitos en el perfil del profesor (si falta, el checkout lo indica antes de llamar a Culqi). `CULQI_ORDER_TTL_MINUTES` (1440) y `CULQI_THREE_DS_WINDOW_MINUTES` (8) ajustan vigencias. |

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

## 7. Pruebas (ver también §14.6: carreras multi-proceso)

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
- **Es opcional para el checkout elegido (evidencia oficial, 2026-10-22):** la documentación de Custom Checkout (<https://docs.culqi.com/es/documentacion/checkout/checkout-custom>) solo menciona la **llave pública** y no habla de RSA ni de cifrado; la de llaves (<https://docs.culqi.com/es/documentacion/pagos-online/llaves>) describe pública y secreta y solo enlaza a «Llaves RSA»; los SDK oficiales (PHP/Python) activan el cifrado únicamente si el comercio configura `rsa_id` y `rsa_public_key`. No encontré en la documentación pública ninguna operación que lo exija. **Condición de activación:** si CulqiPanel o Culqi exigieran RSA para algún método live, ese método no se habilita hasta resolverlo.
- **Decisión:** el cifrado es opcional y queda **apagado** (`CULQI_ENCRYPT_PAYLOADS=false`); no bloquea ninguna operación. No se intenta más por código.
- **Acción del propietario (única):** inventario manual en CulqiPanel → Desarrollo → RSA Keys del entorno de integración: recrear el par, marcar endpoints (tokens, orders, charges), copiar ID y llave (una vez) a los secretos, y reprobar con `culqi:sandbox-smoke`. No imprimir las llaves, ni siquiera comentadas.

## 12. Alcance PCI (no asumido)

Culqi indica que usar la API de tokens directamente exige SAQ-D. Con **Custom Checkout** los datos de tarjeta se capturan en el iframe/dominio de Culqi y nunca pasan por el servidor ni el JS de MOVA (verificado: el backend rechaza campos de tarjeta y el JS solo maneja el token). Eso apunta a un cuestionario más ligero, **pero la determinación es de Culqi/el adquirente**: pedir confirmación por escrito antes de producción.

## 13. Build de Vite (`vite: not found`)

Diagnóstico con evidencia: `rm -rf node_modules && npm ci` en el contenedor (Node 22) termina con código 0 en ~38 s, deja `node_modules/.bin/vite` y `npm run build` termina con 0. El fallo anterior **no se reproduce en limpio**; la causa observada fue una instalación interrumpida (`node_modules` parcial, carpeta `vite` vacía) y que el comando original encadenaba `npm ci … | tail && npm run build`, donde el pipe ocultaba el código de salida de `npm ci`. Regla: ejecutar `npm ci` y `npm run build` por separado y comprobar `$?`/`set -o pipefail`; no hay `NODE_ENV=production` ni `omit` que excluya devDependencies.

## 14. Activación en producción: estado real, bloqueos y checklist (2026-10-22)

> **PREPARADO PARA PRODUCCIÓN ≠ OPERATIVO EN PRODUCCIÓN.** Hoy Culqi está **desplegado e inactivo** en Azure (imagen `a32a1f0`; `PAYMENT_PROVIDER=mercadopago`, cero variables `CULQI_*`) y **no está preparado para cobros live**: hay bloqueos de términos y de credenciales (abajo). Nada de lo siguiente se ha activado.

### 14.1 Bloqueos que impiden activar cobros reales

| # | Bloqueo | Evidencia | Quién lo resuelve |
|---|---|---|---|
| B-1 | **Azure for Students no es para uso comercial.** La FAQ oficial responde a «¿Puedo usar Azure for Students para producción o solo desarrollo?»: ofrece los productos «*expressly for the support of education or teaching, noncommercial research, or efforts to design, develop, test, and demonstrate software applications for these purposes*». MOVA cobra a profesores reales: operar cobros live en esta suscripción es un uso comercial fuera de ese alcance. Términos: <https://azure.microsoft.com/offers/ms-azr-0170p/> · FAQ: <https://learn.microsoft.com/en-us/azure/education-hub/faq>. El crédito (US$ 100/12 meses) además tiene límite de gasto: al agotarse o a los 12 meses los recursos se **decomisionan** salvo que se pase a pago por uso (90 días de plazo). | Microsoft Learn FAQ (consultada 2026-10-22). No se pudo leer el texto legal completo de la oferta: confirmarlo con Microsoft | **Titular**: decidir alojamiento compatible (§14.8) antes de cobrar. Regla del proyecto: no activar cobros reales en esa suscripción |
| B-2 | **No existen llaves live** en ningún sitio. `.env` local: solo `pk_test_/sk_test_`, RSA (id + llave, dan `crypto_error`), secreto de webhook (48 caracteres) y `CULQI_WEBHOOKS_ENABLED=false`. Azure: ninguna variable `CULQI_*` en web/worker/scheduler y ningún secreto de Culqi en `kv-movap-192f01` (10 secretos, todos de otras integraciones) | `az containerapp show` (solo nombres) + `az keyvault secret list` (solo nombres) | **Titular**: obtenerlas en CulqiPanel de **producción** (§14.3) |
| B-3 | **Comercio Culqi aprobado y medios live habilitados: sin verificar.** No hay acceso a CulqiPanel desde las herramientas (el navegador integrado no tiene permiso para `*.culqi.com`) y la documentación pública no lo expone | — | **Titular** |
| B-4 | **Webhooks no registrados** en CulqiPanel (ningún evento real ha llegado al backend público; el endpoint responde 404 porque `CULQI_WEBHOOKS_ENABLED` no existe en Azure) | 2 avisos «[Culqi/Webhook] … descartada» en Log Analytics, ambos de pruebas propias | **Titular** (registro manual) |
| B-5 | **Conectividad Azure → Culqi sin probar** (se descartó crear un job efímero para no añadir recursos). Inferido, no verificado: el entorno ya llama a internet (Mercado Pago, Cloudinary, JaaS, SMTP) y no hay reglas de salida restrictivas | — | Probar tras tener llaves, p. ej. `php artisan culqi:diagnose --remote` en un contenedor existente |
| B-6 | **PCI/contrato**: con Custom Checkout los datos de tarjeta no pasan por MOVA (el backend rechaza `card_number`/`cvv` y el JS solo maneja el token), pero **no hay evidencia de cumplimiento PCI DSS** ni confirmación escrita del cuestionario aplicable (SAQ) por parte de Culqi | `CulqiCheckoutController::FORBIDDEN_FIELDS` + pruebas | **Titular** con Culqi |
| B-7 | **Métodos sin validar de punta a punta**: Yape (solo el token se creó en sandbox) y órdenes (el estado `paid` nunca se observó; solo dobles) | `payments-migration-mp-to-culqi.md` §8 | Sandbox/live controlado |

### 14.2 Credenciales y variables (nombres reales del proyecto; sin valores)

| Variable | Dónde | Estado |
|---|---|---|
| `PAYMENT_PROVIDER` (`fake`/`mercadopago`/`culqi`) | web, worker, scheduler (misma en las tres: la lee el scheduler para agendar `culqi:reconcile`) | Azure: `mercadopago` — **no cambiar** hasta superar §14.9 |
| `PAYMENTS_ENABLED` | idem | Azure: `true` (Mercado Pago live acotado) |
| `CULQI_ENV` (`test`/`live`) | idem | Azure: no existe · local: `test` |
| `CULQI_PUBLIC_KEY` | web (se entrega al navegador) | Azure: no existe · local: test |
| `CULQI_SECRET_KEY` (alias antiguo `CULQI_PRIVATE_KEY`) | **secreto** en Key Vault, `secretRef` en web, worker y scheduler (los tres hablan con la API) | Azure: no existe · local: test |
| `CULQI_WEBHOOK_SECRET` (≥ 32 caracteres) | **secreto**; solo web lo recibe, pero el job/comando lo lee en cualquier rol | Azure: no existe · local: definido |
| `CULQI_WEBHOOKS_ENABLED` | web (y worker si procesa el job) | Azure: no existe (⇒ desactivado) · local: `false` |
| `CULQI_METHODS` (`card`,`yape`,`order`) | web | Por defecto `card`. Añadir `yape`/`order` solo tras validarlos |
| `CULQI_ENCRYPT_PAYLOADS`, `CULQI_RSA_ID`, `CULQI_RSA_PUBLIC_KEY` | — | **Desactivado** (§11) |
| `CULQI_ORDER_TTL_MINUTES`, `CULQI_THREE_DS_WINDOW_MINUTES`, `CULQI_CHECKOUT_ALLOWLIST` | web | Opcionales (defaults en `config/payments.php`) |

**Separación test/live:** `CulqiConfig` aborta el arranque con `PAYMENTS_ENABLED=true` si `CULQI_ENV` no coincide con el prefijo de las llaves, si hay llaves mezcladas o si hay `*_live_` fuera de `APP_ENV=production`; `applyTruth` además rechaza cargos con prefijo de otro entorno. **Nunca cargar llaves test en producción ni llaves live en staging/local.** Cargar los secretos con `az keyvault secret set` + `secretRef` en la app (mismo patrón que `mercadopago-access-token`), sin imprimirlos, y crear una revisión por rol (`az containerapp update --image …` no basta: hay que añadir las variables/secretRef).

### 14.3 CulqiPanel: lo que debe hacer el titular (no hay API documentada para configurarlo)

| # | Producto | Recurso | Acción | Resultado esperado | Entorno |
|---|---|---|---|---|---|
| 1 | CulqiPanel (producción) | Desarrollo → API Keys | Copiar la llave pública y mostrar/copiar la secreta **directamente** a Key Vault / variables (sin pegarlas en chats) | `pk_live_…` y `sk_live_…` cargadas como secretos | producción |
| 2 | CulqiPanel (producción) | Comercio / medios de pago | Confirmar que el comercio está **aprobado** y qué medios están habilitados (tarjeta, Yape, PagoEfectivo/billeteras/banca móvil/agentes) | Lista de medios live | producción |
| 3 | CulqiPanel (integración) | Eventos → Webhook | Crear **un webhook por evento**: `charge.creation.succeeded`, `charge.creation.failed`, `refund.creation.succeeded`, `refund.creation.failed`, `order.status.changed` (IDs del OpenAPI oficial `apiculqi.yaml`; la página pública de webhooks no los lista) | 5 webhooks apuntando a `https://movaeduca.me/api/webhooks/culqi/<CULQI_WEBHOOK_SECRET>` | integración (llaves test) **primero contra un entorno que no sea producción** |
| 4 | CulqiPanel (producción) | Eventos → Webhook | Repetir los 5 con la URL de producción solo cuando haya llaves live y `CULQI_WEBHOOKS_ENABLED=true` | idem | producción |
| 5 | CulqiPanel | Desarrollo → RSA Keys | Opcional: ver §11. No bloquea tarjeta, Yape ni órdenes | — | — |

URL **HTTPS pública**; autenticación: **secreto en la ruta** (Culqi no documenta firma ni cabecera de autenticación ni lista de IP de origen; verificado en la documentación oficial 2026-10-22). Obtén la URL con `php artisan culqi:webhook-url --reveal` en tu terminal; el informe y los logs no la muestran. Tras registrar, validar la recepción real con un evento de sandbox (un cargo de prueba de tarjeta test) y comprobar en `payment_webhooks` una fila `culqi` procesada y en `payment_orders` el estado esperado.

### 14.4 Seguridad del secreto del webhook en cada capa (verificado)

| Capa | Estado |
|---|---|
| Logs de acceso de Caddy | No hay directiva `log` en `docker/Caddyfile`: no se registran URLs |
| Logs HTTP de ingreso de Container Apps | El entorno solo envía a Log Analytics la consola y el sistema; no hay tabla de acceso HTTP; no hay Application Insights |
| Logs de la aplicación | El controlador no registra la URL; Log Analytics (30 días) solo contiene dos avisos «[Culqi/Webhook] … descartada» sin secreto |
| Sentry | `SentryScrubber`: redacta `/webhooks/culqi/{secreto}` en URL, transacción, mensaje, breadcrumbs, excepciones, **trazas y spans** (`CulqiSecretsTest`, 6 pruebas) |
| Documentación / informes | Solo `<CULQI_WEBHOOK_SECRET>` |
| Mecanismo más fuerte | **No hay uno oficial documentado** (sin firma/HMAC); se mantiene el secreto en la ruta + re-consulta de la API antes de acreditar |

Rotación del secreto: nuevo valor (`openssl rand -hex 24`) en Key Vault → revisión nueva → actualizar la URL de los 5 webhooks en CulqiPanel (ventana corta; `culqi:reconcile` cada 5 min recupera lo perdido).

### 14.5 Matriz de métodos de pago (qué está probado de verdad)

| Método | Implementado | A. Mocks/PHPUnit | B. Sandbox real | C. Navegador | Operativo |
|---|---|---|---|---|---|
| Tarjeta | Sí | Sí | **Sí** (aprobada y rechazada) | **Sí** | **Solo sandbox** |
| 3DS | Sí (Culqi3DS, dos pasos) | Sí (inicio, completar, cancelar, ventana vencida, token distinto) | **Sí** (tarjeta `4456 5300 0000 1096`, reto simulado, OTP de prueba) | **Sí** | **Solo sandbox**. Cancelado/fallido/inconcluso se distinguen: cancelado → `abandonThreeDs`; ventana vencida → cierre; segundo reto → fallo |
| Yape | Sí (token `ype_`) | Sí | **Parcial**: solo se creó el token por API | No | **Pendiente de validación** |
| Efectivo/billeteras/banca móvil/agentes (órdenes) | Sí | Sí (incluye `paid`, `expired`, `cancelled`) | **Parcial**: orden creada con CIP/QR y `pending` | **Parcial**: se muestra el pendiente, créditos sin acreditar | **Pendiente**: `state=paid` nunca observado |

### 14.6 Pruebas de concurrencia reales (procesos independientes sobre MySQL)

Nuevo comando QA `mova:qa-payment-race` + `scripts/qa-payment-race.sh` (+ doble HTTP local `scripts/qa/culqi-stub.php`): lanzan N procesos PHP independientes con barrera común contra `mysql_qa`/`mova_qa` (falla cerrado fuera de local/testing; no toca Culqi real).

| Operación | Qué hace | Resultado (8 y 12 procesos; 3 repeticiones) |
|---|---|---|
| `reconcile-paid` | N procesos aplican la misma verdad «cargo pagado» (webhook + barrido + respuesta simultáneos) | **1 depósito**, saldo = 5, recarga `approved`, 0 errores |
| `reconcile-refund` | recarga aprobada; N procesos aplican la misma devolución total | **1 reversión**, saldo 0, recarga `reversed` |
| `charge-attempt` | N procesos (doble clic) piden cobrar el mismo intento contra el doble HTTP | **exactamente 1 `POST /v2/charges`**, 1 depósito |

La primera ejecución encontró un defecto real (ver §14.7) y se corrigió antes de la serie verde. Uso: `bash scripts/qa-payment-race.sh <operación> <procesos>` dentro de `php_qa` (APP_ENV=testing).

### 14.7 Defecto encontrado y corregido: el aviso por correo podía revertir una acreditación

`RechargeApprovalService::credit()`/`reverse()` enviaban el aviso al profesor con `->notify()` **dentro de la transacción exterior** de `applyTruth`. Con la cola `sync` (la del web en producción) un fallo SMTP lanzaba `MailDeliveryException` y **revertía la acreditación de un pago ya confirmado** (se recuperaba después por el barrido, con retraso). Corrección mínima: `notifyAfterCommit()` (`DB::afterCommit` + `try/catch` + `report()`): el aviso solo sale tras el commit y su fallo nunca afecta al ledger. `changed` sigue garantizando un único aviso. Pruebas: `RechargeNotificationResilienceTest` (3; las dos primeras fallan sin el arreglo). **Esta corrección está en el repositorio pero NO desplegada en Azure** hasta que el titular decida el siguiente despliegue.

### 14.8 Alojamiento compatible con uso comercial (si se confirma B-1)

Sin migrar nada automáticamente. Precios de lista (Azure Retail Prices API, 2026-10-22, mexicocentral); solo estimaciones:

| Opción | US$/mes aprox. | Notas |
|---|---|---|
| Mismo diseño en una suscripción **de pago por uso** (3 Container Apps + ACR Basic + IP + MySQL B1ms 32 GiB) | ≈ 44–52 | MySQL deja de ser gratuito (≈ 13,6 + 4,0). Conserva la optimización de OPcache CLI |
| Lo anterior con la optimización C-1 de costos (worker+scheduler en un contenedor; en estudio, ver la documentación de costos de Azure) | ≈ 38–46 | Requiere prueba |
| 1 VM `B2ats v2` (≈ 7,5) + disco + IP + MySQL gestionado | ≈ 34 | Rediseño de despliegue; `B1s` no está disponible en la suscripción actual |
| Fuera de Azure | no evaluado | Requiere autorización del titular |

### 14.9 Checklist de la Fase 9 (evidencia)

| Condición | Estado | Evidencia |
|---|---|---|
| Comercio Culqi aprobado | ❌ sin verificar | B-3 |
| Métodos de pago live habilitados | ❌ sin verificar | B-3 |
| Credenciales live correctas | ❌ no existen | B-2 |
| Webhooks HTTPS registrados y validados | ❌ | B-4 |
| Secretos protegidos de logs | ✅ | §14.4 |
| Checkout funcionando | ✅ solo sandbox (tarjeta/3DS) | §14.5 |
| 3DS preparado | ✅ sandbox | §14.5 |
| Confirmación de pagos verificada (API como verdad) | ✅ | `applyTruth` + `CulqiCheckoutTest` + `CulqiPaymentTest` |
| Acreditación idempotente | ✅ | clave única + 3 carreras multi-proceso GREEN |
| Conciliación disponible | ✅ | `culqi:reconcile` (scheduler 5 min cuando el proveedor es Culqi) |
| Trabajadores de colas funcionando | ✅ (Mercado Pago hoy) | worker `Healthy`, `WorkerHeartbeatJob` cada minuto |
| Rollback documentado | ✅ | §14.10 |
| Monitoreo de errores financieros | ✅ parcial | alertas `payment_review`/`reconciliation_failure`; Sentry con redacción |
| Capacidad de investigar y reembolsar | ✅ | `payments-migration-mp-to-culqi.md` §6; el reembolso se hace en CulqiPanel |
| PCI y condiciones contractuales | ❌ sin evidencia | B-6 |
| Compatibilidad con Azure for Students | ❌ **incompatible con uso comercial** | B-1 |

**Conclusión: NO está preparado para activar cobros live.** La activación (cambio de `PAYMENT_PROVIDER`) exige aprobación explícita del titular una vez resueltos B-1…B-7. Una prueba live (S/ 1 + reembolso) también exige su autorización específica de operación e importe.

### 14.10 Pasos de activación (futuros) y rollback

1. Resolver B-1 (alojamiento/suscripción), B-2 (llaves live en Key Vault), B-3, B-6.
2. Desplegar la imagen vigente (incluye la corrección §14.7) y cargar `CULQI_ENV=live`, `CULQI_PUBLIC_KEY`, `CULQI_SECRET_KEY` (secretRef), `CULQI_WEBHOOK_SECRET` (secretRef), `CULQI_WEBHOOKS_ENABLED=true`, `CULQI_METHODS=card`, `CULQI_CHECKOUT_ALLOWLIST` acotada, **sin** cambiar aún `PAYMENT_PROVIDER`.
3. Registrar los 5 webhooks de producción (§14.3) y validar recepción con `culqi:diagnose`.
4. Prueba live única autorizada (importe y operación aprobados por el titular) + reembolso; verificar un depósito y una reversión.
5. Cambiar `PAYMENT_PROVIDER=culqi` en web, worker y scheduler (revisión nueva de cada uno); comprobar que el checkout usa Culqi, que los pagos antiguos siguen con su proveedor y que `culqi:reconcile` está agendado.

**Rollback (nuevas recargas):** `PAYMENT_PROVIDER=mercadopago` en los tres roles; mantener `CULQI_*` y el webhook activos hasta cerrar las recargas/órdenes ya creadas con `provider=culqi` (se completan por `culqi:reconcile`); no borrar órdenes ni revertir asientos; no cambiar el proveedor de transacciones existentes. El rollback no duplica créditos: la acreditación depende de la clave `recharge:{id}:deposit`, no del proveedor activo.

### 14.11 Errores frecuentes y reembolsos

| Síntoma | Causa probable | Acción |
|---|---|---|
| Webhook responde 404 | `CULQI_WEBHOOKS_ENABLED` ausente/false o secreto distinto | Revisar variable y URL registrada (sin imprimir el secreto) |
| Webhook 401 | Secreto de la ruta incorrecto | Rotar y actualizar la URL en CulqiPanel |
| Recarga `pending` con cargo ya cobrado | Respuesta perdida/timeout | `php artisan culqi:reconcile --order=<id>` |
| `review_reason` no nulo | Importe/metadata/entorno no coinciden, devolución parcial, disputa | Investigar con `culqi_get_charge` (MCP) y decidir manualmente; nunca acreditar a mano |
| `crypto_error` | Cifrado RSA activado con un par inválido | Mantener `CULQI_ENCRYPT_PAYLOADS=false` |
| Orden `pending` sin cambios | El usuario no pagó el CIP/QR | Vence sola (`CULQI_ORDER_TTL_MINUTES`) |

**Reembolso:** solo desde CulqiPanel (o API de Culqi) sobre `chr_…`; el evento `refund.creation.succeeded` + re-consulta aplican `reverse()` si es **total** (puede dejar saldo negativo por regla de negocio); parcial/disputa → revisión humana. No existe reembolso desde MOVA ni aprobación manual de recargas Culqi.


## 15. Mercado Pago retirado para compras nuevas; Culqi único proveedor (2026-10-22)

> Estado del código: **hecho y probado**. Estado en producción: ver `release/DEPLOY_HISTORY.md`. Culqi **no** acepta todavía pagos reales (bloqueos de §14.1): mientras tanto MOVA **no acepta compras nuevas** (no vuelve a Mercado Pago ni a `fake`).

### 15.1 Regla

| Concepto | Comportamiento |
|---|---|
| Compras nuevas | **Solo Culqi.** `CheckoutProvider::active()` devuelve `culqi` únicamente si pagos están habilitados, `PAYMENT_PROVIDER=culqi`, la configuración es coherente (llaves/modo/hosts) y el usuario pasa la allowlist; en cualquier otro caso devuelve `null` y la pantalla de créditos muestra «Las recargas se habilitarán próximamente». No existe respaldo automático hacia Mercado Pago ni hacia `fake` |
| Bandera de retiro | `MERCADOPAGO_NEW_PAYMENTS_ENABLED` (`config('payments.mercadopago.new_payments_enabled')`), **default `false` = retirado**. Con `false`: `store` responde 503 y no crea `RechargeRequest`; `show` de una recarga antigua de Mercado Pago redirige a la pantalla de créditos; `pay` responde 410; `MercadoPagoPaymentProvider::createPaymentAttempt()` lanza `RuntimeException` (ningún comando/script/servicio puede crear un cobro nuevo); nada llama a la API. `true` solo como marcha atrás de emergencia (y con `PAYMENT_PROVIDER=mercadopago`) o en pruebas (`phpunit.xml`) |
| Operaciones históricas | **Se conservan.** El webhook `/api/webhooks/mercadopago` depende solo de `MERCADOPAGO_WEBHOOKS_ENABLED` (no del proveedor activo); `ProcessMercadoPagoWebhook`, `MercadoPagoPaymentReconciliationService`, el polling de estado (`status`/`refresh`, que usan `CheckoutProvider::mercadoPagoLegacyProcessing()`), los comandos `mercadopago:*` y devoluciones siguen funcionando; `mercadopago:reconcile` sigue agendado mientras haya proveedor `mercadopago` o webhook de MP habilitado con credenciales (Kernel). Cada pago conserva su `provider`/`payment_method`; nada se migra a Culqi; no se borra ninguna tabla, columna ni registro |
| Credenciales | Las de Mercado Pago se conservan (pagos históricos, webhooks pendientes, devoluciones) hasta cerrar todas las operaciones antiguas |
| Frontend | `Index.vue` ya no ofrece «Pagar con Yape» de Mercado Pago salvo que la bandera esté en `true`; `Checkout.vue` (Mercado Pago) queda inaccesible por redirección; la spec Playwright de Yape de Mercado Pago se omite sin la bandera. **No se tocaron** los textos legales (`Legal/Privacy.vue` menciona a Mercado Pago como procesador): cambiarlos exige versión legal nueva y re-aceptación, decisión del titular; **antes de activar Culqi live hay que citarlo como procesador en la política de privacidad** |

### 15.2 Pruebas

`MercadoPagoRetirementTest` (12): sin proveedor ofrecido con solo Mercado Pago; `store` 503 sin filas ni llamadas HTTP; `show` redirige; `pay` 410 sin `PaymentOrder` ni HTTP; el proveedor se niega desde cualquier entrada; una mala configuración de Culqi **nunca** cae a Mercado Pago ni con la bandera de emergencia; Culqi es el único proveedor cuando está listo; un pago histórico de Mercado Pago se verifica y acredita **exactamente una vez** tras el cambio a Culqi; el barrido sigue agendado con Culqi como proveedor; el webhook de Mercado Pago es independiente del proveedor activo; el barrido y el refresh de Culqi sobreviven a un rollback; un intento histórico en curso muestra su estado en solo lectura; con Culqi activo se exige la configuración histórica de Mercado Pago.

### 15.3 Activación de Culqi como único proveedor (cuando se levanten los bloqueos de §14.1)

1. `PAYMENT_PROVIDER=culqi` y las variables `CULQI_*` de §14.2 en web y scheduler (revisión nueva de cada uno). **No** definir `MERCADOPAGO_NEW_PAYMENTS_ENABLED` (queda `false`).
2. Los pagos antiguos siguen en Mercado Pago; `mercadopago:reconcile` y el webhook de Mercado Pago siguen activos.
3. **Rollback.** (a) *Cerrar compras sin perder la conciliación* (lo seguro y preferible; es el estado de producción hasta que Culqi esté listo): `PAYMENT_PROVIDER=mercadopago` con `MERCADOPAGO_NEW_PAYMENTS_ENABLED=false` — no hay compras nuevas; Mercado Pago histórico y los cargos/órdenes de Culqi ya creados siguen conciliándose (Culqi mientras su webhook siga habilitado y la llave secreta cargada: `CheckoutProvider::culqiLegacyProcessing()`). (b) *Reabrir Mercado Pago*: `MERCADOPAGO_NEW_PAYMENTS_ENABLED=true` + `PAYMENT_PROVIDER=mercadopago`, solo por orden expresa del titular. **No** usar `PAYMENTS_ENABLED=false` como rollback: apaga también la conciliación de lo que está en curso.

### 15.4 Endurecimiento tras la revisión de seguridad financiera (2026-10-22)

Sin críticos ni altos. Corregido: el barrido y el refresh de Culqi sobreviven a un rollback del proveedor (`culqiLegacyProcessing`); con `PAYMENT_PROVIDER=culqi` y el webhook de Mercado Pago habilitado se **exigen** `MERCADOPAGO_EXPECTED_COLLECTOR_ID` y `MERCADOPAGO_WEBHOOK_SECRET` (si no, los controles de cuenta/firma de lo histórico se omitirían en silencio); `show` de un intento histórico en curso o en revisión sigue mostrando su estado en solo lectura (no se pierde el aviso «no vuelvas a pagar»); la bandera se lee con `FILTER_VALIDATE_BOOLEAN` (un `off`/`no` ya no cuenta como verdadero); `mercadopago:sandbox-smoke` se niega con la bandera en `false`; el aviso `MERCADOPAGO_WEBHOOK_QUEUE_SYNC` del health-check usa la misma regla histórica. **Aceptado/pendiente:** `PAYMENTS_ENABLED=false` detiene también la conciliación histórica (no usarlo como interruptor de compras); falta una prueba de concurrencia webhook + refresh simultáneos sobre un pago histórico con la bandera en `false` (la acreditación única ya está cubierta por las carreras multi-proceso y las pruebas existentes).
