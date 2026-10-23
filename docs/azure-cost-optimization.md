# MOVA — Optimización de costos en Azure (Azure for Students)

> Estado: CURRENT · 2026-10-22. Cifras en US$. **Medido** = leído de Cost Management / Azure Monitor / `az` en esta fecha.
> **Estimado** = cálculo sobre tarifas observadas. Los costos de Azure se publican con 24–48 h de retraso: el ahorro de la
> sección 5 es **estimado** hasta que existan ≥ 2 días completos de facturación posteriores al cambio (ver §7).
> Sin secretos. Complementa `docs/architecture/06-cost-and-scaling.md` (decisiones y rondas previas) y el Runbook.

## 1. Suscripción, crédito y límite de gasto

| Dato | Valor | Fuente |
|---|---|---|
| Oferta | `AzureForStudents_2018-01-01`, estado `Enabled`, **límite de gasto activado (`spendingLimit=On`)** | `az rest GET /subscriptions/{id}` |
| Crédito total | **US$ 100** anuales, 2026-09-01 → 2027-09-30 (presupuesto `Developer-Pack`); AGENTS.md fija el vencimiento operativo en 2027-09-14 | Consumption Budgets |
| Gastado acumulado | **US$ 44,91** (2026-09-15 → 2026-10-22; `Developer-Pack` marca 44,77) | Cost Management (ActualCost) |
| Crédito restante (estimado) | **≈ US$ 55,1** (100 − 44,9; el saldo exacto solo lo da el portal de la oferta: <https://www.microsoftazuresponsorships.com/balance>) | cálculo |
| Gasto de los últimos 7 / 14 / 30 días | 3,90 / 22,47 / 42,62 (la caída del 16-oct es el apagado de staging; el salto del 20-oct es el paso de web/worker/scheduler a min=1) | Cost Management |
| **Ritmo actual** | **≈ US$ 1,15/día ≈ US$ 34,4/mes** (21-oct, día completo con todo el cómputo en min=1) | Cost Management |

**Consecuencia del límite de gasto:** con `spendingLimit=On`, cuando se agote el crédito Azure **deshabilita** los recursos (no cobra). A US$ 34,4/mes el
crédito alcanza ≈ **48 días (≈ 9 dic 2026)**. Para llegar a 2027-09-14 (≈ 326 días) el gasto tendría que ser ≤ **US$ 5,1/mes**, imposible con
Container Apps siempre encendido (solo el ACR y la IP suman US$ 8,6/mes). Quien decida pasar a pago por uso (no autorizado en AGENTS.md) o mover el
cómputo fuera de Azure debe decidirlo el titular. **Términos de la oferta:** es un crédito educativo/de aprendizaje; Microsoft puede restringir su uso comercial. Revisar los términos
de la oferta antes de operar MOVA como negocio con cobros reales (no se pudo verificar el texto vigente por CLI).

Franquicias gratuitas: **MySQL Flexible Server B1ms + 32 GiB cubierto (costo 0 medido)**; Log Analytics bajo la cuota gratuita (1,5 GB/30 d, costo 0); **las cuotas gratuitas
de Container Apps (180 000 vCPU-s / 360 000 GiB-s al mes) NO se observan aplicadas** en la facturación de esta suscripción (el costo de cada app coincide con tarifa de lista × tiempo): no contar con ellas.

## 2. Inventario real de recursos (suscripción `Azure for Students`)

Solo `mova-prod-rg` y su RG gestionado pertenecen a MOVA. `rg-healthypets` (2 Container Apps, ≈ US$ 0,2/mes), `chambi-rg`, `MOVA-RECURSOS` y los `DefaultResourceGroup-*`/`NetworkWatcherRG` son de **otros proyectos o gratuitos: no se tocaron**.

| Recurso | Tipo / SKU | Región | Config relevante | Uso medido | Costo 21-oct (día) | US$/mes | ¿Indispensable? | ¿Optimizable? |
|---|---|---|---|---|---|---|---|---|
| `movap-web` | Container App, Consumption 0,25 vCPU / 0,5 GiB | mexicocentral | min 1 / max 2, sin reglas de escala, probes L/R/S, revisión única | CPU media 3,8 mCPU, RAM 138–192 MiB (27–38 %) | 0,204 | 6,1 | Sí (web, webhooks) | Solo `min=0` (ver C-2) |
| `movap-worker` | idem | idem | min=max 1 → **Stopped desde 2026-10-22 (C-1), 0 réplicas** | 2 mCPU, 111–129 MiB | 0,194 | 5,8 | Sí (cola de notificaciones y latido) | Fusionar con el scheduler (C-1) |
| `movap-scheduler` | idem | idem | min=max 1, **`queue:work` + `schedule:work` en un contenedor desde 2026-10-22 (C-1)** | **10,3 mCPU** (umbral "activo" = 10), 109–130 MiB | **0,441** | **13,2** | Sí | **Hecho: OPcache CLI (§4)**; resto C-1 |
| `mova-web/worker/scheduler` | Container Apps de **staging** | idem | **Stopped** (0 réplicas) | — | 0 | 0 | No (staging sin BD) | Ya optimizado; mantener detenidas |
| `mova-aca-env` | Managed Environment (solo Consumption), logs a Log Analytics | idem | IP estática `68.155.88.233`, 3 certificados gestionados | — | (IP + LB) | — | Sí (único permitido por la suscripción) | No |
| `capp-svc-lb-ip` + `capp-svc-lb` (RG `ME_mova-aca-env_…`) | IPv4 Standard estática + LB Standard (platform-managed) | idem | 2 reglas | — | 0,120 | 3,6 | Sí (ingreso del entorno) | No (topología) |
| `movaacrsplisbj6ldoqw` | ACR **Basic** | idem | admin deshabilitado, 21 tags / 60 manifiestos, **2,26 de 10 GiB** | pulls por identidad | 0,167 | 5,0 | Sí (imágenes y rollback) | Solo cambiando de registro (C-3) |
| `mova-prod-mysql-8uxzgq` | MySQL Flexible 8.4 **B1ms Burstable**, 32 GiB, HA off, privado | idem | PITR 14 d, backups diarios + manual previo al despliegue | CPU 9 % (máx. 32), RAM 19 %, disco 2 %, 4,5 conexiones, 274 créditos CPU | **0** (capa gratuita) | 0 | **Sí, no tocar** | No |
| `kv-movap-192f01` | Key Vault standard, RBAC, purge protection | idem | 10 secretos | — | 0,01 | 0,3 | Sí | No |
| `mova.private.mysql.database.azure.com` (+link) | Private DNS zone | global | 2 recordsets | — | 0,019 | 0,6 | Sí (acceso privado a MySQL) | No |
| `mova-logs` | Log Analytics `PerGB2018`, retención 30 d, tope 1 GB/día | idem | consola de las apps | 1,5 GB / 30 d (≈ 0,7 MB/día de las 3 apps hoy) | 0 | 0 | Sí (observabilidad de pagos) | No necesita recorte |
| `mova-vnet`, `mova-apps-identity`, `NetworkWatcher_*` | VNet 10.20.0.0/16 (3 subredes), identidad administrada, Network Watcher | varias | — | — | 0 | 0 | Sí | No |
| Jobs `mova-maint-*`, `movap-*` antiguos, ACR `movapacr…`, storage `movaimport…`, MySQL de staging/`restoretest` | **ya eliminados** (aparecen en Cost Management con costo 0) | — | — | — | 0 | 0 | — | — |

**Costo mensual medido por línea (21-oct × 30):** scheduler 13,2 · web 6,1 · worker 5,8 · ACR 5,0 · IP 3,6 · DNS 0,6 · Key Vault 0,3 · MySQL 0 · logs 0 ⇒ **≈ US$ 34,6** (coincide con el ritmo medido de 34,4).
**Piso del diseño actual** (los tres con tarifa de reposo): 3 × 5,6 + 9,5 fijos = **US$ 26,3/mes**.

## 3. Por qué cuesta lo que cuesta (evidencia de facturación por medidor)

- Cada réplica 0,25 vCPU / 0,5 GiB cuesta aunque no reciba tráfico: memoria 0,124/día + vCPU en reposo 0,062/día = **0,186/día (US$ 5,6/mes)**. No se puede bajar (0,25/0,5 es la combinación mínima de Consumption).
- `movap-scheduler` factura **vCPU activa ≈ 0,27/día** (≈ 52 % del día en tarifa activa, 8× la de reposo) porque `schedule:work` lanza dos procesos `php artisan` cada minuto
  (`schedule:run` y `classmate:send-reminders`) y su CPU media (10,3 mCPU) supera el umbral de 0,01 vCPU de Container Apps. Worker (2 mCPU) y web (3,8 mCPU) casi nunca superan el umbral.
- Con `opcache.enable_cli = 0` (valor heredado en `docker/php.ini`) **cada arranque de `php artisan` recompilaba todo Laravel**.

## 4. Optimización ejecutada (categoría B: requiere imagen nueva; sin interrupción)

**Cambio:** `docker/php-cli.ini` (nuevo, solo CLI) activa OPcache de **archivos** con JIT apagado y se elimina `opcache.enable_cli = 0` de `docker/php.ini`; el `Dockerfile` copia el ini
al final (para que ningún bytecode de build entre en la imagen) y crea `/tmp/opcache-cli`. El servidor web FrankenPHP **no** usa `php-cli.ini` y conserva su OPcache en memoria compartida. Commit `a32a1f0`.
Sin cambios de código Laravel, de reglas de negocio, de frecuencia de tareas ni de variables de entorno.

| Medición local (imagen de producción, 20 arranques de `php artisan list`) | CPU | Reloj |
|---|---|---|
| Antes (imagen `77667e9`) | 3,16 s | 3,17 s |
| Después (imagen `a32a1f0`) | 1,88 s (**−40 %**) | 1,29 s (**−59 %**) |
| Banco previo con JIT apagado y caché de archivos (30 arranques) | −58 % a −72 % de CPU | — |

Despliegue (2026-10-22 ≈ 10:07 UTC): imagen `movaacrsplisbj6ldoqw.azurecr.io/mova:release-20261022-a32a1f0` → digest
`sha256:fece8113f637667f47240a8f744812c45f608d31b3253e94b3a51c3d5fa964e4`; revisiones web `0000025→0000026`, worker `0000022→0000023`, scheduler `0000024→0000025`.
**Rollback:** `az containerapp update -g mova-prod-rg -n <app> --image movaacrsplisbj6ldoqw.azurecr.io/mova@sha256:bf92e6dca233d42a2b07ac54c15b116d7defe2ec30fc5bec49086a1bfa62dc6a`
(digest de `77667e9`, ver `docs/release/DEPLOY_HISTORY.md`). Sin migraciones de por medio.

Verificación funcional tras el cambio: `/readyz` `ready` (BD, migraciones, caché ok), `/healthz`, `/quienes-somos`, `/login` 200; el scheduler ejecuta `classmate:send-reminders`
(≈ 0,35–0,39 s), `mova:heartbeat` y `mercadopago:reconcile` cada 5 min; el worker procesa `WorkerHeartbeatJob`; sin líneas de error.

## 5. Ahorro

| Concepto | Antes (US$/mes) | Después (US$/mes) | Ahorro | Estado |
|---|---|---|---|---|
| Scheduler (vCPU activa → reposo) | 13,2 | **≈ 5,6–9** | **≈ 4–7,6** | **Estimado**; CPU media medida 10,3 → ≈ 6 mCPU en los primeros minutos (ver §7); falta confirmar por facturación |
| Presupuesto/alertas de crédito | 0 | 0 | 0 | Hecho (gratuito) |
| Resto (web, worker, ACR, IP, DNS, KV, MySQL, logs) | 21,4 | 21,4 | 0 | Sin cambios: ya en su mínimo técnico |
| **Total** | **34,4** | **≈ 26,3–30** | **≈ 4–8 (12–22 %)** | Estimado |

El tope teórico de este diseño (todos en reposo) es **26,3/mes**; el ahorro confirmado se medirá en §7.

## 6. Propuestas que requieren autorización (categoría C) y descartadas (D)

| # | Propuesta | Ahorro/mes | Riesgo / consecuencia | Decisión necesaria |
|---|---|---|---|---|
| C-1 | **Un solo contenedor worker + scheduler** (supervisor `docker/mova-supervise.sh`) | **≈ 5,8** (worker detenido) | Destino de fallo compartido: mitigado con supervisor (reinicio del contenedor si un proceso cae, SIGTERM reenviado, el job en curso termina) | **HECHO 2026-10-22** (§10.1): 195 min medidos en Azure sin reinicios; ahorro por confirmar con facturación |
| C-2 | `movap-web` `min=0` | ≈ 5,3 | Arranque en frío medido 22,9 s (≥ ~14 s aun optimizando sondas); plazo de webhook de Culqi **no documentado** | **NO activar** (§10.2). No hay riesgo resuelto que lo permita |
| C-3 | Sustituir el ACR Basic por GitHub Container Registry | ≈ 5,0 | Dependencia de GitHub para nuevos arranques; las imágenes de rollback actuales solo existen en el ACR | **Paso 1 HECHO 2026-10-22** (web y scheduler ya sirven desde ghcr.io, §10.3). **Falta tu aprobación para eliminar el ACR** (no antes de ≥ 1 semana) |
| C-4 | Scheduler como Container Apps **Job** cada minuto | ≈ 10 frente al estado previo, ≈ 2 frente al piso | Contenedor por minuto (arranque/pull), posibles solapes; ya analizado y descartado en `06-cost-and-scaling.md` | No recomendado salvo prueba |
| C-5 | Eliminar el worker usando `QUEUE_CONNECTION=sync` en el scheduler | ≈ 5,6 | Las notificaciones de recordatorio pasarían a ejecutarse dentro de la transacción del recordatorio: un fallo de correo revertiría el reclamo y **podría duplicar envíos**; pierde reintentos/backoff | Rediseño con revisión de seguridad financiera/notificaciones |
| C-6 | **Todo en uno**: web + cola + scheduler en un contenedor (`infra/azure/proposals/mova-supervise-aio.sh`) | ≈ 5,1 adicionales sobre C-1 | Web y tareas comparten fallo/CPU; exige `maxReplicas=1`; picos de memoria 243 MiB de 512 | **Probado en local con carga (§10.4). Necesita tu aprobación** |
| D-1 | Reducir CPU/RAM por debajo de 0,25/0,5 | — | No existe en Consumption | — |
| D-2 | Reducir retención de backups de MySQL, apagar/eliminar MySQL, SQLite en producción | 0 (la BD ya es gratuita) | Pérdida de recuperación | No |
| D-3 | Recortar logs | 0 | El workspace ya está bajo la cuota gratuita; los logs de pagos/webhooks son necesarios | No |
| D-4 | VM B1s gratuita + MySQL gratuito como alternativa de arquitectura | ≈ 25 (≈ 4–5/mes en total) | **No disponible**: `Standard_B1s` no aparece en mexicocentral ni en canadacentral/westus/northcentralus/brazilsouth para esta suscripción (la política de regiones permitidas lo limita); `B2ts_v2` figura `NotAvailableForSubscription` | No viable hoy |

**Cifras corregidas con las mediciones de la 2.ª ronda:** con C-1 hecho el gasto estimado es **US$ 21–28,6/mes**; con C-3 + C-6 el rango sería **US$ 11–18,7/mes** (el extremo bajo exige que el vCPU del contenedor combinado se facture casi siempre en reposo). Ninguna combinación dentro de Azure Container Apps llega a los US$ 5,1/mes necesarios para cubrir hasta 2027-09-14; hacerlo exigiría mover el cómputo fuera de Azure o pasar a pago por uso, decisiones del titular. Ver §10.5.

## 6b. Duración del crédito (estimación con ≈ US$ 55,1 restantes)

| Escenario | US$/mes | Duración | Fecha aprox. de agotamiento |
|---|---|---|---|
| Antes de esta optimización | 34,4 | 48 días | ≈ 9 dic 2026 |
| Hoy (scheduler en reposo, estimado 26,3–30) | ≈ 28 | ≈ 59 días | ≈ 20 dic 2026 |
| + C-1 | ≈ 21 | ≈ 79 días | ≈ 9 ene 2027 |
| + C-1, C-2, C-3 | ≈ 11 | ≈ 150 días | ≈ 20 mar 2027 |
| Meta: llegar al 2027-09-14 | ≤ 5,1 | 326 días | — (no alcanzable en este diseño) |

## 7. Presupuesto, alertas y verificación

- `mova-credito-estudiante-100` (nuevo, gratuito): US$ 100 anuales (2026-09-01 → 2027-09-30), alertas **al 50 %, 75 % y 90 % del crédito** (real) y previsión > 100 %, al mismo contacto que ya usaba `mova-monthly-20`. Con el ritmo actual el 50 % (US$ 50) se cruza hacia el 26-oct.
- `mova-monthly-20` (US$ 15 mensuales, avisos 50 %/80 %/previsión 100 %) **sigue sin cambios**: ya está superado (US$ 33 en octubre). Propuesta: subirlo a **US$ 28/mes** (ritmo optimizado + margen) con 50/75/90 % cuando el titular confirme; no se cambió el importe sin su acuerdo.
- Las alertas **no detienen el gasto** ni los servicios; no hay apagados automáticos (pagos y clases dependen de web/worker/scheduler).
- Cómo verificar el ahorro: (1) `UsageNanoCores` del scheduler (Azure Monitor) — objetivo: media < 10 mCPU y menos minutos ≥ 10 mCPU; (2) Cost Management agrupado por `ResourceId` y `Meter`: `movap-scheduler` debería pasar de
  `Standard vCPU Active Usage ≈ 0,27/día` a ≈ 0 y su total de ≈ 0,44 a ≈ 0,19–0,3/día; comparar el día 2026-10-23 (primer día completo con la imagen nueva) con el 2026-10-21.
- Medición posterior al cambio (2026-10-22 10:12–10:31 UTC, 18 minutos con la imagen nueva): CPU media del scheduler **5,9 mCPU** frente a 10,3 antes (**−43 %**), mediana 4,8 (antes 8,6), 1 de 18 minutos por encima de 10 mCPU frente a 36 de 180 (20 %). Muestra corta: **no es evidencia de facturación** (pendiente 2026-10-23, ver arriba).
- Validación funcional posterior (solo lectura, sin operaciones financieras): `/`, `/quienes-somos`, `/login`, `/register`, `/forgot-password`, `/privacidad`, `/terminos`, `/healthz`, `/readyz` y el manifest de Vite responden 200; webhook de Mercado Pago sin firma 401 y de Culqi 404 (inactivo); `PAYMENT_PROVIDER=mercadopago` sin cambios; conteos financieros idénticos antes y después (1 recarga `pending`, 1 orden `mercadopago`, 0 asientos de ledger, créditos 0, 5 usuarios); web, worker y scheduler `Running`/`Succeeded` en revisiones `0000026`/`0000023`/`0000025`.

## 8. Qué no debe eliminarse ni cambiarse sin decisión del titular

> Secretos y variables (Key Vault `kv-movap-192f01`, 10 secretos, consumidores por contenedor, rotación y verificación, 2026-10-22): [`azure-secrets-configuration.md`](azure-secrets-configuration.md). La sincronización de secretos no cambió costos (sin recursos nuevos; Key Vault ≈ US$ 0,3/mes ya incluido).

`mova-prod-mysql-8uxzgq` (única base de producción y su PITR/backups), `kv-movap-192f01` y sus 10 secretos, `movaacrsplisbj6ldoqw` (imágenes y digests de rollback: `bf92e6dc…`, `237d93c8…`),
`mova-aca-env` y su IP/LB, la zona DNS privada, `mova-vnet`, `mova-apps-identity`, `mova-logs`, los tres `movap-*`, y los recursos de otros proyectos de la suscripción. Pagos: `PAYMENT_PROVIDER=mercadopago` y Culqi inactivo, sin cambios.

## 9. Recomendaciones futuras

1. Revisar el 2026-10-24/25 la facturación del 23-oct y completar §5 con el ahorro confirmado (la sección 7 dice cómo).
2. Decidir C-1 (mayor ahorro con riesgo acotado): probar en staging (hay que recrear BD de staging, ≈ US$ 1–2 por prueba corta) memoria y CPU de los dos procesos en un contenedor.
3. Decidir el presupuesto mensual (US$ 28 propuesto) y si se acepta C-2/C-3.
4. Si el objetivo es operar hasta septiembre de 2027, el titular debe decidir entre: pasar a pago por uso (fuera de las reglas actuales), mover el cómputo a un proveedor con capa gratuita, o aceptar que MOVA se deshabilita al agotar el crédito (≈ dic 2026–mar 2027).
5. Cada despliegue de la imagen sobrescribe el digest del scheduler/worker: mantener `docs/release/DEPLOY_HISTORY.md` al día para el rollback.

## 10. Segunda ronda (2026-10-22): C-1, C-3, C-2 y la opción todo-en-uno (C-6)

> Solo lo marcado **HECHO** está aplicado en Azure. Los costos son estimaciones con tarifas de lista y consumo medido; **no hay ahorro confirmado por facturación** hasta que exista ≥ 1 día completo posterior (2026-10-23).

### 10.0 Arquitectura realmente implementada hoy y su costo

| Componente | Estado | US$/mes (estimado) |
|---|---|---|
| `movap-web` 0,25 vCPU / 0,5 GiB, min 1 / max 2 | sin cambios | 6,1 |
| `movap-scheduler` = **cola + scheduler** (C-1, 0,25/0,5, min=max 1) | **HECHO** (revisión `0000026`) | 5,6 si el vCPU queda en reposo – 13,5 si sigue facturando activo |
| `movap-worker` | **Stopped** (0 réplicas; reversible) | 0 (antes 5,8) |
| Fijos: ACR Basic 5,0 · IP 3,6 · DNS privado 0,6 · Key Vault 0,3 | sin cambios | 9,5 |
| MySQL B1ms, logs | capa gratuita / bajo cuota | 0 |
| **Total de la arquitectura implementada** | | **≈ 21,2 – 29,1** (ritmo medido el 21-oct: **34,4**) |

El rango alto del contenedor combinado solo se daría si el scheduler siguiera facturando vCPU activa el 52 % del día (como antes del cambio de OPcache). Las métricas indican lo contrario (CPU media 6,6 mCPU, bajo el umbral de 10), pero **solo la facturación lo confirmará**.

### 10.1 C-1 — Worker + scheduler en un contenedor: **HECHO**

**Costo actual → proyectado:** worker 5,8 + scheduler (13,2 antes de OPcache) → un solo contenedor 5,6–13,5. Ahorro seguro (si la facturación lo confirma): **≈ 5,8** (el worker detenido no factura; las apps de staging detenidas facturan 0).

**Diseño (`docker/mova-supervise.sh`; mismo contenido en `infra/azure/apps.bicep` → `combineWorkerScheduler`, compilado con `az bicep build`):**
- Lanza `queue:work` y `schedule:work -v` con los **mismos argumentos** que tenían por separado; `php artisan config:clear || exit 1` antes, como antes.
- Si **cualquiera** termina (fallo, OOM de un hijo) mata al otro y sale con 1 ⇒ Container Apps reinicia el contenedor (nunca queda un proceso sin su pareja).
- `SIGTERM`/`SIGINT` se reenvían a ambos; `queue:work` termina el job en curso y el contenedor sale con 0.
- Se despliega **sin reconstruir la imagen**: `args: ["bash","-c","<contenido del script>","mova-supervise"]` con `command: ["mova-entrypoint"]` (misma imagen `fece8113…`).

**Evidencia (local, imagen de producción, 0,25 vCPU / 512 MiB, 7 min con el ciclo real de tareas):**

| Prueba | Resultado |
|---|---|
| Memoria en régimen / pico / OOM | 112–119 MiB / 214 MiB / 0 |
| CPU | ≈ 0,1 % del límite |
| Matar `queue:work` (SIGKILL) | contenedor sale con **1**, mensaje `[supervise] queue:work terminó…`; al reiniciar vuelven ambos procesos |
| Matar `schedule:work` (SIGKILL) | idem con `schedule:work` |
| `SIGTERM` con un job de 12 s en curso | la parada **esperó 12 s**, el job **terminó** (marcador escrito), salida 0, 0 jobs pendientes, 0 fallidos |

**Evidencia en Azure (revisión `movap-scheduler--0000026`, 195 min, métricas y logs):** CPU media **6,6 mCPU** (máx. 25,9), memoria **164 MiB** media / **180 MiB** máx. (35 % de 512), **0 reinicios**; en los últimos 40 min: 40/40 `WorkerHeartbeatJob`, 40/40 `mova:heartbeat`, 40/40 `classmate:send-reminders`, 0 fallos; `/readyz` `ready`; los heartbeats del worker siguen llegando desde el contenedor combinado.

**Despliegue (HECHO):** (1) `PATCH` de `movap-scheduler` con los argumentos nuevos → revisión `0000026` sana (la anterior `0000025` se desactiva sola); (2) con el combinado sano, `stop` (ARM `2024-10-02-preview`) de `movap-worker`. **Hallazgo:** poner `minReplicas=0` en el worker NO lo apagó (sin reglas de escalado, KEDA responde *"no triggers defined"* y no gestiona el escalado a cero); la acción `stop` sí. Nada se eliminó.

**Riesgos y mitigación:** fallo compartido → el supervisor reinicia ambos y el heartbeat/alertas (`mova:health-check`) lo detectan igual que antes; un despliegue solapa brevemente dos colas/schedulers (igual que ya ocurría con el scheduler; `withoutOverlapping` y los *claims* por lección/recarga evitan duplicados); un job > 30 s durante un reinicio podría cortarse (`terminationGracePeriodSeconds` por defecto 30 s; los jobs de MOVA duran < 1 s).

**Rollback (sin tocar datos):**
1. Reponer el worker: `PATCH` de `movap-worker` con `scale {minReplicas:1,maxReplicas:1}` y luego `POST …/containerApps/movap-worker/start?api-version=2024-10-02-preview` (su definición no se tocó; su revisión es la `…0000024`).
2. Devolver el scheduler a su comando original con otro `PATCH` de `movap-scheduler` (campos `name,image,command,env,resources`) y `args = ["sh","-c","php artisan config:clear && php artisan schedule:work -v"]` (la revisión `0000025` conserva el original).
Verificar `/readyz`, logs y que `WorkerHeartbeatJob` y `mova:heartbeat` vuelvan a verse.

### 10.2 C-2 — Reducir el costo del web sin `min=0`: **no activar**

**Costo:** web 6,1/mes con `min=1`; con `min=0` ≈ 0,8 (solo segundos activos) ⇒ ahorro ≈ 5,3.

**Evidencia:**

| Medición | Valor |
|---|---|
| Arranque local hasta `/healthz` 200 (0,25 vCPU, imagen ya local), 3 repeticiones | 3,8–3,9 s (storage:link 1,3 s, optimize:clear 0,3 s, optimize 0,4 s, view:cache 0,2 s) |
| Arranque en frío en Azure (medido el 2026-10-19) | 22,9 s ⇒ ≈ 19 s son plataforma: planificación de la réplica, **descarga de la imagen (≈ 920 MB)** y sondas |
| Sondas del web | startup 5 s/5 s, **readiness `/readyz` cada 10 s** (añade 0–10 s), liveness 30 s |

**Palancas** (no aplicadas): readiness 10 s → 2 s (−0 a 8 s), cachés de rutas/vistas en el build (−0,5 s), adelgazar la imagen (la capa mayor pesa 366 MB). Mejor caso estimado ≈ 14 s: demasiado para la primera visita de un usuario y **Culqi no documenta ni el plazo de espera ni la política de reintentos de sus webhooks** (docs.culqi.com no lo indica). Los pagos con tarjeta no dependen del webhook (respuesta síncrona) y las órdenes se concilian por barrido cada 5 min, pero no se sabe si Culqi desactiva un webhook tras fallos repetidos.

**Decisión:** `minReplicas` se queda en 1. No se cumple ninguna condición para `min=0`: (a) plazo de webhook de Culqi confirmado por escrito, (b) arranque en frío < 5 s medido en Azure, (c) aceptación de ≥ 14 s en la primera visita. **Alternativa que sí ahorra sin `min=0`:** C-6.

### 10.3 C-3 — GitHub Container Registry en lugar de ACR: **paso 1 HECHO (origen de imagen cambiado); eliminar el ACR pendiente de aprobación**

**Costo:** ACR Basic 0,1666 US$/día = **5,0/mes**; ghcr.io: el almacenamiento y el ancho de banda del Container registry son gratuitos hoy (docs.github.com, *GitHub Packages billing*; GitHub avisa con ≥ 1 mes si cambia) y los paquetes públicos son gratuitos. Ahorro **5,0/mes**, solo cuando se elimine el ACR.

**Qué se construyó:** `.github/workflows/image-ghcr.yml` (manual o al empujar un tag `img-*`; no despliega ni corre en cada push; validado con `actionlint`). Publica `ghcr.io/abel-castill0/mova:<sha7>` con `GITHUB_TOKEN` (`packages: write`), sin secretos adicionales.

| Prueba | Resultado |
|---|---|
| Build en GitHub Actions desde el Dockerfile del repo | éxito en ≈ 3 min (más rápido que subir ≈ 900 MB desde este equipo) |
| Descarga **anónima** desde ghcr.io | OK (el paquete es público: hereda la visibilidad del repo público) |
| **Compatibilidad real con Azure Container Apps** | la app de staging detenida `mova-worker` se apuntó al digest de ghcr.io, se arrancó y descargó la imagen sin credenciales: la consola mostró `PHP 8.3.36` y la marca de prueba; luego se detuvo y se **restauró** al digest del ACR (mismo `min` e imagen) |
| Contenido idéntico al desplegado (`a32a1f0`): tag `img-a32a1f0-eq` → `ghcr.io/abel-castill0/mova:b43290d`, digest `sha256:59cb9f4e25ab18400d4ea7459e7d5c1edbac28c7a2a6fe4803e6fd27b329b2a2` | 9 150 ficheros en ambas imágenes e **idénticos byte a byte salvo 91 ficheros de `public/build`** (los nombres con hash de Vite cambian entre builds; mismo código fuente) |
| Secretos en la imagen | sin `.env`, sin claves PEM, sin `auth.json`/`.npmrc`; único patrón de llave: dobles de prueba (`pk_test_Moc…`) en `tools/culqi-mcp/test.mjs` |

**Permisos:** ACA no necesita credenciales para una imagen pública (se quita el vínculo con la identidad de ACR de esa app). Si algún día se hiciera privada, ACA necesitaría un token `read:packages` como secreto (Key Vault).

**Riesgos y mitigación:** (1) **Rollback**: las imágenes actuales (`bf92e6dc…`, `237d93c8…`, `fece8113…`) solo existen en el ACR; en ghcr.io un rollback sería reconstruir el commit con el workflow (otro digest, mismo código) ⇒ **mantener el ACR ≥ 1 semana tras el cambio**; (2) dependencia de GitHub: una caída solo impediría arrancar réplicas nuevas (las que corren no se ven afectadas); ACA debe referenciar siempre el **digest**, nunca un tag; (3) el código del repo ya es público, así que una imagen pública no revela nada nuevo, pero **no debe contener secretos**: el escaneo manual de arriba no está automatizado en el workflow; (4) no verificado: el beneficio "1 ACR Standard gratis 12 meses" que Microsoft lista para Azure for Students no se observa en esta suscripción (el Basic se factura) y tendría las mismas restricciones de uso.

**Imagen candidata ya publicada (equivalente al código en producción):** `ghcr.io/abel-castill0/mova@sha256:59cb9f4e25ab18400d4ea7459e7d5c1edbac28c7a2a6fe4803e6fd27b329b2a2`. (Existe además `…@sha256:9c8a9456f6dd0217e6034cead798c31ea582138a46c6413e534e7d92baffe3a1`, construida desde `master` con la corrección de acreditaciones de Culqi `994e180`: **no desplegar sin tu decisión**.)

**Estado (2026-10-22):** el titular aprobó el paso 1. `movap-scheduler` (rev `0000027`) y `movap-web` (rev `0000027`) usan `ghcr.io/abel-castill0/mova@sha256:59cb9f4e…`; verificado en `docs/release/DEPLOY_HISTORY.md` (4). El ACR sigue intacto y facturando. **Pasos previstos:** (1) `PATCH` de la imagen de `movap-web` y `movap-scheduler` al digest de ghcr.io (revisiones nuevas, sin cambiar variables); verificar; (2) esperar ≥ 1 semana con el ACR intacto; (3) solicitarte aprobación explícita para **eliminar el ACR** (única acción irreversible). **Rollback del paso 1:** `PATCH` de la imagen al digest del ACR.

### 10.4 C-6 (nueva) — Web + cola + scheduler en un solo contenedor: **probado en local, pendiente de aprobación**

Surge de §10.2: como bajar el web a 0 es inviable, la forma de quitar su costo base es compartir el contenedor. **Costo:** web 6,1 + combinado 5,6–13,5 ⇒ un solo contenedor ≈ 6,6–14,2 (reposo + actividad del web ≈ 1,0 + posible actividad del scheduler). Ahorro estimado ≈ 5,1 sobre C-1.

**Diseño (`infra/azure/proposals/mova-supervise-aio.sh`, NO desplegado):** FrankenPHP + `queue:work` + `schedule:work`; si cae la cola o el scheduler se relanzan solos tras 5 s sin tocar el web (más de 5 caídas en 10 min ⇒ reinicio del contenedor); si cae el web sale con 1; `SIGTERM` los detiene a todos. Exige `maxReplicas=1` en el web.

| Prueba (0,25 vCPU / 512 MiB, 120 s, 8 clientes concurrentes, ≈ 2,9 k peticiones) | Todo en uno | Solo web |
|---|---|---|
| Errores | 0 | 0 |
| Latencia p50 / p95 / p99 | 250 / 352 / 455 ms | 250 / 349 / 437 ms |
| Memoria en régimen / pico | 165–175 / 243 MiB | — / 103 MiB |
| Tareas de fondo durante la carga | puntuales (recordatorios, latidos, `WorkerHeartbeatJob`) | — |
| Matar cola / scheduler | se relanzan; el web sigue en 200 | — |
| Matar el web | contenedor sale con 1 | — |
| `SIGTERM` | todo se detiene en 1 s, salida 0 | — |

**Riesgos:** el tráfico y el scheduler comparten 0,25 vCPU (con 24 req/s el límite se satura; MOVA hoy está muy por debajo); sin escalado horizontal (max 1) no hay segunda réplica ante picos; un fallo del web reinicia también cola y scheduler; hay que añadir al web el `optimize`/`view:cache` que ya hace el entrypoint. **Rollback:** `PATCH` de `movap-web` a su comando original y `max 2`, y reponer `movap-scheduler` (ya existe). **Requiere tu aprobación** por cambiar el componente crítico de cara al usuario.

### 10.5 Costo mensual: implementado frente a pendiente (US$, estimado)

| Escenario | Fijos | Web | Cola + scheduler | **Total** | Estado |
|---|---|---|---|---|---|
| Antes de todo (21-oct, medido) | 9,5 | 6,1 | 18,8 (worker 5,8 + scheduler 13,2) | **34,4** | medido |
| **Implementado hoy** (OPcache CLI + C-1) | 9,5 | 6,1 | 5,6–13,5 | **21,2–29,1** | estimado, sin confirmar |
| + C-3 (imagen ya en ghcr.io; **ACR aún sin eliminar**) | 9,5 hoy → 4,5 al eliminar el ACR | 6,1 | 5,6–13,5 | 16,2–24,1 solo tras eliminar el ACR | origen cambiado; **eliminar el ACR pendiente de aprobación** |
| + C-3 + C-6 (todo en uno) | 4,5 | — | 6,6–14,2 | **11,1–18,7** | **pendiente de aprobación** |
| C-2 (web `min=0`) | — | 0,8 | — | −5,3 | **descartado** |

La meta de ≈ US$ 11 solo se alcanza en el mejor caso de C-3 + C-6 y con la facturación confirmando que el vCPU queda en reposo. Crédito restante estimado ≈ US$ 55: a US$ 21–29 dura ≈ 57–79 días; a 11–18,7, ≈ 88–150 días. Sigue siendo insuficiente para septiembre de 2027 (§6b).

### 10.6 Verificación pendiente (cuando exista la facturación del 2026-10-23)
`movap-scheduler` debe pasar de ≈ 0,44/día a ≈ 0,19–0,3; `movap-worker` debe quedar en 0. Si el scheduler combinado sigue sobre ≈ 0,3/día, el ahorro real de OPcache + C-1 será menor y habrá que revisar la carga por minuto (`classmate:send-reminders` cada minuto es la mayor fuente de CPU).
