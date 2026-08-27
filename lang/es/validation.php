<?php

// Traducción de los mensajes estándar de validación de Laravel al español.
// No existía ningún lang/ en el proyecto (confirmado antes de crear esto) -
// APP_LOCALE quedaba en 'en' aunque toda la copia de la app es en español,
// así que cualquier validate() sin :messages custom (la mayoría, salvo un
// puñado de campos que ya tenían mensajes a mano) mostraba texto en inglés.
// Cubre las claves estándar de Laravel 10 completas, no solo las que hoy
// usa el código - para que un validate() nuevo en el futuro no vuelva a
// caer en el mismo hueco por default.
//
// Evaluado contra el filtro de dependencias de CLAUDE.md antes de escribir
// esto a mano: instalar un paquete de terceros (p. ej. laravel-lang/lang)
// solo para un idioma, cuando el contenido es texto estático y estable, no
// justifica una dependencia permanente con su propio ciclo de versiones -
// se resuelve igual de bien con este archivo.

return [

    /*
    |--------------------------------------------------------------------------
    | Mensajes de validación por defecto
    |--------------------------------------------------------------------------
    */

    'accepted' => 'Debes aceptar :attribute.',
    'accepted_if' => 'Debes aceptar :attribute cuando :other es :value.',
    'active_url' => ':attribute no es una URL válida.',
    'after' => ':attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => ':attribute solo debe contener letras.',
    'alpha_dash' => ':attribute solo debe contener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':attribute solo debe contener letras y números.',
    'array' => ':attribute debe ser una lista.',
    'ascii' => ':attribute solo debe contener caracteres alfanuméricos y símbolos de un solo byte.',
    'before' => ':attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'array' => ':attribute debe tener entre :min y :max elementos.',
        'file' => ':attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute debe estar entre :min y :max.',
        'string' => ':attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => ':attribute debe ser verdadero o falso.',
    'can' => ':attribute contiene un valor no autorizado.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'contains' => ':attribute no contiene un valor requerido.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => ':attribute no es una fecha válida.',
    'date_equals' => ':attribute debe ser una fecha igual a :date.',
    'date_format' => ':attribute no coincide con el formato :format.',
    'decimal' => ':attribute debe tener :decimal decimales.',
    'declined' => 'Debes rechazar :attribute.',
    'declined_if' => 'Debes rechazar :attribute cuando :other es :value.',
    'different' => ':attribute y :other deben ser diferentes.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'digits_between' => ':attribute debe tener entre :min y :max dígitos.',
    'dimensions' => ':attribute tiene dimensiones de imagen inválidas.',
    'distinct' => ':attribute tiene un valor duplicado.',
    'doesnt_end_with' => ':attribute no debe terminar en uno de los siguientes: :values.',
    'doesnt_start_with' => ':attribute no debe empezar con uno de los siguientes: :values.',
    'email' => ':attribute debe ser una dirección de correo válida.',
    'ends_with' => ':attribute debe terminar en uno de los siguientes: :values.',
    'enum' => ':attribute seleccionado no es válido.',
    'exists' => ':attribute seleccionado no es válido.',
    'extensions' => ':attribute debe tener una de las siguientes extensiones: :values.',
    'file' => ':attribute debe ser un archivo.',
    'filled' => ':attribute no puede estar vacío.',
    'gt' => [
        'array' => ':attribute debe tener más de :value elementos.',
        'file' => ':attribute debe pesar más de :value kilobytes.',
        'numeric' => ':attribute debe ser mayor que :value.',
        'string' => ':attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute debe tener :value elementos o más.',
        'file' => ':attribute debe pesar :value kilobytes o más.',
        'numeric' => ':attribute debe ser mayor o igual que :value.',
        'string' => ':attribute debe tener :value caracteres o más.',
    ],
    'hex_color' => ':attribute debe ser un color hexadecimal válido.',
    'image' => ':attribute debe ser una imagen.',
    'in' => ':attribute seleccionado no es válido.',
    'in_array' => ':attribute no existe en :other.',
    'integer' => ':attribute debe ser un número entero.',
    'ip' => ':attribute debe ser una dirección IP válida.',
    'ipv4' => ':attribute debe ser una dirección IPv4 válida.',
    'ipv6' => ':attribute debe ser una dirección IPv6 válida.',
    'json' => ':attribute debe ser una cadena JSON válida.',
    'list' => ':attribute debe ser una lista.',
    'lowercase' => ':attribute debe estar en minúsculas.',
    'lt' => [
        'array' => ':attribute debe tener menos de :value elementos.',
        'file' => ':attribute debe pesar menos de :value kilobytes.',
        'numeric' => ':attribute debe ser menor que :value.',
        'string' => ':attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute no debe tener más de :value elementos.',
        'file' => ':attribute no debe pesar más de :value kilobytes.',
        'numeric' => ':attribute debe ser menor o igual que :value.',
        'string' => ':attribute no debe tener más de :value caracteres.',
    ],
    'mac_address' => ':attribute debe ser una dirección MAC válida.',
    'max' => [
        'array' => ':attribute no debe tener más de :max elementos.',
        'file' => ':attribute no debe pesar más de :max kilobytes.',
        'numeric' => ':attribute no debe ser mayor que :max.',
        'string' => ':attribute no debe tener más de :max caracteres.',
    ],
    'max_digits' => ':attribute no debe tener más de :max dígitos.',
    'mimes' => ':attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => ':attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':attribute debe tener al menos :min elementos.',
        'file' => ':attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':attribute debe ser al menos :min.',
        'string' => ':attribute debe tener al menos :min caracteres.',
    ],
    'min_digits' => ':attribute debe tener al menos :min dígitos.',
    'missing' => ':attribute no debe estar presente.',
    'missing_if' => ':attribute no debe estar presente cuando :other es :value.',
    'missing_unless' => ':attribute no debe estar presente a menos que :other sea :value.',
    'missing_with' => ':attribute no debe estar presente cuando :values está presente.',
    'missing_with_all' => ':attribute no debe estar presente cuando :values están presentes.',
    'multiple_of' => ':attribute debe ser múltiplo de :value.',
    'not_in' => ':attribute seleccionado no es válido.',
    'not_regex' => 'El formato de :attribute no es válido.',
    'numeric' => ':attribute debe ser un número.',
    'password' => [
        'letters' => ':attribute debe contener al menos una letra.',
        'mixed' => ':attribute debe contener al menos una mayúscula y una minúscula.',
        'numbers' => ':attribute debe contener al menos un número.',
        'symbols' => ':attribute debe contener al menos un símbolo.',
        'uncompromised' => ':attribute aparece en una filtración de datos conocida. Elige otra.',
    ],
    'present' => ':attribute debe estar presente.',
    'present_if' => ':attribute debe estar presente cuando :other es :value.',
    'present_unless' => ':attribute debe estar presente a menos que :other sea :value.',
    'present_with' => ':attribute debe estar presente cuando :values está presente.',
    'present_with_all' => ':attribute debe estar presente cuando :values están presentes.',
    'prohibited' => ':attribute no está permitido.',
    'prohibited_if' => ':attribute no está permitido cuando :other es :value.',
    'prohibited_unless' => ':attribute no está permitido a menos que :other esté en :values.',
    'prohibits' => ':attribute no permite que :other esté presente.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_array_keys' => ':attribute debe contener entradas para: :values.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'required_if_accepted' => 'El campo :attribute es obligatorio cuando :other es aceptado.',
    'required_unless' => 'El campo :attribute es obligatorio a menos que :other esté en :values.',
    'required_with' => 'El campo :attribute es obligatorio cuando :values está presente.',
    'required_with_all' => 'El campo :attribute es obligatorio cuando :values están presentes.',
    'required_without' => 'El campo :attribute es obligatorio cuando :values no está presente.',
    'required_without_all' => 'El campo :attribute es obligatorio cuando ninguno de :values está presente.',
    'same' => ':attribute y :other deben coincidir.',
    'size' => [
        'array' => ':attribute debe contener :size elementos.',
        'file' => ':attribute debe pesar :size kilobytes.',
        'numeric' => ':attribute debe ser :size.',
        'string' => ':attribute debe tener :size caracteres.',
    ],
    'starts_with' => ':attribute debe empezar con uno de los siguientes: :values.',
    'string' => ':attribute debe ser una cadena de texto.',
    'timezone' => ':attribute debe ser una zona horaria válida.',
    'unique' => ':attribute ya está en uso.',
    'uploaded' => ':attribute no se pudo subir.',
    'uppercase' => ':attribute debe estar en mayúsculas.',
    'url' => ':attribute debe ser una URL válida.',
    'ulid' => ':attribute debe ser un ULID válido.',
    'uuid' => ':attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensajes de validación personalizados
    |--------------------------------------------------------------------------
    |
    | Reservado para overrides puntuales "attribute.rule" que no valga la
    | pena repetir en cada validate() del controlador. Vacío hoy porque los
    | casos reales ya se resuelven con el :messages explícito en cada
    | controlador (el patrón ya establecido en esta base de código, p. ej.
    | RegisteredUserController::store()) - este bloque existe para no
    | reinventar ese mecanismo si algún día conviene centralizarlo aquí.
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Nombres de atributos personalizados
    |--------------------------------------------------------------------------
    |
    | Traduce los nombres de campo que aparecen dentro de ":attribute" en los
    | mensajes de arriba (p. ej. "El campo email es obligatorio" en vez de
    | "El campo :attribute es obligatorio" sin traducir). Solo los campos
    | reales de los formularios de la app - se amplía si se agrega uno nuevo
    | y su nombre en inglés se filtra a un mensaje visible.
    |
    */

    'attributes' => [
        'name' => 'nombre',
        'email' => 'correo electrónico',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de contraseña',
        'phone' => 'teléfono',
        'role' => 'rol',
        'accepted_terms' => 'los términos y condiciones',
        'teacher_subject_names' => 'las materias',
        'student_id' => 'el alumno',
        'subject_id' => 'la materia',
        'class_offer_id' => 'la oferta',
        'teacher_referral_code' => 'el código de profesor',
        'help_needed' => 'la descripción de ayuda',
        'preferred_times' => 'los horarios preferidos',
        'duration_minutes' => 'la duración',
        'first_name' => 'el nombre',
        'last_name' => 'el apellido',
        'grade_level' => 'el grado',
        'hourly_rate' => 'la tarifa por hora',
        'specific_rate' => 'la tarifa específica',
        'payment_method' => 'el método de pago',
        'operation_number' => 'el número de operación',
        'package_code' => 'el paquete',
    ],

];
