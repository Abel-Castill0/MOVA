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

// Fail-closed en COMPILACIÓN (antes de cualquier deployment): con
// MOVA_DEPLOY_APPS=true, cada valor obligatorio vacío aborta la compilación.
var apps = toLower(readEnvironmentVariable('MOVA_DEPLOY_APPS', 'false')) == 'true'
var image = readEnvironmentVariable('MOVA_CONTAINER_IMAGE', '')
var appPw = readEnvironmentVariable('MOVA_MYSQL_APP_PASSWORD', '')
var key = readEnvironmentVariable('MOVA_APP_KEY', '')
param deployApps = apps
// Sin fallback: vacío salvo que AZ-3 suministre la imagen MOVA real del ACR.
param containerImage = apps && empty(image) ? fail('MOVA_DEPLOY_APPS=true requires MOVA_CONTAINER_IMAGE') : image
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
param mysqlAppPassword = apps && length(appPw) < 16 ? fail('MOVA_DEPLOY_APPS=true requires MOVA_MYSQL_APP_PASSWORD (>= 16 chars)') : appPw

param appConfig = {}

// APP SECRET — APP_KEY actual de Railway; obligatorio con deployApps=true (main.bicep falla si vacío).
param appKey = apps && empty(key) ? fail('MOVA_DEPLOY_APPS=true requires MOVA_APP_KEY (current Railway APP_KEY, never regenerated)') : key

// Otros secretos, solo NOMBRES; los valores vienen del entorno del pipeline / Key Vault.
// CLOUDINARY_URL no es obligatorio para bootstrap: la app falla de forma controlada
// solo al subir un avatar en producción sin él; se verifica en el cutover.
param appSecrets = {
  CLOUDINARY_URL: readEnvironmentVariable('MOVA_CLOUDINARY_URL', '')
}
