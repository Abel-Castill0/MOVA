<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Preferencias de notificación del usuario.
 *
 * Existe por el mismo motivo que `whatsapp_opt_in_at`: MOVA debe poder
 * demostrar por qué envía un WhatsApp a una persona, y esa persona debe poder
 * revocarlo sin tener que invalidar su número de teléfono — que es lo que
 * pasaría si el consentimiento y la verificación fueran el mismo campo.
 *
 * Darse de baja NO afecta al código de verificación (OTP): ese mensaje lo pide
 * el propio usuario y no pasa por WhatsAppChannel.
 */
class NotificationPreferencesController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate(['whatsapp' => 'required|boolean']);

        $user = $request->user();

        if ($data['whatsapp']) {
            $user->optInToWhatsApp();

            return back()->with('success', 'Recibirás avisos de tus clases por WhatsApp.');
        }

        $user->optOutOfWhatsApp();

        return back()->with('success', 'Ya no recibirás notificaciones por WhatsApp. Seguirás recibiéndolas por correo.');
    }
}
