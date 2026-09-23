<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * P0-C — MFA TOTP para administradores.
 *
 * TOTP/QR delegados a pragmarx/google2fa y bacon/bacon-qr-code (el mismo
 * núcleo que usa laravel/fortify, sin arrastrar su stack de passkeys). Lo
 * único propio es el estado de sesión y los recovery codes, que se guardan
 * HASHEADOS (bcrypt) dentro de una columna cifrada: ni un dump de la DB ni
 * un APP_KEY filtrado por separado bastan para reutilizarlos.
 */
class AdminMfaService
{
    public const SESSION_KEY = 'admin_mfa';

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private Google2FA $google2fa = new Google2FA()) {}

    public function isEnrolled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    /** Secret pendiente de confirmar; se guarda en sesión, no en la DB, hasta que el admin demuestre que lo escaneó. */
    public function pendingSecret(Request $request): string
    {
        $secret = $request->session()->get('admin_mfa_pending_secret');

        if (! is_string($secret) || $secret === '') {
            $secret = $this->google2fa->generateSecretKey(32);
            $request->session()->put('admin_mfa_pending_secret', $secret);
        }

        return $secret;
    }

    public function qrCodeSvg(User $user, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(config('app.name', 'MOVA'), $user->email, $secret);

        return (new Writer(new ImageRenderer(new RendererStyle(192, 0), new SvgImageBackEnd())))->writeString($url);
    }

    /**
     * Confirma el enrolamiento. Devuelve los recovery codes en claro UNA sola
     * vez (para mostrarlos al admin); en DB solo quedan sus hashes.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, Request $request, string $code): ?array
    {
        $secret = $request->session()->get('admin_mfa_pending_secret');

        if (! is_string($secret) || $secret === '' || ! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        // bcrypt fuera del lock: no alargar la sección crítica.
        $codes = $this->newRecoveryCodes();
        $hashes = array_map(fn ($c) => Hash::make($c), $codes);

        // Misma serialización que verify(): dos confirms concurrentes no
        // pueden enrolar dos veces (el segundo vería el primero ya
        // confirmado y el timestep ya consumido) ni pisar los recovery codes
        // que el primero ya mostró.
        $enrolled = DB::transaction(function () use ($user, $secret, $code, $hashes) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($this->isEnrolled($locked)) {
                return false;
            }

            $timestep = $this->acceptedTimestep($locked, $secret, $code);
            if ($timestep === null) {
                return false;
            }

            $locked->forceFill([
                'two_factor_secret'             => $secret,
                'two_factor_recovery_codes'     => $hashes,
                'two_factor_confirmed_at'       => now(),
                'two_factor_last_used_timestep' => $timestep,
            ])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if (! $enrolled) {
            return null;
        }

        $request->session()->forget('admin_mfa_pending_secret');
        $this->markVerified($request, $user);

        return $codes;
    }

    /** Verifica un TOTP o, si no lo es, consume un recovery code (un solo uso). */
    public function verify(User $user, string $code): bool
    {
        $code = trim($code);

        if (preg_match('/^\d{6}$/', $code)) {
            return $this->verifyTotp($user, $code);
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();

        return $codes;
    }

    public function reset(User $user): void
    {
        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ])->save();
    }

    public function markVerified(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, ['user_id' => $user->id, 'at' => now()->getTimestamp()]);
    }

    /** Segundos desde la última verificación MFA de ESTE usuario en esta sesión, o null. */
    public function secondsSinceVerified(Request $request, User $user): ?int
    {
        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ($state['user_id'] ?? null) !== $user->id || ! is_int($state['at'] ?? null)) {
            return null;
        }

        return max(0, now()->getTimestamp() - $state['at']);
    }

    /**
     * P0-01 — anti-replay TOTP atómico entre workers: el secret y el último
     * timestep aceptado se leen de la fila BLOQUEADA (SELECT … FOR UPDATE) y
     * el nuevo timestep se persiste en la misma transacción. Dos requests con
     * el mismo código serializan sobre la fila; la segunda ve el timestep ya
     * consumido y verifyKeyNewer la rechaza. Sin cache ni estado de proceso.
     */
    private function verifyTotp(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $timestep = $this->acceptedTimestep($locked, (string) $locked->two_factor_secret, $code);

            if ($timestep === null) {
                return false;
            }

            $locked->forceFill(['two_factor_last_used_timestep' => $timestep])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** Timestep del código si es válido y POSTERIOR al último usado; null si no. Llamar con la fila bloqueada. */
    private function acceptedTimestep(User $locked, string $secret, string $code): ?int
    {
        if ($secret === '' || ! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $timestep = $this->google2fa->verifyKeyNewer($secret, $code, (int) ($locked->two_factor_last_used_timestep ?? 0), 1);

        return $timestep === false ? null : (int) $timestep;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        // lockForUpdate: dos requests concurrentes con el mismo código no
        // pueden consumirlo ambas.
        return DB::transaction(function () use ($user, $code) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $hashes = $locked->two_factor_recovery_codes ?? [];

            foreach ($hashes as $i => $hash) {
                if (Hash::check($code, $hash)) {
                    unset($hashes[$i]);
                    $locked->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                    $user->setRawAttributes($locked->getAttributes(), true);

                    return true;
                }
            }

            return false;
        });
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(
            fn () => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }
}
