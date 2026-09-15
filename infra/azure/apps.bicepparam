// Parámetros de deploy de las apps (mova-web/worker/scheduler), AZ-3D.
// NINGÚN valor secreto aquí: todo secreto se lee de variables de entorno del
// shell que ejecuta el deploy y FALLA si falta. NUNCA requiere
// MOVA_MYSQL_ADMIN_PASSWORD — ese secreto es exclusivo de main.bicepparam.
//
// Requiere:
//   MOVA_CONTAINER_IMAGE=<acr>.azurecr.io/mova@sha256:<digest>
//   MOVA_MYSQL_APP_PASSWORD  (usuario mova_app, >= 16 chars)
//   MOVA_APP_KEY             (el actual de Railway, NUNCA regenerar)
//   MOVA_CLOUDINARY_URL      (opcional)
using './apps.bicep'

param prefix = 'mova'
param location = 'mexicocentral'

var image = readEnvironmentVariable('MOVA_CONTAINER_IMAGE', '')
param containerImage = empty(image) ? fail('MOVA_CONTAINER_IMAGE requerido: <acr>.azurecr.io/mova@sha256:<digest>') : image

param mysqlDatabaseName = 'mova'
param mysqlAppUser = 'mova_app'

var appPw = readEnvironmentVariable('MOVA_MYSQL_APP_PASSWORD', '')
param mysqlAppPassword = length(appPw) < 16 ? fail('MOVA_MYSQL_APP_PASSWORD requerido (>= 16 chars, usuario mova_app)') : appPw

// Vacío = https://mova-web.<defaultDomain del ACA environment> (primer staging).
param appUrl = readEnvironmentVariable('MOVA_APP_URL', '')

param appConfig = {}

var key = readEnvironmentVariable('MOVA_APP_KEY', '')
param appKey = empty(key) ? fail('MOVA_APP_KEY requerido (el actual de Railway, nunca key:generate)') : key

// Otros secretos, solo NOMBRES; los valores vienen del entorno del pipeline / Key Vault.
// CLOUDINARY_URL no es obligatorio: la app falla de forma controlada solo al
// subir un avatar en producción sin él; se verifica en el cutover.
param appSecrets = {
  CLOUDINARY_URL: readEnvironmentVariable('MOVA_CLOUDINARY_URL', '')
}
