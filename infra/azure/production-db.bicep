// MOVA — servidor MySQL DEDICADO de PRODUCCIÓN (separado del de staging).
//
// Scope: resource group de la plataforma (mova-prod-rg: aloja el ACA environment, la VNet, el ACR y la
// identidad compartidos; pese al nombre, ahí viven también las apps de staging `mova-*`). La suscripción
// Student solo permite 1 Container Apps Environment, así que producción reutiliza ese entorno y la VNet,
// pero tiene servidor MySQL, usuario de aplicación, secretos y apps (`movap-*`) propios.
//
// Crea una subred delegada nueva en la VNet existente, enlazada a la misma Private DNS zone, y el servidor
// vía modules/mysql.bicep (acceso privado, TLS obligatorio, sin HA ni geo-redundancia, 14 días de PITR).
// El password de administración se lee de MOVA_MYSQL_ADMIN_PASSWORD_PROD y no se versiona ni se inyecta
// en las apps.
targetScope = 'resourceGroup'

param location string = 'mexicocentral'
param vnetName string = 'mova-vnet'
param subnetName string = 'snet-mysql-prod'
param subnetPrefix string = '10.20.2.16/28'
param dnsZoneName string = 'mova.private.mysql.database.azure.com'

@description('Nombre del servidor de producción (global dentro de azure.com).')
param serverName string

param adminUser string = 'movap_admin'

@secure()
@minLength(16)
param adminPassword string

param skuName string = 'Standard_B1ms'
param skuTier string = 'Burstable'
param version string = '8.4'
param storageGb int = 32
@minValue(1)
@maxValue(35)
param backupRetentionDays int = 14
param databaseName string = 'mova'

resource vnet 'Microsoft.Network/virtualNetworks@2024-01-01' existing = {
  name: vnetName
}

resource subnet 'Microsoft.Network/virtualNetworks/subnets@2024-01-01' = {
  parent: vnet
  name: subnetName
  properties: {
    addressPrefix: subnetPrefix
    delegations: [
      {
        name: 'mysql'
        properties: { serviceName: 'Microsoft.DBforMySQL/flexibleServers' }
      }
    ]
  }
}

resource dns 'Microsoft.Network/privateDnsZones@2020-06-01' existing = {
  name: dnsZoneName
}

module mysql 'modules/mysql.bicep' = {
  name: 'mysql-production'
  params: {
    location: location
    serverName: serverName
    adminUser: adminUser
    adminPassword: adminPassword
    skuName: skuName
    skuTier: skuTier
    version: version
    storageGb: storageGb
    backupRetentionDays: backupRetentionDays
    delegatedSubnetId: subnet.id
    privateDnsZoneId: dns.id
    databaseName: databaseName
  }
}

output fqdn string = mysql.outputs.fqdn
