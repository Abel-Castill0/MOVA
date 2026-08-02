<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * El EmailVerificationRequest de Laravel lanza un 403 genérico ("Acceso no
 * permitido") cuando la sesión autenticada no coincide con el {id} del
 * enlace — típico cuando alguien tiene otra cuenta abierta en el mismo
 * navegador y hace clic en su correo de verificación. En vez de un
 * callejón sin salida, cerramos esa sesión y mandamos a login con un
 * mensaje explicando qué pasó.
 */
class VerifyEmailRequest extends EmailVerificationRequest
{
    protected function failedAuthorization()
    {
        if (Auth::check() && (string) Auth::id() !== (string) $this->route('id')) {
            Auth::logout();
            $this->session()->invalidate();
            $this->session()->regenerateToken();

            throw new HttpResponseException(
                redirect()->route('login')->with(
                    'error',
                    'Ese enlace de verificación es de otra cuenta. Inicia sesión con la cuenta correcta y vuelve a intentarlo.'
                )
            );
        }

        throw new AuthorizationException;
    }
}
