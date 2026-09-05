> **HISTORICAL SNAPSHOT** — verify against `docs/MOVA_SYSTEM_MAP.md` and current code.
> Documento de una fase anterior. Conservado como registro de decisiones, NO como
> descripcion fiable del comportamiento actual.

# Arquitectura de pagos — base para pagos automáticos (Culqi/otro PSP)

Este documento describe la capa de pagos automáticos añadida sobre el
sistema de recargas manual existente. No reemplaza nada: `RechargeRequest`,
`config/credits.php` y el ledger (`credit_transactions`) siguen siendo
exactamente los mismos que antes. Ver `docs/HANDOFF_FINAL.md` §17 (C-1/C-2/C-3)
para el contexto histórico del sistema financiero que esta capa no toca.

## Por qué existe esta capa

MOVA nunca cobró en línea por diseño (`docs/HANDOFF_FINAL.md:150`): el padre
paga al profesor por fuera de la plataforma, y el profesor le compra
créditos a MOVA a mano (comprobante + aprobación de un admin). Esta ronda
agrega la posibilidad de que esa compra de créditos se automatice vía un
proveedor de pagos (Culqi, en principio) — **sin** tocar cómo el padre le
paga al profesor, que sigue siendo un asunto completamente externo a MOVA.

**Estado actual: no hay cuenta comercial de Culqi.** Todo lo de este
documento funciona hoy en modo `fake` (test/desarrollo) y está diseñado para
no requerir ningún cambio al núcleo cuando exista la cuenta — solo
implementar `App\Payment\CulqiPaymentProvider`.

## Flujo

```
RechargeRequest (pending)          — ya existe, sin cambios de forma de crearse
        │
        ▼
PaymentProviderContract::createOrder()
        │
        ▼
PaymentOrder (created → pending)   — 1:1 con la RechargeRequest, amount_minor congelado
        │
        ▼
[proveedor externo procesa el cobro]
        │
        ▼
webhook entrante → PaymentProviderContract::verifyWebhook()
        │                (verifica firma; nunca se confía en el payload sin esto)
        ▼
PaymentWebhookEvent (ya verificado y normalizado)
        │
        ▼
PaymentWebhookService::handle()
        │
        ├── payment_webhooks: UNIQUE(provider, event_id) → duplicado/retry se ignora
        ├── PaymentOrder: created/pending → paid | failed  (bajo lockForUpdate)
        │
        ▼
RechargeApprovalService::credit()   — MISMO método que usa la aprobación manual de un admin
        │
        ├── credit_transactions: type=deposit, idempotency_key="recharge:{id}:deposit"
        └── teacher_profiles.credits_available += credits
```

El frontend nunca decide que un pago fue exitoso — solo
`PaymentWebhookService::handle()`, alimentado por un evento que ya pasó por
`verifyWebhook()`, puede llegar a `RechargeApprovalService::credit()`.

## Entidades

| Tabla | Relación con lo existente |
|---|---|
| `recharge_requests` | Ya existía. Sigue siendo la entidad financiera (paquete/créditos/monto). Ahora puede terminar en `reversed` además de `approved`/`rejected`. |
| `payment_orders` | Nueva. 1:1 con una `recharge_request`. Todo lo específico del proveedor (id remoto, expiración, estado de cobro) vive aquí, no en `recharge_requests`. |
| `payment_webhooks` | Nueva. Log crudo de cada evento recibido — nunca se borra, es la prueba de qué llegó y cuándo. |
| `credit_transactions` | Ya existía. Ahora acepta un 5º tipo, `reversal`, para revertir un `deposit` ya aplicado (ver más abajo). |

## Por qué no hay una entidad `Payment`/`Recharge` separada

