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

        // Variante de una materia que ya existe («Matemática» frente a «Matemáticas»): se reutiliza la del catálogo
        // (la más antigua) en vez de crear otra fila que partiría en dos a profesores y solicitudes.
        $key = SubjectNormalizer::matchKey($rawName);
        $variant = static::orderBy('id')->get(['id', 'name', 'normalized_name', 'level'])
            ->first(fn (self $s) => SubjectNormalizer::matchKey($s->name) === $key);
        if ($variant) {
            return $variant;
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

    /**
     * Ids de todas las materias equivalentes a las dadas (ellas incluidas). Es la ÚNICA regla de coincidencia por
     * materia entre una solicitud y un profesor: la usan la bandeja, el contador, la autorización y los avisos.
     * Existe porque el catálogo real ya tiene variantes del mismo nombre con ids distintos.
     *
     * @param  iterable<int|string>  $subjectIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function equivalentIds(iterable $subjectIds): \Illuminate\Support\Collection
    {
        $ids = collect($subjectIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return $ids;
        }

        $keys = static::query()->get(['id', 'name'])->mapWithKeys(fn (self $s) => [$s->id => SubjectNormalizer::matchKey($s->name)]);
        $wanted = $ids->map(fn (int $id) => $keys->get($id))->filter()->unique();

        return $keys->filter(fn (string $key) => $wanted->contains($key))->keys()
            ->merge($ids)->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** Materias (y sus equivalentes) con al menos un profesor VERIFICADO con la cuenta abierta. */
    public static function idsWithVerifiedTeachers(): \Illuminate\Support\Collection
    {
        $taught = \Illuminate\Support\Facades\DB::table('teacher_subject')
            ->join('teacher_profiles', 'teacher_profiles.id', '=', 'teacher_subject.teacher_profile_id')
            ->join('users', 'users.id', '=', 'teacher_profiles.user_id')
            ->where('teacher_profiles.is_verified', true)
            ->whereNull('users.account_closed_at')
            ->distinct()
            ->pluck('teacher_subject.subject_id');

        return static::equivalentIds($taught);
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
