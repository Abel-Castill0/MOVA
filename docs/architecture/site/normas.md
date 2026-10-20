# Mapeo de diagramas a normas ISO

> Estado: CURRENT · 2026-10-19. **Mapeo orientativo para ingeniería: MOVA no está certificada ni auditada contra ninguna de estas normas.** Sirve para ubicar qué característica o control respalda cada diagrama y dónde está el hueco.

## Ediciones usadas (y una corrección)

| Norma | Edición | Uso en MOVA |
|---|---|---|
| ISO/IEC 25010 | 2023 | Modelo de calidad del producto: adecuación funcional, eficiencia de desempeño, compatibilidad, capacidad de interacción (antes usabilidad), fiabilidad, seguridad, mantenibilidad, flexibilidad (antes portabilidad), seguridad operacional (safety) |
| ISO/IEC/IEEE 29119 | 29119-1:**2022** (conceptos), 29119-2/-3/-4:**2021** (procesos, documentación, técnicas), 29119-5:2016 | Pruebas de software. *Verificado en el catálogo ISO: la parte 1 vigente es 2022; 2021 corresponde a las partes 2, 3 y 4.* |
| ISO/IEC 27001 | 2022 | Controles del Anexo A (numeración 2022: A.5, A.6, A.7, A.8) |
| ISO/IEC 27701 | 2025 | Gestión de información de privacidad (PIMS), norma independiente. Numeración Anexo A (PII controller): A.1.x; (PII processor): A.2.x. *La numeración proviene de fuentes secundarias (isms.online); verificar contra el texto de ISO antes de citarla formalmente.* |
| ISO/IEC 27017 | 2015 | Controles de seguridad para servicios cloud (extiende 27002): controles `CLD.x`. No se encontró edición posterior en la verificación |
| ISO/IEC 27018 | 2025 (3.ª ed., ago-2025) | Protección de PII en nube pública como procesador; alineada con ISO/IEC 27002:2022 |
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

## Privacidad y nube: controles concretos aplicables

| Norma | Control | Aplicación en MOVA | Estado |
|---|---|---|---|
| 27701:2025 | **A.1.3.11** Toma de decisiones automatizada | Recomendación de profesores por reglas (`DiagnosticRecommendationService`) y enriquecimiento IA opcional: no decide ni bloquea; el padre elige. IA externa apagada | Cumple por diseño; falta política escrita y vía de impugnación documentada |
| 27701:2025 | **A.1.4.6** Desidentificación y eliminación de PII al terminar el tratamiento | `Student::anonymize()` al dar de baja; borrado/anonimización de cuenta; avatar borrado en Cloudinary | Parcial: sin calendario de retención ejecutado automáticamente (política publicada, 2026-10-06) |
| 27701:2025 | **A.1.4.5** Minimización de PII | Sentry `send_default_pii=false`; JaaS solo nombre visible e id; sin PAN en BD | Cumple |
| 27701:2025 | A.1.3.x Obligaciones con los titulares (transparencia, derechos) | Política de Privacidad `2026-10-06.2`, libro de reclamaciones | Parcial: sin procedimiento operativo ARCO/derechos medido |
| 27017:2015 | CLD.6.3.1 Roles y responsabilidades compartidos | Azure (IaaS/PaaS) vs MOVA (aplicación, secretos, datos); documentado en `01-deployment.md` | Parcial: matriz de responsabilidad no formal |
| 27017:2015 | CLD.9.5.1 Segregación en entornos virtuales | Producción con BD, usuario y secretos propios; staging separado; entorno ACA/VNet compartido por restricción de la suscripción | Parcial (riesgo aceptado, ver 01) |
| 27017:2015 | CLD.12.1.5 Seguridad operativa del administrador | `az` con cuenta del titular; MFA de admin en la app; sin Key Vault (ver `07-secrets-keyvault-plan.md`) | Gap: secretos como secretos de Container Apps |
| 27017:2015 | CLD.12.4.5 Monitoreo de servicios cloud | Log Analytics 30 d, Sentry, `mova:health-check`; sin alerta de réplicas = 0 | Gap |
| 27017:2015 | CLD.8.1.5 Retiro/devolución de activos del cliente | Export/respaldo de staging, procedimiento de eliminación de recursos | Parcial |
| 27018:2025 | Uso de PII solo según instrucciones del cliente; divulgación y transferencias; retorno/eliminación | MOVA es controlador; sus proveedores (Azure, Cloudinary, Sentry, Google, JaaS, Mercado Pago) actúan como encargados; flujo transfronterizo por declarar ante la ANPD | Pendiente: contratos/DPA por proveedor y registro del banco de datos |

## Continuidad (ISO 22301)

