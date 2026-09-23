<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LegalAcceptance;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Rules\NormalizablePhone;
use App\Rules\NotProfane;
use App\Services\SubjectNormalizer;
use App\Notifications\WelcomeParentNotification;
use App\Notifications\WelcomeTeacherNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(Request $request): Response
    {
        // "Soy profesor"/"Soy padre" en la landing llegan con ?role=... — se
        // valida contra una lista blanca (nunca se confía en el string crudo)
        // y se pasa como prop para que Register.vue preseleccione y BLOQUEE
        // el paso 1, en vez de que el usuario tenga que volver a elegir.
        $role = $request->query('role');
        $lockedRole = in_array($role, ['parent', 'teacher'], true) ? $role : null;

        return Inertia::render('Auth/Register', [
            'lockedRole' => $lockedRole,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // H-12 (bug hermano) — El registro validaba solo la longitud, así
            // que aceptaba "12345" y creaba una cuenta con un teléfono que
            // NUNCA podría verificarse. Ahora usa la misma regla que el perfil
            // (App\Rules\NormalizablePhone → User::normalizePhone()), de modo
            // que el número que entra al sistema es siempre uno que el flujo de
            // verificación por WhatsApp puede procesar.
            //
            // Sigue siendo opcional: quien no quiera darlo, no lo da.
            'phone' => ['nullable', 'string', 'max:20', new NormalizablePhone],
            'role' => 'required|in:parent,teacher',
            'accepted_terms' => 'accepted',
            'teacher_subject_names' => 'required_if:role,teacher|array',
            'teacher_subject_names.*' => ['nullable', 'string', 'max:100', new NotProfane],
        ], [
            'accepted_terms.accepted' => 'Debes aceptar los Términos y Condiciones y la Política de Privacidad para continuar.',
            'teacher_subject_names.required_if' => 'Agrega al menos una materia o curso especializado.',
        ]);

        // Envuelto en transacción: encontrado al corregir el abort_if() de
        // abajo (mismo bug ya arreglado en ClassRequestController::store(),
        // commit 206d886) - sin transacción, User::create() y
        // TeacherProfile::create() ya habían escrito filas reales ANTES de
        // llegar al chequeo de materias, así que lanzar una excepción ahí
        // dejaba una cuenta huérfana a medio crear en vez de simplemente
        // no crear nada (confirmado con una prueba real que falló primero
        // en assertDatabaseCount('users', 0) antes de este cambio - no
        // asumido). El comentario original de NotProfane.php ya reconocía
        // este riesgo ("ninguno de esos dos flujos usa DB::transaction()
        // hoy, así que fallar tarde ahí dejaría filas huérfanas") como una
        // razón para preferir rechazar en el validate() inicial - pero no
        // elimina el riesgo si de todos modos se llega aquí, así que se
        // cierra la brecha en la fuente en vez de seguir dependiendo solo
        // de que el validate() inicial atrape todo antes.
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
            ]);

            $user->assignRole($request->role);

            if ($request->role === 'teacher') {
                $profile = TeacherProfile::create(['user_id' => $user->id, 'hourly_rate' => 20]);

                $subjectIds = collect($request->input('teacher_subject_names', []))
                    ->map(fn($name) => trim((string) $name))
                    ->filter()
                    ->unique(fn($name) => SubjectNormalizer::normalize($name))
                    ->map(fn($name) => Subject::firstOrCreateByName($name)->id);

                if ($subjectIds->isEmpty()) {
                    throw ValidationException::withMessages([
                        'teacher_subject_names' => 'Agrega al menos una materia o curso especializado.',
                    ]);
                }

                $profile->subjects()->sync(
                    $subjectIds->mapWithKeys(fn($id) => [$id => ['specific_rate' => null]])
                );
            }

            // P0-K: versión + timestamp de lo aceptado, en la misma transacción.
            LegalAcceptance::recordCurrent($user, $request);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);

        if ($request->role === 'parent') {
            $user->notify(new WelcomeParentNotification());
            $user->update(['welcome_notification_sent_at' => now()]);
            return redirect()->route('students.create');
        }

        $user->notify(new WelcomeTeacherNotification());
        $user->update(['welcome_notification_sent_at' => now()]);

        return redirect()->route('teacher.setup');
    }
}
