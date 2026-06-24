<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(RouteServiceProvider::HOME . '?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        $user = $request->user();

        // After email verification, prompt phone verification if user has a phone but hasn't verified it
        if ($user->phone && !$user->phone_verified_at && User::normalizePhone($user->phone)) {
            return redirect()->route('phone.verification.notice')
                ->with('status', 'email-verified');
        }

        return redirect()->intended(RouteServiceProvider::HOME . '?verified=1');
    }
}
