// Parámetros de validación/what-if. NINGÚN valor real aquí.
// Los secretos se leen de variables de entorno del shell que ejecuta el deploy.
using './main.bicep'

param location = 'brazilsouth'
param resourceGroupName = 'mova-prod-rg'
param prefix = 'mova'

// AZ-3 reemplaza por <acr>.azurecr.io/mova@sha256:<digest> (misma imagen para los 3 roles).
param containerImage = 'mcr.microsoft.com/k8se/quickstart:latest'

param acrSku = 'Basic'
param mysqlSkuName = 'Standard_B1ms'
param mysqlSkuTier = 'Burstable'
param mysqlVersion = '8.0.21'
param mysqlStorageGb = 32

param mysqlAdminPassword = readEnvironmentVariable('MOVA_MYSQL_ADMIN_PASSWORD', 'placeholder-what-if-only-Aa1!')

param appConfig = {
  APP_URL: 'https://mova.example'
}

// Solo NOMBRES: los valores se inyectan en AZ-3 (Key Vault / env del pipeline).
param appSecrets = {
  APP_KEY: readEnvironmentVariable('MOVA_APP_KEY', '')
  DB_PASSWORD: readEnvironmentVariable('MOVA_MYSQL_ADMIN_PASSWORD', 'placeholder-what-if-only-Aa1!')
  CLOUDINARY_URL: readEnvironmentVariable('MOVA_CLOUDINARY_URL', '')
}
