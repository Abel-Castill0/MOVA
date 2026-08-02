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
use Inertia\Inertia;
use Inertia\Response;

class PhoneVerificationController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const CODE_TTL_MINUTES = 10;
    private const WELCOME_BONUS_CREDITS = 5;

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

        $sent = $this->sendWhatsAppCode($normalized, $code, $user->name);

        if (!$sent) {
            if (app()->environment('local', 'testing')) {
                return back()->with([
                    'status'    => 'phone-verification-sent',
                    'debugCode' => $code,
                ]);
            }

            return back()->withErrors(['phone' =>
                'No se pudo enviar el código por WhatsApp. Si tu número no está unido al Sandbox de Twilio, ' .
                'envía "join <sandbox-code>" al número de Twilio desde tu WhatsApp.'
            ]);
        }

        return back()->with('status', 'phone-verification-sent');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|digits:6']);

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

        $user->update([
            'phone_verified_at'              => now(),
            'phone_verification_code_hash'   => null,
            'phone_verification_expires_at'  => null,
            'phone_verification_attempts'    => 0,
        ]);

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

    private function sendWhatsAppCode(string $to, string $code, string $name): bool
    {
        $sid   = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from  = config('services.twilio.whatsapp_from');

        if (!$sid || !$token || !$from) {
            Log::warning('[PhoneVerification] Twilio no configurado — código no enviado.');
            return false;
        }

        try {
            $client = new \Twilio\Rest\Client($sid, $token);
            $client->messages->create("whatsapp:{$to}", [
                'from' => "whatsapp:{$from}",
                'body' => "MOVA — Tu código de verificación es: *{$code}*\n\nVálido por 10 minutos. No lo compartas con nadie.",
            ]);
            return true;
        } catch (\Twilio\Exceptions\RestException $e) {
            if ($e->getStatusCode() === 63007) {
                Log::warning('[PhoneVerification] Número no unido al Sandbox de Twilio.');
            } else {
                Log::error('[PhoneVerification] Error Twilio ' . $e->getStatusCode() . ': ' . $e->getMessage());
            }
            return false;
        } catch (\Throwable $e) {
            Log::error('[PhoneVerification] Error inesperado: ' . $e->getMessage());
            return false;
        }
    }

    private function maskPhone(?string $phone): string
    {
        if (!$phone || strlen($phone) < 4) return '****';
        return '****' . substr($phone, -4);
    }
}
