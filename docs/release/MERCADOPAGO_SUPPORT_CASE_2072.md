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

## Evidencia interna (no enviar)

- Intento cerrado en MOVA como rechazado (`PaymentOrder#1 failed`, alerta cerrada); sin movimientos en el ledger; ver `INCIDENT_2026-10-06_yape_live_checkout.md`.
- No reintentar con otro importe por conjetura hasta tener la respuesta.
