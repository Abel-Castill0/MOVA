<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hashes HMAC (con la clave de la aplicación) de correo/teléfono de cuentas cerradas con restricción o deuda. */
class ClosureBlocklist extends Model
{
    public $timestamps = false;

    protected $table = 'closure_blocklist';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    private static function hash(string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind.':'.$value, (string) config('app.key'));
    }

    private static function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public static function add(?string $email, array $phones, string $reason): void
    {
        $items = [];
        if ($value = self::normalizeEmail($email)) {
            $items[] = ['email', $value];
        }
        foreach ($phones as $phone) {
            if ($value = User::normalizePhone($phone)) {
                $items[] = ['phone', $value];
            }
        }
        foreach ($items as [$kind, $value]) {
            static::firstOrCreate(['kind' => $kind, 'hash' => self::hash($kind, $value)], ['reason' => $reason, 'created_at' => now()]);
        }
    }

    public static function blocks(?string $email = null, ?string $phone = null): bool
    {
        $conditions = [];
        if ($value = self::normalizeEmail($email)) {
            $conditions[] = ['email', self::hash('email', $value)];
        }
        if ($phone && ($value = User::normalizePhone($phone))) {
            $conditions[] = ['phone', self::hash('phone', $value)];
        }

        return $conditions !== [] && static::query()->where(function ($query) use ($conditions) {
            foreach ($conditions as [$kind, $hash]) {
                $query->orWhere(fn ($q) => $q->where('kind', $kind)->where('hash', $hash));
            }
        })->exists();
    }
}
