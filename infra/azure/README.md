# MOVA — Azure IaC (Bicep)

Estado: **AZ-2 — modelado y validado localmente. NO provisionado.**
Railway sigue siendo producción/rollback hasta que AZ-3/AZ-4 lo reemplacen.

## Archivos

| Archivo | Qué modela |
|---|---|
| `main.bicep` | Scope suscripción: RG `mova-prod-rg` (brazilsouth) + módulos |
| `main.bicepparam` | Parámetros de deploy. **Sin valores reales**: secretos vía env, falla si falta el admin password |
| `modules/network.bicep` | VNet `10.20.0.0/16`, `snet-aca` /23 (delegada a `Microsoft.App/environments`), `snet-mysql` /28 (delegada a `Microsoft.DBforMySQL/flexibleServers`), Private DNS MySQL |
| `modules/mysql.bicep` | MySQL Flexible Server privado, TLS obligatorio, sin HA, sin geo-backup |
| `modules/log-analytics.bicep` | Workspace PerGB2018, 30 días, tope 1 GB/día |
| `modules/acr.bicep` | ACR Basic/Standard, admin user deshabilitado |
| `modules/identity.bicep` | User-assigned MI + rol `AcrPull` (único RBAC) |
| `modules/aca-environment.bicep` | Environment en VNet, perfil Consumption, sin zone redundancy |
| `modules/container-app.bicep` | App genérica (misma imagen; rol = command/args + ingress) |

## Despliegue en dos pasos (`deployApps`)

`main.bicep` separa **foundation** de **apps** para no desplegar nunca MOVA con una imagen que no existe (o con una pública de ejemplo):

| Fase | `deployApps` | Crea | Exige |
|---|---|---|---|
| 1. Foundation | `false` (default) | RG, VNet/subnets, Private DNS, Log Analytics, ACR, identity + AcrPull, MySQL, ACA environment | `MOVA_MYSQL_ADMIN_PASSWORD` |
| 2. Build + push | — | `docker build` → `<acr>.azurecr.io/mova@sha256:<digest>` | AcrPush del operador/pipeline |
| 3. Bootstrap DB | — | Job manual (ver abajo): `CREATE USER mova_app` + `GRANT` solo sobre `mova.*` | admin DB (solo en el Job) |
| 4. Migrations | — | mismo Job: `php artisan migrate --force` (una vez por deploy) | `mova_app` o admin, dentro de la VNet |
| 5. Apps | `true` | `mova-web`, `mova-worker`, `mova-scheduler` | `MOVA_CONTAINER_IMAGE` (imagen MOVA real del ACR), `MOVA_MYSQL_APP_PASSWORD`, `MOVA_APP_KEY` (el actual, **no regenerar**) |

Contrato de `containerImage`: **no hay fallback**. Vacío en foundation; con `deployApps=true` `main.bicep` falla (`fail()`) si está vacío o si `mysqlAppPassword` tiene menos de 16 caracteres. `main.bicepparam` falla si falta `MOVA_MYSQL_ADMIN_PASSWORD` (sin default).

`APP_URL`: vacío ⇒ `https://mova-web.<defaultDomain del ACA environment>` (primer staging). El dominio final se fija en el cutover vía `MOVA_APP_URL`.

## Identidades de base de datos

| Identidad | Uso | Dónde vive |
|---|---|---|
| `mysqlAdminUser` / `mysqlAdminPassword` | crear el servidor; Job de bootstrap/migración | parámetro `@secure()`; **nunca** se inyecta en web/worker/scheduler |
| `mysqlAppUser` = `mova_app` / `mysqlAppPassword` | runtime de las tres apps (`DB_USERNAME` / secret `DB_PASSWORD`) | `runtimeSecrets` → secret de Container Apps |

Bootstrap (diseño AZ-3, sin implementar): un **Container Apps Job manual**, temporal e idempotente, en el mismo environment (dentro de la VNet) con la misma imagen MOVA. Es el único lugar donde existen las credenciales admin. No sustituye a `mova-scheduler` (que sigue siendo Container App persistente 1..1).

```sql
CREATE USER IF NOT EXISTS 'mova_app'@'%' IDENTIFIED BY '<MOVA_MYSQL_APP_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, LOCK TABLES ON `mova`.* TO 'mova_app'@'%';
```
Sin privilegios server-wide.

## TLS cliente → MySQL

`require_secure_transport=ON` en el servidor. Las apps reciben `MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt` (bundle de root CAs del sistema en la imagen FrankenPHP; verificado: 150 raíces, `/usr/lib/ssl/cert.pem` apunta a él). Se confía en las raíces, no en intermedias concretas, para tolerar rotaciones de Azure. Verificación de certificado **activada**; nunca `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=false` ni `require_secure_transport=OFF`.

## Invariantes

- **Misma imagen (mismo SHA)** para `mova-web`, `mova-worker`, `mova-scheduler`.
- **`mova-scheduler`: min = max = 1 réplica.** Dos `schedule:work` duplicarían recordatorios y reconciliaciones. No subir `maxReplicas`.
- **`mova-worker`: min = max = 1** en esta etapa (sin KEDA/autoscaling por cola).
- `QUEUE_CONNECTION=SESSION_DRIVER=CACHE_DRIVER=database` — el disco del contenedor es efímero; los locks de `withoutOverlapping()` necesitan un store compartido.
- **Nunca `migrate --force` en el entrypoint.** Migraciones = paso explícito y único por deploy (AZ-3).
- MySQL **sin IP pública**; `require_secure_transport=ON`.
- Pull de imágenes por Managed Identity; **sin usuario/contraseña de registry**.
- La contraseña admin de MySQL **nunca** llega a web/worker/scheduler; el runtime usa `mova_app`.

## Pendientes marcados para AZ-3

- `B1MS_AVAILABILITY_PENDING_AZ3`: `az mysql flexible-server list-skus -l brazilsouth` devolvió 500 en AZ-1 y AZ-2. Reverificar antes de provisionar; `mysqlSkuName/Tier` son parámetros.
- `mysqlVersion`: confirmar la versión real de Railway (no combinar migración cloud con upgrade mayor). Default provisional `8.0.21`. `RAILWAY_DB_VERSION_UNVERIFIED` en AZ-2.1 (el proxy TCP público cerró el handshake; no se insistió contra producción).
- Evidencia AZ-2.1: la imagen de producción (`mova:az2`, **sin** `doctrine/dbal`) ejecutó `migrate --force` desde cero sobre MySQL 8.0.46 efímero: 91 migraciones aplicadas, 0 pendientes; los `->change()` usan ALTER nativo. `doctrine/dbal` NO es dependencia de producción.
- `acrSku`: Standard solo si el beneficio Student se confirma; Basic es técnicamente suficiente para MOVA.
- `containerImage`: suministrar `<acr>.azurecr.io/mova@sha256:<digest>` vía `MOVA_CONTAINER_IMAGE` en la fase apps (sin placeholder).
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
# foundation (MOVA_DEPLOY_APPS sin definir / false)
az deployment sub what-if --location brazilsouth --template-file infra/azure/main.bicep --parameters infra/azure/main.bicepparam
# apps (MOVA_DEPLOY_APPS=true + MOVA_CONTAINER_IMAGE + MOVA_MYSQL_APP_PASSWORD)
```

AZ-2.2: foundation what-if = 12 `Create` sin ninguna app; apps what-if = mismos 12 + `mova-web/worker/scheduler` en `potentialChanges` (dependen de outputs runtime). What-if es solo preview. **No ejecutar `az deployment sub create` hasta AZ-3.**
