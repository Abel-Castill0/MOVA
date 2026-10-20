# 05 · Verificación de la auditoría externa (2026-10-19)

Una auditoría externa (texto pegado por el propietario) se contrastó contra el
código de `master`, el Ledger y el Runbook. Resultado: **su tesis (distancia
entre lo documentado, lo probado y lo desplegado) es correcta, pero varios
hallazgos concretos son falsos u obsoletos**. Este documento fija la verdad;
los falsos no deben volver a entrar en backlogs.

Leyenda: ✅ cierto · ❌ falso · ⚠️ parcial / obsoleto · 📌 acción.

## Hallazgos que la auditoría marcó como críticos

| # | Afirmación | Veredicto | Evidencia y acción |
|---|---|---|---|
| C1 | Azure está en `LESSON_SETTLEMENT_MODE=dry_run`; el flujo financiero está simulado | ❌ obsoleto | El `dry_run` del README de `infra/azure` es de **staging, 2026-09-29**. Desde 2026-10-06 producción corre `live` (ledger interno, sin dinero real; Ledger «Estado de lanzamiento controlado»). 📌 verificar el modo efectivo con `az containerapp show` (solo lectura) en cada cierre de fase |
| C2 | Railway suspendido ⇒ no hay rollback operativo | ⚠️ cierto el hecho, falso el diagnóstico | Railway está `FAILED` desde julio de 2026 y es **LEGACY**; el rollback real es `az containerapp revision activate` + imágenes por digest en ACR (01-deployment). El `README.md` raíz seguía diciendo «Railway producción actual»: **corregido** |
| C3 | Error 2072 sin causa raíz | ✅ cierto | Documentado en el incidente y en `MERCADOPAGO_SUPPORT_CASE_2072.md` (sin enviar). La clasificación ya trata 2072 como terminal y guarda `x-request-id`. 📌 enviar el caso a soporte desde la cuenta del titular; no probar importes por conjetura. La sugerencia de «`currency_id: PEN` explícito» es una hipótesis **no** respaldada: la moneda la fija el sitio de la cuenta (`MPE`) |
| C4 | Credenciales expuestas en la rama pública `Elias` | ⚠️ parcial | `origin/Elias` (109 commits) está contenida en `master`; el incidente **F-26** (credencial MySQL de Railway en el historial) sigue **ABIERTO** por diseño ([MOVA_CREDENTIAL_EXPOSURE](../MOVA_CREDENTIAL_EXPOSURE.md)); el deployment de MySQL en Railway quedó `REMOVED` (2026-09-15) pero su volumen sigue `Ready` y no debe borrarse sin confirmar su contenido, así que **no se puede asumir** que la credencial esté muerta. 📌 confirmar rotación y decidir la purga del historial (`git filter-repo` reescribe historia pública: decisión del titular) |
| C5 | La documentación declara Railway como producción | ✅ cierto (era) | `README.md` raíz y `docs/DEPLOY_RAILWAY.md`. **Corregidos**: README actualizado y banner HISTÓRICO en la guía de Railway |

## Hallazgos altos

