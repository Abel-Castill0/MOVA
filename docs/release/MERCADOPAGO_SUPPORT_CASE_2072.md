# Caso para el soporte de Mercado Pago — HTTP 400 / 2072 en Yape (S/ 1)

> **ENVIADO el 2026-10-20** por el asistente Maxwell del Centro de atención de Mercado Pago Developers (autorización del titular). **N.º de ticket: WCS-55456** (estado «Aguardando soporte»). El mensaje enviado fue la versión «habilitación de Yape» de abajo, con Application ID y collector ID incluidos. Texto original de preparación (ya no aplica): no incluye tokens, teléfonos ni OTP.

**Asunto:** Checkout API Payments (Perú) — Yape — HTTP 400 «Invalid value for transaction_amount» (2072) con `transaction_amount=1`

**Mensaje:**

Estoy integrando Mercado Pago Checkout API Payments (`POST /v1/payments`) para cobrar en Perú mediante Yape, con credenciales de producción.

El 6 de octubre de 2026 una solicitud de creación de pago con `transaction_amount=1` (PEN) recibió HTTP 400, `Invalid value for transaction_amount`, código 2072, asociada al identificador de solicitud `x-request-id: d1b5e205-5c79-40a8-87d5-c9222e72cd1d`.

El payload incluía `payment_method_id=yape`, `installments=1`, `token` generado con `mp.yape` (OTP + celular del pagador) y `payer.email`. El listado de métodos de pago (`/v1/payment_methods`) mostraba Yape activo con un mínimo de S/ 1. La búsqueda posterior por `external_reference` no devolvió ningún pago, así que no hubo cobro.

La documentación de Yape no indica un monto mínimo. Pregunto:

1. ¿Qué validación concreta produjo el código 2072 para esta cuenta y esta solicitud?
2. ¿Existe un mínimo efectivo de monto para Yape en producción, o una condición de habilitación de Yape para mi cuenta/aplicación que deba cumplir?
3. ¿Hay algún campo o formato específico que deba corregir (p. ej. entero vs. decimal en `transaction_amount`, datos del pagador)?

Con su respuesta haré una única prueba controlada de pago con reembolso inmediato.

**Datos de la solicitud (para el formulario de soporte):**

- Fecha/hora: 2026-10-06 04:42:43 UTC · País/sitio de la cuenta: Perú (MPE) · Moneda: PEN (no se envía `currency_id`; la fija el sitio de la cuenta).
- Endpoint: `POST /v1/payments` (Checkout API Payments, no Orders) · Modo: producción (live) · `x-request-id`: ver arriba.
- Respuesta: HTTP 400, mensaje `Invalid value for transaction_amount`; el log original no guardó `cause[].code`, el 2072 se deduce del mensaje (hoy se registran los códigos).
- Cuenta: *Application ID* `4497812261072016` · *collector/user ID* `3659471468` (ambos verificados contra el panel y `GET /users/me` el 2026-10-20; no son secretos, pueden pegarse en el formulario).
- Payload (sin token ni datos personales): `transaction_amount: 1` (numérico), `description`, `external_reference` del intento, `capture: true`, `binary_mode: false`, `payer.email`, `payment_method_id: "yape"`, `installments: 1`, `token` (Yape).
- Qué se pide: causa exacta del 2072, mínimo efectivo de Yape para la cuenta, condición de habilitación de Yape y confirmación de que una única prueba controlada (importe indicado por ellos, cuenta del titular, reembolso inmediato) es válida.

## Mensaje listo para pegar: habilitación de Yape (2026-10-19)

> **2026-10-19 (2.º intento):** se comprobó `http://localhost:9222/json/version` y **no hay ningún navegador con `--remote-debugging-port=9222`**; sin eso no se puede conectar un MCP de Playwright por CDP. Además, Chrome 136 y posteriores ignoran ese puerto en el perfil por defecto (hay que usar un `--user-data-dir` propio, sin tus sesiones) y exponerlo da control total del navegador. Recomendación: pegar el mensaje de abajo tú mismo.
>
> **2026-10-19 (3.er intento):** `list_connected_browsers` devuelve vacío: ninguna extensión de Chrome está conectada a Claude. Registrar el MCP `@playwright/mcp --extension` con `claude mcp add` no sirve dentro de esta sesión (las herramientas nuevas solo cargan al reiniciarla) y además exige que el titular instale y apruebe la extensión *Playwright MCP Bridge*. Vías para operar el panel: (1) conectar la extensión de **Claude in Chrome** a esta cuenta y abrir el panel de Mercado Pago ya con sesión; o (2) instalar *Playwright MCP Bridge*, registrar el MCP y reiniciar Claude Code. Aun así, el alta de Yape probablemente no es un interruptor del panel y se resolverá con soporte: el mensaje de abajo sigue vigente.
>
> El panel de Mercado Pago **no se pudo operar desde esta sesión**: no hay un navegador de Chrome conectado a Claude y un navegador propio no tendría tu sesión ni tu 2FA. Estos pasos los hace el titular.

