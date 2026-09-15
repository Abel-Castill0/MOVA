// MOVA — infraestructura Azure (AZ-2: modelado y validado; AZ-3: provisión).
//
// Scope: suscripción. Crea el Resource Group y delega todo lo demás a módulos.
// NO se despliega en AZ-2 — solo `az bicep build` y `what-if`.
//
// Topología:
//   mova-prod-rg (brazilsouth)
//   ├─ mova-vnet 10.20.0.0/16
//   │   ├─ snet-aca   10.20.0.0/23  → Container Apps Environment (workload profiles)
//   │   └─ snet-mysql 10.20.2.0/28  → delegada a Microsoft.DBforMySQL/flexibleServers
//   ├─ Private DNS  mova.private.mysql.database.azure.com  (MySQL sin IP pública)
//   ├─ Log Analytics (retención mínima)
//   ├─ ACR (admin deshabilitado; pull por Managed Identity + AcrPull)
//   ├─ User-assigned Managed Identity compartida por las 3 apps
//   ├─ MySQL Flexible Server (SKU parametrizable, sin HA, sin geo-redundancia)
//   └─ Container Apps Environment (Consumption profile)
//       ├─ mova-web        ingress HTTPS externo → :8080, /healthz, 0..2 réplicas
//       ├─ mova-worker     sin ingress, queue:work, 1..1 réplica
//       └─ mova-scheduler  sin ingress, schedule:work, 1..1 réplica  (INVARIANTE)
targetScope = 'subscription'

@description('Región primaria. Brazil South por latencia hacia Perú.')
param location string = 'brazilsouth'

@description('Resource Group destino. MOVA-RECURSOS/eastus (preexistente) NO se reutiliza.')
param resourceGroupName string = 'mova-prod-rg'

@description('Prefijo corto para nombres de recursos.')
@minLength(3)
@maxLength(10)
param prefix string = 'mova'

@description('Imagen completa (registry/repo@sha256:... o :tag). Misma para web/worker/scheduler.')
param containerImage string

@description('Nombre del ACR (solo alfanumérico, global). Se calcula si no se indica.')
param acrName string = ''

@description('SKU del ACR. AZ-3 elige Standard si el beneficio Student está confirmado; Basic si no.')
@allowed(['Basic', 'Standard'])
param acrSku string = 'Basic'

@description('SKU de MySQL Flexible Server. B1MS_AVAILABILITY_PENDING_AZ3: reverificar disponibilidad en brazilsouth antes de provisionar.')
param mysqlSkuName string = 'Standard_B1ms'

@allowed(['Burstable', 'GeneralPurpose', 'MemoryOptimized'])
param mysqlSkuTier string = 'Burstable'

@description('Versión MySQL. PENDIENTE: confirmar versión real de Railway antes de AZ-3 — no combinar migración cloud con upgrade mayor.')
@allowed(['5.7', '8.0.21', '8.4'])
param mysqlVersion string = '8.0.21'

@description('Storage en GB. 32 = objetivo del beneficio Student (mínimo Azure: 20).')
@minValue(20)
param mysqlStorageGb int = 32

@minValue(1)
@maxValue(35)
param mysqlBackupRetentionDays int = 7

param mysqlAdminUser string = 'mova_admin'

@secure()
@description('INFRA SECRET — nunca versionar. Inyectar vía variable de entorno/Key Vault en AZ-3.')
param mysqlAdminPassword string

@description('Nombre de la base de datos de la aplicación.')
param mysqlDatabaseName string = 'mova'

@description('APP CONFIG no sensible que las tres apps comparten (APP_URL, drivers, etc.). Ver README.')
param appConfig object = {}

@secure()
@description('APP SECRETS (APP_KEY, DB_PASSWORD, CLOUDINARY_URL, ...) como objeto nombre→valor. Nunca versionar valores.')
param appSecrets object = {}

// ---------------------------------------------------------------------------

resource rg 'Microsoft.Resources/resourceGroups@2024-03-01' = {
  name: resourceGroupName
  location: location
  tags: {
    project: 'mova'
    env: 'prod'
    managedBy: 'bicep'
  }
}

module network 'modules/network.bicep' = {
  scope: rg
  name: 'network'
  params: {
    location: location
    prefix: prefix
  }
}

module logs 'modules/log-analytics.bicep' = {
  scope: rg
  name: 'logs'
  params: {
    location: location
    prefix: prefix
  }
}

module acr 'modules/acr.bicep' = {
  scope: rg
  name: 'acr'
  params: {
    location: location
    acrName: empty(acrName) ? '${prefix}acr${uniqueString(rg.id)}' : acrName
    sku: acrSku
  }
}

