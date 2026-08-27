<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(Request $request): Response
    {
        return Inertia::render('Auth/PhoneVerification', [
            'phone' => $this->maskPhone($request->user()->phone),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

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
        if (User::where('phone_verified_normalized', $normalized)->where('id', '!=', $user->id)->exists()) {
            return back()->withErrors(['phone' => 'Este número de teléfono ya está verificado en otra cuenta de MOVA.']);
        }

        // El throttle:3,1 de la ruta es POR USUARIO AUTENTICADO — no protege
        // contra alguien creando N cuentas distintas para mandar mensajes al
        // MISMO número de teléfono (el número en sí no está verificado
        // todavía en ninguna, así que el chequeo de arriba no lo bloquea).
        // Este límite es POR NÚMERO, cruza cuentas.
        $phoneRateLimitKey = self::PHONE_RATE_LIMIT_PREFIX.$normalized;
        if (RateLimiter::tooManyAttempts($phoneRateLimitKey, self::PHONE_RATE_LIMIT_MAX)) {
            Log::warning('[PhoneVerification] Límite por número excedido.', ['phone' => $normalized]);

            return back()->withErrors(['phone' => 'Se enviaron demasiados códigos a este número. Intenta de nuevo más tarde.']);
        }

        if ($user->phone_verification_attempts >= self::MAX_ATTEMPTS
            && $user->phone_verification_expires_at
            && now()->lt($user->phone_verification_expires_at)) {
            return back()->withErrors(['code' => 'Demasiados intentos. Espera 10 minutos para solicitar un nuevo código.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->update([
            'phone_verification_code_hash'   => Hash::make($code),
            'phone_verification_expires_at'  => now()->addMinutes(self::CODE_TTL_MINUTES),
            'phone_verification_attempts'    => 0,
        ]);

        RateLimiter::hit($phoneRateLimitKey, self::PHONE_RATE_LIMIT_DECAY_SECONDS);
        $sent = $this->sendWhatsAppCode($normalized, $code, $user->name, $user->id);

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
                'No se pudo enviar el código por WhatsApp en este momento. Intenta de nuevo en unos minutos.'
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
        if ($request->boolean('whatsapp_notifications') && $user->whatsapp_opt_out_at === null) {
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

        $this->grantTeacherWelcomeBonus($user);

        return redirect()->intended(route('dashboard'))->with('status', 'phone-verified');
    }

    private function grantTeacherWelcomeBonus(User $user): void
    {
        if (!$user->hasRole('teacher')) {
            return;
        }

        DB::transaction(function () use ($user) {
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

    private function maskPhone(?string $phone): string
    {
        if (!$phone || strlen($phone) < 4) return '****';
        return '****' . substr($phone, -4);
    }
}