**Dónde:** Mercado Pago → Tus integraciones → (aplicación de producción) → Soporte/Contacto, o el formulario de integradores de Perú. Antes de pedir nada, revisa en el panel si existe una opción de habilitar Yape para la aplicación (Medios de pago / Configuración de la cuenta): si existe, actívala y prueba con la cuenta de prueba (una sola transacción mínima, con reembolso).

**Asunto:** Habilitación de Yape en Checkout API Payments (Perú) — HTTP 400 «Invalid value for transaction_amount» (2072) con S/ 1

**Mensaje:**

Hola. Integro Checkout API Payments (`POST /v1/payments`) en producción (Perú, sitio MPE, moneda PEN). Mi intento de pago con Yape por `transaction_amount=1` del 6 de octubre de 2026, 04:42:43 UTC, recibió HTTP 400 «Invalid value for transaction_amount» (código 2072), `x-request-id: d1b5e205-5c79-40a8-87d5-c9222e72cd1d`. `GET /v1/payment_methods` muestra Yape como `active` con mínimo S/ 1 y máximo S/ 2000, y la búsqueda por `external_reference` no devuelve ningún pago (no hubo cobro).

Necesito saber: (1) si mi cuenta/aplicación de cobro requiere una habilitación específica de Yape y cómo solicitarla; (2) qué validación produjo el 2072; (3) si el payload (`transaction_amount` numérico, `payment_method_id: "yape"`, `installments: 1`, `token` de `mp.yape`, `payer.email`, `external_reference`, `capture: true`) debe cambiar en algún campo. Application ID: 4497812261072016; collector/user ID: 3659471468. Haré una única prueba controlada con reembolso inmediato en cuanto me confirmen.

## Antes de enviar (checklist del titular)

1. Abrir el panel de Mercado Pago Developers con la cuenta titular y verificar que *Application ID* `4497812261072016` y *collector ID* `3659471468` (ya incluidos en el mensaje) corresponden a la aplicación que envía el caso.
2. No adjuntar tokens, OTP, teléfonos ni el *access token*.
3. Después de enviar, registrar en el Ledger la fecha y el número de caso; **no** reintentar cobros live hasta tener respuesta.

## Hipótesis del 2072 (revisado 2026-10-19)

**Descartada: monto mínimo.** La documentación oficial de Mercado Pago Perú y `GET /v1/payment_methods` indican para Yape un mínimo de **S/ 1,00**, y el intento fue de S/ 1,00. No hay evidencia de un mínimo mayor (la cifra «≈15 para tarjetas» no tiene fuente y no aplica a Yape). No se probarán S/ 5, 10 y 20 por esta hipótesis.

**Causas que siguen abiertas (ninguna confirmada; solo soporte puede decidir):**

| # | Hipótesis | A favor / en contra | Cómo se aclara |
|---|---|---|---|
| a | **Cuenta de cobro no habilitada para Yape** (aplicación o cuenta sin la habilitación de Yape pese a figurar `active` en `payment_methods`) | `payment_methods` lista Yape activo, pero ese listado es del sitio, no una garantía por cuenta/aplicación | Pregunta 2 del mensaje a soporte (condición de habilitación) |
| b | **Payload no conforme al endpoint de Yape** (p. ej. `transaction_amount` entero `1` vs decimal `1.00`, campos de `payer`, `installments`, vínculo token–monto) | El payload coincide con el ejemplo de «Crear pago»; el token Yape (`mp.yape`, OTP + celular) se genera en el navegador y podría llevar el monto o el pagador asociados | Pregunta 3 del mensaje (campo o formato específico); comparar con el payload de referencia que entregue soporte |
| c | **Restricción de moneda/sitio** (el sitio `MPE` fija PEN; una discrepancia de moneda del token, de la cuenta o de la aplicación) | No se envía `currency_id`; la moneda la fija el sitio | Pregunta 1 (qué validación produjo el 2072) |

