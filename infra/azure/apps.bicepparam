// Parámetros de deploy de las apps (mova-web/worker/scheduler).
// NINGÚN valor secreto aquí: todo secreto se lee de variables de entorno del
// shell que ejecuta el deploy y FALLA si falta cuando es obligatorio. NUNCA
// requiere MOVA_MYSQL_ADMIN_PASSWORD — ese secreto es exclusivo de
// main.bicepparam.
//
// Requiere siempre:
//   MOVA_CONTAINER_IMAGE=<acr>.azurecr.io/mova@sha256:<digest>
//   MOVA_MYSQL_APP_PASSWORD  (usuario mova_app, >= 16 chars)
//   MOVA_APP_KEY             (el actual de Railway, NUNCA regenerar)
//
// AZ-3G: integraciones de producción opcionales, TODAS omitidas por completo
// (nunca como secret/env vacío) cuando su variable de entorno no está
// presente en el shell que ejecuta el deploy. Ver README para la lista.
using './apps.bicep'

param prefix = 'mova'
param location = 'mexicocentral'

var image = readEnvironmentVariable('MOVA_CONTAINER_IMAGE', '')
param containerImage = empty(image) ? fail('MOVA_CONTAINER_IMAGE requerido: <acr>.azurecr.io/mova@sha256:<digest>') : image

// AZ-3G: web + worker. scheduler queda para AZ-3H (ejecuta liquidación real,
// recordatorios y recuperación de pagos -- side effects que exigen que las
// integraciones externas estén revisadas primero, no solo desplegadas).
param deployWeb = true
param deployWorker = true
param deployScheduler = false

param mysqlDatabaseName = 'mova'
param mysqlAppUser = 'mova_app'

var appPw = readEnvironmentVariable('MOVA_MYSQL_APP_PASSWORD', '')
param mysqlAppPassword = length(appPw) < 16 ? fail('MOVA_MYSQL_APP_PASSWORD requerido (>= 16 chars, usuario mova_app)') : appPw

// Vacío = https://mova-web.<defaultDomain del ACA environment> (staging).
param appUrl = readEnvironmentVariable('MOVA_APP_URL', '')

var key = readEnvironmentVariable('MOVA_APP_KEY', '')
param appKey = empty(key) ? fail('MOVA_APP_KEY requerido (el actual de Railway, nunca key:generate)') : key

// ---------------------------------------------------------------------------
// Integraciones opcionales: cada bloque se omite por completo (var = {}) si
// su variable de entorno no está presente. Los campos NO sensibles
// (identificadores públicos, from-address, cluster de Pusher...) van a
// appConfig; las credenciales reales van a appSecrets. El frontend consume
// Pusher vía runtime config de Inertia (HandleInertiaRequests), nunca
// VITE_PUSHER_* (ver fix(realtime) de esta misma fase).
// ---------------------------------------------------------------------------

// Gmail (envío por Gmail API, no SMTP). AZ-3G: refresh_token presente en
// Railway pero INVALID_GRANT al validarlo -- se migra igual (estructura
// lista) pero re-autorizar es un paso humano pendiente (GmailAuthUrl /
// GmailExchangeCode), no bloquea este deploy porque MAIL_MAILER=array.
var gmailClientId = readEnvironmentVariable('MOVA_GMAIL_CLIENT_ID', '')
var gmailClientSecret = readEnvironmentVariable('MOVA_GMAIL_CLIENT_SECRET', '')
var gmailRefreshToken = readEnvironmentVariable('MOVA_GMAIL_REFRESH_TOKEN', '')
var gmailFromAddress = readEnvironmentVariable('MOVA_GMAIL_FROM_ADDRESS', '')
var gmailFromName = readEnvironmentVariable('MOVA_GMAIL_FROM_NAME', '')
var gmailPresent = !empty(gmailClientId) && !empty(gmailClientSecret) && !empty(gmailRefreshToken)
var gmailConfig = gmailPresent ? union(
  empty(gmailFromAddress) ? {} : { GMAIL_FROM_ADDRESS: gmailFromAddress },
  empty(gmailFromName) ? {} : { GMAIL_FROM_NAME: gmailFromName }
) : {}
var gmailSecrets = gmailPresent ? {
  GMAIL_CLIENT_ID: gmailClientId
  GMAIL_CLIENT_SECRET: gmailClientSecret
  GMAIL_REFRESH_TOKEN: gmailRefreshToken
} : {}

