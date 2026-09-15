# MOVA — Azure IaC (Bicep)

Estado: **AZ-2 — modelado y validado localmente. NO provisionado.**
Railway sigue siendo producción/rollback hasta que AZ-3/AZ-4 lo reemplacen.

## Archivos

| Archivo | Qué modela |
|---|---|
| `main.bicep` | Scope suscripción: RG `mova-prod-rg` (brazilsouth) + módulos |
| `main.bicepparam` | Parámetros de validación. **Sin valores reales** (secretos vía env) |
| `modules/network.bicep` | VNet `10.20.0.0/16`, `snet-aca` /23 (delegada a `Microsoft.App/environments`), `snet-mysql` /28 (delegada a `Microsoft.DBforMySQL/flexibleServers`), Private DNS MySQL |
| `modules/mysql.bicep` | MySQL Flexible Server privado, TLS obligatorio, sin HA, sin geo-backup |
| `modules/log-analytics.bicep` | Workspace PerGB2018, 30 días, tope 1 GB/día |
| `modules/acr.bicep` | ACR Basic/Standard, admin user deshabilitado |
| `modules/identity.bicep` | User-assigned MI + rol `AcrPull` (único RBAC) |
| `modules/aca-environment.bicep` | Environment en VNet, perfil Consumption, sin zone redundancy |
| `modules/container-app.bicep` | App genérica (misma imagen; rol = command/args + ingress) |

## Invariantes

- **Misma imagen (mismo SHA)** para `mova-web`, `mova-worker`, `mova-scheduler`.
- **`mova-scheduler`: min = max = 1 réplica.** Dos `schedule:work` duplicarían recordatorios y reconciliaciones. No subir `maxReplicas`.
- **`mova-worker`: min = max = 1** en esta etapa (sin KEDA/autoscaling por cola).
- `QUEUE_CONNECTION=SESSION_DRIVER=CACHE_DRIVER=database` — el disco del contenedor es efímero; los locks de `withoutOverlapping()` necesitan un store compartido.
- **Nunca `migrate --force` en el entrypoint.** Migraciones = paso explícito y único por deploy (AZ-3).
- MySQL **sin IP pública**; `require_secure_transport=ON`.
- Pull de imágenes por Managed Identity; **sin usuario/contraseña de registry**.

## Pendientes marcados para AZ-3

- `B1MS_AVAILABILITY_PENDING_AZ3`: `az mysql flexible-server list-skus -l brazilsouth` devolvió 500 en AZ-1 y AZ-2. Reverificar antes de provisionar; `mysqlSkuName/Tier` son parámetros.
- `mysqlVersion`: confirmar la versión real de Railway (no combinar migración cloud con upgrade mayor). Default provisional `8.0.21`. `RAILWAY_DB_VERSION_UNVERIFIED` en AZ-2.1 (el proxy TCP público cerró el handshake; no se insistió contra producción).
- Evidencia AZ-2.1: la imagen de producción (`mova:az2`, **sin** `doctrine/dbal`) ejecutó `migrate --force` desde cero sobre MySQL 8.0.46 efímero: 91 migraciones aplicadas, 0 pendientes; los `->change()` usan ALTER nativo. `doctrine/dbal` NO es dependencia de producción.
- `acrSku`: Standard solo si el beneficio Student se confirma; Basic es técnicamente suficiente para MOVA.
- `containerImage`: reemplazar el placeholder por `<acr>.azurecr.io/mova@sha256:<digest>`.
- Registrar providers `Microsoft.App`, `Microsoft.ContainerRegistry`, `Microsoft.OperationalInsights`, `Microsoft.ManagedIdentity` (hoy NotRegistered; `Microsoft.DBforMySQL` ya Registered).
- GitHub Actions + OIDC (fase posterior; no se inventan permisos aquí).

## Clasificación de configuración

| Clase | Ejemplos | Dónde vive |
|---|---|---|
| APP CONFIG | `APP_URL`, `APP_ENV`, drivers, `DB_HOST/PORT/DATABASE/USERNAME` | `sharedEnv` / `appConfig` (texto plano) |
| APP SECRET | `APP_KEY` (migrar **exactamente**, no regenerar), `DB_PASSWORD`, `CLOUDINARY_URL`, credenciales Meta/Google/Gmail/Mercado Pago/JaaS/Pusher/Sentry | `appSecrets` → secrets de Container Apps (`secretRef`) |
| INFRA SECRET | `mysqlAdminPassword`, shared key de Log Analytics | Parámetro `@secure()` / `listKeys()` en deploy; nunca en el repo |

## Validación ejecutada en AZ-2

```bash
az bicep build --file infra/azure/main.bicep
az deployment sub what-if --location brazilsouth --template-file infra/azure/main.bicep --parameters infra/azure/main.bicepparam
```

What-if es solo preview. **No ejecutar `az deployment sub create` hasta AZ-3.**
