<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Mensaje de prueba del panel de administración. Viaja SIEMPRE por WhatsAppChannel (consentimiento, baja, teléfono
 * verificado, suspensión, cuenta cerrada, lista del piloto, dedupe y límites): el panel ya no puede saltarse nada de eso.
 * Solo canal WhatsApp: no deja rastro en la base de datos ni envía correo.
 */
class WhatsAppTestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private string $text)
    {
    }

    public function via($notifiable): array
    {
        return [\App\Channels\WhatsAppChannel::class];
    }

    public function toWhatsApp($notifiable): string
    {
        return "MOVA — Mensaje de prueba\n\n" . $this->text;
    }
}