**Siguiente paso:** enviar el caso (acción del titular). Cualquier prueba live adicional —incluida una con otro importe— requiere respuesta de soporte y autorización expresa del titular (`AGENTS.md`: una sola transacción live, mínima, con reembolso). **Sin cambios en el código de pago.**

## Evidencia interna (no enviar)

- Intento cerrado en MOVA como rechazado (`PaymentOrder#1 failed`, alerta cerrada); sin movimientos en el ledger; ver `INCIDENT_2026-10-06_yape_live_checkout.md`.
- No reintentar con otro importe por conjetura hasta tener la respuesta.

## Ronda de cierre (2026-10-19, 4.ª sesión)

- **Verificación de Yape en el panel: no realizada.** `list_connected_browsers` devuelve vacío y no hay herramientas de Playwright en la sesión; no se pudo abrir `mercadopago.com.pe` con la sesión del titular. Estado de Yape en el panel: **desconocido**. Acción del titular: revisar Tus integraciones / Medios de pago; si no existe un interruptor, enviar el mensaje de «habilitación de Yape» de arriba.
- **Prueba con S/ 5 o S/ 10: no ejecutada.** Es un cobro live (no hay sandbox para Yape) y este caso exige respuesta de soporte y autorización del titular antes de cualquier otro intento; el monto ya fue descartado como causa (mínimo oficial S/ 1). Sin resultados nuevos; la causa raíz más probable sigue siendo la habilitación de la cuenta (hipótesis a), sin confirmar.
- El mensaje de soporte está listo y **no se ha enviado**.

## Auditoría del flujo OTP/token Yape (2026-10-19, 5.ª sesión)

**Conclusión: el código SÍ implementa el flujo con OTP; la falta de token Yape NO es la causa del 2072.**

- Frontend: `resources/js/Pages/Teacher/Credits/Checkout.vue` captura celular + OTP de 6 dígitos y `resources/js/lib/mercadoPagoYape.js` llama a `mp.yape({ otp, phoneNumber }).create()`; solo el token llega al backend (`YapePaymentInstrument`). Teléfono/OTP no se persisten.
- Backend: `MercadoPagoPaymentProvider::createPaymentAttempt()` rechaza (fail closed) si no hay instrumento tokenizado y arma `token`, `payment_method_id: "yape"`, `installments: 1`, `payer.email`, `transaction_amount` numérico, `capture`, `binary_mode`, `external_reference`.
- Documentación oficial (Checkout API Payments → Yape, consultada hoy): campos obligatorios `token`, `transaction_amount`, `installments=1`, `payment_method_id=yape`, `payer.email`. **`payer.phone.number` NO figura en la tabla**; el celular solo se usa para generar el token. La premisa de que sea obligatorio no se confirma. El payload de MOVA coincide con el ejemplo oficial. **Sin cambios de código.**
- Hipótesis nueva (d), verificable sin cobrar: **public key y access token de aplicaciones distintas.** El panel muestra 3 aplicaciones Checkout API (`MOVA Produccion Payments`, `MOVA Recarga de Creditos Payments`, `MOVA Recarga de Creditos`). El token Yape se emite con la public key de una aplicación y el pago se crea con el access token de otra: un desajuste podría producir un 400. Comprobar que `MERCADOPAGO_PUBLIC_KEY` y `MERCADOPAGO_ACCESS_TOKEN` de producción pertenezcan a la **misma** aplicación (solo comparar el origen en el panel, sin imprimir valores). Añadir esta pregunta al mensaje a soporte.
- El panel no tiene interruptor «Habilitar Yape»; la habilitación se resuelve con soporte (mensaje arriba).

## Verificación del panel (2026-10-20, 6.ª sesión)

