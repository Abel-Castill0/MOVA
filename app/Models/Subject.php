<?php

namespace App\Models;

use App\Services\SubjectNormalizer;
use App\Support\SubjectCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Materia del catálogo maestro cerrado (App\Support\SubjectCatalog, sembrado por SubjectSeeder).
 *
 * Los profesores solo MARCAN materias existentes (subject_ids validados con exists); ya no hay forma de crear
 * una materia escribiendo texto libre. Una materia es pública («Materias disponibles») solo mientras la tenga
 * al menos un profesor verificado con la cuenta activa: scopeAvailableForPublic().
 */
class Subject extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'normalized_name', 'slug', 'level', 'category', 'icon'];

    protected static function booted(): void
    {
        static::saving(function (Subject $subject) {
            if ($subject->isDirty('name') || !$subject->normalized_name) {
                $subject->normalized_name = SubjectNormalizer::normalize($subject->name);
            }
            if (! $subject->slug) {
                $subject->slug = SubjectCatalog::slugFor($subject->name);
            }
            // Una fila con el nombre de una materia del catálogo hereda su categoría e ícono (la vuelve elegible).
            if (! $subject->category && ($entry = SubjectCatalog::find($subject->slug))) {
                $subject->category = $entry['category'];
                $subject->icon ??= $entry['icon'];
            }
        });
    }

    /** Solo materias del catálogo maestro (con categoría): las únicas que un profesor puede elegir. */
    public function scopeInCatalog(Builder $query): Builder
    {
        return $query->whereNotNull('subjects.category');
    }

    /**
     * Materias con al menos un profesor VERIFICADO cuya cuenta está activa (ni cerrada/eliminada ni suspendida).
     * Es la ÚNICA regla de visibilidad pública: la usan la portada, el catálogo público y Movi. Se calcula en cada
     * consulta (sin bandera ni caché que se puedan desincronizar), así que verificar, rechazar, suspender,
     * reactivar o cerrar la cuenta de un profesor, o que cambie sus materias, se refleja en la siguiente visita.
     */
    public function scopeAvailableForPublic(Builder $query): Builder
    {
        return $query->whereHas('teachers', fn (Builder $teachers) => $teachers->publiclyActive());
    }

    /** Lista para el selector del profesor: todo el catálogo, agrupado por categoría y en orden alfabético. */
    public static function forPicker(): \Illuminate\Support\Collection
    {
        return static::query()->inCatalog()->get(['id', 'name', 'level', 'category', 'icon'])
            ->sortBy(fn (self $s) => sprintf('%02d %s', SubjectCatalog::categoryOrder($s->category), SubjectNormalizer::normalize($s->name)))
            ->map(fn (self $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'level' => $s->level,
                'icon' => $s->icon,
                'category' => $s->category,
                'category_label' => SubjectCatalog::categoryLabel($s->category),
            ])
            ->values();
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

    /** Materias (y sus equivalentes) con al menos un profesor verificado y activo (misma regla que la portada). */
    public static function idsWithVerifiedTeachers(): \Illuminate\Support\Collection
    {
        return static::equivalentIds(static::query()->availableForPublic()->pluck('id'));
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
