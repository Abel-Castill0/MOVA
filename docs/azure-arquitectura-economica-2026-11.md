# Arquitectura Azure más económica para MOVA (evaluación 2026-11-03)

Solo lectura: no se creó, borró ni modificó ningún recurso. Fuentes: Azure CLI (suscripción, inventario, métricas de 7 días), Cost Management (costos reales por día, recurso y medidor) y Azure Retail Prices API (precios de lista). «Observado» = facturado por Azure; «estimado» = calculado con precios de lista.

## A. Estado de la suscripción

| Dato | Valor | Fuente |
|---|---|---|
| Tipo | Azure for Students (`AzureForStudents_2018-01-01`), **límite de gasto activado** | ARM (observado) |
| Crédito restante | US$ 38 (dato del titular; la API no expone el saldo de esta oferta) | titular |
| Vencimiento del crédito | 2027-09-14 (registrado en `CLAUDE.md`) | documentación |
| Gasto 30 días (4-oct → 3-nov) | US$ 43,57 (incluía staging hasta el 16-oct y la VM del piloto) | observado |
| Gasto 7 días (27-oct → 2-nov) | US$ 6,68 | observado |
| Último día completo (2-nov) | US$ 0,46 — **engañoso**: Container Apps factura US$ 0 al inicio de mes mientras consume su franquicia mensual (~3 días con la carga actual) | observado |
| Ritmo real sin franquicia (24-28 oct: web + scheduler, sin `movaqa-culqi` ni piloto) | **US$ 0,79/día ≈ 23,7/mes** | observado |
| Ritmo actual proyectado (añade `movaqa-culqi` + su almacenamiento) | **≈ US$ 1,1/día ≈ 33-35/mes** | observado (29-31 oct) + estimado |
| Agotamiento de US$ 38 al ritmo actual | **≈ 5 semanas (principios de diciembre)**. Con el límite de gasto, Azure **deshabilita la suscripción**: web, scheduler, MySQL y webhooks dejan de funcionar | estimado |

**Beneficios gratuitos realmente aplicados (comprobado en la factura):**
- MySQL Flexible `Standard_B1ms` + 32 GB: octubre facturó **US$ 0,03** ⇒ la franquicia de 750 h y almacenamiento se está aplicando.
- VM `B2pts v2` (ARM) del piloto en northcentralus: medidor «Virtual Machines» **US$ 0,00** ⇒ las 750 h gratuitas de ese tamaño se aplicaron. El disco **E4 sí se facturó** (no está cubierto; el gratuito es P6) y la IP pública también.
- Container Apps: franquicia mensual de la oferta de consumo (vCPU-s, GiB-s y solicitudes) ⇒ ~3 días gratis al mes con 3 réplicas fijas.
- **No verificado:** si las 750 h de `B1s`, `B2pts v2` y `B2ats v2` son **independientes por tamaño** (dos VMs gratis a la vez) o compartidas; solo se ha visto una VM a la vez en la factura. Tampoco si los beneficios sobreviven a un cambio a pago por uso.

**Disponibilidad de tamaños (consultado con `az vm list-skus`):**
- `mexicocentral` (donde están MOVA y MySQL): `B2ats v2` y `B2als v2` → **NotAvailableForSubscription**; `B1s`, `B2s`, `B2pts v2`, `B2pls v2` no se ofrecen ahí.
- `northcentralus`: `B2pts v2`, `B2ats v2`, `B2pls v2`, `B2als v2` **disponibles** (región del piloto; peering global con mexicocentral ya probado, ≈ US$ 0).
- `southcentralus`: `B2pts v2`, `B2pls v2` disponibles. `eastus`: sin resultados para esta suscripción.

**Uso comercial:** la oferta Azure for Students es para educación, investigación no comercial y desarrollo/demostración (`payments-culqi.md` §14.1 B-1). Ninguna arquitectura de este documento resuelve eso: cobrar con Culqi live en esta suscripción sigue siendo una decisión del titular, y al agotar el crédito o los 12 meses hay que pasar a pago por uso o renovar.

## B. Inventario (estado real)

