// Parámetros de deploy de las apps (mova-web/worker/scheduler), AZ-3D.
// NINGÚN valor secreto aquí: todo secreto se lee de variables de entorno del
// shell que ejecuta el deploy y FALLA si falta. NUNCA requiere
// MOVA_MYSQL_ADMIN_PASSWORD — ese secreto es exclusivo de main.bicepparam.
//
// Requiere:
//   MOVA_CONTAINER_IMAGE=<acr>.azurecr.io/mova@sha256:<digest>
//   MOVA_MYSQL_APP_PASSWORD  (usuario mova_app, >= 16 chars)
//   MOVA_APP_KEY             (el actual de Railway, NUNCA regenerar)
//   MOVA_CLOUDINARY_URL      (opcional; se omite el secret si no está)
using './apps.bicep'

param prefix = 'mova'
param location = 'mexicocentral'

var image = readEnvironmentVariable('MOVA_CONTAINER_IMAGE', '')
param containerImage = empty(image) ? fail('MOVA_CONTAINER_IMAGE requerido: <acr>.azurecr.io/mova@sha256:<digest>') : image

// AZ-3D: staging web-only. worker/scheduler ejecutan side effects (emails,
// notificaciones, settlement/recovery schedules) y quedan para AZ-3E, tras
// revisar la configuración de integraciones externas.
param deployWeb = true
param deployWorker = false
param deployScheduler = false

param mysqlDatabaseName = 'mova'
param mysqlAppUser = 'mova_app'

var appPw = readEnvironmentVariable('MOVA_MYSQL_APP_PASSWORD', '')
param mysqlAppPassword = length(appPw) < 16 ? fail('MOVA_MYSQL_APP_PASSWORD requerido (>= 16 chars, usuario mova_app)') : appPw

// Vacío = https://mova-web.<defaultDomain del ACA environment> (primer staging).
param appUrl = readEnvironmentVariable('MOVA_APP_URL', '')

// AZ-3D/AZ-3F: staging deliberadamente sin side effects externos. No se
// inyectan credenciales de Gmail/Meta/MercadoPago/JaaS/Pusher/OpenAI/Gemini/
// Sentry en esta fase — solo flags que apagan cada integración o la ponen en
// modo fake. MAIL_MAILER=array (AZ-3F): 'log' seguía escribiendo al log de
// stderr en cada request/comando que dispara un mail; 'array' lo mantiene en
// memoria del proceso sin ninguna E/S, cero entrega real de todos modos.
// SEARCH_INDEXING_ENABLED=false: el FQDN temporal de ACA no debe indexarse
// (ver config/seo.php).
param appConfig = {
  // AZ-3F: settle-lessons --dry-run --json contra la BD real de Azure dio
  // would_consume=0, would_escalate_missing_report=0, would_review=0,
  // invalid=[], safe_to_enable=true -- 0 candidatas post-ledger tras excluir
  // la única legacy (fix de este mismo commit). El scheduler sigue apagado
  // (mova-scheduler=0), así que esto NO ejecuta liquidación todavía; solo
  // dejar de esconder deliberadamente el comportamiento real cuando el
  // scheduler se active en una fase posterior.
  LESSON_SETTLEMENT_MODE: 'live'
  MAIL_MAILER: 'array'
  BROADCAST_DRIVER: 'null'
  WHATSAPP_ENABLED: 'false'
  WHATSAPP_PROVIDER: 'fake'
  WHATSAPP_MODE: 'sandbox'
  GOOGLE_LOGIN_ENABLED: 'false'
  RECHARGES_ENABLED: 'false'
  PAYMENTS_ENABLED: 'false'
  PAYMENT_PROVIDER: 'fake'
  MERCADOPAGO_WEBHOOKS_ENABLED: 'false'
  DIAGNOSTIC_AI_ENABLED: 'false'
  SEARCH_INDEXING_ENABLED: 'false'
}

var key = readEnvironmentVariable('MOVA_APP_KEY', '')
param appKey = empty(key) ? fail('MOVA_APP_KEY requerido (el actual de Railway, nunca key:generate)') : key

// Otros secretos: se OMITEN por completo cuando no hay valor, en vez de crear
// un Container Apps secret vacío. CLOUDINARY_URL no es obligatorio para este
// staging: la app falla de forma controlada solo al subir un avatar sin él.
var cloudinaryUrl = readEnvironmentVariable('MOVA_CLOUDINARY_URL', '')
param appSecrets = empty(cloudinaryUrl) ? {} : {
  CLOUDINARY_URL: cloudinaryUrl
}