// Pusher (broadcasting/realtime). Ausente por completo en Railway a fecha
// de AZ-3G -- bloques listos para cuando exista.
var pusherAppId = readEnvironmentVariable('MOVA_PUSHER_APP_ID', '')
var pusherAppKey = readEnvironmentVariable('MOVA_PUSHER_APP_KEY', '')
var pusherAppSecret = readEnvironmentVariable('MOVA_PUSHER_APP_SECRET', '')
var pusherCluster = readEnvironmentVariable('MOVA_PUSHER_APP_CLUSTER', '')
var pusherHost = readEnvironmentVariable('MOVA_PUSHER_HOST', '')
var pusherPort = readEnvironmentVariable('MOVA_PUSHER_PORT', '')
var pusherScheme = readEnvironmentVariable('MOVA_PUSHER_SCHEME', '')
var pusherPresent = !empty(pusherAppKey) && !empty(pusherAppSecret)
var pusherConfig = pusherPresent ? union(
  { PUSHER_APP_KEY: pusherAppKey },
  empty(pusherCluster) ? {} : { PUSHER_APP_CLUSTER: pusherCluster },
  empty(pusherHost) ? {} : { PUSHER_HOST: pusherHost },
  empty(pusherPort) ? {} : { PUSHER_PORT: pusherPort },
  empty(pusherScheme) ? {} : { PUSHER_SCHEME: pusherScheme }
) : {}
var pusherSecrets = pusherPresent ? union(
  { PUSHER_APP_SECRET: pusherAppSecret },
  empty(pusherAppId) ? {} : { PUSHER_APP_ID: pusherAppId }
) : {}

// Cloudinary. Ausente en Railway a fecha de AZ-3G.
var cloudinaryUrl = readEnvironmentVariable('MOVA_CLOUDINARY_URL', '')
var cloudinarySecrets = empty(cloudinaryUrl) ? {} : { CLOUDINARY_URL: cloudinaryUrl }

// JaaS (Jitsi as a Service, videollamadas). Ausente en Railway a fecha de
// AZ-3G -- launch-critical para el producto (las clases usan videollamada).
var jaasAppId = readEnvironmentVariable('MOVA_JAAS_APP_ID', '')
var jaasKeyId = readEnvironmentVariable('MOVA_JAAS_KEY_ID', '')
var jaasPrivateKey = readEnvironmentVariable('MOVA_JAAS_PRIVATE_KEY', '')
var jaasPresent = !empty(jaasAppId) && !empty(jaasKeyId) && !empty(jaasPrivateKey)
var jaasConfig = jaasPresent ? { JAAS_APP_ID: jaasAppId, JAAS_KEY_ID: jaasKeyId } : {}
var jaasSecrets = jaasPresent ? { JAAS_PRIVATE_KEY: jaasPrivateKey } : {}

// Google OAuth (login social). Ausente en Railway a fecha de AZ-3G.
// GOOGLE_LOGIN_ENABLED se mantiene false en appConfig hasta que exista el
// dominio final -- la redirect URI registrada en Google Cloud Console debe
// coincidir exactamente con {APP_URL}/auth/google/callback.
var googleClientId = readEnvironmentVariable('MOVA_GOOGLE_CLIENT_ID', '')
var googleClientSecret = readEnvironmentVariable('MOVA_GOOGLE_CLIENT_SECRET', '')
var googlePresent = !empty(googleClientId) && !empty(googleClientSecret)
var googleConfig = googlePresent ? { GOOGLE_CLIENT_ID: googleClientId } : {}
var googleSecrets = googlePresent ? { GOOGLE_CLIENT_SECRET: googleClientSecret } : {}

// Meta WhatsApp Business. Ausente en Railway a fecha de AZ-3G.
var metaPhoneId = readEnvironmentVariable('MOVA_META_WHATSAPP_PHONE_NUMBER_ID', '')
var metaAccessToken = readEnvironmentVariable('MOVA_META_WHATSAPP_ACCESS_TOKEN', '')
var metaAppId = readEnvironmentVariable('MOVA_META_WHATSAPP_APP_ID', '')
var metaAppSecret = readEnvironmentVariable('MOVA_META_WHATSAPP_APP_SECRET', '')
var metaVerifyToken = readEnvironmentVariable('MOVA_META_WHATSAPP_WEBHOOK_VERIFY_TOKEN', '')
var metaTemplateGeneric = readEnvironmentVariable('MOVA_META_WHATSAPP_TEMPLATE_GENERIC', '')
var metaTemplateOtp = readEnvironmentVariable('MOVA_META_WHATSAPP_TEMPLATE_OTP', '')
var metaPresent = !empty(metaPhoneId) && !empty(metaAccessToken) && !empty(metaAppSecret) && !empty(metaVerifyToken)
var metaConfig = metaPresent ? union(
  { META_WHATSAPP_PHONE_NUMBER_ID: metaPhoneId },
  empty(metaAppId) ? {} : { META_WHATSAPP_APP_ID: metaAppId },
  empty(metaTemplateGeneric) ? {} : { META_WHATSAPP_TEMPLATE_GENERIC: metaTemplateGeneric },
  empty(metaTemplateOtp) ? {} : { META_WHATSAPP_TEMPLATE_OTP: metaTemplateOtp }
) : {}
var metaSecrets = metaPresent ? {
  META_WHATSAPP_ACCESS_TOKEN: metaAccessToken
  META_WHATSAPP_APP_SECRET: metaAppSecret
  META_WHATSAPP_WEBHOOK_VERIFY_TOKEN: metaVerifyToken
} : {}