| Recurso | Estado | Costo observado | Necesario | Nota |
|---|---|---|---|---|
| `movap-web` (0,25 vCPU / 0,5 GiB, min 1) | Running | ≈ US$ 7-8/mes | Sí | 3 mCPU prom., 56 mCPU máx.; 188 MiB prom., 257 MiB máx. |
| `movap-scheduler` (0,25/0,5; también procesa la cola) | Running | ≈ US$ 6-9/mes | Sí | 8 mCPU prom., 67 máx.; 182 MiB prom., 265 máx. |
| `movaqa-culqi` (entorno del evaluador Culqi) | Running | ≈ US$ 6-7/mes | **¿?** | 6 mCPU, 165 MiB |
| `movaqastg225258` (share `qa-culqi` de `movaqa-culqi`) | activo | **≈ US$ 4/mes** (operaciones de escritura) | solo si sigue el evaluador | creado 28-oct |
| `movap-worker`, `mova-web/worker/scheduler` (staging) | Stopped | 0 | rollback | usan imágenes de ACR |
| ACR `movaacrsplisbj6ldoqw` (Basic) | activo | **US$ 5,0/mes** | solo para esas apps detenidas y el job `movap-culqi-net` | producción usa GHCR |
| IP pública del entorno ACA (`capp-svc-lb-ip`) | activo | US$ 3,72/mes | Sí (mientras exista ACA) | |
| Zona DNS privada de MySQL | activo | US$ 0,50/mes | Sí | |
| Key Vault, Log Analytics | activos | ≈ US$ 0,15 / ≈ 0 | Sí | |
| MySQL `mova-prod-mysql-8uxzgq` B1ms, 32 GB, MySQL 8.4, backup 14 d, sin acceso público | Ready | ≈ US$ 0 (franquicia) | Sí | CPU 8 % prom. (31 % máx.), memoria 19 %, **0,75 GB usados**, 5-8 conexiones |
| WAHA | **no existe** (piloto borrado el 1-nov) | 0 desde el 2-nov | Sí, a reconstruir | sesión perdida |
| `mova-waha-janitor-rg` | identidad | 0 | sí (limpieza de pilotos) | |

## C. Requisitos medidos de MOVA (7 días)

| Componente | Medido | Necesario con margen |
|---|---|---|
| Laravel web (PHP-FPM/Caddy) | 188 MiB prom., 257 máx.; 3-56 mCPU | 384-512 MiB, 0,25-0,5 vCPU |
| Scheduler + cola | 182 MiB prom., 265 máx.; 8-67 mCPU | 384-512 MiB, 0,25 vCPU |
| MySQL | 0,75 GB de datos; 19 % de 2 GiB (~390 MiB); 5-8 conexiones | gestionado B1ms o ~512 MiB dentro de una VM |
| WAHA NOWEB | ~250-400 MiB (piloto en 1 GiB sin problemas; sin medición fina) | 1 GiB dedicado (aislado de MOVA) |
| Disco | MySQL < 1 GB; sesión WAHA ~4 MB | 32-64 GB |
| Disponibilidad | webhooks de Culqi 24/7 (Culqi reintenta y `culqi:reconcile` recupera) | 1 instancia con reinicio automático basta hoy |

Todo junto (web + scheduler + MySQL + WAHA + SO) ≈ 1,5-2 GiB en uso ⇒ **una VM de 1 GiB no alcanza**; 4 GiB sí con margen.

## D. Comparación de arquitecturas (US$/mes, 30 días)

Precios de lista 2026-11-03 (VMs en northcentralus/eastus por disponibilidad; resto mexicocentral). IP pública Standard US$ 0,005/h = 3,65/mes. Disco E4 32 GB 2,64; E6 64 GB 5,28; P6 64 GB 11,23 (gratis 2×P6 con el beneficio, **no verificado en esta cuenta**). Blob Hot LRS ≈ 0,022/GB.

