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

## Reevaluación del scheduler con costos medidos (2026-10-19, 2.ª ronda)

Cost Management (API, acumulado 2026-10-01 → 10-19/20, por recurso; solo lectura):

| Recurso | US$ acumulados | Nota |
|---|---|---|
| `movap-scheduler` (producción) | 4,83 | ≈ 7,5/mes prorrateado; ≈ 10/mes si estuvo apagado parte del periodo |
| `movap-worker` / `movap-web` (producción) | 2,43 / 2,39 | |
| `mova-scheduler` / `mova-worker` (**staging**, hoy a 0 réplicas) | 7,36 / 7,09 | consumo ya hecho; revisar que no vuelvan a arrancar |
| ACR + IP pública + DNS privado | 3,14 + 2,27 + 0,34 | |
| MySQL | 0 | cubierto por la capa gratuita |
| **Total de los 14 recursos principales** | **≈ 30,9** | frente al crédito de US$ 86 hasta 2027-09-14 |

Las cifras de la comparación externa (always-on ≈ US$ 15,55/mes frente a Job ≈ US$ 0,04/mes) son de **lista y para otro régimen**: 15,55 = 0,25 vCPU × tarifa activa × 30 días **sin** tarifa de reposo ni cuotas gratuitas, y 0,04 corresponde a pocas ejecuciones al día. El cargo medido del scheduler es ≈ US$ 7,5–10/mes.

**Frecuencia real de tareas (`app/Console/Kernel.php`, logs del scheduler):** 2 tareas **cada minuto** (`classmate:send-reminders` ≈ 0,3–0,8 s y el latido `mova:heartbeat` ≈ 50 ms) ⇒ 2 880 ejecuciones/día; cada 5 min `mercadopago:reconcile`; 3 tareas cada hora (`mova:settle-lessons`, `mova:health-check --alert`, `mova:expire-class-requests` al minuto 30); 1 diaria (`mova:reconcile-ledger` 03:10).

| Opción | Costo estimado/mes | Riesgo | ISO 25010 |
|---|---|---|---|
| A. `schedule:work` always-on (actual) | ≈ 7,5–10 (medido) | bajo; un solo proceso, locks `withoutOverlapping` | Fiabilidad ✔ · Eficiencia de desempeño ✘ |
| B. Container App Job `* * * * *` ejecutando `schedule:run` | ≈ 0–3,2: 43 200 ejecuciones × ~10 s × 0,25 vCPU = 108 000 vCPU-s (≈ US$ 2,6) + 216 000 GiB-s (≈ US$ 0,65), menos las cuotas gratuitas mensuales de la suscripción (180 000 vCPU-s y 360 000 GiB-s, compartidas con las demás apps) | arranque de contenedor por minuto (latencia 3–30 s), posibles minutos omitidos o solapados, el latido deja de ser continuo, `/readyz` más frágil | Eficiencia ✔ · Fiabilidad ✘ (media) |
| C. Híbrido: Job por minuto solo para las 2 tareas por minuto y Job horario para el resto | ≈ igual que B | más piezas que mantener | Mantenibilidad ✘ |

**Decisión (criterio no cumplido):** la propuesta externa exigía una frecuencia **horaria o menor** y la carga real es **por minuto**, así que **no se escribe el módulo Bicep del Job ni se migra**. Ahorro potencial de B: **≈ US$ 4,5–10/mes** (≈ US$ 50–100 hasta 2027-09), a costa de fiabilidad. Antes de decidir, medir la duración real de un `php artisan schedule:run` en el contenedor (arranque + ejecución) y el cargo de uno o dos días del Job de prueba **en staging**. Requiere aprobación del titular.

> **Corrección:** las estimaciones de worker/scheduler de la 1.ª ronda (≈ US$ 1,0 y 2,2/mes, derivadas de la lectura del 2026-10-05) quedan reemplazadas por los valores medidos de esta tabla.

## Plan de reducción de gasto (3.ª ronda, 2026-10-19)

Objetivo: bajar de ≈ US$ 23–26/mes sin romper pagos ni liquidación. Estado de cada acción (ISO 25010: eficiencia de desempeño, utilización de recursos; contrapeso: fiabilidad):

