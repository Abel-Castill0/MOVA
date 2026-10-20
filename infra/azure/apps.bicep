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

@description('''Prefijo de los NOMBRES de las apps (<prefix>-web/-worker/-scheduler). Por defecto = prefix (staging: mova-*).
Producción comparte entorno ACA/ACR/identidad con staging (la suscripción Student permite 1 entorno) y usa
appNamePrefix=movap para tener apps, secretos y base de datos propios.''')
@minLength(3)
@maxLength(10)
param appNamePrefix string = prefix

@description('Nombre del servidor MySQL. Vacío = el de la foundation de staging (<prefix>-mysql-<uniq>). Producción pasa su servidor dedicado.')
param mysqlServerName string = ''

// ISO/IEC 25010: fiabilidad (disponibilidad/tolerancia a fallos) y eficiencia de desempeño (comportamiento temporal).
// Medido 2026-10-19: con 0 réplicas la primera petición tardó 22,9 s (arranque en frío), igual al plazo de
// respuesta de los webhooks de Mercado Pago (~22 s). Costo de min=1 a 0,25 vCPU/0,5 GiB ≈ US$ 1,7/mes.
// ISO/IEC 29119 (criterio): con min=1, `curl /healthz` tras 10 min de inactividad responde < 2 s.
// El default es 1 (producción). 0 es SOLO para entornos no productivos y debe fijarse explícitamente (MOVA_WEB_MIN_REPLICAS=0).
@description('Réplicas mínimas del web. 0 en staging (costo); 1 en producción (sin arranque en frío).')
@minValue(0)
@maxValue(2)
param webMinReplicas int = 1

@description('Imagen MOVA por digest inmutable: <acr>.azurecr.io/mova@sha256:<digest>. NUNCA latest/prod/stable.')
@minLength(1)
param containerImage string

@description('Deploy staggered por rol, AZ-3D: activar web primero, worker/scheduler solo tras revisión de integraciones externas (AZ-3E) porque ejecutan side effects — emails, notificaciones, settlement.')
param deployWeb bool = true
param deployWorker bool = false
param deployScheduler bool = false

// ── Opciones de ahorro EXPERIMENTALES (default false = comportamiento actual, sin cambios) ──
// ISO/IEC 25010: eficiencia de desempeño vs fiabilidad. Ver docs/architecture/06-cost-and-scaling.md.
@description('EXPERIMENTAL. Ejecuta el scheduler como Container Apps Job cron (en lugar de `schedule:work` always-on). Exige deployScheduler=true; mutuamente excluyente con combineWorkerScheduler.')
param schedulerAsJob bool = false
@description('Cron del Job del scheduler (UTC). `* * * * *` conserva la frecuencia por minuto que exigen las tareas actuales.')
param schedulerCron string = '* * * * *'
@description('EXPERIMENTAL. Un solo contenedor ejecuta `queue:work` y `schedule:work` (ahorra una app). Exige deployWorker=true; la app del scheduler no se despliega. Un fallo de cualquiera de los dos procesos reinicia el contenedor.')
param combineWorkerScheduler bool = false

@description('Liquidación programada: dry_run por defecto. live requiere selección explícita del operador en apps.bicepparam y gate de producción aprobado.')
@allowed(['dry_run', 'live'])
param settlementMode string = 'dry_run'

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

@description('Secretos respaldados por Key Vault (NOMBRE_VAR → URI sin versión). Un nombre NO puede estar a la vez aquí y en appSecrets/runtimeSecrets. Ver docs/architecture/07-secrets-keyvault-plan.md y scripts/azure/migrate-secrets-to-keyvault.sh.')
param keyVaultSecrets object = {}

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
  name: empty(mysqlServerName) ? '${prefix}-mysql-${uniqueString(resourceGroup().id)}' : mysqlServerName
}

// ---------------------------------------------------------------------------

// Env compartido por los tres roles. DB_HOST apunta al FQDN privado del
// servidor MySQL; la app usa el usuario de aplicación (nunca el admin).
// APP_URL: FQDN de Container Apps = <app>.<defaultDomain del environment>.
// union(): el último argumento gana → los invariantes de IaC van al final y
// appConfig NUNCA puede sobrescribirlos.
var effectiveAppUrl = empty(appUrl) ? 'https://${appNamePrefix}-web.${envRef.properties.defaultDomain}' : appUrl
var sharedEnv = union(
  appConfig,
  {
    APP_NAME: 'MOVA'
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
    // Invariante separado de deployScheduler; appConfig no puede activarlo.
    LESSON_SETTLEMENT_MODE: settlementMode
  }
)

// Secretos runtime: APP_KEY y DB_PASSWORD son SIEMPRE los parámetros explícitos
// (último argumento gana; appSecrets no puede sobrescribirlos).
var runtimeSecrets = union(appSecrets, {
  APP_KEY: appKey
  DB_PASSWORD: mysqlAppPassword
})

