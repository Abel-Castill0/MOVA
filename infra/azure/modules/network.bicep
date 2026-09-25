// VNet + subnets + Private DNS para MySQL. La DB nunca tiene IP pública.
param location string
param prefix string

param vnetAddressSpace string = '10.20.0.0/16'
// Workload-profiles environment exige mínimo /27; /23 deja margen de escalado.
param acaSubnetPrefix string = '10.20.0.0/23'
param mysqlSubnetPrefix string = '10.20.2.0/28'

resource vnet 'Microsoft.Network/virtualNetworks@2024-01-01' = {
  name: '${prefix}-vnet'
  location: location
  properties: {
    addressSpace: { addressPrefixes: [vnetAddressSpace] }
    subnets: [
      {
        name: 'snet-aca'
        properties: {
          addressPrefix: acaSubnetPrefix
          delegations: [
            {
              name: 'aca'
              properties: { serviceName: 'Microsoft.App/environments' }
            }
          ]
        }
      }
      {
        name: 'snet-mysql'
        properties: {
          addressPrefix: mysqlSubnetPrefix
          delegations: [
            {
              name: 'mysql'
              properties: { serviceName: 'Microsoft.DBforMySQL/flexibleServers' }
            }
          ]
        }
      }
    ]
  }
}

// Azure exige que la zona termine en mysql.database.azure.com para VNet integration.
resource mysqlDns 'Microsoft.Network/privateDnsZones@2020-06-01' = {
  name: '${prefix}.private.mysql.database.azure.com'
  location: 'global'
}

resource mysqlDnsLink 'Microsoft.Network/privateDnsZones/virtualNetworkLinks@2020-06-01' = {
  parent: mysqlDns
  name: '${prefix}-vnet-link'
  location: 'global'
  properties: {
    virtualNetwork: { id: vnet.id }
    registrationEnabled: false
  }
}

output vnetId string = vnet.id
output acaSubnetId string = vnet.properties.subnets[0].id
output mysqlSubnetId string = vnet.properties.subnets[1].id
output mysqlPrivateDnsZoneId string = mysqlDns.id
