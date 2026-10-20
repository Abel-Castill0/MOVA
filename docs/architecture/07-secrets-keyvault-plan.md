# 07 · Secretos: estado actual y plan de migración a Key Vault

> Estado: **MIGRACIÓN EJECUTADA Y VERIFICADA EN PRODUCCIÓN (2026-10-19)** (ver «Migración completada»). Requiere aprobación del titular
> (nuevo recurso con costo y cambio de RBAC). Mapea a ISO/IEC 27001:2022 A.8.24 y
> A.5.17 (información de autenticación), ISO/IEC 27017 CLD.12.1.5.

## Estado actual (verificado en `infra/azure`)

- **No hay Key Vault** en ningún Bicep (`grep -i keyvault infra` sin resultados).
- Los secretos viajan como `secrets` de Azure Container Apps (`modules/container-app.bicep`:
  `secretItems` → `secretRef`), cargados con `az containerapp secret set` o en cada
  deploy desde variables de shell (`apps.bicepparam`: `MOVA_APP_KEY`, `MOVA_MYSQL_APP_PASSWORD`,
  credenciales de proveedores). Cada rol (web/worker/scheduler) necesita el secreto **y** la
  variable que lo referencia; un cambio exige una revisión nueva.
- Identidad administrada de usuario (`mova-apps-identity`) con **solo `AcrPull`**: ya existe el
  mecanismo para autenticar contra otros recursos Azure.
- El valor del secreto no se imprime ni se versiona (reglas de `AGENTS.md`); el riesgo residual es
  que viven en el recurso de la app y en el entorno del operador durante el deploy.

## Propuesta

| Paso | Acción | Detalle |
|---|---|---|
| 1 | Crear **un** Key Vault `kv-mova-prod` (Standard, RBAC, soft-delete 90 d, purge protection) | Región `mexicocentral`; acceso público deshabilitado si es viable, o *firewall* + *trusted services*; costo: ≈ US$ 0,03 por 10 000 operaciones y sin cargo mensual por secreto estándar |
| 2 | Asignar a `mova-apps-identity` el rol **Key Vault Secrets User** (solo lectura de secretos) sobre ese vault | Mínimo privilegio; ningún rol de escritura para las apps |
| 3 | Cargar los secretos al vault **desde el panel/CLI del titular**, nunca por chat | Mismos nombres que hoy (`app-key`, `db-password`, `mercadopago-access-token`, `mercadopago-webhook-secret`, `jaas-*`, `cloudinary-url`, `sentry-dsn`, `mail-*`) |
| 4 | En `container-app.bicep`, definir cada secreto como referencia: `{ name, keyVaultUrl, identity }` en lugar de `value` | Container Apps resuelve en el arranque de la revisión; rotar un secreto = nueva versión + nueva revisión |
| 5 | Probar en **staging** primero (apps a 0 réplicas, sin BD: ver Runbook §1b) | Criterio ISO 29119: la revisión inicia `Healthy` y `/readyz` responde con la identidad, sin ningún valor en la plantilla |
| 6 | Migrar producción rol por rol (scheduler → worker → web) con `/readyz` y heartbeats verificados | Rollback: reactivar la revisión anterior (conserva los secretos locales hasta confirmar) |
| 7 | Retirar los valores de `apps.bicepparam`/shell y activar auditoría (`AuditEvent` del vault a Log Analytics) | ISO 27001 A.8.15/A.8.16 |

## Riesgos y condiciones

- **Restricción de la suscripción Student:** las políticas pueden bloquear asignaciones RBAC o recursos
  (ya se vio `MaxNumberOfGlobalEnvironmentsInSubExceeded` y la política de regiones). Comprobar primero con
  una asignación de prueba.
- **Dependencia nueva:** si el vault no está disponible, una revisión nueva no arranca (las ya activas
  siguen). Mitigación: purge protection y no rotar en horario crítico.
- **Costo:** despreciable frente al crédito (US$ 86 hasta 2027-09-14), pero no es cero: confirmar en la
  calculadora antes de crearlo (regla de `AGENTS.md`).
- **No resuelve** secretos expuestos en el pasado (F-26): esos se rotan en el proveedor.

## Decisión pedida al titular

¿Apruebas crear el Key Vault y migrar en el orden indicado (staging → producción)? Hasta entonces los
secretos permanecen como secretos de Container Apps, con las reglas de manejo vigentes.

## Ejecución (2026-10-19)

