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
**Payload enviado (revisado en el código, `MercadoPagoPaymentProvider::createPaymentAttempt()`):** `transaction_amount` numérico `1` (PHP `1.0`, que `json_encode` serializa como `1`; igual que el ejemplo oficial
`"transaction_amount": 5000`), `description`, `external_reference` del intento, `capture: true`, `binary_mode: false`, `payer.email` (del profesor), y, para Yape, `token`, `payment_method_id: "yape"`, `installments: 1`.
La moneda no se envía (la fija el sitio de la cuenta, PEN). Coincide con el cuerpo documentado de «Crear pago»; ningún campo del payload es evidentemente incorrecto.

**Comprobado:**
- Mercado Pago respondió **HTTP 400** con el mensaje «Invalid value for transaction_amount» — el texto coincide con el **código 2072** de la tabla oficial de errores 400 de «Crear pago»
  (fuente primaria: <https://www.mercadopago.com.pe/developers/es/reference/online-payments/checkout-api-payments/create-payment/post>, leída el 2026-10-06; descripción oficial: «Asegúrese de que el
  transaction_amount sea válido»). Una validación de request rechazada no crea el pago: coherente con 0 resultados en la búsqueda por referencia y 0 pagos en la cuenta.
- **Matiz honesto:** el número 2072 se deduce del mensaje; el log original solo guardó el mensaje, no `cause[].code` (esa omisión ya se corrigió: ahora se registran los códigos).
- La clasificación anterior leía solo el primer código (el `error` raíz, normalmente «bad_request») y dejaba el rechazo como incierto: defecto de MOVA, corregido.

**Qué NO prueba el código 2072:** por qué el importe se considera inválido. La documentación **no** publica un mínimo ni un formato distintos para Yape (solo máximos de S/ 500/900/2000 configurados en la app Yape);
`GET /v1/payment_methods` declara para Yape mínimo S/ 1 y máximo S/ 2000. Hipótesis sin probar: un mínimo efectivo mayor, una regla de la cuenta/cobrador, o una validación del lado de Yape/token. **No se debe asumir que S/ 3 (u otro importe) lo resuelva.**
Solo una prueba controlada nueva, autorizada y con la causa ya acotada (idealmente con soporte de Mercado Pago, citando el `x-request-id` del rechazo) puede aclararlo.

## Defecto de interfaz (corregido en código, sin desplegar)
El checkout hacía hasta 46 consultas con backoff (≈ 320 s) pero anunciaba «hasta un minuto» y, al agotarlas, dejaba el spinner girando para siempre. Ahora el plazo mostrado sale de
`resources/js/lib/checkoutPolling.js`, y al agotarse se muestra «No pudimos confirmar tu pago todavía» (ni aprobado ni fallido), con «No vuelvas a pagar» y «Consultar estado» (solo reconcilia).
Prueba dirigida: `npm run check:checkout-poll` (verificado que falla con el componente anterior).

## Resolución en código (esta ronda)
- **Clasificación:** un 400 de creación con un código numérico documentado de validación de request en `cause[].code` (p. ej. 2072, 4002/4003/4037…) cierra el intento como `failed` (nunca `paid`, sin reintento automático);
  lo ambiguo (400 sin código reconocido o de cuenta, 409, 429, 5xx, excepciones de red) sigue incierto.
- **Evidencia persistente:** columnas `payment_orders.creation_*` (estado HTTP, códigos, `x-request-id`, fecha) escritas en cada creación no exitosa.
- **Cierre administrativo acotado** (`POST admin/recharges/{id}/close-rejected-payment`, admin + MFA sensible, lock, idempotente, auditoría en la incidencia): solo si hay evidencia persistida de rechazo terminal, el intento
  sigue en revisión sin ID de proveedor, el ledger de la recarga está vacío, no hay intento posterior, pasaron ≥ 10 min y una búsqueda remota por referencia devuelve 0 pagos. Nunca acredita, reembolsa ni borra.
- **Atestación de operador** (`php artisan mova:attest-payment-rejection`, solo CLI): registra una vez la evidencia de un intento anterior a las columnas (el de la recarga 1), sin cambiar estado.

## Estado y siguiente paso
- El intento 1 queda **en revisión segura**; no existe ruta canónica de resolución manual, así que no se tocó la base. Para repetir la prueba hace falta resolver esa revisión con una
  herramienta administrativa auditada (decisión pendiente) y definir el importe de la nueva prueba tras entender el rechazo 2072.
- Flags live y despliegue sin cambios.
