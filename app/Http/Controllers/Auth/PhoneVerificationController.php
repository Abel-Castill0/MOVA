<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class PhoneVerificationController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const CODE_TTL_MINUTES = 10;
    private const WELCOME_BONUS_CREDITS = 5;
    private const PHONE_RATE_LIMIT_PREFIX = 'whatsapp-otp-phone:';
    private const PHONE_RATE_LIMIT_MAX = 5;
    private const PHONE_RATE_LIMIT_DECAY_SECONDS = 3600;
    // Espera mínima entre dos códigos aceptados: evita que un doble clic o un «Reenviar» inmediato invaliden el código que
    // acaba de salir (cada código nuevo reemplaza al anterior).
    private const RESEND_COOLDOWN_SECONDS = 30;
    private const SENT_KEY = 'phone-otp-sent:';
    private const COOLDOWN_KEY = 'phone-otp-cooldown:';

    /**
     * C-P1-PHONE-VERIFICATION — ¿puede completarse hoy la verificación? El
     * único canal de entrega del OTP es WhatsApp; con WHATSAPP_ENABLED=false
     * ningún código llega en producción. En local/testing el código se
     * muestra en pantalla (ver send()), así que ahí el flujo sí funciona.
     * No cambia el proveedor: solo evita dirigir a una acción imposible.
     */
    public static function isAvailable(): bool
    {
        return (bool) config('services.whatsapp.enabled', false)
            || app()->environment('local', 'testing');
    }

    public function show(Request $request): Response
    {
        $user = $request->user();

        // Esta vista (GET) nunca envía: la pantalla pide el primer código por POST al montarse (`auto`), con los mismos
        // controles que «Reenviar código». `pending` evita un segundo código al recargar mientras el anterior sigue vigente.
        return Inertia::render('Auth/PhoneVerification', [
            'phone' => $this->maskPhone($user->phone),
            'available' => self::isAvailable(),
            'hasPhone' => filled($user->phone),
            'verified' => $user->phone_verified_at !== null,
            'pending' => $this->hasLiveCode($user),
            'cooldown' => $this->cooldownRemaining($user),
            // Intentos agotados con el código aún vigente: no se pide otro automáticamente (send() lo rechazaría).
            'locked' => $user->phone_verification_attempts >= self::MAX_ATTEMPTS
                && $user->phone_verification_expires_at && now()->lt($user->phone_verification_expires_at),
        ]);
    }

    /**
     * Único punto de envío del OTP: lo usan el primer envío automático (`auto=1`, al abrir la pantalla) y «Reenviar código».
     * Un bloqueo por usuario serializa clics y pestañas simultáneas; `auto` nunca reemplaza un código vigente ya aceptado.
     */
    public function send(Request $request): RedirectResponse
    {
        // 45 s > peor caso del camino WAHA (cupo 5 s + sesión 8 s + envío 12 s): el bloqueo no caduca con un envío en vuelo.
        $lock = Cache::lock('phone-otp-send:'.$request->user()->id, 45);
        if (! $lock->get()) {
            return back()->with('status', 'phone-verification-in-progress');
        }

        try {
            // refresh(): dentro del bloqueo se decide con el estado ya guardado por un envío concurrente.
            return $this->sendLocked($request->user()->refresh(), $request->boolean('auto'));
        } finally {
            $lock->release();
        }
    }

    private function sendLocked(User $user, bool $auto): RedirectResponse
    {
        // Antes: se generaba el código, se consumía el límite por número y se
        // respondía "intenta de nuevo en unos minutos" — un fallo presentado
        // como pasajero que nunca se resolvería.
        if (! self::isAvailable()) {
            return back()->withErrors(['phone' => 'La verificación por WhatsApp todavía no está habilitada en MOVA.']);
        }

        if ($user->phone_verified_at) {
            return back()->with('status', 'phone-already-verified');
        }

        $normalized = User::normalizePhone($user->phone);
        if (!$normalized) {
            return back()->withErrors(['phone' => 'Número de teléfono inválido. Por favor actualiza tu perfil.']);
        }

        // Chequeo amistoso, NO la garantía real (esa vive en verify(), con el
        // UNIQUE de phone_verified_normalized) — evita gastar un envío de
        // WhatsApp en un número que de todos modos no podría completar la
        // verificación. Hallazgo CRÍTICO de auditoría (2026-08-22): antes de
        // este fix, N cuentas podían verificar el mismo teléfono y cobrar el
        // bono de bienvenida N veces.
        if (\App\Models\ClosureBlocklist::blocks(null, $normalized)) {
            return back()->withErrors(['phone' => 'No es posible verificar este número. Escríbenos a soporte.']);
        }
        if (User::where('phone_verified_normalized', $normalized)->where('id', '!=', $user->id)->exists()) {
            return back()->withErrors(['phone' => 'Este número de teléfono ya está verificado en otra cuenta de MOVA.']);
        }

        // Primer envío automático idempotente y espera entre códigos (no consumen el límite por número).
        if ($auto && $this->hasLiveCode($user)) {
            return back()->with('status', 'phone-verification-pending');
        }
        if (($wait = $this->cooldownRemaining($user)) > 0) {
            return $auto
                ? back()->with('status', 'phone-verification-pending')
                : back()->withErrors(['resend' => "Acabamos de enviarte un código. Espera {$wait} s antes de pedir otro."]);
        }

        // El throttle:3,1 de la ruta es POR USUARIO AUTENTICADO — no protege
        // contra alguien creando N cuentas distintas para mandar mensajes al
        // MISMO número de teléfono (el número en sí no está verificado
        // todavía en ninguna, así que el chequeo de arriba no lo bloquea).
        // Este límite es POR NÚMERO, cruza cuentas.
        $phoneRateLimitKey = self::PHONE_RATE_LIMIT_PREFIX.$normalized;
        if (RateLimiter::tooManyAttempts($phoneRateLimitKey, self::PHONE_RATE_LIMIT_MAX)) {
            // El número está en la clave del RateLimiter, no debe replicarse
            // en logs/Sentry: para observabilidad basta el usuario solicitante.
            Log::warning('[PhoneVerification] Límite por número excedido.', ['user_id' => $user->id]);

            return back()->withErrors(['phone' => 'Se enviaron demasiados códigos a este número. Intenta de nuevo más tarde.']);
        }

        if ($user->phone_verification_attempts >= self::MAX_ATTEMPTS
            && $user->phone_verification_expires_at
            && now()->lt($user->phone_verification_expires_at)) {
            return back()->withErrors(['code' => 'Demasiados intentos. Espera 10 minutos para solicitar un nuevo código.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Escritura condicionada al teléfono leído: si el perfil lo cambió mientras tanto, este código (que iría al número
        // anterior) no se guarda ni se envía, así no puede verificar un número distinto del que lo recibió.
        $expiresAt = now()->addMinutes(self::CODE_TTL_MINUTES);
        $stored = User::whereKey($user->id)->where('phone', $user->phone)->whereNull('phone_verified_at')->update([
            'phone_verification_code_hash'   => Hash::make($code),
            'phone_verification_expires_at'  => $expiresAt,
            'phone_verification_attempts'    => 0,
        ]);
        if ($stored !== 1) {
            return back()->withErrors(['phone' => 'Tu número cambió mientras preparábamos el código. Vuelve a intentarlo.']);
        }
        $user->refresh();

        RateLimiter::hit($phoneRateLimitKey, self::PHONE_RATE_LIMIT_DECAY_SECONDS);
        $sent = $this->sendWhatsAppCode($normalized, $code, $user->name, $user->id);

        if ($sent) {
            Cache::put(self::SENT_KEY.$user->id, 1, $user->phone_verification_expires_at);
            Cache::put(self::COOLDOWN_KEY.$user->id, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->getTimestamp(), self::RESEND_COOLDOWN_SECONDS);
        } else {
            // Sin aceptación del proveedor no hay espera: la persona puede reintentar ya (sujeta a los límites de arriba).
            // El código guardado no se borra: si el resultado fue incierto podría haber llegado.
            Cache::forget(self::SENT_KEY.$user->id);
        }

        // En local/testing mostramos el código siempre, sin depender de $sent:
        // el proveedor puede "aceptar" el envío (sendTemplate() no lanza) y
        // aun así no entregarlo de forma asíncrona — si dependiéramos de
        // $sent, el fallback nunca se activaría en ese caso. Solo producción
        // confía en la respuesta real del proveedor.
        if (app()->environment('local', 'testing')) {
            return back()->with([
                'status'    => 'phone-verification-sent',
                'debugCode' => $code,
            ]);
        }

        if (!$sent) {
            return back()->withErrors(['phone' =>
                'No pudimos confirmar el envío del código por WhatsApp. Si no te llega en un minuto, pulsa «Reintentar envío».'
            ]);
        }

        return back()->with('status', 'phone-verification-sent');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|digits:6',
            // Casilla EXPLÍCITA, sujeta a validación real (no un default
            // silencioso). Antes esta ruta fijaba whatsapp_opt_in_at=now()
            // como efecto colateral de verificar el teléfono — es decir,
            // trataba "el usuario demostró controlar este número" como si
            // fuera "el usuario quiere que le escribamos". Son dos hechos
            // distintos: uno es autenticación, el otro es una decisión de
            // producto que le corresponde al usuario, no al sistema.
            'whatsapp_notifications' => 'sometimes|boolean',
        ]);

        $user = $request->user();

        if ($user->phone_verified_at) {
            return redirect()->intended(route('dashboard'));
        }

        if (!$user->phone_verification_code_hash || !$user->phone_verification_expires_at) {
            return back()->withErrors(['code' => 'No hay un código pendiente. Solicita uno nuevo.']);
        }

        if (now()->gt($user->phone_verification_expires_at)) {
            return back()->withErrors(['code' => 'El código ha expirado. Solicita uno nuevo.']);
        }

        if ($user->phone_verification_attempts >= self::MAX_ATTEMPTS) {
            return back()->withErrors(['code' => 'Demasiados intentos incorrectos. Solicita un nuevo código.']);
        }

        if (!Hash::check($request->code, $user->phone_verification_code_hash)) {
            $user->increment('phone_verification_attempts');
            $remaining = self::MAX_ATTEMPTS - $user->phone_verification_attempts;
            return back()->withErrors(['code' => "Código incorrecto. Te quedan {$remaining} intentos."]);
        }

        $normalized = User::normalizePhone($user->phone);
        if (!$normalized) {
            return back()->withErrors(['phone' => 'Número de teléfono inválido. Por favor actualiza tu perfil.']);
        }

        // GARANTÍA REAL contra teléfono duplicado (hallazgo CRÍTICO de
        // auditoría, 2026-08-22): el UNIQUE de phone_verified_normalized, no
        // el chequeo amistoso de send() — mismo patrón que idempotency_key en
        // el ledger. phone_verified_normalized está deliberadamente FUERA de
        // $fillable, así que se asigna directo y no vía update() con mass
        // assignment (mismo motivo que credits_settled_at en Lesson).
        $user->phone_verified_at = now();

        // Consentimiento de notificaciones — EXPLÍCITO, no inferido.
        //
        // Verificar el teléfono es autenticación: demuestra que el usuario
        // controla ese número. NO demuestra que quiera recibir avisos en él.
        // Antes esta línea fijaba el opt-in automáticamente para todo el que
        // completara la verificación; ahora depende de una casilla real que el
        // usuario marca en Auth/PhoneVerification.vue, sin preseleccionar.
        //
        // El OTP mismo (arriba, sendWhatsAppCode) nunca dependió de esto y
        // sigue sin depender: es el mensaje que el propio usuario pidió al
        // pulsar «enviar código», no una notificación opcional.
        $optedInHere = $request->boolean('whatsapp_notifications') && $user->whatsapp_opt_out_at === null && $user->whatsapp_opt_in_at === null;
        if ($optedInHere) {
            $user->whatsapp_opt_in_at = now();
        }

        $user->phone_verification_code_hash = null;
        $user->phone_verification_expires_at = null;
        $user->phone_verification_attempts = 0;
        $user->phone_verified_normalized = $normalized;

        try {
            $user->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return back()->withErrors([
                'code' => 'Este número de teléfono ya está verificado en otra cuenta de MOVA.',
            ]);
        }

        Cache::forget(self::SENT_KEY.$user->id);
        Cache::forget(self::COOLDOWN_KEY.$user->id);

        if ($optedInHere) {
            try {
                \App\WhatsApp\WhatsAppConsent::log($user, 'opt_in', 'phone_verification');
            } catch (\Throwable $e) {
                report($e); // la verificación ya se guardó; el hueco en el historial queda visible en Sentry
            }
        }

        $this->grantTeacherWelcomeBonus($user);

        return redirect()->intended(route('dashboard'))->with('status', 'phone-verified');
    }

    private function grantTeacherWelcomeBonus(User $user): void
    {
        if (!$user->hasRole('teacher')) {
            return;
        }

        DB::transaction(function () use ($user) {
            // User before TeacherProfile (same order as account closure): a closed account gets no new credits.
            $owner = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $owner || $owner->account_closed_at !== null) {
                return;
            }
            $teacher = TeacherProfile::where('user_id', $user->id)->lockForUpdate()->first();

            if (!$teacher) {
                return;
            }

            $welcomeBonusExists = $teacher->creditTransactions()
                ->where(function ($query) use ($teacher) {
                    $query->where('idempotency_key', "teacher:{$teacher->id}:welcome")
                        ->orWhere(function ($legacy) {
                            $legacy->where('type', 'deposit')
                                ->where('description', 'Bono de bienvenida MOVA');
                        });
                })
                ->exists();

            if ($welcomeBonusExists) {
                return;
            }

            $teacher->update([
                'credits_available' => $teacher->credits_available + self::WELCOME_BONUS_CREDITS,
            ]);

            $teacher->creditTransactions()->create([
                'idempotency_key' => "teacher:{$teacher->id}:welcome",
                'type'        => 'deposit',
                'amount'      => self::WELCOME_BONUS_CREDITS,
                'description' => 'Bono de bienvenida MOVA',
            ]);
        });
    }

    private function sendWhatsAppCode(string $to, string $code, string $name, int $userId): bool
    {
        if (!config('services.whatsapp.enabled', false)) {
            Log::debug('[PhoneVerification] WhatsApp deshabilitado globalmente — código no enviado.');
            return false;
        }

        // Plantilla de categoría "authentication" en Meta — separada de la
        // genérica que usa WhatsAppChannel::send() para notificaciones (ver
        // config/services.php y docs/whatsapp-architecture.md). Un solo
        // parámetro: el código de 6 dígitos. client_reference NO incluye el
        // código en sí — solo el id de usuario, para poder rastrear en
        // whatsapp_messages sin dejar el OTP en un campo de auditoría.
        return app(\App\WhatsApp\Contracts\WhatsAppProviderContract::class)
            ->sendTemplate($to, 'phone_verification_code', [$code], "phone_verification:{$userId}");
    }

    /** Hay un código aceptado por el proveedor, sin caducar y con intentos disponibles. */
    private function hasLiveCode(User $user): bool
    {
        return Cache::has(self::SENT_KEY.$user->id)
            && $user->phone_verification_code_hash
            && $user->phone_verification_expires_at
            && now()->lt($user->phone_verification_expires_at)
            && $user->phone_verification_attempts < self::MAX_ATTEMPTS;
    }

    private function cooldownRemaining(User $user): int
    {
        // Sin código guardado (p. ej. tras cambiar el teléfono) no hay nada que proteger: no se hace esperar.
        if (! $user->phone_verification_code_hash) {
            return 0;
        }
        $until = (int) Cache::get(self::COOLDOWN_KEY.$user->id, 0);

        return max(0, $until - now()->getTimestamp());
    }

    private function maskPhone(?string $phone): string
    {
        if (!$phone || strlen($phone) < 4) return '****';
        return '****' . substr($phone, -4);
    }
}
