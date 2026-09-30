<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\MailDeliveryException;
use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (MailDeliveryException $e) {
            report($e);

            return back()->with('error', 'No pudimos enviar el correo de verificación. Inténtalo de nuevo en unos momentos.');
        }

        return back()->with('status', 'verification-link-sent');
    }
}
