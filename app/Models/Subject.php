<?php

namespace App\Models;

use App\Services\SubjectNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
     */
    public static function firstOrCreateByName(string $rawName): self
    {
        $normalized = SubjectNormalizer::normalize($rawName);

        return static::firstOrCreate(
            ['normalized_name' => $normalized],
            ['name' => trim($rawName), 'level' => 'todos']
        );
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
