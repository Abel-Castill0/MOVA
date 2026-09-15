// MOVA — infraestructura Azure, FOUNDATION únicamente (AZ-3A/AZ-3C).
//
// Scope: suscripción. Crea el Resource Group y delega todo lo demás a módulos.
// NUNCA despliega mova-web/worker/scheduler — eso vive en apps.bicep
// (AZ-3D), que se despliega scoped al resource group aquí creado, referencia
// estos recursos como `existing`, y NUNCA requiere mysqlAdminPassword. La
// separación existe para que el ciclo de deploy normal de apps (imagen nueva,
// rollback, escalado) no dependa del secreto de administración de MySQL, que
// solo hace falta para bootstrap/mantenimiento puntual (ver AZ-3C).
//
// Topología:
//   mova-prod-rg (mexicocentral — brazilsouth rechazada por la suscripción
//   con ProvisionNotSupportedForRegion en el deployment real de MySQL, ver
//   README §Pendientes AZ-3A-R)
//   ├─ mova-vnet 10.20.0.0/16
//   │   ├─ snet-aca   10.20.0.0/23  → Container Apps Environment (workload profiles)
//   │   └─ snet-mysql 10.20.2.0/28  → delegada a Microsoft.DBforMySQL/flexibleServers
//   ├─ Private DNS  mova.private.mysql.database.azure.com  (MySQL sin IP pública)
//   ├─ Log Analytics (retención mínima)
//   ├─ ACR (admin deshabilitado; pull por Managed Identity + AcrPull)
//   ├─ User-assigned Managed Identity compartida por las 3 apps
//   ├─ MySQL Flexible Server (SKU parametrizable, sin HA, sin geo-redundancia)
//   └─ Container Apps Environment (Consumption profile) — apps: ver apps.bicep
targetScope = 'subscription'

@description('''Región primaria. mexicocentral: la más cercana a Perú entre las
regiones permitidas por la Azure Policy de esta suscripción Student que además
soportan MySQL Flexible Server/Container Apps/ACR/Log Analytics. brazilsouth
fue rechazada en AZ-3A con ProvisionNotSupportedForRegion en el deployment
real de MySQL (el resto de la foundation sí se creó ahí).''')
param location string = 'mexicocentral'

@description('Resource Group destino. MOVA-RECURSOS/eastus (preexistente) NO se reutiliza.')
param resourceGroupName string = 'mova-prod-rg'

@description('Prefijo corto para nombres de recursos.')
@minLength(3)
@maxLength(10)
param prefix string = 'mova'

@description('Nombre del ACR (solo alfanumérico, global). Se calcula si no se indica.')
param acrName string = ''

@description('SKU del ACR. AZ-3 elige Standard si el beneficio Student está confirmado; Basic si no.')
@allowed(['Basic', 'Standard'])
param acrSku string = 'Basic'

@description('SKU de MySQL Flexible Server. B1MS_AVAILABILITY_PENDING_AZ3B: `list-skus` devuelve 500 para esta suscripción en cualquier región probada; la disponibilidad real se confirma por el resultado del deployment, no por ese endpoint.')
param mysqlSkuName string = 'Standard_B1ms'

@allowed(['Burstable', 'GeneralPurpose', 'MemoryOptimized'])
param mysqlSkuTier string = 'Burstable'

@description('Versión MySQL. Confirmada 8.4 en AZ-2 (dump real 9.4→8.4 con paridad exacta y 91/91 migraciones).')
@allowed(['5.7', '8.0.21', '8.4'])
param mysqlVersion string = '8.0.21'

@description('Storage en GB. 32 = objetivo del beneficio Student (mínimo Azure: 20).')
@minValue(20)
param mysqlStorageGb int = 32

@minValue(1)
@maxValue(35)
param mysqlBackupRetentionDays int = 7

@description('Usuario administrador del servidor. SOLO infraestructura/bootstrap (Job manual); nunca llega a web/worker/scheduler.')
param mysqlAdminUser string = 'mova_admin'

@secure()
@minLength(16)
@description('INFRA SECRET — nunca versionar. Solo se usa al crear el servidor y en el Job de bootstrap.')
param mysqlAdminPassword string

@description('Nombre de la base de datos de la aplicación. apps.bicep lo reutiliza como referencia, no como parámetro compartido.')
param mysqlDatabaseName string = 'mova'

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

output resourceGroup string = rg.name
output acrLoginServer string = acr.outputs.loginServer
output mysqlFqdn string = mysql.outputs.fqdn
output mysqlDatabaseName string = mysqlDatabaseName
output identityPrincipalId string = identity.outputs.principalId
output acaEnvironmentDefaultDomain string = env.outputs.defaultDomain