| # | Afirmación | Veredicto | Evidencia / acción |
|---|---|---|---|
| A1 | Culqi habilitado pero no implementado | ✅ | `config/payments.php` lo lista en `supported_providers`; `CulqiPaymentProvider` lanza `RuntimeException`. 📌 retirar `culqi` de `supported_providers` o bloquear el arranque con un test (cambio de código: fuera de esta tarea documental) |
| A2 | Consentimientos de menores para IA no documentados | ⚠️ | Consentimiento parental por menor **sí existe** (`student_data_consents`, `legal_acceptances`, decisiones §1). La IA externa está apagada y la política lo declara. Falta consentimiento específico **antes de activar IA**. 📌 gate antes de `AI_*` |
| A3 | MySQL sin HA ni geo-backup | ✅ | Decisión de costo con RTO probado ≈ 50 min y PITR 14 d (01-deployment) |
| A4 | Migraciones sin rollback ni prueba incremental | ⚠️ | El deploy usa un Job manual y `/readyz` bloquea revisiones con migraciones pendientes; migraciones recientes son aditivas con `down()`; CI ejecuta MySQL 8.4 completo. Falta prueba incremental sobre dump anonimizado |
| A5 | `PaymentOrder` pendiente indefinido | ⚠️ | Hay recuperación (`recovery_attempts`), `review_detected_at`/`resolved_at`, alerta crítica y cierre administrativo auditado; el incidente 1 se resolvió así. Sin TTL automático declarado |
| A6 | Sin TTL para clases `pending` | ❌ | No existe el estado `pending` (es `scheduled`); `mova:settle-lessons` escala a `needs_admin_review` a los 7 días y libera/consume (03) |
| A7 | `SendClassReminders` ignora referidos | ❌ ya corregido | Usa `ClassRequest::eligibleTeacherUsers()`, el mismo camino que `SendClassRequestNotifications` (comentario en `SendClassReminders.php`) |
| A8 | Staging no aislado para pagos | ⚠️ | Producción tiene BD, usuario, `APP_KEY` y secretos propios (`movap-*`); comparten entorno ACA/VNet/ACR por la restricción de la suscripción Student |

## Medios y bajos

| Hallazgo | Veredicto |
|---|---|
| `Lesson` → tabla `classes`; `paid` ambiguo | ✅ y ya documentado en el docblock de `Lesson.php` (F-11); ver 03 |
| Contract testing contra sandbox de Mercado Pago | ⚠️ existe `MercadoPagoSandboxSmoke` y rondas `LIVE_STAGING` en el Ledger; no corre en CI (necesita credenciales) |
| Sin gate de accesibilidad en CI | ✅ (hay revisión manual y E2E multi-viewport) |
| Despliegue manual, sin `deploy-azure.yml` | ✅ 📌 automatizar con OIDC (sin permisos inventados) |
| Webhooks WhatsApp/JaaS «inertes» | ⚠️ JaaS: **activo en producción desde 2026-10-06** (solo evidencia de presencia). WhatsApp: apagado |
| «Falta `CLAUDE.md`» | ❌ existe en la raíz (y reglas en `.claude/rules/`) |
| `Elias` obsoleta (Laravel 10, Zoom) | ⚠️ cierto el contenido, pero está contenida en `master`; archivar con un tag antes de borrar la rama remota |
| Falta `docs/architecture/` | ✅ → **creado** (este directorio) |

## Errores del modelo de datos en el texto original

Para que no se propaguen: `students` usa `parent_user_id`/`first_name`/`last_name`
(no `user_id`/`name`/`surname`); `teacher_profiles` no tiene `credits` sino
`credits_available` y `credits_reserved`; el ledger se llama `credit_transactions`
con `lesson_id` (no `class_id`), sin `balance_after` y con `type` en
`deposit|reservation|consumption|refund|reversal`; `classes.status` inicia en
`scheduled`; la aceptación **reserva** créditos (no los «debita»); `ai_usage_logs`
no guarda costo en USD. El ER correcto está en [02](02-data-model.md).

## Pendientes reales (priorizados, tras la verificación)

| Prioridad | Acción | Quién |
|---|---|---|
| P0 | Cerrar F-26: confirmar rotación de la credencial histórica de Railway y decidir purga del historial | Titular |
| P0 | Enviar el caso 2072 a soporte de Mercado Pago; no abrir cobros a terceros hasta la respuesta | Titular |
| P1 | Retirar Culqi de `supported_providers` (o fallo explícito al arrancar) | Código |
| P1 | Confirmar el modo efectivo de liquidación en las 3 apps con `az` (lectura) | Operación |
| P1 | Automatizar deploy con OIDC + smoke + rollback | Operación |
| P2 | Consentimiento específico antes de activar IA con datos de menores | Producto/Legal |
| P2 | Prueba incremental de migraciones sobre dump anonimizado | Código |
| P3 | Gate de accesibilidad automático en CI | Frontend |
