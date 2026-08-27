<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CloudinaryService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    public function updateAvatar(Request $request, CloudinaryService $cloudinary): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $user = $request->user();
        $url = $cloudinary->uploadAvatar($request->file('avatar'), $user->id);

        $user->update(['avatar_url' => $url]);

        // back(), no una ruta fija: UpdateAvatarForm.vue ahora se usa desde
        // /profile Y /teacher/profile (Teacher/Edit.vue) — redirigir siempre
        // a /profile sacaría a un profesor de la pantalla que estaba
        // editando justo después de subir su foto.
        return back();
    }

    // Vuelve a las iniciales — no borra el archivo en Cloudinary (fuera de
    // alcance de esta ronda; el registro seguiría existiendo ahí, solo deja
    // de estar referenciado desde MOVA). Simétrico a updateAvatar(): mismo
    // patrón simple, sin lógica financiera de por medio.
    //
    // TODO: cuando haya credenciales reales de Cloudinary en producción,
    // borrar también el asset remoto aquí (CloudinaryService ya tiene el
    // public_id determinístico "avatars/user-{id}" — ver uploadAvatar() —
    // así que un cloudinary()->destroy() no necesitaría guardar el ID por
    // separado). Sin esto, cada "quitar foto" deja un archivo huérfano en
    // la cuenta de Cloudinary indefinidamente.
    public function removeAvatar(Request $request): RedirectResponse
    {
        $request->user()->update(['avatar_url' => null]);

        return back();
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Logout first so token rotation cannot persist a model after a hard delete.
        Auth::logout();

        // F-01: la definición vive ahora en User::hasProtectedHistory(), para
        // que TODO camino de borrado la respete (incluido el guard de
        // User::booted()), no solo este formulario.
        $preserveFinancialHistory = $user->hasProtectedHistory();

        DB::transaction(function () use ($user, $preserveFinancialHistory) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->syncRoles([]);

            if ($preserveFinancialHistory) {

                $user->forceFill([
                    'name' => 'Cuenta eliminada',
                    'email' => "deleted-{$user->id}@mova.invalid",
                    'email_verified_at' => null,
                    'phone' => null,
                    'phone_verified_at' => null,
                    'phone_verification_code_hash' => null,
                    'phone_verification_expires_at' => null,
                    'remember_token' => null,
                    'password' => Str::random(64),
                    'suspended_at' => now(),
                    'suspension_reason' => 'Cuenta anonimizada a solicitud del usuario.',
                ])->save();

                if ($user->teacherProfile) {
                    $user->teacherProfile->update([
                        'bio' => null,
                        'is_verified' => false,
                    ]);
                    $user->teacherProfile->classOffers()->update(['is_active' => false]);
                }

                $user->students()->get()->each(function ($student) {
                    $student->update([
                        'first_name' => 'Estudiante',
                        'last_name' => "anonimizado {$student->id}",
                        'birth_date' => null,
                        'school' => null,
                    ]);
                });
            } else {
                $user->delete();
            }
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

}
