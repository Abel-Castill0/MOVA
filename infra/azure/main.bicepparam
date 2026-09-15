// Parámetros de deploy de la FOUNDATION únicamente. NINGÚN valor secreto
// aquí: el único secreto (mysqlAdminPassword) se lee de una variable de
// entorno del shell que ejecuta el deploy y FALLA si falta.
//
// Apps (mova-web/worker/scheduler) viven en apps.bicep/apps.bicepparam
// (AZ-3D) y NUNCA requieren este admin password.
using './main.bicep'

// Brazil South rechazada por esta suscripción Azure for Students con
// ProvisionNotSupportedForRegion durante el provisioning real de MySQL
// Flexible Server (AZ-3A, deployment real, no what-if). Región vigente
// resuelta en AZ-3A-R por intersección real: Azure Policy
// "Allowed resource deployment regions" de la suscripción
// (westus, mexicocentral, canadacentral, northcentralus, brazilsouth)
// ∩ regiones soportadas por Microsoft.DBforMySQL/flexibleServers,
// Microsoft.App/managedEnvironments, Microsoft.ContainerRegistry/registries
// y Microsoft.OperationalInsights/workspaces. Las 4 no-Brazil pasaron la
// intersección; mexicocentral es la más cercana a Perú.
param location = 'mexicocentral'
param resourceGroupName = 'mova-prod-rg'
param prefix = 'mova'

param acrSku = 'Basic'
param mysqlSkuName = 'Standard_B1ms'
param mysqlSkuTier = 'Burstable'
param mysqlVersion = '8.4'
param mysqlStorageGb = 32

// INFRA SECRET — obligatorio siempre (sin default → error si falta).
param mysqlAdminPassword = readEnvironmentVariable('MOVA_MYSQL_ADMIN_PASSWORD')

param mysqlDatabaseName = 'mova'