module web 'modules/container-app.bicep' = if (deployWeb) {
  name: '${appNamePrefix}-web'
  params: {
    location: location
    name: '${appNamePrefix}-web'
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
    // ISO/IEC 25010 (eficiencia de desempeño: utilización de recursos): 0,25 vCPU / 0,5 GiB es lo que corre en producción
    // desde 2026-10-06 (runbook §1). Antes el IaC decía 0,5/1 GiB: un deploy habría DUPLICADO el costo del web (deriva).
    // Criterio ISO 29119: /healthz < 2 s con réplica caliente; sin OOM en 7 días (Log Analytics).
    cpu: '0.25'
    memory: '0.5Gi'
    // 0 en staging (costo); producción usa webMinReplicas=1 (ISO 25010: fiabilidad + eficiencia temporal; ver arriba).
    minReplicas: webMinReplicas
    maxReplicas: 2
    env: sharedEnv
    secrets: runtimeSecrets
    keyVaultSecrets: keyVaultSecrets
  }
}

module worker 'modules/container-app.bicep' = if (deployWorker) {
  name: '${appNamePrefix}-worker'
  params: {
    location: location
    name: '${appNamePrefix}-worker'
    environmentId: envRef.id
    identityId: identityRef.id
    registryServer: acrRef.properties.loginServer
    image: containerImage
    // Contrato Railway intacto: timeout 60 < retry_after 90 (config/queue.php).
    // ISO/IEC 25010: fiabilidad (madurez). min=max=1: la cola es `database` y no hay escalador KEDA; con min=0 nada
    // la consume (se halló así el 2026-10-19: correos, webhooks y recuperación de pagos detenidos). Un escalador
    // KEDA MySQL ahorraría ≈ US$ 1/mes pero añade un secreto y un punto de fallo: descartado (docs/architecture/06).
    // ISO/IEC 29119 (criterio): tras encolar 100 jobs de prueba, `queue:monitor` llega a 0 pendientes en < 5 min.
    command: ['mova-entrypoint']
    // combineWorkerScheduler (EXPERIMENTAL): ambos procesos en un contenedor; `wait -n` requiere bash (no dash). Si uno
    // termina, el contenedor sale con 1 y Container Apps lo reinicia. Probar memoria (0,5 GiB) en staging antes de usarlo.
    args: combineWorkerScheduler ? [
      'bash'
      '-c'
      'php artisan config:clear && { php artisan schedule:work -v & php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 --backoff=5 --no-interaction & wait -n; echo "proceso terminado, reiniciando contenedor" >&2; exit 1; }'
    ] : [
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
    keyVaultSecrets: keyVaultSecrets
  }
}

// ISO/IEC 25010: fiabilidad (madurez, recuperabilidad) y eficiencia de recursos. min=max=1 (≈ US$ 2,2/mes).
// Migrarlo a un Container Apps Job con cron `* * * * *` ahorraría ≲ US$ 1-2/mes pero arranca un contenedor por minuto
// y duplica el riesgo de solapes: descartado por ahora (docs/architecture/06-cost-and-scaling.md).
// ISO/IEC 29119 (criterio): `system_heartbeats` del scheduler < 2 min de antigüedad; `mova:settle-lessons --dry-run`
// con `safe_to_enable=true` ANTES de arrancar el scheduler tras cualquier parada.
// INVARIANTE: exactamente 1 réplica. withoutOverlapping() protege por lock en
// cache=database, pero dos schedule:work duplicarían despachos de recordatorios
// y reconciliaciones. NO subir maxReplicas.
module scheduler 'modules/container-app.bicep' = if (deployScheduler && !schedulerAsJob && !combineWorkerScheduler) {
  name: '${appNamePrefix}-scheduler'
  params: {
    location: location
    name: '${appNamePrefix}-scheduler'
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
    keyVaultSecrets: keyVaultSecrets
  }
}

// EXPERIMENTAL — alternativa de bajo costo al scheduler always-on (ver docs/architecture/06-cost-and-scaling.md).
// Mutuamente excluyente con la app del scheduler y con combineWorkerScheduler: así nunca coexisten dos planificadores.
module schedulerJob 'modules/container-job.bicep' = if (deployScheduler && schedulerAsJob && !combineWorkerScheduler) {
  name: '${appNamePrefix}-scheduler-job'
  params: {
    location: location
    name: '${appNamePrefix}-scheduler-job'
    environmentId: envRef.id
    identityId: identityRef.id
    registryServer: acrRef.properties.loginServer
    image: containerImage
    command: ['mova-entrypoint']
    args: ['sh', '-c', 'php artisan config:clear && php artisan schedule:run -v']
    cronExpression: schedulerCron
    env: sharedEnv
    secrets: runtimeSecrets
  }
}

output webFqdn string = web.?outputs.?fqdn ?? ''
output appUrl string = effectiveAppUrl
