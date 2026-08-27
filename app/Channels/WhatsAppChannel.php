<?php

namespace App\Channels;

use App\Models\WhatsAppMessage;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use App\WhatsApp\WhatsAppMessageStatus;
use App\WhatsApp\WhatsAppSkipReason;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Política formal AUTHENTICATION vs. UTILITY (categorías de plantilla de
 * Meta) — hasta ahora implícita en el código, formalizada aquí a pedido de
 * revisión explícita:
 *
 *   - AUTHENTICATION (el OTP de verificación de teléfono): NUNCA pasa por
 *     este canal. Lo envía PhoneVerificationController directamente contra
 *     el proveedor, sin consultar opt-in NI `suspended_at` — ni la ruta HTTP
 *     que lo dispara (`verify-phone/send`) lleva el middleware
 *     `not.suspended`. Es deliberado: una cuenta suspendida puede necesitar
 *     verificar su teléfono como parte de una apelación o de soporte, y el
 *     OTP es el mecanismo para demostrar control de un número, no una
 *     notificación de negocio opcional.
 *   - UTILITY (todo lo que pasa por WhatsAppChannel::send() — recordatorios,
 *     confirmaciones, recargas): sujeto a AMBOS gates, cuenta activa y
 *     consentimiento. Esta es la única superficie que esta clase controla.
 *
 * Los dos gates nunca deben fusionarse ni aplicarse al OTP.
 */
