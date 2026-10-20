# 02 · Modelo de datos

> Derivado de las 103 migraciones de `database/migrations` y de `app/Models`
> (2026-10-19). Se omiten columnas de auditoría menores; `created_at/updated_at`
> existen salvo indicación. Tablas del framework (`cache`, `jobs`, `sessions`,
> `notifications`, `personal_access_tokens`…) no se dibujan.
> **Ojo:** el modelo `Lesson` usa la tabla `classes`; el padre es un `users`
> (con rol) y el alumno (`students`) **no tiene cuenta**.

## Identidad y catálogo

```mermaid
erDiagram
    users {
        bigint id PK
        string email UK
        string phone
        bool parental_control
        ts email_verified_at
        ts phone_verified_at
        ts suspended_at
        ts whatsapp_opt_in_at
        text two_factor_secret
    }
    students {
        bigint id PK
        bigint parent_user_id FK
        string first_name
        string last_name
        date birth_date
        enum grade_level "primaria,secundaria,universidad"
        ts deleted_at "soft delete + anonymize"
    }
    teacher_profiles {
        bigint id PK
        bigint user_id FK
        decimal hourly_rate
        bool is_verified
        int credits_available
        int credits_reserved
        int mentorship_slots_total
        int mentorship_slots_taken
        string referral_code UK
    }
    subjects {
        bigint id PK
        string name
        enum level
        string normalized_name
    }
    teacher_subject {
        bigint teacher_profile_id FK
        bigint subject_id FK
        decimal specific_rate
    }
    teacher_availability_slots {
        bigint teacher_profile_id FK
        tinyint day_of_week
        time start_time
        time end_time
    }
    users ||--o{ students : "padre o apoderado"
    users ||--o| teacher_profiles : "profesor"
    teacher_profiles ||--o{ teacher_subject : imparte
    subjects ||--o{ teacher_subject : "es impartida"
    teacher_profiles ||--o{ teacher_availability_slots : "agenda"
```

Roles (Spatie): `admin`, `teacher`, `parent`. El admin exige MFA TOTP server-side.

## Solicitudes y clases

```mermaid
erDiagram
    class_offers {
        bigint id PK
        bigint teacher_profile_id FK
        bigint subject_id FK
        string title
        decimal specific_rate
        bool is_active
    }
    class_requests {
        bigint id PK
        bigint student_id FK
        bigint subject_id FK
        bigint class_offer_id FK "nullable"
        bigint teacher_profile_id FK "nullable, codigo de referido"
        bigint student_diagnostic_id FK
        string status "ver 03-state-machines"
        json preferred_times
        bool is_mentorship
        datetime counteroffer_time
        int counteroffer_duration_minutes
        bigint counteroffer_teacher_profile_id FK
    }
    classes {
        bigint id PK "modelo Lesson"
        bigint teacher_profile_id FK
        bigint student_id FK
        bigint class_request_id FK
        bigint class_offer_id FK
        datetime start_time
        smallint duration_minutes
        decimal price_frozen_pen
        string status "string, no enum"
        string jitsi_room
        ts credits_settled_at "solo la capa de liquidacion"
        ts cancelled_at
        bigint cancelled_by
    }
    class_events {
        bigint id PK
        bigint lesson_id
        bigint class_request_id
        bigint actor_id
        string event_type
        json metadata
    }
    lesson_reports {
        bigint id PK
        bigint lesson_id FK
        text topic_covered
        text student_performance
        ts sent_to_parent_at
    }
    lesson_presence_events {
        bigint id PK
        bigint lesson_id FK
        string event_type "PARTICIPANT_JOINED o LEFT"
        string external_event_id UK
        ts occurred_at
    }
    teacher_reviews {
        bigint id PK
        bigint lesson_id UK
        tinyint rating
        bool is_visible
    }
    class_offers ||--o{ class_requests : origina
    students ||--o{ class_requests : solicita
    class_requests ||--o{ classes : acepta
    classes ||--o{ class_events : audita
    classes ||--o| lesson_reports : reporta
    classes ||--o{ lesson_presence_events : "evidencia JaaS"
    classes ||--o| teacher_reviews : evalua
```

