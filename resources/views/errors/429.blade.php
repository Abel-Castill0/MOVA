@include('errors.minimal', [
    'code' => 429,
    'title' => 'Demasiados intentos',
    'message' => 'Has hecho esta acción demasiadas veces en poco tiempo. Espera un momento y vuelve a intentarlo.',
])
