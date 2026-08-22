<?php

namespace App\Rules;

use App\Services\SubjectNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regla de validación reutilizada por los dos puntos de entrada de materias
 * por texto libre (RegisteredUserController, TeacherProfileController) —
 * falla ANTES de crear cualquier registro (User, TeacherProfile), como parte
 * del $request->validate() normal, para no dejar una cuenta a medio crear si
 * el nombre de materia se rechaza (ninguno de esos dos flujos usa
 * DB::transaction() hoy, así que fallar tarde ahí dejaría filas huérfanas).
 *
 * Subject::firstOrCreateByName() mantiene su propio chequeo equivalente
 * como garantía real — esta regla es la capa de UX (422 limpio, sin
 * escritura previa), no la única.
 */
class NotProfane implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $words = preg_split('/\s+/', SubjectNormalizer::normalize($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $blocklist = config('profanity.subject_name_blocklist', []);

        if (array_intersect($words, $blocklist) !== []) {
            $fail('El nombre de la materia no es válido.');
        }
    }
}
