<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Rules\NotProfane;
use App\Services\SubjectNormalizer;
use App\Notifications\WelcomeParentNotification;
use App\Notifications\WelcomeTeacherNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
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
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:parent,teacher',
            'accepted_terms' => 'accepted',
            'teacher_subject_names' => 'required_if:role,teacher|array',
            'teacher_subject_names.*' => ['nullable', 'string', 'max:100', new NotProfane],
        ], [
            'accepted_terms.accepted' => 'Debes aceptar los Términos y Condiciones y la Política de Privacidad para continuar.',
            'teacher_subject_names.required_if' => 'Agrega al menos una materia o curso especializado.',
        ]);

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

            abort_if($subjectIds->isEmpty(), 422, 'Agrega al menos una materia o curso especializado.');

            $profile->subjects()->sync(
                $subjectIds->mapWithKeys(fn($id) => [$id => ['specific_rate' => null]])
            );
        }

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
