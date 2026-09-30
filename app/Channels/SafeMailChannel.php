<?php

namespace App\Channels;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Una sola responsabilidad: **decidir qué hace un fallo de correo según los
 * canales de la notificación**.
 *
 *  - MULTICANAL (database, broadcast, mail, WhatsApp...): un fallo de correo NO
 *    debe tumbar la notificación. Si el canal `mail` propagara su excepción, el
 *    job se reintentaría y reenviaría TAMBIÉN los canales que ya habían salido
 *    bien (la misma notificación in-app o el mismo WhatsApp varias veces por un
 *    problema que solo afectaba al correo). Se registra, se reporta y se sigue.
 *
 *  - SOLO CORREO (`via()` == ['mail']: constancia del Libro de Reclamaciones,
 *    bienvenida, verificación de email, recuperación de contraseña): no hay
 *    ningún otro canal que duplicar, y tragar el fallo dejaría el correo
 *    perdido para siempre con el job «exitoso». Aquí la excepción se RELANZA:
 *    en cola el job falla y usa los reintentos del worker (`--tries`,
 *    `--backoff`) hasta `failed_jobs`; en flujos síncronos el llamador recibe
 *    el error en vez de darlo por enviado.
 *
 * El conjunto de canales se lee de `$notification->via($notifiable)`, el mismo
 * método que usa NotificationSender. Laravel no pasa el contexto de canales a
 * MailChannel, así que se llama una vez, solo en el camino de fallo (los `via()`
 * de MOVA son funciones puras de atributos del notifiable).
 *
 * H-05 — QUÉ SE QUITÓ DE AQUÍ Y POR QUÉ:
 *
 * Esta clase contenía además todo un enrutador de mailers escrito a mano:
 * interceptaba `gmail_api` (que no era un mailer real), llamaba a la Gmail API
 * por su cuenta, y ante un fallo reasignaba `config(['mail.default' => 'smtp'])`
 * en caliente para reenviar. Eso significaba dos caminos de envío mantenidos a
 * mano y una configuración que el framework no entendía.
 *
 * Ahora `gmail_api` es un transporte de verdad
 * (App\Mail\Transport\GmailApiTransport) y el respaldo se declara con el mailer
 * `failover` nativo, que solo pasa al siguiente transporte cuando el anterior
 * LANZA — imposible el doble envío. Este canal ya no decide nada sobre
 * proveedores: delega en el pipeline estándar.
 *
 * R-08 — QUÉ SE AÑADIÓ:
 *
 * Antes el `catch` solo escribía un `Log::error`. Un correo perdido no llegaba
 * ni a `failed_jobs` (porque el job no falla, deliberadamente) ni a Sentry
 * (porque Sentry no estaba enganchado, H-01). Era literalmente invisible.
 * Ahora se llama a `report()`, que desde H-01 sí llega a Sentry: el correo
 * perdido sigue sin romper la notificación, pero deja de ser invisible.
 */
class SafeMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        $mailer = config('mail.default');

        // `array` y `log` son mailers de prueba: no hay nada que enviar ni nada
        // que pueda fallar, y saltarlos evita ruido en la suite.
        if (in_array($mailer, ['array', 'log'], true)) {
            return;
        }

        try {
            return parent::send($notifiable, $notification);
        } catch (Throwable $e) {
            $mailOnly = $this->isMailOnly($notifiable, $notification);

            // Sin dirección de destino ni mensaje de la excepción (los
            // transportes pueden incluir el destinatario): solo la clase.
            Log::error('[Mail] No se pudo enviar el correo de la notificación.', [
                'notification' => class_basename($notification),
                'mailer' => $mailer,
                'exception' => $e::class,
                'mail_only' => $mailOnly,
            ]);

            if ($mailOnly) {
                // Se relanza SIN report(): quien la recibe (worker de cola o
                // handler HTTP) ya la reporta a Sentry; reportarla aquí la
                // duplicaría en cada intento.
                throw $e;
            }

            // Multicanal: observable sin hacer fallar el job; los demás canales
            // ya salieron y no deben reenviarse.
            report($e);
        }
    }

    /**
     * ¿Es el correo el ÚNICO canal de esta notificación? Ante cualquier duda
     * (via() lanza o devuelve algo raro) se asume multicanal: es la opción que
     * jamás duplica canales ya entregados.
     */
    private function isMailOnly(mixed $notifiable, Notification $notification): bool
    {
        try {
            $channels = array_unique(array_map(
                fn ($channel) => is_string($channel) ? $channel : (is_object($channel) ? $channel::class : ''),
                array_values((array) $notification->via($notifiable))
            ));
        } catch (Throwable) {
            return false;
        }

        return $channels === ['mail'];
    }
}
