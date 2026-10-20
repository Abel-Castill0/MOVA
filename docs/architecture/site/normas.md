# Mapeo de diagramas a normas ISO

> Estado: CURRENT · 2026-10-19. **Mapeo orientativo para ingeniería: MOVA no está certificada ni auditada contra ninguna de estas normas.** Sirve para ubicar qué característica o control respalda cada diagrama y dónde está el hueco.

## Ediciones usadas (y una corrección)

| Norma | Edición | Uso en MOVA |
|---|---|---|
| ISO/IEC 25010 | 2023 | Modelo de calidad del producto: adecuación funcional, eficiencia de desempeño, compatibilidad, capacidad de interacción (antes usabilidad), fiabilidad, seguridad, mantenibilidad, flexibilidad (antes portabilidad), seguridad operacional (safety) |
| ISO/IEC/IEEE 29119 | partes 1–4 | Conceptos, procesos, documentación y técnicas de prueba |
| ISO/IEC 27001 | 2022 | Controles del Anexo A (numeración 2022: A.5, A.6, A.7, A.8) |
| ISO/IEC 27701 | 2025 | Gestión de información de privacidad (PIMS) |
| ISO/IEC 42001 | **2023** | Sistema de gestión de IA. *La edición vigente es 2023, no 2025: se corrige el dato del encargo.* |
| ISO 22301 | 2019 | Continuidad del negocio (RTO/RPO) |

## Mapa por diagrama

| Diagrama | ISO/IEC 25010:2023 | ISO/IEC/IEEE 29119 (nivel y gap) | ISO/IEC 27001:2022 (Anexo A) | ISO/IEC 27701:2025 | ISO/IEC 42001:2023 | ISO 22301:2019 |
|---|---|---|---|---|---|---|
| Topología de despliegue en Azure | Fiabilidad, eficiencia de desempeño | Sistema/aceptación: `/healthz`, `/readyz`, E2E. Gap: sin prueba de carga | A.8.20, A.8.22, A.8.31 | — | — | RTO ≈ 50 min medido; PITR 14 d |
| Conexión de red y servicios externos | Seguridad, fiabilidad | Sistema. Gap: sin escaneo de red/pentest | A.8.20, A.8.21, A.8.22, A.5.23 | Flujo transfronterizo: Azure (México), Cloudinary, Sentry, JaaS, Mercado Pago | IA externa apagada (OpenAI/Gemini) | Dependencia de proveedores sin plan de salida documentado |
| Pipeline CI/CD | Mantenibilidad, fiabilidad | Sistema en CI (SQLite, MySQL 8.4, Playwright). Gap: despliegue manual | A.8.32, A.8.9 | — | — | Rollback por revisión (no ensayado en esta ronda) |
| ER Identidad y catálogo | Seguridad, compatibilidad | Unidad/integración de autorización (Policies, roles, MFA) | A.5.15, A.5.16, A.8.5 | Datos de menores: consentimiento parental, baja con anonimización | — | — |
| ER Solicitudes y clases | Adecuación funcional, fiabilidad | Integración/sistema. Gap: presencia JaaS no decide asistencia | A.8.15 (`class_events`) | — | — | — |
| ER Créditos y pagos | Fiabilidad, seguridad funcional | Concurrencia real multi-proceso (5/5) + PHPUnit MySQL | A.8.3, A.8.15, A.8.16 | Minimización: sin PAN ni datos de tarjeta en BD | — | `mova:reconcile-ledger` diario |
| ER Diagnóstico, IA, consentimientos | Seguridad, capacidad de interacción | Integración. Gap: consentimiento específico por menor para IA pendiente | A.5.34 | Consentimiento versionado y append-only; ANPD pendiente (trámite del titular) | `ai_usage_logs`; política declara no enviar datos a IA | — |
| Estados `ClassRequest` | Adecuación funcional | Cobertura de transiciones por tests de flujo | — | — | — | — |
| Estados `Lesson` | Adecuación funcional, fiabilidad | Gap: `paid` es declarativo (F-11); sin disputas de asistencia | — | — | — | — |
| Ciclo de créditos | Fiabilidad, seguridad funcional | `MonetizationIntegrityTest`, idempotencia, saldo negativo | A.8.28 | — | — | — |
| Estados `PaymentOrder` | Fiabilidad, seguridad | Webhook/recovery cubiertos; gap: sin contract test sandbox en CI | A.8.15, A.8.16 | — | — | Cierre administrativo auditado ante incierto |
| Secuencia reserva de clase | Fiabilidad, eficiencia | Concurrencia: accept-lesson (8 procesos) | A.8.28 | — | — | — |
| Liquidación de créditos | Fiabilidad, seguridad funcional | Dry-run con `safe_to_enable`; gap: sin prueba de carga de backlog | A.8.32 | — | — | Reanudación tras parada: dry-run primero |
| Recarga con Mercado Pago (2072) | Fiabilidad, seguridad | Gap: causa del 2072 sin resolver; sin pruebas live repetibles | A.8.24, A.8.5 | Datos de pago tokenizados por el proveedor | — | Sin cobros a terceros hasta respuesta del soporte |
| Webhook y reconciliación | Fiabilidad, seguridad | Tests de firma/recovery; gap: arranque en frío del web (resuelto con min=1) | A.8.24, A.8.16 | — | — | Recupera eventos perdidos en ≤ 90 d |
| Diagnóstico e IA | Seguridad, capacidad de interacción | Fallback sin IA probado | — | Menores: redacción previa no garantizada técnicamente | Gobierno: IA apagada por defecto; falta evaluación de riesgos de IA | — |
| Mapa de APIs | Seguridad, mantenibilidad | Gap: sin prueba automática de contrato de rutas | A.5.15, A.8.5, A.8.3 | — | — | — |

## Continuidad (ISO 22301)

| Parámetro | Valor | Evidencia |
|---|---|---|
| RTO objetivo declarado | No definido formalmente (gap: no hay BIA) | — |
| RTO medido | ≈ 50 min (PITR de 32 GB en B1ms, 2026-10-05) | Runbook §3 |
| RPO | Nominal de minutos por PITR continuo (retención 14 d); **no medido** | Runbook §3 |
| Geo-redundancia / alta disponibilidad | No (decisión de costo) | Ledger |
| Aplicación | Rollback por revisión de Container Apps con imágenes por digest | Runbook §2 |

## Brechas consolidadas (priorizadas)

| Prioridad | Brecha | Norma | Acción |
|---|---|---|---|
| Alta | F-26 credencial histórica en el repositorio público | 27001 A.5.x/A.8.x | Titular: rotar o destruir el volumen de Railway |
| Alta | Error 2072 sin causa raíz | 25010 fiabilidad | Titular: enviar el caso a soporte |
| Media | Sin BIA ni RTO/RPO objetivos aprobados | 22301 | Definir objetivos y probar restauración periódica |
| Media | Consentimiento específico para IA con menores | 27701 / 42001 | Gate antes de activar IA |
| Media | Despliegue manual sin smoke/rollback automatizado | 27001 A.8.32 | `deploy-azure.yml` con OIDC |
| Baja | Sin gate de accesibilidad ni contract tests de rutas/pagos | 25010 / 29119 | Añadir a CI |
