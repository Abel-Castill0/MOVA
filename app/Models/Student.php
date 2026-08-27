<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'parent_user_id', 'first_name', 'last_name',
        'birth_date', 'grade_level', 'school',
    ];

    protected $appends = ['full_name'];

    /**
     * F-18 — Guard contra el borrado FÍSICO de un alumno con historial.
     *
     * `StudentController::destroy()` hacía `$student->delete()` físico sin
     * ninguna comprobación. Con historial académico eso significaba:
     *   - MySQL: `classes.student_id` es RESTRICT, así que saltaba una
     *     excepción cruda de constraint y el padre veía un error 500.
     *   - SQLite (tests): las FK conservan el CASCADE original, así que las
     *     clases del alumno se borraban de verdad y ningún test lo detectaba.
     *
     * Ahora el borrado normal es SOFT (la fila sobrevive, las clases que la
     * referencian siguen siendo coherentes y el ledger conserva su respaldo),
     * así que el guard solo tiene que cubrir el `forceDelete()` — el único
     * camino que todavía destruiría historial.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (self $student) {
            if ($student->hasAcademicHistory()) {
                throw new \RuntimeException(
                    "No se puede eliminar físicamente al alumno {$student->id}: tiene clases o solicitudes "
                    .'asociadas, y ese historial respalda movimientos del ledger. Usa el borrado normal '
                    .'(soft delete), que preserva la fila.'
                );
            }
        });
    }

    /**
     * ¿Este alumno arrastra historial que debe sobrevivir a su baja? Es el
     * equivalente de User::hasProtectedHistory() para el nivel del alumno.
     */
    public function hasAcademicHistory(): bool
    {
        return $this->classes()->exists() || $this->classRequests()->exists();
    }

    /**
     * Sustituye los datos personales del menor por marcadores, conservando la
     * fila para que las clases y solicitudes que la referencian sigan siendo
     * coherentes. Es lo mismo que hace ProfileController al dar de baja una
     * cuenta con historial.
     */
    public function anonymize(): void
    {
        $this->update([
            'first_name' => 'Estudiante',
            'last_name' => "eliminado {$this->id}",
            'birth_date' => null,
            'school' => null,
        ]);
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function classRequests()
    {
        return $this->hasMany(ClassRequest::class);
    }

    public function classes()
    {
        return $this->hasMany(Lesson::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
