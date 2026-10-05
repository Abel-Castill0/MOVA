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

// El scheduler y la liquidación live son decisiones independientes. Sin
// MOVA_DEPLOY_SCHEDULER=true no se crea scheduler; si se crea, empieza en
// dry_run salvo selección y confirmación explícitas del modo live.
param deployWeb = true
param deployWorker = true
var schedulerChoice = readEnvironmentVariable('MOVA_DEPLOY_SCHEDULER', 'false')
param deployScheduler = schedulerChoice == 'true' ? true : schedulerChoice == 'false' ? false : fail('MOVA_DEPLOY_SCHEDULER debe ser true o false')

var settlementChoice = readEnvironmentVariable('MOVA_LESSON_SETTLEMENT_MODE', 'dry_run')
var liveSettlementAck = readEnvironmentVariable('MOVA_LIVE_SETTLEMENT_ACK', '')
param settlementMode = settlementChoice == 'dry_run' ? 'dry_run' : settlementChoice == 'live' && liveSettlementAck == 'I_ACKNOWLEDGE_LIVE_SETTLEMENT' ? 'live' : fail('Modo live requiere MOVA_LESSON_SETTLEMENT_MODE=live y MOVA_LIVE_SETTLEMENT_ACK=I_ACKNOWLEDGE_LIVE_SETTLEMENT; dry_run es el default')

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
// Webhooks de presencia de JaaS: solo evidencia (lesson_presence_events), sin
// efectos de negocio. Se activan SOLO si hay JaaS completo, hay al menos un
// secreto de autenticación y MOVA_JAAS_WEBHOOKS_ENABLED=true. DOS secretos
// distintos (ver config/jaas.php):
//   MOVA_JAAS_WEBHOOK_SIGNING_SECRET  "signing secret" que genera JaaS por endpoint
//                                     (verifica X-Jaas-Signature, HMAC-SHA256). Recomendado.
//   MOVA_JAAS_WEBHOOK_AUTH_TOKEN      token que defines tú; se carga en la consola de JaaS
//                                     como header Authorization = "Bearer <token>". Opcional.
var jaasWebhookSigningSecret = readEnvironmentVariable('MOVA_JAAS_WEBHOOK_SIGNING_SECRET', '')
var jaasWebhookAuthToken = readEnvironmentVariable('MOVA_JAAS_WEBHOOK_AUTH_TOKEN', '')
var jaasWebhooksChoice = readEnvironmentVariable('MOVA_JAAS_WEBHOOKS_ENABLED', 'false')
var jaasWebhooksOn = jaasPresent && (!empty(jaasWebhookSigningSecret) || !empty(jaasWebhookAuthToken)) && jaasWebhooksChoice == 'true'
var jaasConfig = jaasPresent ? union(
  { JAAS_APP_ID: jaasAppId, JAAS_KEY_ID: jaasKeyId },
  { JAAS_WEBHOOKS_ENABLED: jaasWebhooksOn ? 'true' : 'false' }
) : {}
var jaasSecrets = jaasPresent ? union(
  { JAAS_PRIVATE_KEY: jaasPrivateKey },
  jaasWebhooksOn && !empty(jaasWebhookSigningSecret) ? { JAAS_WEBHOOK_SIGNING_SECRET: jaasWebhookSigningSecret } : {},
  jaasWebhooksOn && !empty(jaasWebhookAuthToken) ? { JAAS_WEBHOOK_AUTH_TOKEN: jaasWebhookAuthToken } : {}
) : {}

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
// SENTRY_ENVIRONMENT etiqueta cada evento. Sin él, el SDK asume "production" y
// staging contaminaría el proyecto de producción (APP_ENV=production también
// en staging). Por defecto 'staging'; en el deploy de producción: MOVA_SENTRY_ENVIRONMENT=production.
var sentryEnvironment = readEnvironmentVariable('MOVA_SENTRY_ENVIRONMENT', 'staging')
var sentryConfig = empty(sentryDsn) ? {} : { SENTRY_ENVIRONMENT: sentryEnvironment }

