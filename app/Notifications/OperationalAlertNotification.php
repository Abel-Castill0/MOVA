<?php

namespace App\Notifications;

use App\Models\OperationalAlert;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a los administradores de que hay una incidencia operativa que exige
 * decisión humana.
 *
 * SOLO se envía una vez por episodio: la deduplicación no vive aquí sino en
 * App\Services\OperationalAlertService, que es el único que instancia esta
 * notificación y solo lo hace cuando `notified_at` está vacío.
 *
 * `$afterCommit = true` es OBLIGATORIO, no decorativo: raise() se llama desde
 * dentro de transacciones financieras (markReview() vive dentro de la
 * transacción de reconciliación). Sin esto, el worker podría recoger el job y
 * leer una fila `operational_alerts` que todavía no ha hecho commit —o que
 * nunca lo hará, si la transacción termina revirtiéndose— y avisar de una
 * incidencia que no existe.
 *
 * DELIBERADAMENTE SIN WhatsApp: es un canal de coste por mensaje y con
 * plantillas aprobadas por Meta; una incidencia técnica no justifica gastarlo.
 * Correo + campana in-app son suficientes para operación.
 */
class OperationalAlertNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public OperationalAlert $alert)
    {
        // Vía el método del trait Queueable, no redeclarando la propiedad:
        // Queueable ya declara `public $afterCommit` sin tipo, y volver a
        // declararla aquí con tipo es un conflicto de composición de traits.
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isCritical = $this->alert->severity === OperationalAlert::SEVERITY_CRITICAL;
        $prefix = $isCritical ? '[CRÍTICO] ' : '';

        $mail = (new MailMessage)
            ->subject($prefix.'Incidencia operativa en MOVA: '.$this->alert->title)
            ->greeting('Hola, Administrador.')
            ->line($this->alert->message);

        // El contexto es para diagnóstico (ids, estados, importes esperados) y
        // lo construye siempre código de MOVA, nunca entrada de usuario. Aun así
        // se listan solo escalares: nada de volcar estructuras anidadas ni
        // payloads completos de proveedor en un correo.
        foreach ($this->alert->context ?? [] as $label => $value) {
            if (is_scalar($value) || $value === null) {
                $mail->line('**'.$label.':** '.($value ?? '—'));
            }
        }

        return $mail
            ->action('Ver panel de administración', $this->appRoute('dashboard'))
            ->line('Esta incidencia requiere una decisión humana: MOVA no la corrige sola a propósito.')
            ->salutation('El equipo de MOVA');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'operational_alert',
            'alert_id' => $this->alert->id,
            'alert_key' => $this->alert->alert_key,
            'alert_type' => $this->alert->type,
            'severity' => $this->alert->severity,
            'message' => $this->alert->title,
        ];
    }
}