| # | Acción | Ahorro estimado (US$/mes) | Riesgo | Verificación | Estado / fecha |
|---|---|---|---|---|---|
| 1 | Apagar staging: `mova-web/worker/scheduler` en **Stopped** (acción `stop` de la API ARM `2024-10-02-preview`; la CLI 1.3.0b5 no trae `az containerapp stop`). No existe MySQL de staging (eliminado 2026-10-06): `az mysql flexible-server list` solo muestra el de producción, que **no se toca** | ≈ 0 adicional (ya estaban a 0 réplicas); evita un arranque accidental (el staging gastó US$ 14,45 en octubre antes de apagarse) | Bajo; reversible con la acción `start` | `az containerapp list`: staging `Stopped`, producción `Running`; `/healthz` de producción 200 | **Hecho** 2026-10-19 |
| 2 | Scheduler: confirmar frecuencia real | — | — | 2 tareas cada minuto, 5 min, 3 horarias, 1 diaria (ver sección anterior) | **Hecho** (diagnóstico) |
| 2b | Scheduler a cron reducido (15–30 min) | ≈ 0 por sí solo si sigue always-on; solo ahorra si pasa a Job | **Alto: cambia comportamiento** (recordatorios y latido por minuto; requiere código en `Kernel.php`) | — | **No aplicado**; decisión del titular |
| 2c | Scheduler como Job cron `* * * * *` | 4,5–10 | Medio (fiabilidad): ver opciones B/C arriba | Prueba en staging con medición de duración y cargo | **Pendiente de aprobación** |
| 2d | **Un solo contenedor** para worker + scheduler (`sh -c` con `schedule:work` y `queue:work` y `wait -n`; solo `args` en Bicep, sin tocar la imagen) | ≈ 3,8 (la línea base del worker) | Medio: un OOM o fallo afecta a ambos; 0,5 GiB para dos procesos PHP por medir; el contenedor se reinicia si cualquiera termina | Prueba en staging: memoria máxima < 70 %, `queue:monitor` = 0 y tareas del scheduler en logs | **Pendiente de aprobación** |
| 3 | `movap-web` a 0,25 vCPU / 0,5 GiB | 0 (ya estaba así en producción desde 2026-10-06) | — | `az containerapp show`: 0,25 / 0,5 GiB; sin líneas OOM; arranque en frío medido 22,9 s (< 30 s) y con min=1 no aplica. **El IaC decía 0,5 / 1 GiB**: corregido en `apps.bicep` para que un deploy no duplique el costo (≈ + 1,7–3,5/mes evitados) | **Hecho** (IaC alineado) |
| 4 | KEDA scale-to-zero del worker | 0 real | — | `app/Console/Kernel.php:178` despacha `WorkerHeartbeatJob` **cada minuto**: la cola nunca está vacía más de 60 s, así que un escalador por profundidad mantendría el worker encendido (y facturando) todo el tiempo; además el latido alimenta la alerta de worker caído. Un escalador también necesitaría un secreto de conexión a MySQL y alcance a la red privada | **Descartado** con evidencia (sin cambios) |
| 5 | Alerta de presupuesto de US$ 15/mes | 0 (informa, no detiene gasto) | — | `az rest PUT` sobre `Microsoft.Consumption/budgets` (2023-05-01) devolvió `amount=15.0` el 2026-10-19; avisos 50 % y 80 % reales y 100 % previsto conservados | **Hecho** (el presupuesto conserva el nombre `mova-monthly-20`, importe 15) |

**Resumen del ahorro.** Acciones ya aplicadas: ahorro adicional ≈ **US$ 0/mes** (el cómputo de staging y el sobredimensionado del web ya estaban cubiertos; el IaC corregido evita un aumento futuro). Ahorro **disponible con aprobación**: Job cron del scheduler ≈ US$ 4,5–10 y/o contenedor único worker+scheduler ≈ US$ 3,8; combinados se estiman ≈ US$ 12–17/mes frente a ≈ US$ 23–26 actuales. Costos fijos que no se pueden recortar sin cambiar de arquitectura: ACR ≈ 5, IP pública ≈ 3,7, web ≈ 3,7, DNS ≈ 0,5.

## Implementación de las opciones 2c y 2d (4.ª ronda, 2026-10-19) — escritas, NO desplegadas

