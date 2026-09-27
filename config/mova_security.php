<?php

return [
    // P0-C — MFA admin (ver App\Http\Middleware\EnsureAdminMfa). No hay
    // interruptor para desactivarlo: solo se ajustan las ventanas.
    'admin_mfa' => [
        // Vida de la verificación MFA dentro de una sesión admin.
        'session_minutes' => (int) env('ADMIN_MFA_SESSION_MINUTES', 720),
        // Step-up para acciones sensibles (créditos, refunds, suspensiones, profesores).
        'step_up_minutes' => (int) env('ADMIN_MFA_STEP_UP_MINUTES', 15),
    ],
];
