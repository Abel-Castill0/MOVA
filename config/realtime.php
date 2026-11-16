<?php

/**
 * Tiempo real de MOVA: Azure SignalR Service en modo Serverless (ver App\Realtime).
 *
 * REALTIME_DRIVER=null (por defecto) deja la interfaz con su refresco normal. Con `signalr` hacen falta el endpoint del
 * servicio y su clave de acceso; ambos viven en el almacén de secretos, nunca en el repositorio ni en el navegador.
 */
return [
    'driver' => env('REALTIME_DRIVER', 'null'),

    'signalr' => [
        'endpoint' => env('SIGNALR_ENDPOINT'),       // https://<nombre>.service.signalr.net
        'access_key' => env('SIGNALR_ACCESS_KEY'),
        'hub' => env('SIGNALR_HUB', 'mova'),
        'client_token_ttl' => (int) env('SIGNALR_CLIENT_TOKEN_TTL', 3600),
    ],
];