| Paso | Resultado |
|---|---|
| Proveedor `Microsoft.KeyVault` | Registrado (estaba `NotRegistered`) |
| Vault `kv-movap-192f01` (mexicocentral, Standard, RBAC, *soft-delete* 90 d; sin *purge protection* todavía) | Creado en `mova-prod-rg`. URI `https://kv-movap-192f01.vault.azure.net/` |
| `mova-apps-identity` → **Key Vault Secrets User** (solo lectura de secretos) | Asignado |
| Titular/operador → **Key Vault Secrets Officer** (para cargar secretos) | Asignado |
| Prueba del mecanismo en **staging** (`mova-worker`, sin tocar producción): secreto `kvtest` como `keyvaultref` con la identidad + variable `KVTEST=secretref:kvtest` | Revisión `mova-worker--0000027` **Provisioned**; prueba y secreto temporal retirados; el secreto `mova-kv-selftest` (valor `ok`) sigue en el vault y puede borrarse |
| Soporte en IaC: `modules/container-app.bicep` acepta `keyVaultSecrets` (NOMBRE → URI sin versión) y `apps.bicep` lo expone | Compila (`az bicep build`); sin desplegar |
| Migración de los 10 secretos de producción (`app-key`, `db-password`, `mercadopago-*`, `mail-*`, `cloudinary-url`, `jaas-*`, `sentry-laravel-dsn`) | **No ejecutada por Claude**: `AGENTS.md` prohíbe consultar valores de secretos de Azure. Script para el titular: `scripts/azure/migrate-secrets-to-keyvault.sh` (`copy` → `switch` → `revert`), sin imprimir valores |

Notas: no existe un `JWT_SECRET` en MOVA (los secretos reales son los 10 listados). Al pasar a referencias, los **mismos nombres** de secreto y de variable se conservan, así que no cambia el entorno de las apps. Orden recomendado del `switch`: scheduler → worker → web, con `/readyz` entre cada paso. Una vez migrado y verificado: borrar los valores en línea, habilitar *purge protection* (irreversible) y activar los registros `AuditEvent` del vault hacia Log Analytics. Costo del vault: despreciable (operaciones ≈ US$ 0,03 por 10 000; sin cargo por secreto estándar).

## Migración completada (2026-10-19)

- `copy`: 10 secretos copiados desde `movap-web` al vault por archivo temporal 0600, sin imprimir valores. Los valores multilínea (`jaas-private-key`) se conservan byte a byte: el script decodifica JSON, no `tsv`.
- `verify`: **10/10 `OK`** comparando SHA-256 origen/vault.
- `switch`: scheduler, luego worker, luego web, cada uno con revisión nueva (`…--kv10192205/06/07`), `Healthy` y `/readyz` 200 entre pasos. Resultado: 10/10 secretos de cada app son `keyvaultref`; `/healthz`, `/readyz`, `/login` y `/register` responden 200; el scheduler ejecuta tareas y el worker procesa el latido; sin errores de acceso a secretos en logs.
- IaC: `apps.bicep` acepta `keyVaultName` (lista `kvManagedNames`) y `apps.bicepparam` lo lee de `MOVA_KEYVAULT_NAME`. **Sin esa variable un deploy reemplazaría las referencias por valores en línea.**
- **Pendiente (titular):** rotar secretos desde el vault (nueva versión + revisión nueva), borrar el secreto de prueba `mova-kv-selftest`, habilitar *purge protection* (irreversible) y activar `AuditEvent` hacia Log Analytics. `revert` del script recupera los valores en línea desde el vault si hiciera falta.

## Cierre del vault (2026-10-19, 2.ª pasada)

| Pendiente | Resultado |
|---|---|
| Borrar el secreto de prueba `mova-kv-selftest` | Hecho; el vault solo contiene los 10 secretos reales (quedó en *soft-delete* 90 d) |
| *Purge protection* | **Activada** (irreversible): los secretos y el vault borrados no pueden purgarse durante 90 días |
| Auditoría | Proveedor `Microsoft.Insights` registrado (estaba sin registrar); ajuste de diagnóstico `kv-audit` con `AuditEvent` hacia el workspace `mova-logs`. Consulta de ejemplo (Log Analytics): `AzureDiagnostics \| where ResourceProvider == "MICROSOFT.KEYVAULT" \| where Category == "AuditEvent"` |
| Salud tras los cambios | `/healthz` y `/readyz` 200 |