Una versión anterior de esta propuesta (ver historial de la conversación)
sugería `PaymentOrder + PaymentAttempt + PaymentWebhook + Recharge` como 4
entidades nuevas. `Recharge` ya existe — es `RechargeRequest`, con estados,
`payment_method`, e idempotencia por `idempotency_key`. Crear una tabla
paralela habría producido dos sistemas financieros que mantener en sync,
justo el problema que ya causó el hallazgo C-1 (docs/HANDOFF_FINAL.md §17).

## Idempotencia

Dos capas, ninguna es "la única":

1. **`payment_webhooks.UNIQUE(provider, event_id)`** — protege contra que el
   mismo evento externo (mismo `event_id`) se procese dos veces (retry del
   proveedor).
2. **`credit_transactions.UNIQUE(idempotency_key)`** — protege contra que la
   MISMA recarga se acredite dos veces, sin importar cuántos eventos
   distintos lleguen apuntando a ella (ver
   `test_two_different_paid_events_for_the_same_order_still_credit_once` en
   `tests/Feature/PaymentOrderTest.php`). Esta es la protección real contra
   doble acreditación — la de `payment_webhooks` solo evita trabajo repetido.

Ambas son restricciones de base de datos, no solo checks en código (mismo
principio que ya usa `RechargeController::approve()` desde antes de esta
ronda).

## Refunds y chargebacks

`RechargeApprovalService::reverse()` revierte una recarga **ya aprobada**.
Nunca edita ni borra el `deposit` original — crea un asiento `reversal` con
`amount` negativo, deja `recharge_requests.status = 'reversed'` con
`reversed_at/reversed_by/reversal_reason`, y resta el balance.

Si el profesor ya gastó esos créditos, `credits_available` puede quedar
negativo — es deliberado (ver el comentario en el propio método). MOVA no
inventa créditos para tapar el hueco ni bloquea la cuenta en silencio. Qué
hacer operativamente con un balance negativo (recuperación, restricción de
cuenta) es una política de producto que esta ronda deja pendiente de
definir explícitamente — no se resolvió por decisión de diseño, no por
omisión.

No hay todavía una ruta/UI de admin para disparar `reverse()` manualmente —
se agrega en la ronda que conecte un proveedor real, junto con el panel de
refunds/chargebacks reportados por ese proveedor.

## Lo que falta para producción (bloqueado por no tener cuenta Culqi)

1. Cuenta comercial de Culqi (aunque sea en modo test) y confirmar en su
   dashboard qué métodos están habilitados para el comercio — no asumir por
   ejemplos de la documentación.
2. Implementar `App\Payment\CulqiPaymentProvider::createOrder()` y
   `::verifyWebhook()` (ver los comentarios de ese archivo).
3. Un endpoint HTTP de webhook — deliberadamente NO se agregó en esta ronda
   porque no hay una firma real que verificar todavía; exponer una ruta
   pública sin verificación real de firma sería una superficie de ataque
   (spoofing de webhook) sin ningún beneficio mientras no exista un
   proveedor real detrás.
4. UI para que el profesor efectivamente inicie un pago automático (botón
   "Recargar con Yape/Plin/Tarjeta" en `Teacher/Credits/Index.vue`) — no
   agregada todavía porque no hay nada real que ese botón pudiera completar.
5. Comando de reconciliación de pagos (`mova:reconcile-payments`, análogo a
   `mova:reconcile-ledger`) una vez haya tráfico real que conciliar contra el
   dashboard del proveedor.
6. Expiración automática de `payment_orders` pendientes (job/scheduler) —
   sin proveedor real todavía no hay órdenes reales que expiren.
7. Panel admin de pagos/refunds/chargebacks y la ruta de `reverse()` (punto
   anterior).

## Configuración

`config/payments.php` + `.env` (`PAYMENT_PROVIDER`, `CULQI_*`). Nunca
credenciales reales en `.env.example` ni en Git. `PAYMENT_PROVIDER=fake` es
el default — mientras no se cambie a `culqi` explícitamente (y se implemente
el provider), nadie puede pagar de verdad por esta vía.
