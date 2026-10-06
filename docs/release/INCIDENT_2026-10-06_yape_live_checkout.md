# Incidente 2026-10-06 — checkout live Yape «Estamos verificando tu pago» (recarga 1)

**Resultado financiero: NO hubo cobro.** Mercado Pago rechazó la creación del pago; no existe ningún pago en la cuenta del cobrador. Sin cobro, sin reembolso.

## Evidencia (solo lecturas)
- **Local:** `RechargeRequest#1` (paquete de verificación, S/ 1,00) `pending`; un único `PaymentOrder` (intento 1, 100 minor / PEN) `pending`, sin ID de proveedor,
  `recovery_attempts=5`, en revisión (`review_reason`: la búsqueda por `external_reference` agotó el presupuesto sin hallar el pago). Ledger de la cuenta vacío
  (0 depósitos, 0 créditos, saldo 0). 0 webhooks de Mercado Pago recibidos. Cola 0 pendientes / 0 fallidos; heartbeats de `scheduler` y `worker` vigentes.
- **Proveedor (GET, modo live):** `GET /v1/payments/search?external_reference=<intento>` ⇒ 200, **0 resultados**; búsqueda de TODA la cuenta desde las 04:00Z ⇒ **0 pagos**
  (no hay duplicados). `GET /v1/payment_methods`: Yape `active`, mínimo S/ 1, máximo S/ 2000.
- **Logs de producción (ventana 04:42–04:46Z, solo líneas del `payment_order_id=1`):** `04:42:43` `POST /v1/payments` ⇒ **HTTP 400** `"Invalid value for transaction_amount"`
  (error 2072 de Payments API; categoría `unclassified`). MOVA lo trató como incierto (conservador), buscó 5 veces por referencia sin hallar nada y dejó el intento en
  revisión (alerta crítica `payment_order:1:review`). No hubo segundo `POST` ni intento nuevo.

## Causa comprobada y no comprobada
- **Comprobado:** el POST de creación fue **rechazado por Mercado Pago (400 / 2072)**; el token Yape sí llegó a `/pay` (MOVA no puede enviar el POST sin él); el pago nunca se creó.
- **Interpretación de la consola:** `/tracks` y `api_integration` son telemetría del SDK del navegador; no son la causa.
- **No comprobado:** por qué el importe S/ 1,00 se considera inválido (la documentación no publica un mínimo para Yape y `payment_methods` declara mínimo S/ 1). Hipótesis a descartar con
  una única prueba controlada: un mínimo efectivo mayor para Yape live o una regla de la cuenta. No se reintentó el POST.

## Defecto de interfaz (corregido en código, sin desplegar)
El checkout hacía hasta 46 consultas con backoff (≈ 320 s) pero anunciaba «hasta un minuto» y, al agotarlas, dejaba el spinner girando para siempre. Ahora el plazo mostrado sale de
`resources/js/lib/checkoutPolling.js`, y al agotarse se muestra «No pudimos confirmar tu pago todavía» (ni aprobado ni fallido), con «No vuelvas a pagar» y «Consultar estado» (solo reconcilia).
Prueba dirigida: `npm run check:checkout-poll` (verificado que falla con el componente anterior).

## Estado y siguiente paso
- El intento 1 queda **en revisión segura**; no existe ruta canónica de resolución manual, así que no se tocó la base. Para repetir la prueba hace falta resolver esa revisión con una
  herramienta administrativa auditada (decisión pendiente) y definir el importe de la nueva prueba tras entender el rechazo 2072.
- Flags live y despliegue sin cambios.
