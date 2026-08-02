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
];