- Navegador integrado con la sesión del titular (tras verificación de cuenta por SMS/WhatsApp). Aplicación `MOVA Produccion Payments` (Checkout API → API Pagos), estado **«Etapa 2 de 6 — Prueba tu integración»**; notificaciones (Webhooks/IPN) sin configurar en el panel.
- **Yape no aparece como opción habilitable** en el detalle de la aplicación ni en su menú: no hay interruptor. Estado de Yape en el panel: no configurable por el titular → la habilitación se pide a soporte (mensaje de arriba, sin enviar).
- Código: el flujo OTP/token (`mp.yape().create()` + `payment_method_id: yape`) ya está implementado; no hay endpoint `/platforms/pci/yape` que añadir (ese es el flujo de plataformas, no de Checkout API). Sin cambios de código.
- Hipótesis (d) (public key y access token de aplicaciones distintas): **sin verificar**. El `.env` local tiene claves TEST; la clave de producción vive en Azure y la comparación con el panel requería leer credenciales en la página, lo que se denegó. Pendiente: comparar en el panel y en Azure los últimos caracteres de la public key.
- Prueba sandbox de Yape (`111111111` / `123456`): no ejecutada; requiere credenciales TEST de la aplicación de prueba y no se hizo ningún cobro live.

### Hipótesis (d), comprobación parcial (2026-10-20)

- Public key de producción del panel (`MOVA Produccion Payments`, app 4497812261072016) termina en `2df813`; `movap-web` (Azure, `MERCADOPAGO_APPLICATION_ID=4497812261072016`, `EXPECTED_LIVE_MODE=true`) tiene una public key `APP_USR-…2df813`. **Coinciden**: el token Yape se emite con la public key de la aplicación correcta.
- Pendiente: confirmar que el access token también es de esa aplicación (no se leyó; comprobable con `GET /users/me`/diagnóstico sin imprimir valores). El stack `mova-*` (staging) usa claves TEST y la app 6583217782927097.
- **Access token verificado (2026-10-20, sin imprimirlo):** el secreto `mercadopago-access-token` de `movap-web` es `APP_USR` con Application ID **4497812261072016** y user ID **3659471468** (los del panel) y `GET /users/me` responde HTTP 200. Public key y access token pertenecen a la **misma** aplicación de producción → la hipótesis (d) queda **descartada**. Sigue abierta la habilitación de la cuenta/Yape (hipótesis a) y el formato del payload (b): solo soporte puede aclararlas.

## Respuesta técnica al soporte (2026-10-20, 22:17 — WCS-55456)

Tras la respuesta humana de soporte (`SUP_IXEXPERT_02`, 19:22: el 2072 es una validación del request, no una habilitación faltante; pidieron JSON completo del `POST /v1/payments`, body completo del 400 y la serialización exacta de `transaction_amount`), se envió la evidencia (autorización del titular):

- **JSON del request** (reconstruido estrictamente desde `MercadoPagoPaymentProvider::createPaymentAttempt()`, líneas 155–188 + `instrumentPayload()` 1538–1543, con `external_reference` = `recharge:1:attempt:1` y `description` = `Recarga de créditos MOVA: Verificación de cobro`): `transaction_amount: 1` (número JSON), `capture: true`, `binary_mode: false`, `payer.email`, `token` **[REDACTED]**, `payment_method_id: "yape"`, `installments: 1`. Token, email y Bearer ocultados; se informó `X-Idempotency-Key` como UUID propio no reutilizado.
- **Body del 400:** honestamente se informó que el log original solo persistió `message` (`Invalid value for transaction_amount`); no se capturó `cause[].code` (ya corregido en MOVA). Se ofreció el `x-request-id d1b5e205-5c79-40a8-87d5-c9222e72cd1d` para que MP reconstruya el body completo en su lado.
- **Serialización de `transaction_amount`:** siempre número JSON, nunca string; backend almacena centavos enteros (100) y `amountMinorToApiNumber()` hace `round(100/100, 2)` → float PHP que `json_encode` serializa como `1` (verificado localmente con PHP 8.3: `json_encode(["transaction_amount" => 1.0])` ⇒ `{"transaction_amount":1}`).
- **Nota final del mensaje:** se entendió que 2072 es validación del request; se pidió que indiquen el campo específico fallando y el valor esperado para `transaction_amount`, con ejemplo de body válido para Yape desde el collector 3659471468.
- **Estado del ticket tras enviar:** «Pendiente». Confirmación visual en el hilo (22:17). La pestaña se cerró tras el clic (navegación), pero la verificación posterior confirmó el mensaje en el thread.
- **Logs locales:** `storage/logs/laravel.log` local no contiene la evidencia del 6/oct (búsqueda sin coincidencias); los logs del intento viven en producción (Azure), y el body completo nunca se persistió allí (limitación ya corregida).
