<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * H-12 — El teléfono debe poder normalizarse a E.164.
 *
 * `User::normalizePhone()` es la ÚNICA definición de "qué es un teléfono válido
 * en MOVA": móvil peruano de 9 dígitos que empieza por 9, `51` + 9 dígitos, o
 * cualquier número que ya venga con `+` y al menos 10 caracteres. Devuelve
 * `null` para todo lo demás.
 *
 * Esa función ya gobernaba la verificación por WhatsApp y la UNIQUE de
 * `phone_verified_normalized`. Esta regla la reutiliza en el formulario de
 * perfil en vez de reimplementar un regex paralelo que acabaría divergiendo.
 *
 * POR QUÉ IMPORTA: antes el perfil ni siquiera aceptaba el campo `phone`, así
 * que un número mal escrito en el registro (donde es `nullable` y sin formato
 * exigido) dejaba al usuario sin ninguna forma de corregirlo — y sin teléfono
 * verificado un profesor no cobra su bono de bienvenida ni recibe avisos.
 * Aceptar el campo sin validarlo solo habría movido el problema: el usuario
 * guardaría un número igual de inválido y volvería a encallar en la pantalla de
 * verificación.
 */
class NormalizablePhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return; // La obligatoriedad la decide `nullable`/`required`, no esta regla.
        }

        if (! is_string($value) || User::normalizePhone($value) === null) {
            $fail('Ingresa un número de celular peruano válido (9 dígitos empezando por 9) o un número internacional con prefijo, por ejemplo +51987654321.');
        }
    }
}
