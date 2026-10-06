<?php

// P0-K — soporte técnico de cumplimiento. Nada de esto es texto jurídico:
// los datos del proveedor se configuran por entorno y, si faltan, la UI lo
// muestra como pendiente y mova:health-check lo marca en producción.
return [
    // Versión vigente de cada documento. Subirla cuando cambie el texto de
    // forma relevante: el middleware legal.current pedirá aceptarla a todo
    // usuario existente en su próxima navegación.
    // 2026-09-29 (C1): texto alineado con el runtime real — IA desactivada,
    // minimización pre-aceptación, consentimiento por alumno, pagos de
    // créditos, proveedores/transferencia internacional, cookies, re-aceptación.
    // 2026-10-06: Términos §5 (reembolso de créditos no usados a 7 días) y
    // Privacidad §11 (plazos de conservación) alineados con DECISIONS.
    'versions' => [
        'terms'   => env('LEGAL_TERMS_VERSION', '2026-10-06'),
        'privacy' => env('LEGAL_PRIVACY_VERSION', '2026-10-06'),
    ],

    // C-P0-MINOR-CONSENT — declaración que el padre/apoderado marca al
    // registrar a un alumno (Students/Create). Texto neutral que describe el
    // tratamiento real; no es redacción legal aprobada. Si cambia el texto,
    // subir la versión: cada StudentDataConsent guarda la versión mostrada.
    'student_consent' => [
        'version'   => '2026-09-29',
        'statement' => 'Soy el padre, la madre o el apoderado legal de este alumno y autorizo a MOVA a tratar los datos que ingreso en este formulario para gestionar sus clases, según la Política de Privacidad.',
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
