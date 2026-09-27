// Azure Database for MySQL Flexible Server — acceso privado, TLS obligatorio,
// sin HA ni geo-redundancia (primera etapa / beneficio Student).
param location string
param serverName string
param adminUser string
@secure()
param adminPassword string
param skuName string
param skuTier string
param version string
param storageGb int
param backupRetentionDays int
param delegatedSubnetId string
param privateDnsZoneId string
param databaseName string

resource server 'Microsoft.DBforMySQL/flexibleServers@2023-12-30' = {
  name: serverName
  location: location
  sku: {
    name: skuName
    tier: skuTier
  }
  properties: {
    administratorLogin: adminUser
    administratorLoginPassword: adminPassword
    version: version
    storage: {
      storageSizeGB: storageGb
      autoGrow: 'Disabled'
      iops: 360
    }
    backup: {
      backupRetentionDays: backupRetentionDays
      geoRedundantBackup: 'Disabled'
    }
    highAvailability: { mode: 'Disabled' }
    network: {
      delegatedSubnetResourceId: delegatedSubnetId
      privateDnsZoneResourceId: privateDnsZoneId
      publicNetworkAccess: 'Disabled'
    }
  }
}

// TLS obligatorio para todo cliente (Laravel: MYSQL_ATTR_SSL_CA en config/database.php).
resource requireSsl 'Microsoft.DBforMySQL/flexibleServers/configurations@2023-12-30' = {
  parent: server
  name: 'require_secure_transport'
  properties: {
    value: 'ON'
    source: 'user-override'
  }
}

resource db 'Microsoft.DBforMySQL/flexibleServers/databases@2023-12-30' = {
  parent: server
  name: databaseName
  properties: {
    charset: 'utf8mb4'
    collation: 'utf8mb4_unicode_ci'
  }
}

output id string = server.id
output fqdn string = server.properties.fullyQualifiedDomainName
