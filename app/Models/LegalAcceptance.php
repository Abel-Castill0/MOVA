<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;

/** P0-K — registro append-only de aceptaciones de documentos legales. */
class LegalAcceptance extends Model
{
    public const DOCUMENTS = ['terms', 'privacy'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['accepted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('legal_acceptances es append-only.'));
        static::deleting(fn () => throw new LogicException('legal_acceptances es append-only.'));
    }

    /** ¿Tiene el usuario una aceptación de la versión VIGENTE de cada documento? */
    public static function hasAcceptedCurrent(User $user): bool
    {
        return self::missingCurrentDocuments($user) === [];
    }

    /** @return list<string> documentos cuya versión vigente el usuario no ha aceptado */
    public static function missingCurrentDocuments(User $user): array
    {
        $accepted = self::where('user_id', $user->id)
            ->where(function ($q) {
                foreach (self::DOCUMENTS as $document) {
                    $q->orWhere(fn ($d) => $d->where('document', $document)
                        ->where('version', (string) config("legal.versions.{$document}")));
                }
            })
            ->pluck('document')
            ->unique()
            ->all();

        return array_values(array_diff(self::DOCUMENTS, $accepted));
    }

    /**
     * C-P1-LEGAL-REACCEPTANCE — añade SOLO las aceptaciones vigentes que
     * faltan. Nunca toca filas previas (append-only): el historial de
     * versiones aceptadas queda completo.
     */
    public static function recordMissingCurrent(User $user, ?Request $request): void
    {
        // $request null = seeders de datos de demo: sin IP/UA inventados.
        foreach (self::missingCurrentDocuments($user) as $document) {
            self::create([
                'user_id'     => $user->id,
                'document'    => $document,
                'version'     => (string) config("legal.versions.{$document}"),
                'accepted_at' => now(),
                'ip'          => $request?->ip(),
                'user_agent'  => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        }
    }

    /** Registra la aceptación de las versiones VIGENTES de Términos y Privacidad. */
    public static function recordCurrent(User $user, Request $request): void
    {
        foreach (self::DOCUMENTS as $document) {
            self::create([
                'user_id'     => $user->id,
                'document'    => $document,
                'version'     => (string) config("legal.versions.{$document}"),
                'accepted_at' => now(),
                'ip'          => $request->ip(),
                'user_agent'  => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        }
    }
}
