<?php

namespace App\WhatsApp\Waha;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Estado de la sesión de WAHA y de la política anti-restricción.
 *
 *  - Volátil (caché compartida, 2 min de frescura): último estado observado. Es solo una ayuda para no enviar a ciegas; la fuente
 *    de verdad sigue siendo GET /api/sessions/{session}.
 *  - DURABLE (tablas `waha_state` y `waha_contacts`): fecha de la última vinculación nueva, última conexión, restricciones de
 *    WhatsApp, alerta de vinculación perdida, último respaldo y quién escribió primero al número. NO usan la caché porque
 *    docker/entrypoint.sh la vacía (`optimize:clear`) en cada arranque de cada contenedor.
 * Si la base falla, las lecturas devuelven «sin dato» y los envíos quedan bloqueados (política fail-closed en WahaProvider).
 */
final class WahaSessionState
{
    private const STATE_KEY = 'waha:session_state';

    private const PAUSE = 'paused_until';

    private const LAST_WORKING = 'last_working_at';

    private const LAST_SCAN = 'last_scan_at';

    private const LINKED_AT = 'linked_at';

    private const BACKUP = 'last_backup';

    private const LOST = 'link_lost';

    private const LAST_STATUS = 'last_status';

    // ── Estado de la sesión ────────────────────────────────────────────────────────────────────────

    /** Estado más reciente si es de hace menos de $maxAgeSeconds; si no, null (desconocido). */
    public static function status(int $maxAgeSeconds = 600): ?string
    {
        $state = Cache::get(self::STATE_KEY);

        if (! is_array($state) || ($state['at'] ?? 0) < now()->subSeconds($maxAgeSeconds)->getTimestamp()) {
            return null;
        }

        return $state['status'] ?? null;
    }

    public static function record(?string $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        Cache::put(self::STATE_KEY, ['status' => $status, 'at' => now()->getTimestamp()], now()->addDay());

        $previous = self::get(self::LAST_STATUS);
        self::put(self::LAST_STATUS, $status);

        if ($status === 'SCAN_QR_CODE') {
            self::put(self::LAST_SCAN, now()->getTimestamp());
        }

        if ($status === 'WORKING') {
            // Vinculación NUEVA = pasar por SCAN_QR_CODE hace poco; un reinicio normal (VM, Docker) NO reinicia el calentamiento.
            $scan = self::get(self::LAST_SCAN);
            if (is_numeric($scan) && (int) $scan > now()->subMinutes(30)->getTimestamp()) {
                self::put(self::LINKED_AT, now()->getTimestamp());
                self::forget(self::LAST_SCAN);
            }
            self::put(self::LAST_WORKING, now()->getTimestamp());
            self::forget(self::LOST);

            return;
        }

        // Estuvo conectada y dejó de estarlo (FAILED, STOPPED o pidiendo QR de nuevo): alerta hasta volver a WORKING.
        // No se marca si un administrador acaba de actuar desde el panel (restablecer pasa por STOPPED/SCAN_QR_CODE a propósito).
        if ($previous === 'WORKING' && in_array($status, ['FAILED', 'STOPPED', 'SCAN_QR_CODE'], true) && ! self::adminActedRecently()) {
            self::put(self::LOST, ['status' => $status, 'at' => now()->getTimestamp()]);
        }
    }

    /** Un administrador actuó sobre la sesión: durante unos minutos los cambios de estado son esperados, no una pérdida. */
    public static function noteAdminAction(): void
    {
        self::put('admin_action_at', now()->getTimestamp());
    }

    private static function adminActedRecently(): bool
    {
        $ts = self::get('admin_action_at');

        return is_numeric($ts) && (int) $ts > now()->subMinutes(10)->getTimestamp();
    }

    /** Almacén durable para otros componentes del canal (p. ej. el backoff del vigilante). */
    public static function remember(string $key, mixed $value): void
    {
        self::put($key, $value);
    }

    public static function recall(string $key): mixed
    {
        return self::get($key);
    }

    public static function erase(string $key): void
    {
        self::forget($key);
    }

    /** Elimina los contactos vencidos (lo llama el vigilante; la limpieza al marcar es solo ocasional). */
    public static function pruneContacts(): void
    {
        try {
            DB::table('waha_contacts')->where('last_inbound_at', '<', self::cutoff())->delete();
        } catch (\Throwable) {
        }
    }

