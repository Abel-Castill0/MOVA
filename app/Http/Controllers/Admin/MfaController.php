<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminMfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MfaController extends Controller
{
    public function __construct(private AdminMfaService $mfa) {}

    public function setup(Request $request)
    {
        $user = $request->user();

        if ($this->mfa->isEnrolled($user)) {
            return redirect()->route('admin.mfa.challenge');
        }

        $secret = $this->mfa->pendingSecret($request);

        return Inertia::render('Admin/Mfa/Setup', [
            'qrSvg'  => $this->mfa->qrCodeSvg($user, $secret),
            'secret' => $secret,
        ]);
    }

    public function confirm(Request $request)
    {
        $user = $request->user();
        abort_if($this->mfa->isEnrolled($user), 409);

        $data = $request->validate(['code' => 'required|string|max:32']);
        $this->throttle($request);

        $codes = $this->mfa->confirm($user, $request, $data['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Código inválido.']);
        }

        Log::info('admin_mfa.enrolled', ['user_id' => $user->id]);

        return redirect()->route('admin.mfa.recovery-codes')->with('recoveryCodes', $codes);
    }

    public function challenge(Request $request)
    {
        if (! $this->mfa->isEnrolled($request->user())) {
            return redirect()->route('admin.mfa.setup');
        }

        return Inertia::render('Admin/Mfa/Challenge');
    }

    public function verify(Request $request)
    {
        $user = $request->user();

        if (! $this->mfa->isEnrolled($user)) {
            return redirect()->route('admin.mfa.setup');
        }

        $data = $request->validate(['code' => 'required|string|max:32']);
        $this->throttle($request);

        if (! $this->mfa->verify($user, $data['code'])) {
            Log::warning('admin_mfa.failed', ['user_id' => $user->id, 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['code' => 'Código inválido.']);
        }

        RateLimiter::clear($this->throttleKey($request));
        $this->mfa->markVerified($request, $user);

        return redirect()->intended(route('dashboard'));
    }

    public function recoveryCodes(Request $request)
    {
        return Inertia::render('Admin/Mfa/RecoveryCodes', [
            'codes' => $request->session()->get('recoveryCodes', []),
        ]);
    }

    // Protegida con admin.mfa:sensitive en la ruta.
    public function regenerateRecoveryCodes(Request $request)
    {
        $codes = $this->mfa->regenerateRecoveryCodes($request->user());
        Log::info('admin_mfa.recovery_codes_regenerated', ['user_id' => $request->user()->id]);

        return redirect()->route('admin.mfa.recovery-codes')->with('recoveryCodes', $codes);
    }

    private function throttle(Request $request): void
    {
        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Demasiados intentos. Espera '.RateLimiter::availableIn($key).' segundos.',
            ]);
        }

        RateLimiter::hit($key, 300);
    }

    private function throttleKey(Request $request): string
    {
        return 'admin-mfa:'.$request->user()->id;
    }
}
