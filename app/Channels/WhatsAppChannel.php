<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    public function send($notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toWhatsApp')) return;

        $to = $notifiable->routeNotificationFor('WhatsApp', $notification);
        if (!$to) {
            Log::debug('[WhatsApp] Skipped: no phone number for ' . class_basename($notifiable) . ' #' . $notifiable->getKey());
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        $sid   = env('TWILIO_SID');
        $token = env('TWILIO_AUTH_TOKEN');
        $from  = env('TWILIO_WHATSAPP_FROM');

        if (!$sid || !$token || !$from) {
            Log::warning('[WhatsApp] Credenciales Twilio no configuradas en .env');
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
                Log::error('[WhatsApp] Credenciales de Twilio inválidas. Verifica TWILIO_SID y TWILIO_AUTH_TOKEN en .env.');
            } else {
                Log::error('[WhatsApp] Error Twilio ' . $code . ': ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('[WhatsApp] Error inesperado: ' . $e->getMessage());
        }
    }
}