| Opción | CPU / RAM | Cómputo | Disco | IP/red | Backups | **Total con beneficios** | Sin beneficios (pago por uso) | Duración de US$ 38 | Riesgo |
|---|---|---|---|---|---|---|---|---|---|
| **A0. Actual sin cambios** | ACA 3×0,25/0,5 | ≈ 20-24 | — | 3,72 + 0,5 | incl. MySQL | **≈ 33-35** | ≈ 50 (MySQL ≈ 13-18) | **≈ 5 semanas** | sin WAHA |
| **A1. Actual optimizada** (detener `movaqa-culqi` + su share, retirar ACR) | ACA 2×0,25/0,5 | ≈ 13-15 | — | 3,72 + 0,5 | incl. | **≈ 17-19** | ≈ 35 | ≈ 9-10 semanas | bajo; sin WAHA |
| **D. A1 + WAHA en VM gratuita `B2pts v2`** (northcentralus, peering) | + 2 vCPU ARM / 1 GiB | 0 (750 h) | P6 0 / E4 2,64 | +3,65 | blob ≈ 0,05 | **≈ 21-24** | ≈ 45 | ≈ 7-8 semanas | bajo; WAHA aislado |
| **B. 1 VM 4 GiB para todo** (`B2pls v2` ARM o `B2als v2`) | 2 vCPU / 4 GiB | ≈ 24,5-27,4 | P6 0 / E6 5,28 | 3,65 | blob ≈ 0,1 | **≈ 28-37** | ≈ 40 | ≈ 4-6 semanas | medio-alto: MySQL propio o migrar MySQL a otra región; imagen ARM no probada |
| **C. 1 VM 8 GiB** | 2 vCPU / 8 GiB | ≈ 49-55 | idem | 3,65 | idem | **≈ 55-65** | ≈ 65 | ≈ 3 semanas | sobredimensionada: **descartada** |
| **E. Híbrida** (mover solo partes) | — | — | — | — | — | igual o peor que D o F | — | — | MySQL en mexicocentral + VM en otra región = latencia de ~40-60 ms por consulta: **descartada** salvo que todo se mueva junto |
| **F. 2 VMs gratuitas** (`B2ats v2` app 1 GiB + `B2pts v2` WAHA 1 GiB) + MySQL B1ms gratis movido a northcentralus, **sin ACA** | 2×2 vCPU / 1 GiB | 0 si las 750 h son por tamaño (**no verificado**); si no, +7,5 | 2×P6 0 / 2×E4 5,28 | 2×3,65 | blob ≈ 0,1 | **≈ 8-21** | ≈ 50-60 | ≈ 2-4 meses | **alto**: 1 GiB justo para Laravel, operación del SO a cargo nuestro, migración de base de datos y DNS, sin escalado |

Impuestos: Azure for Students no suma impuestos al crédito; en pago por uso dependen de la facturación peruana (no estimados).

## E. Recomendación

1. **Más barata técnicamente viable:** F (≈ US$ 8-21). Requiere migrar MySQL y la app fuera de Container Apps y depende de un beneficio no comprobado. No se recomienda hacerlo ahora con pagos reales en juego.
2. **Mejor relación costo/estabilidad y la que recomiendo: D** — mantener Container Apps + MySQL gestionado gratuito (sin migrar datos ni el ledger), apagar lo que no aporta (`movaqa-culqi` y su share, ACR tras repuntar o retirar las apps detenidas que lo usan) y montar WAHA en una VM `B2pts v2` gratuita con disco P6 y respaldo cifrado en blob.
   - **≈ US$ 21-24/mes** (dentro de la meta 15-25). Sin WAHA, A1 ≈ US$ 17-19.
   - **Ahorro vs. hoy:** de ≈ US$ 33-35 a ≈ 21-24 ⇒ ≈ US$ 11/mes (≈ 32 %), e incluye WhatsApp.
   - **Duración del saldo:** ≈ 7-8 semanas (vs. ≈ 5). **Ninguna opción viable hace durar US$ 38 hasta 2027**: antes de que se agote (aprox. finales de diciembre con D) hay que decidir recarga/pago por uso o la suscripción quedará deshabilitada y MOVA fuera de línea.

## F. Plan de migración (no ejecutado)

**Fase 1 — Ahorro inmediato (sin riesgo para datos; requiere tu visto bueno por ser eliminaciones):**
1. Confirmar que el evaluador de Culqi ya no necesita `movaqa-culqi`. Exportar su share `qa-culqi` (respaldo), **detener** la app (reversible) y, tras una semana, borrar la cuenta `movaqastg225258`.
2. ACR: exportar la lista de imágenes; repuntar el job `movap-culqi-net` a GHCR o borrarlo; decidir si las apps detenidas de staging y `movap-worker` (que apuntan a ACR) se conservan para rollback (sus digests equivalentes están en GHCR) o se eliminan; solo entonces borrar el ACR.
3. Nada cambia en `movap-web`, `movap-scheduler`, MySQL, DNS ni secretos.

