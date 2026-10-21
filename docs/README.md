# Documentación de MOVA — índice y estado

> Actualizado 2026-10-19. **Regla:** el [Completion Ledger](release/MOVA_V1_COMPLETION_LEDGER.md)
> decide el *estado* de los entornos; el código decide el *comportamiento*; el
> resto es contexto. Cualquier documento sin banner CURRENT debe leerse como
> histórico.

## Vigente (CURRENT)

| Documento | Para qué |
|---|---|
| [`../README.md`](../README.md) | Visión, stack, instalación y QA |
| [`../AGENTS.md`](../AGENTS.md) · [`../CLAUDE.md`](../CLAUDE.md) | Autorizaciones, reglas de trabajo y límites (agentes) |
| [`architecture/`](architecture/README.md) | Despliegue, ER, estados, flujos, verificación de auditorías |
| [`release/MOVA_V1_COMPLETION_LEDGER.md`](release/MOVA_V1_COMPLETION_LEDGER.md) | Fuente de verdad del estado de V1 |
| [`release/MOVA_PRODUCTION_RUNBOOK.md`](release/MOVA_PRODUCTION_RUNBOOK.md) | Operación de producción en Azure |
| [`release/MOVA_V1_DECISIONS.md`](release/MOVA_V1_DECISIONS.md) | Decisiones legales y de negocio de V1 |
| [`release/INCIDENT_2026-10-06_yape_live_checkout.md`](release/INCIDENT_2026-10-06_yape_live_checkout.md) · [`release/MERCADOPAGO_SUPPORT_CASE_2072.md`](release/MERCADOPAGO_SUPPORT_CASE_2072.md) | Incidente 2072 y caso para soporte |
| [`../infra/azure/README.md`](../infra/azure/README.md) | IaC Bicep (describe plantillas, no estado desplegado) |
| [`PROMPTS_CLAUDE_CODE.md`](PROMPTS_CLAUDE_CODE.md) | Guía y plantillas de indicaciones para Claude Code |
| [`BACKUP_RESTORE_PLAYBOOK.md`](BACKUP_RESTORE_PLAYBOOK.md) · [`payments-architecture.md`](payments-architecture.md) · [`whatsapp-architecture.md`](whatsapp-architecture.md) | Referencias técnicas (verificar contra el código) |
| [`../DESIGN.md`](../DESIGN.md) · [`../PRODUCT.md`](../PRODUCT.md) · [`WEB_INTERFACE_GUIDELINES.md`](WEB_INTERFACE_GUIDELINES.md) | Diseño y producto |
| [`CHANGELOG_2026-10-21_UI_MOVI_DOCKER.md`](CHANGELOG_2026-10-21_UI_MOVI_DOCKER.md) | Registro completo (2026-10-21): cambios UI/Movi/Navbar (`040e673`), integración WAHA WhatsApp, bypass login local y arquitectura de 4 contenedores Docker |

## Referencia útil, parcialmente superada

| Documento | Nota |
|---|---|
| [`MOVA_SYSTEM_MAP.md`](MOVA_SYSTEM_MAP.md) | Mapa funcional extenso (2026-09-12). Útil para rutas y permisos; los estados de despliegue están superados |
| [`release/MOVA_V1_STATE.md`](release/MOVA_V1_STATE.md) | Snapshot AZ-3G0, histórico |
| [`MOVA_CREDENTIAL_EXPOSURE.md`](MOVA_CREDENTIAL_EXPOSURE.md) | **Incidente F-26 abierto**; vigente para ese tema |

## Histórico (no usar para decidir)

Auditorías por fase y snapshots: `MOVA_AUDIT_PHASE0.md`, `MOVA_FULL_AUDIT.md`,
`MOVA_PHASE*_*.md`, `FINAL_PRODUCTION_*`, `PRODUCTION_READINESS_AUDIT.md`,
`MOVA_PRODUCTION_READINESS.md`, `PROFESSIONAL_POLISH_AUDIT.md`,
`MONETIZATION_*`, `phase0-appendices/`, `HANDOFF_FINAL.md`, `ESTADO_FINAL.md`,
`MOVA_SYSTEM_KNOWLEDGE.md`, `../MOVA_MASTER_CONTEXT.md`.
**Railway (legacy):** `DEPLOY_RAILWAY.md`, `SESSION_HANDOFF.md`,
`WHATSAPP_PRODUCTION_NOTES.md` y los `railway*.toml` — Railway no es operable
(Ledger).

## Cómo mantener la documentación

1. Un cambio de comportamiento financiero, de estados o de despliegue actualiza
   en el mismo PR: el Ledger (estado) y `docs/architecture/` (diagramas).
2. Todo documento nuevo lleva en la primera línea `> Estado: CURRENT|HISTÓRICO · fecha`.
3. Nada de secretos; solo nombres de variables.
4. Al cerrar una fase, usar `/handoff` y registrar en el Ledger, no en un archivo nuevo.
