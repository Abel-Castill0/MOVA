<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;

/**
 * C-P0-MINOR-CONSENT — consentimiento del padre/apoderado para el tratamiento
 * de los datos de UN alumno, registrado en el mismo acto (y la misma
 * transacción) en que lo crea. Append-only, igual que LegalAcceptance.
 */
class StudentDataConsent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['accepted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('student_data_consents es append-only.'));
        static::deleting(fn () => throw new LogicException('student_data_consents es append-only.'));
    }

    /** Registra el consentimiento con la declaración y la Política de Privacidad VIGENTES. */
    public static function record(Student $student, User $parent, Request $request): self
    {
        return self::create([
            'student_id'        => $student->id,
            'parent_user_id'    => $parent->id,
            'statement_version' => (string) config('legal.student_consent.version'),
            'privacy_version'   => (string) config('legal.versions.privacy'),
            'accepted_at'       => now(),
            'ip'                => $request->ip(),
            'user_agent'        => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }
}