**Fase 2 — WAHA permanente (D):**
1. Grupo `mova-waha-rg` en northcentralus con los scripts existentes de `infra/azure/waha/` (sin etiqueta de expiración), VM `B2pts v2`, **disco P6** (verificar que factura 0), IP estándar solo de salida, NSG sin entrada desde Internet, peering con `mova-vnet`.
2. Respaldo cifrado de la sesión a un contenedor blob (≈ US$ 0,01/mes); `WAHA_BASE_URL` a la IP privada nueva; vincular por QR desde `/admin/whatsapp`; calentamiento 24 h; pruebas de reinicio de Docker y de VM.
3. Verificar en Cost Management a las 48 h que cómputo = 0 y disco P6 = 0; si no, cambiar a E4 (US$ 2,64).
4. Rollback: borrar el grupo (no afecta a MOVA). Costo de transición ≈ US$ 0,3 (IP y disco de 1-2 días).

**Fase 3 (opcional, más adelante) — prueba aislada de F con datos sintéticos:** 1-2 días con dos VMs gratuitas y un MySQL de prueba ⇒ ≈ US$ 0,3-0,5 (IPs y discos). Solo si se quiere bajar de ~US$ 20 a ~US$ 10 y tras comprobar en la factura que las dos VMs gratuitas facturan 0. La migración real de MySQL (dump consistente, verificación del ledger por sumas y conteos, ventana de mantenimiento, `PAYMENTS_ENABLED` cerrado durante el corte, DNS con TTL bajo y vuelta atrás al servidor anterior intacto) necesitaría autorización expresa.

**Protecciones en todas las fases:** el ledger y MySQL no se tocan en las fases 1-2; Culqi live sigue sin activar; webhooks y conciliación intactos; nada se borra sin respaldo y aprobación.

## G. Decisión pendiente del titular

Autorizar la opción D: **≈ US$ 21-24/mes** con los beneficios actuales (≈ US$ 45/mes sin ellos), gasto único de transición ≈ US$ 0,3, y las eliminaciones de la fase 1 (`movaqa-culqi` + `movaqastg225258`, y el ACR tras resolver sus dependencias).

## H. Ejecución (2026-11-03, opción D aprobada por el titular)

