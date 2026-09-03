---
paths:
  - "app/**/*.php"
  - "database/**/*.php"
  - "routes/**/*.php"
  - "bootstrap/**/*.php"
  - "config/**/*.php"
  - "tests/**/*.php"
---

# Backend (Laravel)

- Controladores delgados: autorización vía Policies/Gates (`app/Policies/`), no lógica de negocio inline — delega a Services.
- Toda acción sobre un recurso ajeno verifica ownership server-side (no solo el rol).
- Mutaciones financieras van dentro de transacción; usa `lockForUpdate()` cuando hay concurrencia real sobre el mismo recurso (créditos, `PaymentOrder`, `RechargeRequest`).
- El ledger de `credit_transactions` es append-only; nunca editar ni borrar una fila existente. Crédito exactamente-once por `RechargeRequest` (ver `RechargeApprovalService::credit()`), protegido por `idempotency_key` único.
- Cambios que tocan auth, dinero, o datos de menores (modelos `Student`, `TeacherProfile`, Jitsi/`JaasService`) requieren una revisión de seguridad explícita antes de darse por terminados — no basta con que los tests pasen.
- Errores hacia el cliente son técnicamente seguros: nunca expongas stack traces, queries, paths internos o payloads de proveedores externos (Mercado Pago, etc.).
