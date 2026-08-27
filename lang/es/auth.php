<?php

// Usado directamente por app/Http/Requests/Auth/LoginRequest.php vía
// trans('auth.failed') / trans('auth.throttle', [...]) - confirmado leyendo
// el archivo, no asumido. Sin esto, un login fallido mostraba "These
// credentials do not match our records." en inglés, verificado en vivo en
// el navegador real (mcp__Claude_Browser) antes de escribir esta traducción.

return [

    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña es incorrecta.',
    'throttle' => 'Demasiados intentos de acceso. Intenta de nuevo en :seconds segundos.',

];
