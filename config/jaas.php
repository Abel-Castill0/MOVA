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

    /*
    |--------------------------------------------------------------------------
    | Ventana de acceso a la sala (F-06)
    |--------------------------------------------------------------------------
    |
    | Antes, join() no comprobaba proximidad temporal alguna y el JWT vivía 24h
    | fijas. La regla "disponible 15 minutos antes" existía SOLO en el frontend
    | (resources/js/utils/lessonJoin.js), así que un POST directo a la ruta
    | devolvía un token válido días antes de la clase: la UI comunicaba una
    | restricción que el backend no aplicaba.
    |
    | Ahora estos dos valores gobiernan AMBOS lados y el `exp` del JWT queda
    | acotado por la misma ventana: el token nunca puede sobrevivir al periodo
    | en que join() lo habría concedido.
    |
    | join_grace_after_minutes es deliberadamente generoso (2h por defecto)
    | para que una clase que se alarga nunca se corte a mitad. Reducirlo exige
    | un smoke test real contra JaaS: no está confirmado desde este repositorio
    | cómo trata JaaS un `exp` que vence con la llamada en curso — ver
    | UNKNOWN-03 en docs/MOVA_FULL_AUDIT.md.
    |
    */
    'join_window_before_minutes' => (int) env('JAAS_JOIN_WINDOW_BEFORE_MINUTES', 15),
    'join_grace_after_minutes'   => (int) env('JAAS_JOIN_GRACE_AFTER_MINUTES', 120),

];
