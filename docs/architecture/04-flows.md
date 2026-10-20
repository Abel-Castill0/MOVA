# 04 · Flujos principales

> Evidencia: `LessonSchedulingService`, `LessonSettlementService`,
> `MercadoPagoPaymentProvider`, `MercadoPagoPaymentReconciliationService`,
> `PaymentWebhookService`, `app/Console/Kernel.php`. Dinero real en MOVA = **solo
> la recarga de créditos del profesor** (Mercado Pago). El pago padre→profesor por
> la clase **no existe en MOVA**: ocurre fuera (Yape/Plin) y el padre solo lo declara.

## 1. Solicitud, aceptación y reserva de créditos

```mermaid
sequenceDiagram
    autonumber
    actor P as Padre
    actor T as Profesor
    participant W as MOVA (Laravel)
    participant DB as MySQL
    P->>W: crear solicitud (alumno, materia, horarios, codigo opcional)
    W->>DB: class_requests status open o pending_parent_approval
    W-->>T: notificacion (campana, correo)
    T->>W: aceptar (horario y duracion)
    W->>DB: BEGIN
    W->>DB: lock del alumno y del User y TeacherProfile del profesor
    W->>DB: conflicto de horario? profesor verificado y no suspendido?
    alt credits_available < creditos necesarios (incluye saldo negativo)
        W->>DB: ROLLBACK
        W-->>T: error accept, creditos insuficientes
    else suficiente
        W->>DB: INSERT classes status scheduled, price_frozen_pen, sala JaaS
        W->>DB: available menos N, reserved mas N
        W->>DB: INSERT credit_transactions reservation (idempotency_key lesson:ID:reservation)
        W->>DB: class_requests.status accepted
        W->>DB: COMMIT
        W-->>P: ClassConfirmed (correo y campana)
    end
```

## 2. Ejecución, reporte y liquidación de créditos

```mermaid
flowchart TD
    A[classes.status = scheduled] --> B[Padre declara pago al profesor<br/>status = paid]
    B --> C[Profesor sube LessonReport<br/>status = pending_parent_confirmation]
    C --> D{¿Padre confirma / reseña?}
    D -->|sí| E[LessonSettlementService::consume]
    D -->|no, pasan 7 días| F{mova:settle-lessons<br/>cada hora}
    F -->|LESSON_SETTLEMENT_MODE=live| E
    F -->|dry_run| G[solo simula y registra]
    F -->|sin reporte o sin cerrar 7 días| H[needs_admin_review<br/>decide un admin]
    H --> E
    H --> I[refund: devuelve la reserva]
    E --> J[INSERT credit_transactions consumption<br/>UNIQUE idempotency_key<br/>reserved menos N, status completed]
    J --> K[LessonSettledNotification]
```

La liquidación es **solo ledger interno de créditos**; en producción corre en
`live` desde 2026-10-06 y es reversible a `dry_run`.

## 3. Recarga de créditos con Mercado Pago

```mermaid
sequenceDiagram
    autonumber
    actor T as Profesor
    participant W as MOVA
    participant MP as Mercado Pago
    participant DB as MySQL
    T->>W: elegir paquete (inicio, impulso, ...) y metodo (tarjeta o Yape)
    W->>W: validar allowlist, montos y paquetes en el servidor
    W->>DB: recharge_requests pending + payment_orders created (idempotency_key)
    W->>MP: POST /v1/payments (token, external_reference del intento)
    alt 201 aceptado
        MP-->>W: payment id, status
        W->>DB: payment_orders pending o paid
    else 400 con codigo documentado de validacion (p. ej. 2072)
        MP-->>W: error
        W->>DB: payment_orders failed + creation_http_status y codigos (sin reintento automatico)
        W-->>T: pago rechazado, sin cobro
    else 409, 429, 5xx, red o 400 ambiguo
        W->>DB: payment_orders pending en revision (incierto)
        W->>MP: buscar por external_reference (recuperacion)
    end
    MP-)W: POST /api/webhooks/mercadopago (firma HMAC)
    W->>W: verificar firma y modo live esperado
    W->>DB: INSERT payment_webhooks (UNIQUE provider+event_id)
    W->>MP: GET del pago (fuente de verdad)
    W->>DB: BEGIN, lock, payment_orders paid, recharge approved
    W->>DB: INSERT credit_transactions deposit (idempotente)
    W->>DB: COMMIT
```

- `mercadopago:reconcile` corre cada 5 min y reprocesa webhooks fallidos sin
  duplicar abonos. Un reembolso/contracargo confirmado genera un `reversal`.
- El webhook usa un throttle propio de 120/min y evita el `throttle:api` global de 60/min (`routes/api.php`), para no descartar reintentos legítimos.
- Estado real del checkout live: abierto solo a la cuenta de prueba del
  propietario; el único intento live fue rechazado con **2072** y sigue sin
  causa raíz (caso en [MERCADOPAGO_SUPPORT_CASE_2072](../release/MERCADOPAGO_SUPPORT_CASE_2072.md),
  sin enviar). No se reintenta ni se cambia el importe por conjetura.
- **Culqi no existe**: `CulqiPaymentProvider` lanza `RuntimeException` en todos
  los métodos; aunque `supported_providers` lo lista, el arranque con
  `PAYMENT_PROVIDER=culqi` no funciona (ver [05](05-claims-verification.md)).

## 4. Tareas programadas (scheduler único)

| Comando | Frecuencia | Función |
|---|---|---|
| `classmate:send-reminders` | cada minuto | recordatorios de clase y solicitud |
| `mova:settle-lessons` | cada hora | consumo automático / escalado (`--dry-run` si no es `live`) |
| `mova:expire-class-requests` | cada hora, minuto 30 | expira solicitudes `open` tras 24 h |
| `mercadopago:reconcile` | cada 5 min | reconcilia pagos y webhooks (si los pagos están activos) |
| `mova:health-check --alert` | cada hora | alertas operativas (`operational_alerts`) |
| `mova:reconcile-ledger --alert` | diaria 03:10 | compara saldos y ledger |
| heartbeat | cada minuto | `system_heartbeats` para `/readyz` y el Operations Center |

## 5. Diagnóstico e IA

```mermaid
flowchart TD
    A[Padre completa el diagnóstico] --> B{idempotency_key<br/>ya existe?}
    B -->|sí| C[devuelve el diagnóstico existente]
    B -->|no| D[student_diagnostics]
    D --> E[DiagnosticRecommendationService<br/>reglas: score y rank]
    E --> F{IA habilitada?}
    F -->|no, estado actual en producción| G[solo recomendación por reglas]
    F -->|sí| H[DiagnosticAiEnrichmentService<br/>+ ai_usage_logs]
    G --> I[diagnostic_recommendations]
    H --> I
```

La IA externa está **apagada** en producción; Movi usa `CHATBOT_PROVIDER=local`.
La política de privacidad declara que no se envían datos a IA
(`LEGAL_PRIVACY_AI_MISMATCH` lo vigila). Antes de activar IA con datos de
menores hace falta el texto de consentimiento específico y confirmar la
redacción previa de datos personales.
