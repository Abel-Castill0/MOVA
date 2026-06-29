<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    public function send($notifiable, Notification $notification): void
    {
        // Global kill switch — default off until WhatsApp is in production
        if (!config('services.whatsapp.enabled', false)) {
            Log::debug('[WhatsApp] Disabled globally (WHATSAPP_ENABLED=false)');
            return;
        }

        if (!method_exists($notification, 'toWhatsApp')) return;

        // Require verified phone when configured
        if (config('services.whatsapp.require_verified', true)) {
            $verifiedAt = $notifiable->phone_verified_at ?? null;
            if (!$verifiedAt) {
                Log::debug('[WhatsApp] Skipped: phone not verified for ' . class_basename($notifiable) . ' #' . $notifiable->getKey());
                return;
            }
        }

        $to = $notifiable->routeNotificationFor('WhatsApp', $notification);
        if (!$to) {
            Log::debug('[WhatsApp] Skipped: no phone number for ' . class_basename($notifiable) . ' #' . $notifiable->getKey());
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        $sid   = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from  = config('services.twilio.whatsapp_from');

        if (!$sid || !$token || !$from) {
            Log::warning('[WhatsApp] Credenciales Twilio no configuradas (TWILIO_SID, TWILIO_AUTH_TOKEN, TWILIO_WHATSAPP_FROM)');
            return;
        }

        try {
            $client = new \Twilio\Rest\Client($sid, $token);
            $result = $client->messages->create("whatsapp:{$to}", [
                'from' => "whatsapp:{$from}",
                'body' => $message,
            ]);
            Log::info('[WhatsApp] Enviado', ['sid' => $result->sid, 'to' => $to]);
        } catch (\Twilio\Exceptions\RestException $e) {
            $code = $e->getStatusCode();
            if ($code === 63007) {
                Log::error('[WhatsApp] El número destino no está unido al Sandbox de Twilio. ' .
                    'Envía "join <sandbox-code>" al número ' . $from . ' desde WhatsApp.');
            } elseif ($code === 20003) {
                Log::error('[WhatsApp] Credenciales de Twilio inválidas. Verifica TWILIO_SID y TWILIO_AUTH_TOKEN en las variables de entorno.');
            } else {
                Log::error('[WhatsApp] Error Twilio ' . $code . ': ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('[WhatsApp] Error inesperado: ' . $e->getMessage());
        }
    }
}
