<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ciclo de vida de una solicitud de clase
    |--------------------------------------------------------------------------
    |
    | §14 — Antes una solicitud `open` que nadie aceptaba se quedaba abierta
    | indefinidamente: no había estado ni proceso que la cerrara. La bandeja del
    | profesor acumulaba solicitudes de hace meses y el contador de solicitudes
    | abiertas del panel de admin dejaba de significar nada
    | (docs/MOVA_SYSTEM_MAP.md R-12).
    |
    | Las dos ventanas se miden desde `created_at` y DEBEN mantener este orden:
    |
    |   reminder (12 h)  <  expiry (24 h)
    |
    | Si la expiración fuera menor o igual al recordatorio, la solicitud moriría
    | antes (o a la vez) que el aviso que existe para rescatarla: el profesor
    | recibiría un "tienes una solicitud sin responder" sobre algo que ya no
    | puede aceptar. El recordatorio de 12 h vive en
    | App\Console\Commands\SendClassReminders::sendUnansweredRequestAlerts().
    |
    */

    'expiry_hours' => (int) env('CLASS_REQUEST_EXPIRY_HOURS', 24),
];
