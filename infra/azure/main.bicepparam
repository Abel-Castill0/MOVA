// Parámetros de deploy. NINGÚN valor secreto aquí: todo secreto se lee de
// variables de entorno del shell que ejecuta el deploy y FALLA si falta.
//
// Fase foundation (default):   deployApps=false, solo MOVA_MYSQL_ADMIN_PASSWORD.
// Fase apps (tras push de imagen):
//   MOVA_DEPLOY_APPS=true
//   MOVA_CONTAINER_IMAGE=<acr>.azurecr.io/mova@sha256:<digest>
//   MOVA_MYSQL_APP_PASSWORD, MOVA_APP_KEY (el actual, NO regenerar), MOVA_CLOUDINARY_URL, ...
using './main.bicep'

param location = 'brazilsouth'
param resourceGroupName = 'mova-prod-rg'
param prefix = 'mova'

param deployApps = toLower(readEnvironmentVariable('MOVA_DEPLOY_APPS', 'false')) == 'true'
// Sin fallback: vacío salvo que AZ-3 suministre la imagen MOVA real del ACR.
param containerImage = readEnvironmentVariable('MOVA_CONTAINER_IMAGE', '')
// Vacío = https://mova-web.<defaultDomain del ACA environment> (primer staging).
param appUrl = readEnvironmentVariable('MOVA_APP_URL', '')

param acrSku = 'Basic'
param mysqlSkuName = 'Standard_B1ms'
param mysqlSkuTier = 'Burstable'
param mysqlVersion = '8.0.21'
param mysqlStorageGb = 32

// INFRA SECRET — obligatorio siempre (sin default → error si falta).
param mysqlAdminPassword = readEnvironmentVariable('MOVA_MYSQL_ADMIN_PASSWORD')
// APP SECRET — obligatorio con deployApps=true (main.bicep falla si está vacío).
param mysqlAppUser = 'mova_app'
param mysqlAppPassword = readEnvironmentVariable('MOVA_MYSQL_APP_PASSWORD', '')

param appConfig = {}

// Solo NOMBRES; los valores vienen del entorno del pipeline / Key Vault.
param appSecrets = {
  APP_KEY: readEnvironmentVariable('MOVA_APP_KEY', '')
  CLOUDINARY_URL: readEnvironmentVariable('MOVA_CLOUDINARY_URL', '')
}
