<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send any email
    | messages sent by your application. Alternative mailers may be setup
    | and used as needed; however, this mailer will be used by default.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers to be used while
    | sending an e-mail. You will specify which one you are using for your
    | mailers below. You are free to add additional mailers as required.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "log", "array", "failover", "roundrobin"
    |
    */

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => env('MAIL_TIMEOUT', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        /*
         * H-05 — Gmail API como mailer de PRIMERA CLASE.
         *
         * Antes `MAIL_MAILER=gmail_api` apuntaba a un mailer que no existía en
         * este archivo: solo funcionaba porque SafeMailChannel interceptaba ese
         * valor antes de que Laravel intentara resolverlo. Ahora es un mailer
         * normal, respaldado por App\Mail\Transport\GmailApiTransport y
         * registrado en AppServiceProvider::boot() con Mail::extend().
         *
         * Con esto, `Mail::send()`, los Mailables y cualquier paquete de
         * terceros que envíe correo funcionan sin sorpresas.
         */
        'gmail_api' => [
            'transport' => 'gmail_api',
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => null,
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'mailgun' => [
            'transport' => 'mailgun',
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        /*
         * H-05 — El respaldo, expresado con el mecanismo del framework.
         *
         * SafeMailChannel tenía un fallback escrito a mano: si la Gmail API
         * fallaba, reasignaba `config(['mail.default' => 'smtp'])` en caliente y
         * reenviaba. Eso era un segundo camino de envío mantenido a mano, con el
         * riesgo de doble entrega si alguna vez ambos lograban salir.
         *
         * `failover` es el mecanismo nativo de Symfony/Laravel para exactamente
         * esto: intenta los transportes EN ORDEN y solo pasa al siguiente cuando
         * el anterior LANZA. Un envío exitoso nunca continúa la cadena, así que
         * el doble envío es imposible por construcción.
         *
         * Para activarlo: MAIL_MAILER=failover. Con MAIL_MAILER=gmail_api (lo
         * que hay hoy en producción) no hay respaldo, que es también una
         * decisión válida y explícita.
         */
        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'gmail_api',
                'smtp',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all e-mails sent by your application to be sent from
    | the same address. Here, you may specify a name and address that is
    | used globally for all e-mails that are sent by your application.
    |
    */

    /*
     * H-05 — COMPATIBILIDAD CON EL DESPLIEGUE ACTUAL.
     *
     * Antes la cabecera `From` la construía a mano GmailApiMailService a partir
     * de GMAIL_FROM_ADDRESS/GMAIL_FROM_NAME. Ahora la construye Symfony desde
     * este bloque, que lee MAIL_FROM_ADDRESS.
     *
     * Un despliegue que solo tuviera puesta GMAIL_FROM_ADDRESS habría empezado a
     * enviar como `hello@example.com` y Gmail habría rechazado el envío (no se
     * puede enviar en nombre de una dirección que la cuenta no controla). El
     * respaldo explícito evita esa regresión silenciosa sin obligar a tocar
     * variables en Railway.
     */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', env('GMAIL_FROM_ADDRESS', 'hello@example.com')),
        'name' => env('MAIL_FROM_NAME', env('GMAIL_FROM_NAME', 'MOVA')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    |
    | If you are using Markdown based email rendering, you may configure your
    | theme and component paths here, allowing you to customize the design
    | of the emails. Or, you may simply stick with the Laravel defaults!
    |
    */

    'markdown' => [
        /*
         * H-05 / C-10 — Identidad visual del correo por el mecanismo del
         * framework.
         *
         * Los correos se maquetaban antes a mano dentro de GmailApiMailChannel
         * con un indigo (#4f46e5) heredado de Breeze que ninguna decisión de
         * marca eligió. Al pasar el correo al pipeline estándar de Laravel, el
         * tema es el punto donde vive el color, y se alinea con
         * tailwind.config.js.
         *
         * Ver resources/views/vendor/mail/html/themes/mova.css.
         */
        'theme' => 'mova',

        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],

];
