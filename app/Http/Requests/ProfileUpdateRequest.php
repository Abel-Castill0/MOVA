<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\NormalizablePhone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],

            /*
             * H-12 — `phone` faltaba aquí.
             *
             * `PhoneVerificationController` decía al usuario "Número de teléfono
             * inválido. Por favor actualiza tu perfil", pero el perfil no
             * aceptaba ese campo: ni en las reglas ni en el formulario. Como
             * `phone` es `nullable` en el registro, quien lo escribiera mal
             * quedaba encerrado sin salida — sin verificación, un profesor no
             * cobra su bono de 5 créditos ni recibe avisos por WhatsApp
             * (docs/MOVA_SYSTEM_MAP.md H-12, C-15).
             */
            'phone' => ['nullable', 'string', 'max:20', new NormalizablePhone, $this->notVerifiedByAnotherAccount()],
        ];
    }

    /**
     * Impide APROPIARSE de un número que otra cuenta ya verificó.
     *
     * La garantía dura sigue siendo la UNIQUE de `users.phone_verified_normalized`
     * más la comprobación de `PhoneVerificationController`: esta regla no la
     * sustituye. Lo que aporta es honestidad temprana — sin ella, MOVA aceptaría
     * guardar el número y el usuario solo descubriría el conflicto al intentar
     * verificarlo, después de gastar un OTP.
     *
     * Se compara sobre el número NORMALIZADO, no sobre el texto: "987654321",
     * "+51 987 654 321" y "51987654321" son el mismo teléfono.
     */
    private function notVerifiedByAnotherAccount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $normalized = User::normalizePhone(is_string($value) ? $value : '');

            if ($normalized === null) {
                return; // Formato inválido: ya lo reporta NormalizablePhone.
            }

            $taken = User::where('phone_verified_normalized', $normalized)
                ->whereKeyNot($this->user()->id)
                ->exists();

            if ($taken) {
                $fail('Este número de teléfono ya está verificado en otra cuenta de MOVA.');
            }
        };
    }
}
