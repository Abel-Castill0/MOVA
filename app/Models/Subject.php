<?php

namespace App\Models;

use App\Services\SubjectNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\UniqueConstraintViolationException;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'normalized_name', 'level'];

    protected static function booted(): void
    {
        static::saving(function (Subject $subject) {
            if ($subject->isDirty('name') || !$subject->normalized_name) {
                $subject->normalized_name = SubjectNormalizer::normalize($subject->name);
            }
        });
    }

    /**
     * Busca por nombre normalizado (sin tildes, minúsculas, sin espacios de
     * más); si no existe, crea con el texto original que escribió el profesor.
     *
     * @throws \InvalidArgumentException si el nombre contiene una palabra de
     *         config('profanity.subject_name_blocklist') — este es el único
     *         punto de entrada de materias por texto libre (registro y
     *         edición de perfil), así que basta filtrar aquí una vez.
     */
    public static function firstOrCreateByName(string $rawName): self
    {
        $normalized = SubjectNormalizer::normalize($rawName);
        static::assertNameIsAppropriate($normalized);

        $existing = static::where('normalized_name', $normalized)->first();
        if ($existing) {
            return $existing;
        }

        // N1 (docs/MOVA_AUDIT_PHASE0.md, sección Q): `firstOrCreate()` hace un
        // SELECT y, si no encuentra nada, un INSERT — sin ninguna protección
        // entre ambos pasos. Bajo dos requests concurrentes creando/editando
        // con el MISMO nombre de materia nuevo (p. ej. dos profesores nuevos
        // escribiendo "Cálculo III" al mismo tiempo), ambos pueden pasar el
        // SELECT sin encontrar nada e intentar el INSERT — uno gana, el otro
        // choca contra el UNIQUE de `normalized_name` con un 500 crudo, a
        // diferencia de cada otro punto de unicidad del sistema
        // (`idempotency_key`, `operation_number_normalized`,
        // `phone_verified_normalized`), que sí captura la excepción y
        // recupera la fila real en vez de fallar.
        try {
            return static::create(['name' => trim($rawName), 'level' => 'todos', 'normalized_name' => $normalized]);
        } catch (UniqueConstraintViolationException) {
            return static::where('normalized_name', $normalized)->firstOrFail();
        }
    }

    private static function assertNameIsAppropriate(string $normalized): void
    {
        $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $blocklist = config('profanity.subject_name_blocklist', []);

        // Comparación por PALABRA COMPLETA, nunca substring — "Educación
        // Sexual" no debe chocar con nada de esta lista.
        if (array_intersect($words, $blocklist) !== []) {
            throw new \InvalidArgumentException('El nombre de la materia no es válido.');
        }
    }

    public function teachers()
    {
        return $this->belongsToMany(TeacherProfile::class, 'teacher_subject')
            ->withPivot('specific_rate');
    }

    public function classOffers()
    {
        return $this->hasMany(ClassOffer::class);
    }

    public function classRequests()
    {
        return $this->hasMany(ClassRequest::class);
    }
}