module identity 'modules/identity.bicep' = {
  scope: rg
  name: 'identity'
  params: {
    location: location
    prefix: prefix
    acrId: acr.outputs.id
  }
}

module mysql 'modules/mysql.bicep' = {
  scope: rg
  name: 'mysql'
  params: {
    location: location
    serverName: '${prefix}-mysql-${uniqueString(rg.id)}'
    adminUser: mysqlAdminUser
    adminPassword: mysqlAdminPassword
    skuName: mysqlSkuName
    skuTier: mysqlSkuTier
    version: mysqlVersion
    storageGb: mysqlStorageGb
    backupRetentionDays: mysqlBackupRetentionDays
    delegatedSubnetId: network.outputs.mysqlSubnetId
    privateDnsZoneId: network.outputs.mysqlPrivateDnsZoneId
    databaseName: mysqlDatabaseName
  }
}

module env 'modules/aca-environment.bicep' = {
  scope: rg
  name: 'aca-environment'
  params: {
    location: location
    prefix: prefix
    infrastructureSubnetId: network.outputs.acaSubnetId
    logAnalyticsWorkspaceId: logs.outputs.id
  }
}

// Env compartido por los tres roles. DB_HOST apunta al FQDN privado del
// servidor MySQL; los secretos llegan por `appSecrets`.
var sharedEnv = union(
  {
    APP_ENV: 'production'
    APP_DEBUG: 'false'
    LOG_CHANNEL: 'stderr'
    DB_CONNECTION: 'mysql'
    DB_HOST: mysql.outputs.fqdn
    DB_PORT: '3306'
    DB_DATABASE: mysqlDatabaseName
    DB_USERNAME: mysqlAdminUser
    // Contenedor efímero: sesiones, caché y locks de withoutOverlapping()
    // viven en MySQL (migraciones cache/sessions/jobs ya existen).
    QUEUE_CONNECTION: 'database'
    SESSION_DRIVER: 'database'
    CACHE_DRIVER: 'database'
    FILESYSTEM_DISK: 'local'
  },
  appConfig
)

module web 'modules/container-app.bicep' = {
  scope: rg
  name: 'mova-web'
  params: {
    location: location
    name: '${prefix}-web'
    environmentId: env.outputs.id
    identityId: identity.outputs.id
    registryServer: acr.outputs.loginServer
    image: containerImage
    // Comando por defecto de la imagen: entrypoint + frankenphp.
    command: []
    args: []
    externalIngress: true
    targetPort: 8080
    healthPath: '/healthz'
    cpu: '0.5'
    memory: '1Gi'
    // 0 para staging/costo; producción final se decidirá por cold-start observado.
    minReplicas: 0
    maxReplicas: 2
    env: sharedEnv
    secrets: appSecrets
  }
}

module worker 'modules/container-app.bicep' = {
  scope: rg
  name: 'mova-worker'
  params: {
    location: location
    name: '${prefix}-worker'
    environmentId: env.outputs.id
    identityId: identity.outputs.id
    registryServer: acr.outputs.loginServer
    image: containerImage
    // Contrato Railway intacto: timeout 60 < retry_after 90 (config/queue.php).
    command: ['mova-entrypoint']
    args: [
      'sh'
      '-c'
      'php artisan config:clear && php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 --backoff=5 --no-interaction'
    ]
    externalIngress: false
    cpu: '0.25'
    memory: '0.5Gi'
    minReplicas: 1
    maxReplicas: 1
    env: sharedEnv
    secrets: appSecrets
  }
}

// INVARIANTE: exactamente 1 réplica. withoutOverlapping() protege por lock en
// cache=database, pero dos schedule:work duplicarían despachos de recordatorios
// y reconciliaciones. NO subir maxReplicas.
module scheduler 'modules/container-app.bicep' = {
  scope: rg
  name: 'mova-scheduler'
  params: {
    location: location
    name: '${prefix}-scheduler'
    environmentId: env.outputs.id
    identityId: identity.outputs.id
    registryServer: acr.outputs.loginServer
    image: containerImage
    command: ['mova-entrypoint']
    args: ['sh', '-c', 'php artisan config:clear && php artisan schedule:work -v']
    externalIngress: false
    cpu: '0.25'
    memory: '0.5Gi'
    minReplicas: 1
    maxReplicas: 1
    env: sharedEnv
    secrets: appSecrets
  }
}

output resourceGroup string = rg.name
output acrLoginServer string = acr.outputs.loginServer
output mysqlFqdn string = mysql.outputs.fqdn
output webFqdn string = web.outputs.fqdn
output identityPrincipalId string = identity.outputs.principalId
