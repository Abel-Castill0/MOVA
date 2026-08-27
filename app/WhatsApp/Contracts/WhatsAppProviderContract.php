<?php

namespace App\WhatsApp\Contracts;

/**
 * Abstracción sobre el proveedor de WhatsApp (Meta Cloud API directo hoy;
 * cualquier otro BSP mañana) — mismo principio que
 * App\Payment\Contracts\PaymentProviderContract: el resto de MOVA
 * (WhatsAppChannel, PhoneVerificationController) trabaja solo con
 * "plantilla + parámetros", nunca con el formato HTTP específico de un
 * proveedor.
 */
interface WhatsAppProviderContract
{
    /**
     * Envía una plantilla ya aprobada por WhatsApp/Meta. $templateKey es una
     * clave SIMBÓLICA propia de MOVA (ver config('services.meta_whatsapp.templates'))
     * — nunca el nombre real de la plantilla en Meta, para poder cambiarlo
     * sin tocar código. Devuelve false (nunca lanza) si la plantilla no está
     * configurada, si el proveedor no está configurado, o si el envío falla
     * — el llamador decide qué hacer con eso (loguear, mostrar error al
     * usuario, etc.), pero nunca debe interrumpir un job en cola.
     *
     * $clientReference es una etiqueta libre que arma el llamador (p. ej.
     * "ClassConfirmedNotification#482") para poder rastrear en
     * whatsapp_messages qué disparó este envío — se guarda tal cual, el
     * proveedor nunca la interpreta.
     */
    public function sendTemplate(string $to, string $templateKey, array $params, ?string $clientReference = null): bool;
}
