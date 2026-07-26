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

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    'whatsapp' => [
        'enabled'          => env('WHATSAPP_ENABLED', false),
        'mode'             => env('WHATSAPP_MODE', 'sandbox'),       // sandbox|production
        'require_verified' => env('WHATSAPP_REQUIRE_VERIFIED_PHONE', true),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
    ],

];
