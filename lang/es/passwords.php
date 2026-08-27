<?php

// Usado por PasswordResetLinkController/NewPasswordController vía
// __($status)/trans($status), donde $status es una de las constantes del
// broker de contraseñas de Laravel (Password::RESET_LINK_SENT, etc. - cada
// una es literalmente una de estas claves) - confirmado leyendo ambos
// controladores, no asumido.

return [

    'reset' => 'Tu contraseña ha sido restablecida.',
    'sent' => 'Te hemos enviado por correo el enlace para restablecer tu contraseña.',
    'throttled' => 'Espera antes de intentarlo de nuevo.',
    'token' => 'El token para restablecer la contraseña no es válido.',
    'user' => 'No encontramos ningún usuario con ese correo electrónico.',

];
