<?php

// P0-K — soporte técnico de cumplimiento. Nada de esto es texto jurídico:
// los datos del proveedor se configuran por entorno y, si faltan, la UI lo
// muestra como pendiente y mova:health-check lo marca en producción.
return [
    // Versión vigente de cada documento. Subirla cuando cambie el texto.
    'versions' => [
        'terms'   => env('LEGAL_TERMS_VERSION', '2026-07-26'),
        'privacy' => env('LEGAL_PRIVACY_VERSION', '2026-07-26'),
    ],

    // Datos del proveedor exigidos en el Libro de Reclamaciones (Indecopi).
    'provider' => [
        'business_name' => env('LEGAL_BUSINESS_NAME'),
        'ruc'           => env('LEGAL_RUC'),
        'address'       => env('LEGAL_ADDRESS'),
    ],

    // Canal de soporte ya publicado en Legal/Privacy.vue.
    'support_email' => env('LEGAL_SUPPORT_EMAIL', 'm0v4class@gmail.com'),

    // Plazo de respuesta (días hábiles) mostrado al consumidor.
    'complaint_response_days' => (int) env('LEGAL_COMPLAINT_RESPONSE_DAYS', 15),
];
