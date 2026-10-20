# MOVA — Arquitectura (documentación viva)

> **Estado:** CURRENT · verificado contra el código de `master` y contra el
> [Completion Ledger](../release/MOVA_V1_COMPLETION_LEDGER.md) el 2026-10-19.
> Si algo de aquí contradice al ledger sobre el *estado de un entorno*, manda el
> ledger; si contradice al código sobre el *comportamiento*, manda el código y
> este documento está desactualizado (corrígelo en el mismo PR).

| Documento | Contenido |
|---|---|
| [01-deployment.md](01-deployment.md) | Topología Azure real (producción + staging), CI/CD, secretos, rollback |
| [02-data-model.md](02-data-model.md) | Modelo entidad-relación por dominio (derivado de `database/migrations`) |
| [03-state-machines.md](03-state-machines.md) | Estados de `class_requests`, `classes`, `recharge_requests`, `payment_orders` |
| [04-flows.md](04-flows.md) | Secuencias: reserva de clase, liquidación, recarga con Mercado Pago, webhooks, diagnóstico |
| [site/](site/README.md) · [site/index.html](site/index.html) | **Sitio navegable** con los 17 diagramas, tema claro/oscuro y mapeo ISO ([normas](site/normas.md)); se regenera con `python docs/architecture/site/build.py` |
| [07-secrets-keyvault-plan.md](07-secrets-keyvault-plan.md) | Estado de secretos y plan de migración a Key Vault (sin implementar) |
| [06-cost-and-scaling.md](06-cost-and-scaling.md) | Costos y escalado de worker/scheduler/web, decisiones con ISO 25010/29119 |
| [05-claims-verification.md](05-claims-verification.md) | Qué afirmaciones de la auditoría externa del 2026-10-19 son ciertas, falsas u obsoletas |

## Convenciones

- Diagramas en **Mermaid** dentro de Markdown (GitHub los renderiza). Para validar en local:
  `npx -y @mermaid-js/mermaid-cli -i docs/architecture/<archivo>.md -o /tmp/out.md`.
- Etiquetas de evidencia, las mismas del ledger: `CODE`, `LOCAL_TEST`, `LIVE_STAGING`, `PRODUCTION_LIVE`.
- No se documentan secretos: solo nombres de variables y procedimientos.
- Los nombres de tabla/columna salen de las migraciones; el modelo `Lesson` mapea a la tabla `classes`.

## Mapa de documentación del repositorio

Ver [docs/README.md](../README.md): qué documento es vigente y cuál es histórico.
