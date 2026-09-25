<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LegalAcceptance;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\WelcomeParentNotification;
use App\Notifications\WelcomeTeacherNotification;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * R-19 — Alta con Google eligiendo rol.
 *
 * ANTES: cualquier cuenta nueva creada por Google recibía `assignRole('parent')`
 * sin preguntar. Un profesor que entrara por "Continuar con Google" quedaba
 * clasificado como padre, sin TeacherProfile, sin forma de corregirlo desde el
 * producto y con acceso a un dashboard que no le correspondía
 * (docs/MOVA_SYSTEM_MAP.md R-19).
 *
 * AHORA: la cuenta NO se crea en el callback. La identidad verificada por Google
 * se guarda en la sesión y el usuario elige rol antes de que exista nada en la
 * base de datos.
 *
 * POR QUÉ NO CREAR EL USUARIO Y PREGUNTARLE DESPUÉS:
 *
 * Un usuario sin rol es un estado inválido en MOVA. Todas las rutas de producto
 * pasan por `role:parent|teacher|admin`, y `DashboardController` ramifica con un
 * `else` final que muestra el panel de PADRE a cualquiera que no sea admin ni
 * profesor — es decir, un usuario sin rol vería exactamente el panel equivocado
 * que este cambio viene a evitar. Si el usuario abandona a mitad, no queda
 * ninguna fila a medias.
 *
 * ADMIN NUNCA ES ELEGIBLE: la validación es un `Rule::in(['parent','teacher'])`
 * explícito. No se deriva de una lista de roles ni del input.
 */
class GoogleAuthController extends Controller
{
    /**
     * Clave de sesión con la identidad ya verificada por Google. Solo la escribe
     * callback() tras un intercambio OAuth correcto; el formulario de elección de
     * rol NUNCA acepta un email del cliente.
     */
    private const PENDING_SESSION_KEY = 'google_pending_registration';

    public function redirect(): SymfonyRedirectResponse|RedirectResponse
    {
        abort_unless(config('services.google.login_enabled'), 503, 'El inicio de sesión con Google está temporalmente no disponible.');

        return Socialite::driver('google')->redirect();
    }

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

        $existing = User::where('email', $email)->first();

        // CUENTA EXISTENTE: conserva su rol, pase el que pase. Este camino nunca
        // asigna ni cambia roles.
        if ($existing) {
            if (! $existing->email_verified_at) {
                $existing->forceFill(['email_verified_at' => now()])->save();
            }

            Auth::login($existing, true);
            request()->session()->regenerate();

            return redirect()->intended(RouteServiceProvider::HOME);
        }

        // CUENTA NUEVA: nada se persiste todavía.
        request()->session()->put(self::PENDING_SESSION_KEY, [
            'email' => $email,
            'name' => $googleUser->getName() ?: explode('@', $email)[0],
        ]);

        return redirect()->route('auth.google.role');
    }

    public function showRoleSelection(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING_SESSION_KEY);

        if (! $pending) {
            return redirect()->route('login')->withErrors([
                'email' => 'Tu sesión con Google expiró. Vuelve a iniciar el proceso.',
            ]);
        }

        return Inertia::render('Auth/GoogleRole', [
            // Solo para mostrar "vas a registrarte como X": el backend jamás lee
            // el email de vuelta desde el formulario.
            'email' => $pending['email'],
            'name' => $pending['name'],
        ]);
    }

    public function storeRoleSelection(Request $request): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING_SESSION_KEY);

        if (! $pending) {
            return redirect()->route('login')->withErrors([
                'email' => 'Tu sesión con Google expiró. Vuelve a iniciar el proceso.',
            ]);
        }

        $data = $request->validate([
            // Lista blanca explícita. 'admin' no aparece aquí y no puede
            // aparecer: no se deriva de Role::pluck() ni de nada dinámico.
            'role' => ['required', 'string', 'in:parent,teacher'],
            'accepted_terms' => ['accepted'],
        ], [
            'role.in' => 'Elige si te registras como familia o como profesor.',
            'accepted_terms.accepted' => 'Debes aceptar los Términos y Condiciones y la Política de Privacidad para continuar.',
        ]);

        // Entre el callback y este envío alguien pudo registrar ese correo por
        // el formulario normal. Se comprueba de nuevo en vez de confiar en la
        // comprobación de hace unos segundos.
        if (User::where('email', $pending['email'])->exists()) {
            $request->session()->forget(self::PENDING_SESSION_KEY);

            return redirect()->route('login')->withErrors([
                'email' => 'Ya existe una cuenta con ese correo. Inicia sesión con ella.',
            ]);
        }

        $user = DB::transaction(function () use ($pending, $data, $request) {
            $user = User::create([
                'name' => $pending['name'],
                'email' => $pending['email'],
                // Sin contraseña utilizable: esta cuenta entra por Google. Puede
                // fijar una con "he olvidado mi contraseña" si algún día quiere.
                'password' => Hash::make(Str::random(64)),
                // Google ya verificó el correo; exigir un segundo enlace sería
                // pedir dos veces la misma prueba.
                'email_verified_at' => now(),
            ]);

            $user->assignRole($data['role']);

            if ($data['role'] === 'teacher') {
                // Sin materias todavía: las pide `teacher.setup`, que ya exige al
                // menos una antes de dejar continuar. El registro por formulario
                // las recoge antes; aquí se recogen un paso después, en la misma
                // pantalla de onboarding que ya existía.
                TeacherProfile::create(['user_id' => $user->id, 'hourly_rate' => 20]);
            }

            // P0-K: versión + timestamp de lo aceptado, en la misma transacción.
            LegalAcceptance::recordCurrent($user, $request);

            return $user;
        });

        $request->session()->forget(self::PENDING_SESSION_KEY);

        event(new Registered($user));
        Auth::login($user, true);
        $request->session()->regenerate();

        // Mismo tratamiento que el registro normal, incluida la marca que
        // SendWelcomeAfterVerification usa como guarda.
        if ($data['role'] === 'parent') {
            $user->notify(new WelcomeParentNotification());
            $user->update(['welcome_notification_sent_at' => now()]);

            return redirect()->route('students.create');
        }

        $user->notify(new WelcomeTeacherNotification());
        $user->update(['welcome_notification_sent_at' => now()]);

        return redirect()->route('teacher.setup');
    }
}
