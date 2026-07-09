<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
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
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
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
            'teacher_subject_names.*' => 'nullable|string|max:100',
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
            $profile = TeacherProfile::create(['user_id' => $user->id, 'hourly_rate' => 0]);

            $subjectIds = collect($request->input('teacher_subject_names', []))
                ->map(fn($name) => trim((string) $name))
                ->filter()
                ->unique(fn($name) => mb_strtolower($name))
                ->map(fn($name) => Subject::firstOrCreate(['name' => $name], ['level' => 'todos'])->id);

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
