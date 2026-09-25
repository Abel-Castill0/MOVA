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
