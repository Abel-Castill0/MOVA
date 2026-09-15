<?php

namespace App\Http\Controllers;

use App\Exceptions\AvatarStorageUnavailable;
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
use Illuminate\Validation\ValidationException;
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

        // AZ-2: en producción sin Cloudinary la subida falla de forma
        // controlada — se muestra como error del campo (el formulario ya lo
        // sabe pintar) en lugar de un 500 o de guardar en disco efímero.
        try {
            $url = $cloudinary->uploadAvatar($request->file('avatar'), $user->id);
        } catch (AvatarStorageUnavailable $e) {
            report($e);

            throw ValidationException::withMessages(['avatar' => $e->getMessage()]);
        }

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
    /**
     * H-12 — Cambiar el teléfono INVALIDA su verificación.
     *
     * No basta con aceptar el campo. `phone_verified_at`,
     * `phone_verified_normalized` y el consentimiento de WhatsApp describen un
     * NÚMERO CONCRETO, no al usuario: si el número cambia, todo eso deja de ser
     * cierto. Sin este reseteo, alguien podría verificar un número propio y
     * luego cambiarlo por el de otra persona conservando el estado "verificado"
     * —y con él el permiso para recibir WhatsApp— sobre un número que nunca
     * demostró controlar.
     *
     * Se compara el número NORMALIZADO, no el texto tal cual: reescribir
     * "987654321" como "+51 987 654 321" es el MISMO teléfono y no debe costar
     * una reverificación ni tirar el consentimiento.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $previousNormalized = User::normalizePhone($user->phone);

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $phoneChanged = User::normalizePhone($user->phone) !== $previousNormalized;

        if ($phoneChanged) {
            $this->resetPhoneVerification($user);
        }

        $user->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Devuelve al usuario al estado "teléfono sin verificar" para el número
     * nuevo.
     *
     * `phone_verified_normalized` está FUERA de `$fillable` a propósito (es la
     * garantía UNIQUE contra teléfonos duplicados), así que se asigna directo:
     * `fill()` lo descartaría en silencio y la cuenta se quedaría reservando un
     * número que ya no usa, impidiendo que su dueño real lo verifique.
     *
     * EL CONSENTIMIENTO DE WHATSAPP TAMBIÉN SE CAE, y en una sola dirección:
     *
     *   - `whatsapp_opt_in_at` se limpia SIEMPRE. El permiso se dio para el
     *     número anterior; arrastrarlo significaría escribir a un número nuevo
     *     que nunca aceptó nada. El usuario lo vuelve a conceder al verificar
     *     (PhoneVerificationController::verify() acepta `whatsapp_notifications`).
     *
     *   - `whatsapp_opt_out_at` se CONSERVA. Una baja es una voluntad expresada
     *     sobre el canal, no sobre un número concreto; limpiarla al cambiar de
     *     teléfono convertiría un cambio de número en una resuscripción
     *     silenciosa. `User::wantsWhatsAppNotifications()` ya da prioridad al
     *     opt-out sobre cualquier opt-in posterior, y ese criterio se respeta
     *     aquí.
     *
     * El BONO DE BIENVENIDA no se toca ni hace falta protegerlo aquí:
     * `PhoneVerificationController::grantTeacherWelcomeBonus()` es idempotente
     * por la clave `teacher:{id}:welcome`, así que verificar un segundo número
     * no vuelve a abonar créditos.
     */
    private function resetPhoneVerification(User $user): void
    {
        $user->phone_verified_at = null;
        $user->phone_verified_normalized = null;
        $user->phone_verification_code_hash = null;
        $user->phone_verification_expires_at = null;
        $user->phone_verification_attempts = 0;
        $user->whatsapp_opt_in_at = null;
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
