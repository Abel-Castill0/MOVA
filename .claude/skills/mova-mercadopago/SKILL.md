---
name: mova-mercadopago
description: MOVA payment invariants for credit recharges, Mercado Pago, PaymentOrder, Yape, cards, 3DS, webhooks, refunds and chargebacks.
---

# MOVA — Mercado Pago

Arquitectura actual: `PaymentProviderContract` (`app/Payment/Contracts/`) implementado por `MercadoPagoPaymentProvider`; un intento de pago es un `PaymentOrder` (no confundir con Orders API — ver comentario en el modelo). Reutiliza este contrato/arquitectura; no crear un segundo camino de integración en paralelo.

## Invariantes

- **Solo Payments API** (`POST /v1/payments`, `GET /v1/payments/{id}`). Nunca Orders API.
- El servidor es la única fuente de verdad sobre montos, moneda y estado — el cliente nunca decide cuánto se cobra ni si un pago fue exitoso.
- Public Key en frontend, Access Token solo en backend. Nunca el Access Token llega al cliente ni a logs.
- Solo instrumentos tokenizados (Card Payment Brick, token de Yape). Nunca PAN ni CVV crudos tocan tu backend ni tus logs.
- Una recarga gestionada por el proveedor nunca se aprueba o rechaza manualmente — el estado sale del `PaymentOrder`/webhook, no de un admin decidiendo a ojo.
- Solo un `PaymentOrder` confirmado (estado final aprobado del proveedor) autoriza acreditar. "Pendiente" o "en revisión" no es éxito ni rechazo — no lo conviertas en ninguno de los dos sin evidencia.
- El crédito al ledger (`credit_transactions`, vía `RechargeApprovalService::credit()`) es exactamente-once por `RechargeRequest`, protegido por `idempotency_key` única — sin importar cuántos `PaymentOrder`/intentos existan.
- `GET` de estado es de solo lectura — nunca muta el `PaymentOrder`.
- `POST` de refresh/reconciliación es explícito e idempotente — reintentarlo no debe duplicar crédito ni cambiar un estado final ya asentado.
- Un callback/redirect del frontend nunca es verdad financiera por sí solo — solo confirma que el usuario volvió; el estado real viene del backend/webhook.
- Producción requiere webhooks configurados **y** reconciliación programada (no solo uno de los dos) — un webhook perdido debe autocorregirse por el job de reconciliación.

## Fuera de alcance de esta skill

Historial de debugging (migración de Orders API, IDs de app antiguos, errores 401/4390, IDs de pago de test, conteos de smoke tests viejos) — eso vive en `docs/`, no aquí.
