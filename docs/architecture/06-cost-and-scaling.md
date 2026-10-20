# 06 · Costos y escalado de Container Apps (decisiones)

> Estado: CURRENT · 2026-10-19. Cada decisión indica la característica de calidad
> afectada (ISO/IEC 25010) y su criterio de verificación (ISO/IEC 29119). Los
> costos son estimaciones a precio de lista y a los consumos medidos en el Ledger
> (lectura del 2026-10-05); la API de Cost Management devolvió 429 el 2026-10-19,
> así que **no hay lectura nueva** y debe repetirse (ver pendientes).

## Diagnóstico (2026-10-19)

| ID | Sev. | ISO 25010 | Hallazgo | Evidencia |
|---|---|---|---|---|
| D-1 | Alta | Fiabilidad (disponibilidad) | `movap-worker` y `movap-scheduler` con `minReplicas=0` y sin reglas de escalado: colas y tareas programadas detenidas | `az containerapp show`: `scale.minReplicas=0`, `rules=null`, 0 réplicas |
| D-2 | Media | Fiabilidad / Eficiencia temporal | `movap-web` con min 0: **arranque en frío de 22,9 s** (`curl /healthz` con 0 réplicas), igual al plazo de respuesta de los webhooks de Mercado Pago (≈ 22 s) | medición directa, `time_total=22.87 s`; con la réplica caliente la portada respondió en 1,1 s |
| D-3 | Info | Mantenibilidad | El IaC fija worker/scheduler en min=max=1; el valor 0 no estaba en el código ⇒ deriva por `az` manual. El default de `webMinReplicas` es 0: un deploy sin `MOVA_WEB_MIN_REPLICAS=1` devuelve el web a 0 | `apps.bicep`, `apps.bicepparam` |
| D-4 | — | Fiabilidad | **No había backlog**: `queue:monitor` = 0 pendientes / 0 retrasados / 0 reservados y `queue:failed` = sin trabajos fallidos; el worker solo ejecutó `WorkerHeartbeatJob`; sin líneas `FAIL` | `queue:monitor`, logs del worker |
| D-5 | — | Seguridad funcional (integridad financiera) | `mova:settle-lessons --dry-run --json` ⇒ `would_consume=0`, `would_escalate_missing_report=0`, `would_review=0`, `invalid=[]`, **`safe_to_enable=true`**. La elegibilidad depende del fin de la clase + 7 días de gracia, no de cuándo corre el scheduler; el crédito ya estaba **reservado** al aceptar | `SettleLessons.php` (`endedBefore($cutoff)`), salida del dry-run |

> Se corrigió una premisa del análisis externo: la parada no “perdió” liquidaciones
> ni generó una ráfaga, porque no había clases elegibles (producción casi sin
> actividad real) y el diseño es idempotente por `idempotency_key`.

## Decisiones

| ID | Rol | Decisión | Costo | Característica ISO 25010 | Criterio de verificación (ISO 29119) | Estado |
|---|---|---|---|---|---|---|
| E-1 | `movap-web` | **min=1**, max=2 | + ≈ US$ 1,7/mes (0,25 vCPU/0,5 GiB en reposo) | Fiabilidad (disponibilidad), Eficiencia temporal | `/healthz` < 2 s tras ≥ 10 min sin tráfico; webhook de prueba firmado responde 200 < 5 s | Aplicado 2026-10-19 (revisión `movap-web--0000024`) |
| E-2 | `movap-worker` | **min=max=1**; **sin KEDA** | ≈ US$ 1,0/mes | Fiabilidad (madurez) | Tras encolar 100 jobs de prueba, `queue:monitor` = 0 en < 5 min; `WorkerHeartbeatJob` cada ≤ 2 min | Aplicado (`…-worker--0000021`). KEDA MySQL descartado: ahorra ≈ US$ 1/mes pero añade secreto, consulta periódica a la BD y un punto de fallo |
| E-3 | `movap-scheduler` | **Mantener `schedule:work` con min=max=1**; no migrar a Job cron | ≈ US$ 2,2/mes | Fiabilidad (recuperabilidad), Eficiencia de recursos | Heartbeat del scheduler < 2 min; `mova:settle-lessons --dry-run` con `safe_to_enable=true` antes de cada rearranque | Aplicado tras el dry-run seguro (`…-scheduler--0000023`). Alternativa Job `* * * * *`: arrancaría 43 200 contenedores/mes, con ahorro ≲ US$ 1–2 y mayor riesgo de solapes/arranque; reevaluar solo si el crédito lo exige |
| E-4 | Todos | No cambiar `LESSON_SETTLEMENT_MODE` (`live`) | 0 | Seguridad funcional | Valor leído sin cambios en las tres apps | Sin cambios; solo el titular decide pasar a `dry_run` |

Costo neto del plan: **+ ≈ US$ 1,7/mes** (solo E-1); worker y scheduler vuelven a su valor de diseño (≈ US$ 3,2/mes ya contemplados en el Runbook). Con el crédito de
US$ 86 hasta 2027-09-14 (≈ US$ 7,9/mes sostenibles) la partida dominante sigue siendo
MySQL/ACR/IP (Runbook §1), no el cómputo de las apps.

## Otras palancas de ahorro evaluadas (sin aplicar)

| Palanca | Ahorro estimado | Riesgo | Decisión |
|---|---|---|---|
| Staging apps a 0 réplicas | ya aplicado | — | hecho |
| MySQL de staging eliminado | ya aplicado | pérdida de QA (datos sintéticos, respaldo local) | hecho |
| ACR Basic → eliminar imágenes viejas | < US$ 0,1 | bajo | opcional |
| IP pública dedicada del entorno | ≈ US$ 3,5/mes | requiere cambiar la topología | inviable (plataforma compartida) |
| Pasar el web a 0,25 vCPU (ya) | — | — | hecho |

## Pendientes de esta línea

1. Repetir la lectura de Cost Management por recurso (429 el 2026-10-19) y comparar con las estimaciones.
2. Añadir `MOVA_WEB_MIN_REPLICAS=1` al procedimiento de deploy de producción (Runbook §2): ya comentado en `apps.bicepparam`.
3. Alerta/monitor que detecte réplicas = 0 de worker o scheduler (hoy `/readyz` no lo ve; `system_heartbeats` caducados sí deberían generar alerta crítica: verificar con `mova:health-check`).