class WhatsAppChannel
{
    public function send($notifiable, Notification $notification): void
    {
        // Global kill switch — default off until WhatsApp is in production.
        // Deliberadamente SIN fila de auditoría aquí: en este estado nada se
        // intenta para NADIE, así que registrar una fila por cada
        // notificación durante todo el tiempo que el canal esté apagado
        // sería ruido puro, no una decisión sobre un destinatario concreto.
        if (!config('services.whatsapp.enabled', false)) {
            Log::debug('[WhatsApp] Disabled globally (WHATSAPP_ENABLED=false)');
            return;
        }

        if (!method_exists($notification, 'toWhatsApp')) return;

        $clientReference = class_basename($notification).'#'.$notifiable->getKey();

        // Require verified phone when configured. Sin fila de auditoría aquí
        // a propósito: no hay un `to` válido que registrar (whatsapp_messages.to
        // no es nullable), y "no sabemos cómo contactarlo" no es la ambigüedad
        // que este cambio existe para resolver — esa es la del opt-out, más
        // abajo, donde SÍ hay un destinatario válido y la decisión es nuestra.
        if (config('services.whatsapp.require_verified', true)) {
            $verifiedAt = $notifiable->phone_verified_at ?? null;
            if (!$verifiedAt) {
                Log::debug('[WhatsApp] Skipped: phone not verified for ' . class_basename($notifiable) . ' #' . $notifiable->getKey());
                return;
            }
        }

        // Cuenta suspendida: nada proactivo debe seguir saliendo hacia
        // alguien cuya cuenta está bajo suspensión (investigación de
        // abuso/fraude, o la anonimización de auto-borrado — ver
        // ProfileController::destroy(), que también fija suspended_at). El
        // middleware EnsureNotSuspended solo protege las rutas HTTP que ESE
        // usuario visita; no protege los jobs en segundo plano que le
        // ESCRIBEN A él (recordatorios, confirmaciones) — son dos superficies
        // distintas y hasta esta ronda solo la primera estaba cubierta.
        // Independiente del opt-in: la suspensión es un gate de cuenta, no
        // una preferencia de notificaciones. SÍ se audita — a diferencia del
        // kill-switch global, aquí hay un destinatario concreto y una
        // decisión sobre él.
        if (($notifiable->suspended_at ?? null) !== null) {
            $to = $notifiable->routeNotificationFor('WhatsApp', $notification);
            Log::debug('[WhatsApp] Skipped: cuenta suspendida para '.class_basename($notifiable).' #'.$notifiable->getKey());

            if ($to) {
                $this->logSkip($clientReference, $to, WhatsAppSkipReason::Suspended);
            }

            return;
        }

        // Consentimiento explícito para NOTIFICACIONES. Separado a propósito de
        // `phone_verified_at`: verificar que alguien controla un número no es
        // lo mismo que aceptar recibir mensajes en él, y MOVA debe poder
        // justificar por qué escribe a una persona concreta.
        //
        // El OTP de verificación NO pasa por aquí (lo envía
        // PhoneVerificationController directamente), así que este gate no puede
        // bloquear el paso donde precisamente se obtiene el consentimiento.
        //
        // A DIFERENCIA de los otros "skip" de este método, este SÍ deja fila
        // en whatsapp_messages (WhatsAppMessageStatus::Skipped). No es un
        // fallo del proveedor — Meta nunca llegó a verse involucrado — es una
        // decisión de MOVA sobre UN destinatario concreto, y esa distinción
        // es justo lo que un status "failed" o el silencio total no podían
        // comunicar: alguien investigando "¿por qué no le llegó el aviso a
        // este padre?" ahora tiene una fila que responde "porque no había
        // consentimiento", no una ausencia total de rastro.
        if (method_exists($notifiable, 'wantsWhatsAppNotifications')
            && !$notifiable->wantsWhatsAppNotifications()
        ) {
            $to = $notifiable->routeNotificationFor('WhatsApp', $notification);
            Log::debug('[WhatsApp] Skipped: sin consentimiento para '.class_basename($notifiable).' #'.$notifiable->getKey());

            if ($to) {
                $this->logSkip($clientReference, $to, WhatsAppSkipReason::OptOut);
            }

            return;
        }

        $to = $notifiable->routeNotificationFor('WhatsApp', $notification);
        if (!$to) {
            Log::debug('[WhatsApp] Skipped: no phone number for ' . class_basename($notifiable) . ' #' . $notifiable->getKey());
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        // Meta Cloud API (ver docs/whatsapp-architecture.md): un mensaje de
        // negocio hacia el cliente fuera de la ventana de 24h de servicio
        // — que es prácticamente todo lo que envía MOVA (recordatorios,
        // confirmaciones, recargas) — SOLO puede ir como plantilla
        // aprobada, nunca como texto libre. Ninguna de las 21 notificaciones
        // existentes se reescribió para esta ronda: todas siguen generando
        // el mismo texto de siempre vía toWhatsApp(), y ese texto se envía
        // como el único parámetro de una plantilla "utility" genérica
        // (generic_notification). Migrar a plantillas propias más ricas por
        // notificación es trabajo futuro, no una regresión de esta ronda.
        $sent = app(WhatsAppProviderContract::class)->sendTemplate($to, 'generic_notification', [$message], $clientReference);

        if ($sent) {
            Log::info('[WhatsApp] Enviado', ['to' => $to, 'notification' => class_basename($notification)]);
        }
    }

    /**
     * Fila de auditoría para un envío que MOVA decidió no intentar. Nunca
     * debe hacer fallar el flujo de notificación por sí misma — el skip ya
     * se decidió, escribir el rastro es secundario a eso.
     *
     * `skip_reason` estructurado (App\WhatsApp\WhatsAppSkipReason), no
     * `error`: `error` queda reservado para fallos REALES del proveedor
     * (status=failed) — un skip nunca llega a Meta, así que nunca debería
     * tener un error suyo. Esto reemplaza la decisión anterior de reutilizar
     * `error` como texto libre, válida solo mientras existiera un único
     * motivo de skip — F-22 introdujo un segundo motivo real (suspendido,
     * distinto de opt-out), así que dejó de serlo.
     */
    private function logSkip(string $clientReference, ?string $to, WhatsAppSkipReason $reason): void
    {
        try {
            WhatsAppMessage::create([
                'to' => $to,
                'template_key' => 'generic_notification',
                'client_reference' => $clientReference,
                'provider' => config('services.whatsapp.provider', 'fake'),
                'provider_message_id' => null,
                'status' => WhatsAppMessageStatus::Skipped,
                'status_updated_at' => now(),
                'skip_reason' => $reason,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] No se pudo registrar el skip', ['error' => $e->getMessage()]);
        }
    }
}
