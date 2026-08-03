<?php

return [

    /*
    |--------------------------------------------------------------------------
    | JaaS (Jitsi as a Service)
    |--------------------------------------------------------------------------
    |
    | App ID y private key emitidos en jaas.8x8.vc — reemplazan al meet.jit.si
    | público, cuyo embed en producción se corta a los 5 minutos. La private
    | key se guarda en JAAS_PRIVATE_KEY como base64 de una sola línea del PEM
    | (evita problemas de parseo de .env con los saltos de línea del PEM
    | original); aquí se decodifica de vuelta a PEM para firmar el JWT.
    |
    */

    'app_id' => env('JAAS_APP_ID'),

    'private_key' => env('JAAS_PRIVATE_KEY')
        ? base64_decode(env('JAAS_PRIVATE_KEY'))
        : null,

    // ID de la API key subida en jaas.8x8.vc (distinto del App ID) — va en
    // el header `kid` del JWT para que JaaS sepa con qué public key
    // verificar la firma. Sin esto, JaaS rechaza el token con "Missing Key
    // ID (kid)" aunque la firma en sí sea válida.
    'key_id' => env('JAAS_KEY_ID'),

    'domain' => '8x8.vc',

];
