<?php

// Configuración del sistema de pagos automáticos (Culqi/otro PSP) — capa
// que se agrega ENCIMA del sistema de recargas manual existente
// (config/credits.php, RechargeRequest), no lo reemplaza. Mientras
// 'provider' sea 'fake', ningún profesor puede pagar de verdad por esta vía
// — solo existe para tests/desarrollo. Cambiar a 'culqi' requiere haber
// implementado App\Payment\CulqiPaymentProvider primero (ver ese archivo).
return [
    // F-03: 'enabled' declara si los pagos automáticos están operativos. Es lo
    // que permite a ProviderGuard distinguir "todavía no conectamos Culqi"
    // (enabled=false, fake aceptable) de "los pagos están vivos pero apuntan
    // al proveedor falso" (enabled=true + fake en producción = arranque
    // abortado). Sin esta bandera no se puede diferenciar una integración
    // pendiente de una integración rota.
    'enabled' => env('PAYMENTS_ENABLED', false),

    'provider' => env('PAYMENT_PROVIDER', 'fake'),

    'culqi' => [
        'public_key' => env('CULQI_PUBLIC_KEY'),
        'private_key' => env('CULQI_PRIVATE_KEY'),
        'webhook_secret' => env('CULQI_WEBHOOK_SECRET'),
        'env' => env('CULQI_ENV', 'test'),
    ],
];