**Fase 1 — ahorro:**
- Respaldo previo en el equipo del titular: `C:\Users\TK\mova-backups\2026-11-03-fase1\` (configuración JSON de `movaqa-culqi`, `movap-worker`, apps de staging y del job; lista de las 63 imágenes del ACR con digest/etiquetas/fecha; copia del share `qa-culqi`, 3 archivos, 1,2 MB).
- `movaqa-culqi` **detenido** (reversible: `POST …/containerApps/movaqa-culqi/start`). La cuenta `movaqastg225258` se conserva una semana (sin la app ya no recibe escrituras) y después se puede borrar.
- ACR `movaacrsplisbj6ldoqw` **eliminado** tras quitar su entrada de registro (identidad) en `movap-web` y `movap-scheduler` (no lo usaban: imágenes en GHCR; siguen en la revisión `0000044`, sanas, `/readyz` 200) y repuntar el job `movap-culqi-net` a GHCR. Las apps detenidas `movap-worker` y `mova-web/worker/scheduler` aún apuntan a imágenes del ACR: **antes de arrancarlas hay que cambiar su imagen a un digest de GHCR** (el JSON del respaldo conserva su configuración).

**Fase 2 — WAHA permanente** (`infra/azure/waha/waha-create.sh`):
- Grupo `mova-waha-rg` (northcentralus, sin caducidad ni janitor): VM `mova-waha` `Standard_B2pts_v2` ARM64, disco SO **Premium SSD P6 64 GiB**, IP Standard solo de salida, NSG que niega todo desde Internet y abre 3000/TCP solo a `mova-vnet`, VNet 10.30.0.0/24 con IP privada 10.30.0.4 (la misma `WAHA_BASE_URL` que ya tenían las apps), peering `to-waha`/`to-mova` **Connected**.
- La VM no puede borrar nada: el temporizador de autodestrucción del piloto está **deshabilitado** y su único rol es «Storage Blob Data Contributor» sobre el contenedor de respaldos (el rol temporal de Key Vault se retiró tras descargar los secretos).
- Respaldo cifrado **diario** (03:30 UTC) a `movawahabak8335a575/waha-session-backups` (fuera de la VM; solo corre con la sesión en `WORKING`; conserva 7).
- Verificado: docker y waha habilitados, política `unless-stopped`, API 200 dentro de la VM, y `mova:waha-check` desde `movap-web` y `movap-scheduler`: «Alcanzable». La sesión espera vinculación (WAHA pasa a `FAILED` si nadie escanea el QR en unos minutos; el panel la reinicia).
- **Pendiente:** vincular por QR (titular), pruebas de persistencia con sesión real y **comprobar en Cost Management a las 48 h** que el cómputo `B2pts v2` y el disco P6 facturan 0 (si el disco factura, cambiar a E4 = US$ 2,64/mes).

## I. Alojamiento comercial al menor costo (2026-11-04)

**Datos reales.**
- Cost Management, 28-oct a 4-nov: **US$ 6,70** en total. El gasto bajó de ~1,4–1,6 a ~0,46–0,64 US$/día tras la fase 1.
- Por recurso en esos 7 días: scheduler 1,31; ACR 1,18 (ya eliminado); web 0,94; `movaqastg225258` 0,89 (eliminado hoy tras verificar su respaldo idéntico); `movaqa-culqi` 0,88 (detenido); IP de ACA 0,86; DNS privado 0,14; Key Vault 0,04; MySQL 0,03 (franquicia).
- **Ritmo con los beneficios actuales ≈ US$ 18/mes:** web ≈ 4,0 · scheduler ≈ 5,6 · IP de ACA 3,7 · IP de WAHA 3,65 · DNS ≈ 0,6 · resto < 0,5. La VM `B2pts v2` y MySQL `B1ms` salen gratis por franquicia; el disco P6 de WAHA, *no verificado aún en factura*.

**Términos.** Azure for Students es *"intended to support your education, non-commercial research"*. Al pasar a pago por uso, Microsoft indica: *"your subscription still has access to free services for 12 months from your sign-up date"* ([fuente](https://learn.microsoft.com/en-us/azure/cost-management-billing/manage/azurestudents-subscription-disabled)). Sobre el saldo no gastado no hay declaración oficial: hay que confirmarlo con soporte de facturación (gratuito) antes de actualizar.

**Precios de lista (Retail Prices API, 2026-11-04):**
- VMs Linux (northcentralus): `B2pts v2` 6,13; `B2ats v2` 6,86; `B2pls v2` (4 GB) 24,53.
- MySQL `B1ms` (mexicocentral): 13,65 + 32 GB × 0,1265 = 17,7.
- Discos: P6 10,21 · E6 4,80 · E4 2,40.

| Opción | Mientras duren las franquicias (hasta ~12 meses desde el alta) | Después | Migración | Riesgo |
|---|---|---|---|---|
| **A. Actualizar esta suscripción a pago por uso** | **≈ US$ 18/mes** (mismo diseño) | ≈ 36–44 (≈ 36 con disco E4 en WAHA) | ninguna | posible pérdida del saldo restante (≤ US$ 38; confirmar con soporte) |
| B. Mantener Students para QA y crear una suscripción comercial nueva | ≈ US$ 44/mes desde el primer día (sin franquicias de estudiante) | ≈ 44 | MySQL (volcado y restauración), ACA, Key Vault, identidad, DNS y la VM de WAHA (riesgo de revincular WhatsApp); 1–2 semanas de costo duplicado ≈ US$ 10–20 | alto |
| Consolidar MOVA en la VM de WAHA | la VM actual tiene 1 GB y WAHA usa ~490 MB: no cabe | `B2pls v2` 4 GB = 24,5 + disco > ACA actual (≈ 13) | rediseño | medio |

**Recomendación: A.** Es la única que cumple la meta de US$ 15–25 sin migrar ni tocar la sesión de WhatsApp. Además deja todo (MOVA y WAHA) en una suscripción comercial a la vez.

**Presupuesto creado.** `mova-mensual-25` (US$ 25/mes): alertas al 50, 80 y 100 % real y al 100 % previsto, enviadas a los propietarios. Es solo aviso, no un tope.

**Antes de que acaben las franquicias:**
- disco de WAHA P6 → E4;
- revisar si `movap-culqi-net` y las apps detenidas `mova-*` siguen haciendo falta (hoy cuestan 0).
