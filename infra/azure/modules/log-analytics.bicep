// Log Analytics mínimo para los logs de Container Apps.
param location string
param prefix string
@minValue(30)
param retentionDays int = 30

resource workspace 'Microsoft.OperationalInsights/workspaces@2022-10-01' = {
  name: '${prefix}-logs'
  location: location
  properties: {
    sku: { name: 'PerGB2018' }
    retentionInDays: retentionDays
    workspaceCapping: {
      // Tope diario de ingesta (GB) para que un bucle de logs no consuma crédito.
      dailyQuotaGb: 1
    }
  }
}

output id string = workspace.id
output customerId string = workspace.properties.customerId