// Correo transaccional. DEFAULT SEGURO: 'array' (no entrega nada). Pasar a
// 'gmail_api' o 'smtp' es una decisión explícita por deploy:
//   MOVA_MAIL_MAILER=gmail_api  requiere las tres credenciales Gmail (gmailPresent)
//   MOVA_MAIL_MAILER=smtp       requiere MOVA_MAIL_HOST, MOVA_MAIL_USERNAME,
//                               MOVA_MAIL_PASSWORD y MOVA_MAIL_FROM_ADDRESS
// Si falta algo, vuelve a 'array' (nunca queda un mailer a medio configurar).
// El health-check (mova:health-check) ya marca como crítico un correo no apto.
var mailMailerChoice = readEnvironmentVariable('MOVA_MAIL_MAILER', 'array')
var smtpHost = readEnvironmentVariable('MOVA_MAIL_HOST', '')
var smtpPort = readEnvironmentVariable('MOVA_MAIL_PORT', '587')
var smtpUsername = readEnvironmentVariable('MOVA_MAIL_USERNAME', '')
var smtpPassword = readEnvironmentVariable('MOVA_MAIL_PASSWORD', '')
var smtpEncryption = readEnvironmentVariable('MOVA_MAIL_ENCRYPTION', 'tls')
var mailFromAddress = readEnvironmentVariable('MOVA_MAIL_FROM_ADDRESS', '')
var mailFromName = readEnvironmentVariable('MOVA_MAIL_FROM_NAME', 'MOVA')
var smtpReady = !empty(smtpHost) && !empty(smtpUsername) && !empty(smtpPassword) && !empty(mailFromAddress)
var mailMailer = mailMailerChoice == 'gmail_api' && gmailPresent ? 'gmail_api' : mailMailerChoice == 'smtp' && smtpReady ? 'smtp' : 'array'
var mailConfig = mailMailer == 'smtp' ? {
  MAIL_MAILER: 'smtp'
  MAIL_HOST: smtpHost
  MAIL_PORT: smtpPort
  MAIL_USERNAME: smtpUsername
  MAIL_ENCRYPTION: smtpEncryption
  MAIL_FROM_ADDRESS: mailFromAddress
  MAIL_FROM_NAME: mailFromName
} : { MAIL_MAILER: mailMailer }
var mailSecrets = mailMailer == 'smtp' ? { MAIL_PASSWORD: smtpPassword } : {}
// Allowlist de destinatarios (STAGING): con valor, el correo solo sale hacia esos
// destinatarios (App\Support\MailAllowlist). Vacía en producción.
var mailAllowlist = readEnvironmentVariable('MOVA_MAIL_ALLOWLIST', '')
var mailAllowlistConfig = empty(mailAllowlist) ? {} : { MAIL_ALLOWLIST: mailAllowlist }

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

// Pagos. DEFAULT SEGURO: apagados (PAYMENTS_ENABLED=false, proveedor fake).
// MOVA_PAYMENTS_MODE=sandbox los enciende SOLO si, además, están presentes las
// credenciales completas de Mercado Pago Y MOVA_MERCADOPAGO_EXPECTED_LIVE_MODE
// vale exactamente 'false' (credenciales de PRUEBA). No existe un modo 'live'
// en este archivo a propósito: habilitar cobros reales exige una decisión y un
// cambio explícitos del titular, fuera de esta plantilla.
var paymentsModeChoice = readEnvironmentVariable('MOVA_PAYMENTS_MODE', 'off')
var paymentsSandbox = paymentsModeChoice == 'sandbox' && mpPresent && mpExpectedLiveMode == 'false' && !empty(mpApplicationId) && !empty(mpExpectedCollectorId)
var paymentsConfig = paymentsSandbox ? {
  PAYMENTS_ENABLED: 'true'
  PAYMENT_PROVIDER: 'mercadopago'
  RECHARGES_ENABLED: 'true'
  MERCADOPAGO_WEBHOOKS_ENABLED: 'true'
} : {
  PAYMENTS_ENABLED: 'false'
  PAYMENT_PROVIDER: 'fake'
  RECHARGES_ENABLED: 'false'
  MERCADOPAGO_WEBHOOKS_ENABLED: 'false'
}

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
  sentryConfig,
  // MAIL_MAILER y los flags de pago salen de mailConfig/paymentsConfig, que
  // por defecto son 'array' y apagado/fake (ver arriba). Van ANTES del objeto
  // literal para que nada de lo de abajo los pise por accidente.
  mailConfig,
  mailAllowlistConfig,
  paymentsConfig,
  {
    // LESSON_SETTLEMENT_MODE se fija como invariante en apps.bicep desde
    // settlementMode; nunca se activa por desplegar el scheduler.
    BROADCAST_DRIVER: 'null'
    WHATSAPP_ENABLED: 'false'
    WHATSAPP_PROVIDER: 'fake'
    WHATSAPP_MODE: 'sandbox'
    GOOGLE_LOGIN_ENABLED: 'false'
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
  mailSecrets,
  mpSecrets
)