La presencia JaaS es **solo evidencia**: no decide asistencia ni toca créditos.

## Créditos y pagos (ledger append-only)

```mermaid
erDiagram
    credit_transactions {
        bigint id PK
        bigint teacher_profile_id FK
        enum type "deposit,reservation,consumption,refund,reversal"
        int amount
        string idempotency_key UK
        bigint lesson_id FK
        bigint recharge_request_id FK
        string reversal_type
    }
    recharge_requests {
        bigint id PK
        bigint teacher_profile_id FK
        string package_code "inicio,impulso,..."
        int credits
        decimal amount_pen
        enum status "pending,approved,rejected"
        string payment_method
        ts reversed_at
    }
    payment_orders {
        bigint id PK
        bigint recharge_request_id FK
        string provider "mercadopago,fake"
        string provider_order_id
        enum status "created,pending,paid,failed,expired,cancelled"
        string submission_status
        int attempt_number
        string idempotency_key
        int recovery_attempts
        smallint creation_http_status
        string creation_error_codes
        ts review_detected_at
        ts review_resolved_at
    }
    payment_webhooks {
        bigint id PK
        string provider
        string event_id "UK con provider"
        json payload
        enum status "received,processed,failed"
        int recovery_attempts
    }
    operational_alerts {
        bigint id PK
        string alert_key UK
        string severity
        ts resolved_at
    }
    teacher_profiles ||--o{ credit_transactions : ledger
    teacher_profiles ||--o{ recharge_requests : recarga
    recharge_requests ||--o{ payment_orders : intentos
    classes ||--o{ credit_transactions : "reserva, consumo, refund"
    recharge_requests ||--o{ credit_transactions : deposito
```

Invariantes (CODE): el UNIQUE sobre `credit_transactions.idempotency_key` impide
doble reserva/consumo/depósito; `credits_settled_at` es solo caché derivada;
cada mutación se hace en transacción con `lockForUpdate()` (profesor y alumno).
`mova:reconcile-ledger` compara saldos y ledger a diario (03:10). **Los montos del
ledger son créditos enteros** (1 crédito = 60 min; paquete «Inicio» = 5 créditos por S/ 10).

## Diagnóstico, IA, consentimientos y soporte

```mermaid
erDiagram
    student_diagnostics {
        bigint id PK
        bigint parent_user_id FK
        bigint student_id FK
        enum status "draft,completed,converted"
        string idempotency_key UK
        text ai_summary
        bool ai_used_fallback
    }
    diagnostic_recommendations {
        bigint student_diagnostic_id FK
        bigint class_offer_id FK
        bigint teacher_profile_id FK
        tinyint rank
        json reasons
    }
    ai_usage_logs {
        bigint user_id
        string provider
        string status
        int total_tokens
    }
    student_data_consents {
        bigint student_id FK
        bigint parent_user_id FK
        string statement_version
        string privacy_version
        ts accepted_at
    }
    legal_acceptances {
        bigint user_id FK
        string document
        string version
        ts accepted_at
    }
    complaints {
        string code UK
        string type
        bool is_minor
        string status
    }
    students ||--o{ student_diagnostics : diagnostica
    student_diagnostics ||--o{ diagnostic_recommendations : recomienda
    students ||--o{ student_data_consents : "consentimiento por menor"
    users ||--o{ legal_acceptances : "append-only"
```

`student_data_consents` es evidencia legal por menor: **no se infiere** para
alumnos históricos y su `down()` no debe ejecutarse con datos reales.
Las tablas de WhatsApp (`whatsapp_messages`, `whatsapp_webhook_events`) existen,
pero el canal está apagado en producción.