    /** Última vez que se observó la sesión en WORKING (solo informativo para el panel). */
    public static function lastWorkingAt(): ?Carbon
    {
        $ts = self::get(self::LAST_WORKING);

        return is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    /** @return array{status: string, at: Carbon}|null */
    public static function linkLost(): ?array
    {
        $v = self::get(self::LOST);

        return is_array($v) && isset($v['status'], $v['at']) ? ['status' => (string) $v['status'], 'at' => Carbon::createFromTimestamp((int) $v['at'])] : null;
    }

    // ── Restricciones de WhatsApp ──────────────────────────────────────────────────────────────────

    public static function pauseUntil(Carbon $until): void
    {
        self::put(self::PAUSE, $until->getTimestamp());
    }

    public static function pausedUntil(): ?Carbon
    {
        $ts = self::get(self::PAUSE);

        return is_numeric($ts) && (int) $ts > now()->getTimestamp() ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    // ── Calentamiento tras una vinculación nueva ───────────────────────────────────────────────────

    /** Momento de la última vinculación NUEVA (por QR/código). */
    public static function linkedAt(): ?Carbon
    {
        $ts = self::get(self::LINKED_AT);

        return is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    /** Minutos de calentamiento que faltan tras una vinculación nueva (0 = ya pasó o no hay dato). */
    public static function warmupRemainingMinutes(): int
    {
        $linked = self::linkedAt();
        $total = (int) config('services.waha.warmup_minutes', 1440);
        if ($linked === null || $total <= 0) {
            return 0;
        }

        return max(0, (int) ceil($total - $linked->diffInMinutes(now(), true)));
    }

    // ── Quién escribió primero al número de MOVA ───────────────────────────────────────────────────

    /** El destinatario escribió primero: responderle es un chat existente, no iniciar uno. Se guarda un HMAC del número, jamás el texto. */
    public static function markContacted(string $digits): void
    {
        if ($digits === '') {
            return;
        }

        try {
            DB::table('waha_contacts')->upsert([['phone_hash' => self::hash($digits), 'last_inbound_at' => now()]], ['phone_hash'], ['last_inbound_at']);
            if (random_int(1, 50) === 1) { // limpieza ocasional de los vencidos
                DB::table('waha_contacts')->where('last_inbound_at', '<', self::cutoff())->delete();
            }
        } catch (\Throwable $e) {
            self::warnMissingTables($e); // sin base no se registra: la política queda en modo seguro
        }
    }

    public static function hasContacted(string $digits): bool
    {
        try {
            return DB::table('waha_contacts')->where('phone_hash', self::hash($digits))->where('last_inbound_at', '>=', self::cutoff())->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function contactedCount(): int
    {
        try {
            return (int) DB::table('waha_contacts')->where('last_inbound_at', '>=', self::cutoff())->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ── Respaldo ───────────────────────────────────────────────────────────────────────────────────

    /** Resultado del último respaldo cifrado (lo informa la VM por webhook firmado; solo huella corta y tamaño). */
    public static function recordBackup(string $sha256, int $bytes, ?int $at = null): void
    {
        self::put(self::BACKUP, ['sha' => substr($sha256, 0, 16), 'bytes' => $bytes, 'at' => $at ?? now()->getTimestamp()]);
    }

    /** @return array{sha: string, bytes: int, at: Carbon}|null */
    public static function lastBackup(): ?array
    {
        $v = self::get(self::BACKUP);

        return is_array($v) && isset($v['sha'], $v['bytes'], $v['at']) ? ['sha' => (string) $v['sha'], 'bytes' => (int) $v['bytes'], 'at' => Carbon::createFromTimestamp((int) $v['at'])] : null;
    }

    // ── Almacén clave/valor durable ────────────────────────────────────────────────────────────────

    private static function get(string $key): mixed
    {
        try {
            $value = DB::table('waha_state')->where('key', $key)->value('value');

            return $value === null ? null : json_decode((string) $value, true);
        } catch (\Throwable $e) {
            self::warnMissingTables($e);

            return null;
        }
    }

    private static function put(string $key, mixed $value): void
    {
        try {
            DB::table('waha_state')->upsert([['key' => $key, 'value' => json_encode($value), 'updated_at' => now()]], ['key'], ['value', 'updated_at']);
        } catch (\Throwable $e) {
            self::warnMissingTables($e); // sin base no se persiste; las lecturas devolverán «sin dato»
        }
    }

    private static function forget(string $key): void
    {
        try {
            DB::table('waha_state')->where('key', $key)->delete();
        } catch (\Throwable) {
        }
    }

    private static function hash(string $digits): string
    {
        return hash_hmac('sha256', $digits, (string) (config('services.waha.contact_hash_key') ?: config('app.key')));
    }

    /** Un aviso (como mucho cada 5 min) si las tablas no existen o la base falla: tras desplegar hay que ejecutar `php artisan migrate --force`. */
    private static function warnMissingTables(\Throwable $e): void
    {
        try {
            if (Cache::add('waha:state:warned', 1, now()->addMinutes(5))) {
                Log::warning('[WhatsApp/WAHA] Estado durable no disponible (tablas waha_state/waha_contacts): ejecuta `php artisan migrate --force`.', ['error' => get_class($e)]);
            }
        } catch (\Throwable) {
        }
    }

    private static function cutoff(): Carbon
    {
        return now()->subDays((int) config('services.waha.contacted_ttl_days', 90));
    }
}
