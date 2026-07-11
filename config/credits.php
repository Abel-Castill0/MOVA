<?php

return [
    'credit_minutes' => 60,
    'credit_price_pen' => '2.00',

    // Phase 1 keeps the existing fixed reservation until duration billing is introduced.
    'fixed_class_cost' => 2,

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