| Parámetro | Valor | Evidencia |
|---|---|---|
| RTO objetivo declarado | No definido formalmente (gap: no hay BIA) | — |
| RTO medido | ≈ 50 min (PITR de 32 GB en B1ms, 2026-10-05) | Runbook §3 |
| RPO | **Pendiente de medir.** Nominal: PITR con copias de registros de transacciones (minutos), retención 14 d. **Objetivo aceptable propuesto (a aprobar por el titular): ≤ 1 hora** dada la baja transaccionalidad actual; revisarlo al abrir pagos a terceros | Runbook §3 |
| Medición del RPO (a ejecutar) | Restaurar a un instante T conocido y comparar la última fila de `credit_transactions`/`payment_orders` con la BD origen; el RPO medido = T − último evento recuperado | Pendiente (objetivo aprobado) |
| Geo-redundancia / alta disponibilidad | No (decisión de costo) | Ledger |
| Aplicación | Rollback por revisión de Container Apps con imágenes por digest | Runbook §2 |

### RPO: objetivo y procedimiento de medición (ISO 22301)

**Decisión sobre «RPO = 0» (2026-10-19): no se declara.** La documentación de Azure atribuye RPO = 0 a la **alta disponibilidad con redundancia de zona** (réplica síncrona en otra zona, conmutación en 60–120 s). El servidor de MOVA (`mova-prod-mysql-8uxzgq`, B1ms) **no tiene HA**, y contratarla duplica aproximadamente el costo del servidor (inviable con el crédito actual). Para la restauración a un punto en el tiempo (PITR) la documentación consultada no publica un intervalo ni una cifra de RPO verificable en el texto que pude leer. Por eso:

| Elemento | Definición |
|---|---|
| RPO = 0 | **Solo** con HA zone-redundant (no contratada). Afirmarlo para MOVA sería falso |
| Objetivo adoptado (aprobado por el titular el 2026-10-19) | **RPO ≤ 1 hora** mientras MOVA no abra pagos a terceros; **≤ 15 min** al hacerlo. Medición: ver procedimiento siguiente; no se declara RPO = 0 sin respaldo oficial de Azure |
| Capacidad nominal | PITR: copias de datos + registros de transacciones (continuas), retención 14 d; ventana real de pérdida **sin medir** |
| Frecuencia de prueba | Antes de abrir pagos a terceros y luego trimestral (el servidor de prueba se elimina: contiene datos personales) |

Procedimiento (solo lectura sobre producción; el servidor restaurado es aislado y temporal):

1. Fijar `T = ahora − 10 min` (UTC). En producción, con una consulta de solo lectura, anotar los valores de referencia a ese instante: `MAX(last_activity)` de `sessions`, `MAX(created_at)` de `credit_transactions` (si hay filas) y el número de migraciones.
2. Restaurar: `az mysql flexible-server restore -g mova-prod-rg -n mova-prod-restore-drill --source-server mova-prod-mysql-8uxzgq --restore-time <T> --subnet <snet-mysql-prod> --private-dns-zone <zona>` (Runbook §3). Costo orientativo de la prueba: servidor B1ms ≈ US$ 0,0187/h × ~1,5 h.
3. Con un Job de solo lectura dentro de la VNet, leer en el restaurado los mismos valores y contar filas.
4. **RPO medido = T − (timestamp del último evento de producción presente en el restaurado con fecha ≤ T)**. Debe ser ≈ 0 para datos confirmados antes de T; además registrar la antigüedad del punto restaurable más reciente que ofrece Azure (`latestRestorableTime` frente a la hora actual) como RPO nominal.
5. Registrar en el Ledger: fecha, T, resultado, RTO medido (≈ 50 min en la prueba del 2026-10-05) y eliminar el servidor de prueba.

**Estado:** pendiente de aprobar el objetivo y de ejecutar la medición.

## Brechas consolidadas (priorizadas)

| Prioridad | Brecha | Norma | Acción |
|---|---|---|---|
| Alta | F-26 credencial histórica en el repositorio público | 27001 A.5.x/A.8.x | Titular: rotar o destruir el volumen de Railway |
| Alta | Error 2072 sin causa raíz | 25010 fiabilidad | Titular: enviar el caso a soporte |
| Media | Sin BIA ni RTO/RPO objetivos aprobados | 22301 | Definir objetivos y probar restauración periódica |
| Media | Consentimiento específico para IA con menores | 27701 / 42001 | Gate antes de activar IA |
| Media | Despliegue manual sin smoke/rollback automatizado | 27001 A.8.32 | `deploy-azure.yml` con OIDC |
| Baja | Sin gate de accesibilidad ni contract tests de rutas/pagos | 25010 / 29119 | Añadir a CI |

### F-26: riesgo aceptado y condiciones de reapertura (2026-10-19)

Estado: **riesgo aceptado por el titular**; no se destruye el volumen de Railway sin su confirmación explícita adicional. Se reabre (Alta) si ocurre cualquiera de estas condiciones: (1) la credencial de Railway sigue aceptando conexiones desde internet; (2) el volumen contiene datos personales reales (sobre todo de menores) o se descubre que algún secreto vigente comparte valor con el histórico; (3) *secret scanning* o los logs de Railway muestran uso no autorizado; (4) MOVA abre pagos a terceros; (5) el repositorio sigue público al cerrar la fase de producción. Cierre definitivo: rotar la credencial, inventariar el contenido del volumen, exportarlo si hace falta y destruirlo con la confirmación del titular.
