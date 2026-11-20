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

    /*
    |--------------------------------------------------------------------------
    | Franjas horarias de preferencia (día + franja)
    |--------------------------------------------------------------------------
    |
    | El padre elige un DÍA y una FRANJA; es una preferencia, no una reserva: el
    | profesor propone la hora exacta y la clase solo existe cuando el padre la
    | acepta. Horas de pared en America/Lima (App\Support\RequestSlots las
    | convierte a instantes UTC), [start, end) en horas enteras. Única fuente: el
    | frontend recibe estos valores por props, no los duplica.
    |
    | Cubren el horario operativo habitual de MOVA (06:00–22:00). Una hora fuera
    | de la franja sigue pudiendo proponerse, pero queda marcada como alternativa
    | que el padre debe aceptar expresamente.
    */
    'slots' => [
        'morning'   => ['label' => 'Mañana', 'start' => 6,  'end' => 12],
        'afternoon' => ['label' => 'Tarde',  'start' => 12, 'end' => 18],
        'evening'   => ['label' => 'Noche',  'start' => 18, 'end' => 22],
    ],

    // Hasta cuántos días por delante se puede pedir una clase puntual.
    'max_days_ahead' => (int) env('CLASS_REQUEST_MAX_DAYS_AHEAD', 60),

    /*
    |--------------------------------------------------------------------------
    | Vencimiento por oportunidad real (App\Models\ClassRequest::responseDeadline)
    |--------------------------------------------------------------------------
    |
    | - Clase puntual con día + franja: sigue abierta hasta que TERMINA la franja
    |   pedida (p. ej. viernes 18:00 de Lima para «tarde»). Nada de «24 horas».
    | - Acompañamiento continuo (sin día): `mentorship_expiry_days` desde su
    |   publicación o renovación; el padre puede renovarlo.
    | - Solicitudes antiguas sin día (preferencias semanales): conservan
    |   `expiry_hours`, la regla con la que se crearon (transición compatible).
    |
    | Una propuesta de horario pendiente vence en su propia hora: si pasa sin
    | respuesta, la solicitud vuelve a estar abierta (si aún hay oportunidad) o
    | vence. Nunca se confirma una clase sola.
    */
    'mentorship_expiry_days' => (int) env('CLASS_REQUEST_MENTORSHIP_EXPIRY_DAYS', 7),
    // El padre puede renovar un acompañamiento ya vencido o al que le queden menos de estos días.
    'mentorship_renew_window_days' => 2,

    // Recordatorios a profesores (SendClassReminders), una vez cada uno.
    'reminders' => [
        'unanswered_after_hours' => 12,  // «sigue sin propuesta» tras publicarse
        'final_before_hours' => 24,      // último aviso cuando falta este tiempo para la franja / el vencimiento
        'final_min_lead_minutes' => 60,  // sin último aviso si queda menos de esto para el vencimiento
        'min_gap_hours' => 3,            // separación mínima entre dos avisos de la misma solicitud
    ],
];
