// MOVA — deploy de apps (mova-web/worker/scheduler), AZ-3D.
//
// Scope: resource group existente (mova-prod-rg). Referencia la foundation
// de main.bicep como `existing` — ACA environment, Managed Identity, ACR,
// MySQL — y NUNCA requiere mysqlAdminPassword: el ciclo normal de deploy de
// apps (imagen nueva, rollback, escalado) no debe depender del secreto de
// administración de MySQL, que solo hace falta para bootstrap/mantenimiento
// puntual (ver AZ-3C, `az containerapp job`).
//
// Reutiliza modules/container-app.bicep — misma imagen (mismo digest) para
// los 3 roles; el rol lo define command/args y la presencia de ingress.
targetScope = 'resourceGroup'

@description('Debe coincidir con el prefix de la foundation (main.bicep) para resolver los nombres de recursos existentes.')
@minLength(3)
@maxLength(10)
param prefix string = 'mova'

@description('Región de los recursos existentes (debe coincidir con la foundation).')
param location string = 'mexicocentral'

@description('Imagen MOVA por digest inmutable: <acr>.azurecr.io/mova@sha256:<digest>. NUNCA latest/prod/stable.')
@minLength(1)
param containerImage string

@description('Nombre de la base de datos de la aplicación (debe coincidir con la foundation).')
param mysqlDatabaseName string = 'mova'

@description('Usuario de aplicación con privilegios únicamente sobre la base `mova`. Lo crea el bootstrap de AZ-3C — nunca el admin.')
param mysqlAppUser string = 'mova_app'

@secure()
@minLength(16)
@description('APP SECRET — contraseña runtime de web/worker/scheduler (usuario mysqlAppUser, NO el admin).')
param mysqlAppPassword string

@description('APP_URL de la aplicación. Vacío = se deriva del FQDN de mova-web en el ACA environment (primer staging). El dominio final lo fija el cutover.')
param appUrl string = ''

@description('APP CONFIG no sensible que las tres apps comparten (drivers, etc.). Ver README.')
param appConfig object = {}

@secure()
@description('APP_KEY actual de Railway, migrado EXACTAMENTE (nunca key:generate).')
@minLength(1)
param appKey string

@secure()
@description('Otros APP SECRETS (CLOUDINARY_URL, ...) como objeto nombre→valor. Nunca versionar valores.')
param appSecrets object = {}

// ---------------------------------------------------------------------------
// Recursos de la foundation, referenciados como existing (mismos nombres
// deterministas que main.bicep calcula al crearlos).
// ---------------------------------------------------------------------------

resource acrRef 'Microsoft.ContainerRegistry/registries@2023-07-01' existing = {
  name: '${prefix}acr${uniqueString(resourceGroup().id)}'
}

resource identityRef 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' existing = {
  name: '${prefix}-apps-identity'
}

resource envRef 'Microsoft.App/managedEnvironments@2024-03-01' existing = {
  name: '${prefix}-aca-env'
}

resource mysqlRef 'Microsoft.DBforMySQL/flexibleServers@2023-12-30' existing = {
  name: '${prefix}-mysql-${uniqueString(resourceGroup().id)}'
}

// ---------------------------------------------------------------------------

// Env compartido por los tres roles. DB_HOST apunta al FQDN privado del
// servidor MySQL; la app usa el usuario de aplicación (nunca el admin).
// APP_URL: FQDN de Container Apps = <app>.<defaultDomain del environment>.
// union(): el último argumento gana → los invariantes de IaC van al final y
// appConfig NUNCA puede sobrescribirlos.
var effectiveAppUrl = empty(appUrl) ? 'https://${prefix}-web.${envRef.properties.defaultDomain}' : appUrl
var sharedEnv = union(
  appConfig,
  {
    APP_ENV: 'production'
    APP_DEBUG: 'false'
    APP_URL: effectiveAppUrl
    LOG_CHANNEL: 'stderr'
    DB_CONNECTION: 'mysql'
    DB_HOST: mysqlRef.properties.fullyQualifiedDomainName
    DB_PORT: '3306'
    DB_DATABASE: mysqlDatabaseName
    DB_USERNAME: mysqlAppUser
    // require_secure_transport=ON en el servidor: el cliente verifica contra
    // el trust store del sistema (root CAs, tolera rotaciones de intermedias).
    MYSQL_ATTR_SSL_CA: '/etc/ssl/certs/ca-certificates.crt'
    // Contenedor efímero: sesiones, caché y locks de withoutOverlapping()
    // viven en MySQL (migraciones cache/sessions/jobs ya existen).
    QUEUE_CONNECTION: 'database'
    SESSION_DRIVER: 'database'
    CACHE_DRIVER: 'database'
    FILESYSTEM_DISK: 'local'
  }
)

// Secretos runtime: APP_KEY y DB_PASSWORD son SIEMPRE los parámetros explícitos
// (último argumento gana; appSecrets no puede sobrescribirlos).
var runtimeSecrets = union(appSecrets, {
  APP_KEY: appKey
  DB_PASSWORD: mysqlAppPassword
})

module web 'modules/container-app.bicep' = {
  name: 'mova-web'
  params: {
    location: location
    name: '${prefix}-web'
    environmentId: envRef.id
    identityId: identityRef.id
    registryServer: acrRef.properties.loginServer
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
    secrets: runtimeSecrets
  }
}

module worker 'modules/container-app.bicep' = {
  name: 'mova-worker'
  params: {
    location: location
    name: '${prefix}-worker'
    environmentId: envRef.id
    identityId: identityRef.id
    registryServer: acrRef.properties.loginServer
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
    secrets: runtimeSecrets
  }
}

// INVARIANTE: exactamente 1 réplica. withoutOverlapping() protege por lock en
// cache=database, pero dos schedule:work duplicarían despachos de recordatorios
// y reconciliaciones. NO subir maxReplicas.
module scheduler 'modules/container-app.bicep' = {
  name: 'mova-scheduler'
  params: {
    location: location
    name: '${prefix}-scheduler'
    environmentId: envRef.id
    identityId: identityRef.id
    registryServer: acrRef.properties.loginServer
    image: containerImage
    command: ['mova-entrypoint']
    args: ['sh', '-c', 'php artisan config:clear && php artisan schedule:work -v']
    externalIngress: false
    cpu: '0.25'
    memory: '0.5Gi'
    minReplicas: 1
    maxReplicas: 1
    env: sharedEnv
    secrets: runtimeSecrets
  }
}

output webFqdn string = web.outputs.fqdn
output appUrl string = effectiveAppUrl
