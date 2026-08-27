<?php

return [
    // 1 crédito = 60 minutos de clase. Usado por Lesson::creditCostForMinutes()
    // para calcular cuántos créditos consume una clase: ceil(duration_minutes / credit_minutes).
    'credit_minutes' => 60,
    'credit_price_pen' => '2.00',

    // Créditos consumidos por cada hora (o fracción) de clase dictada.
    // El costo mínimo es 1 crédito, incluso para clases de menos de 1 hora
    // (ej. 30 min = 1 crédito, igual que 60 min). Ver Lesson::creditCostForMinutes().
    'cost_per_hour' => 1,

    'packages' => [
        'inicio' => [
            'name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => '10.00',
        ],
        'impulso' => [
            'name' => 'Impulso',
            'credits' => 15,
            'amount_pen' => '30.00',
        ],
        'pro' => [
            'name' => 'Pro',
            'credits' => 30,
            'amount_pen' => '60.00',
        ],
    ],

    'payment_methods' => [
        'yape' => 'Yape',
        'transfer' => 'Transferencia bancaria',
    ],

    'recharges' => [
        'enabled' => env('RECHARGES_ENABLED', false),
        'payment_destination' => env('RECHARGE_PAYMENT_DESTINATION'),
    ],

    // C-1 — liquidación automática. Decisión de negocio confirmada explícitamente
    // (Fase 3B, AskUserQuestion): 7 días desde el fin de la clase, medidos con
    // Lesson::scopeEndedBefore() (start_time + duration_minutes, nunca start_time
    // solo). Ambas ventanas comparten el mismo valor a propósito — "recomiendo la
    // misma para facilitar el razonamiento" — no son casualidad que coincidan.
    //
    //   grace_days:        'paid' / 'pending_parent_confirmation' sin cerrar ->
    //                       se liquida solo (consume) tras esta espera.
    //   unconfirmed_days:   'scheduled' sin que el padre jamás confirmara el pago ->
    //                       escala a needs_admin_review (SIN efecto financiero).
    'settlement_grace_days' => (int) env('CREDITS_SETTLEMENT_GRACE_DAYS', 7),
    'unconfirmed_days' => (int) env('CREDITS_UNCONFIRMED_DAYS', 7),

    // F-02 — Modo operativo de la liquidación automática. 'dry_run' (por
    // defecto) hace que el scheduler solo reporte; 'live' hace que escriba de
    // verdad en el ledger. Antes esto era una bandera --dry-run hardcodeada en
    // Kernel.php, lo que dejaba C-1 permanentemente apagado sin que nada lo
    // señalara. Ahora `mova:health-check` marca producción+dry_run como
    // configuración peligrosa. Ver App\Support\SettlementMode.
    //
    // PARA ACTIVAR C-1 EN PRODUCCIÓN: LESSON_SETTLEMENT_MODE=live
    // (revisar antes la salida de `php artisan mova:settle-lessons --dry-run`).
    'settlement_mode' => env('LESSON_SETTLEMENT_MODE', 'dry_run'),

    // Ventana del recordatorio "te falta el reporte": debe vivir estrictamente
    // ANTES del cierre automático (settlement_grace_days), o el profesor podría
    // recibir el aviso después de que la clase ya se liquidó sola. Este valor es
    // el límite inferior (ya pasó tiempo suficiente desde que terminó); el límite
    // superior es settlement_grace_days.
    'report_reminder_delay_hours' => (int) env('CREDITS_REPORT_REMINDER_DELAY_HOURS', 2),
];
