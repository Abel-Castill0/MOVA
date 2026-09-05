<?php

namespace App\Channels;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Una sola responsabilidad: **un fallo de correo no debe tumbar la
 * notificación entera**.
 *
 * Una Notification de MOVA suele viajar por varios canales a la vez (database,
 * broadcast, mail, WhatsApp). Si el canal `mail` propagara su excepción, el job
 * fallaría y se reintentaría hasta 3 veces, reenviando TAMBIÉN los canales que
 * ya habían salido bien: el usuario recibiría la misma notificación in-app y el
 * mismo WhatsApp tres veces por un problema que solo afectaba al correo.
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
            Log::error('[Mail] No se pudo enviar el correo de la notificación.', [
                'notification' => class_basename($notification),
                'mailer' => $mailer,
                'error' => $e->getMessage(),
            ]);

            // Observable sin hacer fallar el job: los demás canales de esta
            // notificación ya salieron y no deben reenviarse.
            report($e);
        }
    }
}