| Opción | Dónde | Cómo activarla | Estado |
|---|---|---|---|
| 2c · Scheduler como Job cron | `infra/azure/modules/container-job.bicep` + `apps.bicep` (`schedulerAsJob`, `schedulerCron`) | `deployScheduler=true`, `schedulerAsJob=true`; mutuamente excluyente con la app del scheduler y con 2d (así nunca hay dos planificadores) | Compila; default `false` (sin cambio en producción) |
| 2d · Worker + scheduler en un contenedor | `apps.bicep` (`combineWorkerScheduler`) | `deployWorker=true`, `combineWorkerScheduler=true`; usa `bash` + `wait -n`: si un proceso termina, el contenedor sale con 1 y se reinicia | Compila; default `false`; requiere comprobar que `bash` existe en la imagen y la memoria con 0,5 GiB |

**Sobre la frecuencia pedida (cada 15 min):** el cron mínimo de Container Apps es 1 minuto y el valor por defecto se deja en `* * * * *`, porque cambiarlo a 15 minutos retrasaría los recordatorios y haría que el latido (`mova:heartbeat`) pareciera caído; eso es un cambio de comportamiento (código), no de infraestructura.

**Bloqueo de la prueba en staging:** staging no tiene base de datos (eliminada el 2026-10-06 para ahorrar crédito) y **no se debe apuntar un Job de prueba a la base de producción**. Para medir costo y fiabilidad hay que recrear un MySQL B1ms de staging (Runbook §1b): ≈ US$ 0,6/día fuera de la capa gratuita (esta ya cubre el servidor de producción), es decir ≈ US$ 1–2 por una prueba de 48 h. **Decisión del titular:** autorizar ese gasto, o aceptar el ahorro estimado sin medirlo. Ahorros estimados: 2c ≈ US$ 4,5–10/mes; 2d ≈ US$ 3,8/mes (no acumulables si se elige 2c: el worker queda solo).

## Estado de la 5.ª ronda (2026-10-19)

- **Presupuesto a US$ 15:** **bloqueado.** Escribir presupuestos devuelve `RBACAccessDenied` («Interactive authentication is needed»): la sesión `az` de este equipo necesita un `az login` interactivo del titular. Hasta entonces sigue `mova-monthly-20`. Edición manual: portal, Cost Management, Presupuestos.
- **Job cron del scheduler:** Bicep listo (`schedulerAsJob`, cron por minuto, apagado por defecto). **No medido ni desplegado**: Cost Management publica el consumo con 24–48 h de retraso, la prueba exigiría recrear una base de staging y sus credenciales, y no se aplicará a producción sin medición. Ahorro **estimado** 4,5–10 US$/mes.

### Procedimiento de la prueba de 48 h del Job cron (no ejecutada)

No se pudo ejecutar en esta sesión: exige ≥ 48 h de observación y el consumo se publica con 24–48 h de retraso. Pasos, para quien la ejecute:

1. Recrear el MySQL de staging (Runbook §1b): B1ms privado en `snet-mysql`, base `mova` y usuario `mova_app` con un Job de bootstrap; `php artisan migrate --force` y, solo en staging, `db:seed --class=LocalTestDataSeeder`. Costo ≈ US$ 0,6/día.
2. Cargar los secretos de **staging** (nunca los de producción) y desplegar `apps.bicep` con `deployScheduler=true`, `schedulerAsJob=true`, `deployWorker=true` y `MOVA_APP_NAME_PREFIX=mova`.
3. Observar 48 h: por minuto, `system_heartbeats` y ejecuciones del Job (`az containerapp job execution list`); contar minutos omitidos y solapes.
4. Criterios (ISO 29119): ≥ 99 % de los minutos con latido < 2 min, 0 solapes, duración mediana de `schedule:run` < 20 s, cargo extrapolado < US$ 3,2/mes (Cost Management por `ResourceId` del Job).
5. Eliminar el servidor MySQL de staging y detener las apps de staging al terminar. Solo si se cumplen los criterios, preparar el cambio de producción (nunca coexistir con `movap-scheduler`).

### Presupuesto US$ 15/mes: procedimiento exacto para el titular

Estado: ya aplicado. Si hiciera falta repetirlo: portal, Cost Management, Presupuestos, `mova-monthly-20`, Editar, importe **15**; o `az rest --method put` sobre `/providers/Microsoft.Consumption/budgets/mova-monthly-20?api-version=2023-05-01` con `amount: 15`.

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
