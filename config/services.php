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
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
        // "Stand by" a pedido: el botón sigue visible en el frontend (con un
        // modal explicando que está temporalmente no disponible), pero la
        // ruta backend también se cierra — un enlace directo a /auth/google
        // no debe poder saltarse el frontend.
        'login_enabled' => env('GOOGLE_LOGIN_ENABLED', false),
    ],

];
