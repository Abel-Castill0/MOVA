<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    // Vincula por email (firstOrCreate) en vez de por google_id: así una cuenta
    // creada con email/password que luego usa "Continuar con Google" con el
    // mismo correo entra a la MISMA cuenta en lugar de duplicarla. Es seguro
    // porque Google ya verificó ese correo antes de devolvérnoslo — es la
    // misma confianza que usa "verificar email" en el registro tradicional.
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors([
                'email' => 'No se pudo completar el inicio de sesión con Google. Inténtalo de nuevo.',
            ]);
        }

        $email = $googleUser->getEmail();
        abort_unless($email, 422, 'Tu cuenta de Google no tiene un correo asociado.');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $googleUser->getName() ?: explode('@', $email)[0],
                // Cuenta creada solo para login por Google: password aleatoria
                // que nadie puede adivinar ni usar — si el usuario quiere
                // también iniciar sesión con contraseña, puede definirla vía
                // "¿Olvidaste tu contraseña?".
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
            ]
        );

        if ($user->wasRecentlyCreated) {
            $user->assignRole('parent');
            event(new Registered($user));
        } elseif (!$user->email_verified_at) {
            // Iniciar sesión vía Google ya es, en sí mismo, una verificación
            // del correo — no tiene sentido seguir bloqueando la cuenta.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, true);
        request()->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
