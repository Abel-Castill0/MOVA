// Azure Container Registry. Sin admin user: el pull lo hace la Managed
// Identity con AcrPull (modules/identity.bicep). Nunca usuario/contraseña.
param location string
param acrName string
@allowed(['Basic', 'Standard'])
param sku string

resource acr 'Microsoft.ContainerRegistry/registries@2023-07-01' = {
  name: acrName
  location: location
  sku: { name: sku }
  properties: {
    adminUserEnabled: false
    publicNetworkAccess: 'Enabled'
  }
}

output id string = acr.id
output loginServer string = acr.properties.loginServer
