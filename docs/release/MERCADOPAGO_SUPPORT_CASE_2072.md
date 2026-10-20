# Caso para el soporte de Mercado Pago — HTTP 400 / 2072 en Yape (S/ 1)

> Texto listo para enviar desde la cuenta del titular (Mercado Pago Developers → Soporte, o el formulario de contacto de integradores de Perú).
> No incluye tokens, teléfonos ni OTP. No se ha enviado.

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
- Cuenta: *Application ID* y *collector/user ID* de la cuenta de producción — **el titular los copia del panel al formulario** (no se versionan aquí).
- Payload (sin token ni datos personales): `transaction_amount: 1` (numérico), `description`, `external_reference` del intento, `capture: true`, `binary_mode: false`, `payer.email`, `payment_method_id: "yape"`, `installments: 1`, `token` (Yape).
- Qué se pide: causa exacta del 2072, mínimo efectivo de Yape para la cuenta, condición de habilitación de Yape y confirmación de que una única prueba controlada (importe indicado por ellos, cuenta del titular, reembolso inmediato) es válida.

## Mensaje listo para pegar: habilitación de Yape (2026-10-19)

> El panel de Mercado Pago **no se pudo operar desde esta sesión**: no hay un navegador de Chrome conectado a Claude y un navegador propio no tendría tu sesión ni tu 2FA. Estos pasos los hace el titular.

**Dónde:** Mercado Pago → Tus integraciones → (aplicación de producción) → Soporte/Contacto, o el formulario de integradores de Perú. Antes de pedir nada, revisa en el panel si existe una opción de habilitar Yape para la aplicación (Medios de pago / Configuración de la cuenta): si existe, actívala y prueba con la cuenta de prueba (una sola transacción mínima, con reembolso).

**Asunto:** Habilitación de Yape en Checkout API Payments (Perú) — HTTP 400 «Invalid value for transaction_amount» (2072) con S/ 1

**Mensaje:**

Hola. Integro Checkout API Payments (`POST /v1/payments`) en producción (Perú, sitio MPE, moneda PEN). Mi intento de pago con Yape por `transaction_amount=1` del 6 de octubre de 2026, 04:42:43 UTC, recibió HTTP 400 «Invalid value for transaction_amount» (código 2072), `x-request-id: d1b5e205-5c79-40a8-87d5-c9222e72cd1d`. `GET /v1/payment_methods` muestra Yape como `active` con mínimo S/ 1 y máximo S/ 2000, y la búsqueda por `external_reference` no devuelve ningún pago (no hubo cobro).

Necesito saber: (1) si mi cuenta/aplicación de cobro requiere una habilitación específica de Yape y cómo solicitarla; (2) qué validación produjo el 2072; (3) si el payload (`transaction_amount` numérico, `payment_method_id: "yape"`, `installments: 1`, `token` de `mp.yape`, `payer.email`, `external_reference`, `capture: true`) debe cambiar en algún campo. Application ID y collector ID: [completar desde el panel]. Haré una única prueba controlada con reembolso inmediato en cuanto me confirmen.

## Antes de enviar (checklist del titular)

1. Abrir el panel de Mercado Pago Developers con la cuenta titular y copiar *Application ID* y *collector ID* al formulario.
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
