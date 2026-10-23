<?php

/**
 * Presentación (imagen, descripción, beneficios) del catálogo público. NUNCA contiene precios: los precios salen de
 * config/credits.php (paquetes y tarifas) y se leen en App\Support\Catalog. Una oferta que no tenga entrada aquí se
 * muestra con la presentación genérica, no desaparece.
 */
return [
    // Paquetes de créditos (código = clave de config('credits.packages')).
    'packages' => [
        'inicio' => [
            'image' => '/images/mascot/movi-saluda-512.webp',
            'alt' => 'Movi, la ardilla mascota de MOVA, saludando',
            'tagline' => 'Para empezar a dictar clases en MOVA',
            'description' => 'Paquete de entrada para profesores nuevos: créditos suficientes para aceptar tus primeras solicitudes de clase.',
            'benefits' => [
                'Créditos prepagados, sin suscripción ni cuotas mensuales.',
                'Se reservan al aceptar una clase y se consumen cuando se liquida; se devuelven si la clase agendada se cancela.',
                'Reembolso íntegro de los créditos no usados dentro de los plazos de la política de devoluciones.',
            ],
        ],
        'esencial' => [
            'image' => '/images/mascot/movi-lee-512.webp',
            'alt' => 'Movi, la ardilla mascota de MOVA, leyendo un libro',
            'tagline' => 'Para preparar tu agenda de clases',
            'description' => 'Diez créditos para profesores que quieren organizar sus próximas clases con saldo disponible.',
            'benefits' => ['Descuento permanente por volumen.', 'Sin suscripción ni cuotas mensuales.', 'Historial de movimientos en tu panel de créditos.'],
        ],
        'impulso' => [
            'image' => '/images/mascot/movi-experto-420.webp',
            'alt' => 'Movi, la ardilla mascota de MOVA, con gesto de experto',
            'tagline' => 'Para profesores con agenda regular',
            'description' => 'Paquete intermedio para quienes ya dictan clases con frecuencia y quieren tener saldo para varias semanas.',
            'benefits' => [
                'Descuento permanente por volumen.',
                'Créditos acreditados automáticamente cuando el pago se confirma.',
                'Historial de movimientos visible en tu panel de créditos.',
            ],
        ],
        'avance' => [
            'image' => '/images/mascot/movi-solicita-420.webp',
            'alt' => 'Movi, la ardilla mascota de MOVA, organizando solicitudes de clase',
            'tagline' => 'Para ampliar tu agenda de clases',
            'description' => 'Veinte créditos para profesores que necesitan continuidad entre sus clases y sus próximas solicitudes.',
            'benefits' => ['Descuento permanente por volumen.', 'Acreditación cuando el pago se confirma.', 'Saldo y movimientos visibles en tu panel.'],
        ],
        'pro' => [
            'image' => '/images/mascot/movi-celebra-420.webp',
            'alt' => 'Movi, la ardilla mascota de MOVA, celebrando',
            'tagline' => 'Para profesores con alta demanda',
            'description' => 'El paquete más grande: saldo para sostener una agenda con muchas clases sin recargar a cada rato.',
            'benefits' => [
                'Mayor cantidad de créditos en una sola compra.',
                'Acreditación automática tras la confirmación del pago.',
                'Cada crédito equivale a 60 minutos de clase (una clase de hasta 1 hora consume 1 crédito).',
            ],
        ],
    ],

    // Clases particulares por materia (clave = nombre normalizado de Subject). Las paga el padre DIRECTAMENTE al profesor.
    'subjects' => [
        'matematicas' => [
            'image' => '/images/catalog/matematicas.svg',
            'alt' => 'Ilustración de una pizarra con operaciones matemáticas y una regla',
            'description' => 'Refuerzo y acompañamiento en aritmética, álgebra y geometría, con ejercicios guiados paso a paso.',
        ],
        'lengua y literatura' => [
            'image' => '/images/catalog/lengua.svg',
            'alt' => 'Ilustración de un libro abierto y una pluma',
            'description' => 'Comprensión lectora, redacción y ortografía, con lecturas y prácticas de escritura.',
        ],
        'ingles' => [
            'image' => '/images/catalog/ingles.svg',
            'alt' => 'Ilustración de dos globos de diálogo con las letras A y B',
            'description' => 'Conversación, vocabulario y gramática del inglés en clases en vivo, a ritmo del alumno.',
        ],
        'ciencias naturales' => [
            'image' => '/images/catalog/ciencias.svg',
            'alt' => 'Ilustración de un matraz de laboratorio y una hoja',
            'description' => 'Seres vivos, materia y energía explicados con ejemplos cotidianos y experimentos sencillos.',
        ],
        'fisica' => [
            'image' => '/images/catalog/fisica.svg',
            'alt' => 'Ilustración de un átomo con órbitas',
            'description' => 'Cinemática, fuerzas y energía con resolución de problemas y repaso para exámenes.',
        ],
        'programacion' => [
            'image' => '/images/catalog/programacion.svg',
            'alt' => 'Ilustración de una pantalla con llaves de código',
            'description' => 'Lógica de programación y primeros proyectos, con ejercicios prácticos en vivo.',
        ],
    ],

    'generic_subject' => [
        'image' => '/images/catalog/generico.svg',
        'alt' => 'Ilustración de un cuaderno y un lápiz',
        'description' => 'Clases particulares individuales en vivo por videollamada.',
    ],
];