// Sentry. PRESENTE en Railway (DSN con sintaxis válida, AZ-3G) -- solo
// observabilidad, sin feature flag que active comportamiento de usuario.
var sentryDsn = readEnvironmentVariable('MOVA_SENTRY_LARAVEL_DSN', '')
var sentrySecrets = empty(sentryDsn) ? {} : { SENTRY_LARAVEL_DSN: sentryDsn }

// Mercado Pago. Ausente por completo en Railway a fecha de AZ-3G.
// PAYMENTS_ENABLED se mantiene false en appConfig pase lo que pase aquí --
// nunca se activa un rail de pago solo porque las credenciales existan.
var mpAccessToken = readEnvironmentVariable('MOVA_MERCADOPAGO_ACCESS_TOKEN', '')
var mpPublicKey = readEnvironmentVariable('MOVA_MERCADOPAGO_PUBLIC_KEY', '')
var mpWebhookSecret = readEnvironmentVariable('MOVA_MERCADOPAGO_WEBHOOK_SECRET', '')
var mpApplicationId = readEnvironmentVariable('MOVA_MERCADOPAGO_APPLICATION_ID', '')
var mpExpectedCollectorId = readEnvironmentVariable('MOVA_MERCADOPAGO_EXPECTED_COLLECTOR_ID', '')
var mpExpectedLiveMode = readEnvironmentVariable('MOVA_MERCADOPAGO_EXPECTED_LIVE_MODE', '')
var mpPresent = !empty(mpAccessToken) && !empty(mpPublicKey) && !empty(mpWebhookSecret)
var mpConfig = mpPresent ? union(
  { MERCADOPAGO_PUBLIC_KEY: mpPublicKey },
  empty(mpApplicationId) ? {} : { MERCADOPAGO_APPLICATION_ID: mpApplicationId },
  empty(mpExpectedCollectorId) ? {} : { MERCADOPAGO_EXPECTED_COLLECTOR_ID: mpExpectedCollectorId },
  empty(mpExpectedLiveMode) ? {} : { MERCADOPAGO_EXPECTED_LIVE_MODE: mpExpectedLiveMode }
) : {}
var mpSecrets = mpPresent ? {
  MERCADOPAGO_ACCESS_TOKEN: mpAccessToken
  MERCADOPAGO_WEBHOOK_SECRET: mpWebhookSecret
} : {}

// Destino de recarga manual (fallback si Mercado Pago no está listo).
// Ausente en Railway a fecha de AZ-3G.
var rechargeDestination = readEnvironmentVariable('MOVA_RECHARGE_PAYMENT_DESTINATION', '')
var rechargeConfig = empty(rechargeDestination) ? {} : { RECHARGE_PAYMENT_DESTINATION: rechargeDestination }

// ---------------------------------------------------------------------------

// AZ-3D/AZ-3F/AZ-3G: sin side effects externos hasta revisión de negocio
// explícita. MAIL_MAILER=array: sin entrega real de todos modos aunque Gmail
// se re-autorice. SEARCH_INDEXING_ENABLED=false: el FQDN temporal de ACA no
// debe indexarse (ver config/seo.php). Ninguna integración se activa solo
// porque sus credenciales existan (gmailConfig/pusherConfig/etc. arriba solo
// aportan los campos de config/secret, nunca los *_ENABLED).
param appConfig = union(
  gmailConfig,
  pusherConfig,
  jaasConfig,
  googleConfig,
  metaConfig,
  mpConfig,
  rechargeConfig,
  {
    // AZ-3F: settle-lessons --dry-run --json contra la BD real de Azure dio
    // safe_to_enable=true -- 0 candidatas post-ledger. El scheduler sigue
    // apagado, así que esto no ejecuta liquidación todavía.
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
)

param appSecrets = union(
  gmailSecrets,
  pusherSecrets,
  cloudinarySecrets,
  jaasSecrets,
  googleSecrets,
  metaSecrets,
  sentrySecrets,
  mpSecrets
)
