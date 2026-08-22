<?php

return [
    // Lista corta y deliberadamente conservadora: solo vulgarismos/insultos
    // inequívocos, NUNCA palabras de tema (ej. "sexual", "sexo" NO están
    // aquí — "Educación Sexual" es una materia legítima). El chequeo
    // compara PALABRA COMPLETA tras normalizar (minúsculas, sin tildes,
    // dividido por espacios), nunca substring — así "Clasismo" no choca con
    // ningún término de esta lista por contener una subcadena parecida.
    //
    // Esto es moderación automática de primera línea, no la única capa: un
    // admin sigue pudiendo revisar/editar materias manualmente. Ampliar esta
    // lista es una decisión de producto, no algo que deba crecer sin criterio
    // solo por "más seguro" — cada palabra nueva es un riesgo de falso
    // positivo sobre una materia real que nadie previó.
    'subject_name_blocklist' => [
        'pinga', 'verga', 'concha', 'conchatumadre', 'huevada', 'huevon',
        'mierda', 'puta', 'puto', 'maricon', 'pendejo', 'cabron',
        'chucha', 'carajo', 'culiao', 'culero',
    ],
];
