<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // WAHA (WhatsApp HTTP API no oficial) como proveedor de WhatsAppChannel (WHATSAPP_PROVIDER=waha).
    // Las credenciales (API key y clave HMAC del webhook) viven SOLO en el almacén de secretos del entorno,
    // nunca en Git. Ver docs/whatsapp-waha.md.
    'waha' => [
        'url' => env('WAHA_BASE_URL', 'http://localhost:3000'),
        'api_key' => env('WAHA_API_KEY'),
        'session' => env('WAHA_DEFAULT_SESSION', 'default'),
        // Clave con la que WAHA firma cada webhook (HMAC-SHA512 del cuerpo bruto). Sin ella el webhook rechaza todo.
        'hmac_key' => env('WAHA_HOOK_HMAC_KEY'),
        // Piloto: lista separada por comas de números autorizados (con o sin +). Si NO está vacía, MOVA no envía a nadie más.
        'allowed_recipients' => env('WAHA_ALLOWED_RECIPIENTS'),
        // Frenos anti-ráfaga (el número es no oficial: ritmo bajo y humano).
        'rate_per_minute' => (int) env('WAHA_RATE_PER_MINUTE', 8),
        'rate_wait_seconds' => (int) env('WAHA_RATE_WAIT_SECONDS', 20),
        'max_per_recipient_per_day' => (int) env('WAHA_MAX_PER_RECIPIENT_PER_DAY', 12),
        'typing_min_ms' => (int) env('WAHA_TYPING_MIN_MS', 1200),
        'typing_max_ms' => (int) env('WAHA_TYPING_MAX_MS', 3200),
        'dedupe_minutes' => (int) env('WAHA_DEDUPE_MINUTES', 30),
        'timeout_seconds' => (int) env('WAHA_TIMEOUT_SECONDS', 12),
        'webhook_tolerance_seconds' => (int) env('WAHA_WEBHOOK_TOLERANCE_SECONDS', 300),
        // Control ADMINISTRATIVO de la sesión (estado, QR, recuperar, mensaje de prueba): independiente de WHATSAPP_ENABLED.
        // WHATSAPP_ENABLED=false sigue significando «no se envía ninguna notificación a usuarios»; esta bandera solo permite
        // que un administrador con MFA gestione la sesión y envíe UN mensaje de prueba al destinatario fijo de abajo.
        'admin_control_enabled' => (bool) env('WAHA_ADMIN_CONTROL_ENABLED', false),
        // Único destinatario del mensaje de prueba del panel (el propietario). No se acepta ningún otro desde la interfaz.
        'admin_test_recipient' => env('WAHA_ADMIN_TEST_RECIPIENT'),
        // Número dedicado de MOVA que DEBE quedar vinculado; si WAHA conecta otro, el panel lo marca como incorrecto.
        'expected_number' => env('WAHA_EXPECTED_NUMBER'),
        // Etiqueta del entorno que muestra el panel (piloto, staging, producción) y caducidad informativa del piloto.
        // Política anti-restricción: WhatsApp penaliza a los clientes no oficiales que inician chats con desconocidos y puede
        // revocar la vinculación y aplicar un «reachout timelock». Con require_user_initiated=true MOVA SOLO escribe a quien ya
        // escribió primero al número (alta por «escribe ACTIVAR»); si no, durante warmup_minutes tras vincular no se inicia
        // ningún chat nuevo.
        'require_user_initiated' => (bool) env('WAHA_REQUIRE_USER_INITIATED', true),
        'warmup_minutes' => (int) env('WAHA_WARMUP_MINUTES', 1440),
        'contacted_ttl_days' => (int) env('WAHA_CONTACTED_TTL_DAYS', 90),
        // Clave del HMAC con el que se guardan los teléfonos que escribieron primero. Si no se define se usa APP_KEY; rotar APP_KEY
        // sin definir esta clave obliga a que todos vuelvan a escribir (falla en modo seguro: nadie recibe un primer mensaje).
        'contact_hash_key' => env('WAHA_CONTACT_HASH_KEY'),
        // true cuando los respaldos cifrados se copian FUERA de la VM (si no, mueren con ella). Solo informativo para el panel.
        'backup_off_vm' => (bool) env('WAHA_BACKUP_OFF_VM', false),
        // Solo informativo para el panel (no cambia el comportamiento).
        'infra_label' => env('WAHA_INFRA_LABEL', 'VM Azure ARM64 (piloto)'),
        'infra_monthly_usd' => env('WAHA_INFRA_MONTHLY_USD'),
        'environment_label' => env('WAHA_ENV_LABEL', 'piloto'),
        'pilot_expires_at' => env('WAHA_PILOT_EXPIRES_AT'),
    ],

    // WhatsApp Cloud API de Meta directo — reemplaza la integración previa
    // vía Twilio (ver docs/whatsapp-architecture.md). 'templates' mapea una
    // clave simbólica propia de MOVA a la plantilla real ya aprobada por
    // Meta; mientras 'name' sea null, WhatsAppChannel/MetaCloudApiProvider
    // se saltan el envío con un log claro — nunca intentan adivinar un
    // nombre de plantilla ni caen a texto libre (Meta lo rechaza fuera de
    // la ventana de 24h de servicio al cliente para mensajes iniciados por
    // el negocio, que es prácticamente siempre el caso de MOVA).
    'meta_whatsapp' => [
        'phone_number_id'      => env('META_WHATSAPP_PHONE_NUMBER_ID'),
        'access_token'         => env('META_WHATSAPP_ACCESS_TOKEN'),
        'app_id'               => env('META_WHATSAPP_APP_ID'),
        'app_secret'           => env('META_WHATSAPP_APP_SECRET'),
        'webhook_verify_token' => env('META_WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'api_version'          => env('META_WHATSAPP_API_VERSION', 'v20.0'),
        'templates' => [
            // Plantilla "utility" genérica: un solo parámetro con el texto
            // que ya generan hoy los 21 Notification::toWhatsApp() — así no
            // hace falta rediseñar cada notificación en esta ronda. Migrar
            // notificaciones a plantillas propias más ricas es trabajo
            // futuro (ver docs/whatsapp-architecture.md).
            'generic_notification' => [
                'name'     => env('META_WHATSAPP_TEMPLATE_GENERIC'),
                'language' => env('META_WHATSAPP_TEMPLATE_LANGUAGE', 'es_PE'),
            ],
            // Categoría "authentication" de Meta — código de un solo uso.
            // Separada de generic_notification a propósito: las plantillas
            // de autenticación tienen su propia política de aprobación en
            // Meta (contenido restringido, botón "Copiar código" opcional).
            //
            // 'button': las plantillas AUTHENTICATION con botón OTP
            // (otp_type=COPY_CODE al crear la plantilla en Meta) necesitan
            // un componente adicional de tipo "button" al ENVIAR el mensaje,
            // no solo el parámetro del body. Verificado contra documentación
            // de dos BSP que reflejan la Cloud API de Meta (MessageBird,
            // 360dialog) — NO contra developers.facebook.com directamente
            // (no se pudo acceder al fetch). sub_type='url' e index=0
            // coinciden en ambas fuentes; confirmar de todas formas contra
            // la documentación vigente de Meta antes de enviar un OTP real
            // — ver docs/whatsapp-architecture.md.
            'phone_verification_code' => [
                'name'     => env('META_WHATSAPP_TEMPLATE_OTP'),
                'language' => env('META_WHATSAPP_TEMPLATE_LANGUAGE', 'es_PE'),
                'button' => [
                    'enabled'  => env('META_WHATSAPP_TEMPLATE_OTP_BUTTON', true),
                    'sub_type' => 'url',
                ],
            ],
        ],
    ],

    'whatsapp' => [
        'enabled'          => env('WHATSAPP_ENABLED', false),
        'provider'         => env('WHATSAPP_PROVIDER', 'fake'), // fake|meta
        'mode'             => env('WHATSAPP_MODE', 'sandbox'),       // sandbox|production
        'require_verified' => env('WHATSAPP_REQUIRE_VERIFIED_PHONE', true),
    ],

    'gmail' => [
        'client_id'     => env('GMAIL_CLIENT_ID'),
        'client_secret' => env('GMAIL_CLIENT_SECRET'),
        'refresh_token' => env('GMAIL_REFRESH_TOKEN'),
        'from_address'  => env('GMAIL_FROM_ADDRESS'),
        'from_name'     => env('GMAIL_FROM_NAME', 'MOVA'),
    ],

    // "Continuar con Google" (login social) — distinto del bloque 'gmail' de
    // arriba, que es para el envío de correos vía Gmail API. Registrar en
    // Google Cloud Console > APIs & Services > Credentials, tipo "OAuth
    // client ID / Web application", con esta redirect URI autorizada:
    // {APP_URL}/auth/google/callback
    'cloudinary' => [
        'cloud_url' => env('CLOUDINARY_URL'),
        // Carpeta de avatares por entorno (cloud compartido en el plan gratuito).
        'folder' => env('CLOUDINARY_FOLDER', 'mova/avatars'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
        // Apagado por defecto. Con false (o sin client_id/secret) Login y
        // Register NO muestran el botón y /auth/google + callback responden
        // 503 — ver GoogleAuthController::isAvailable().
        'login_enabled' => env('GOOGLE_LOGIN_ENABLED', false),
    ],

];
