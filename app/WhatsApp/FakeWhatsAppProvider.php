<?php

namespace App\WhatsApp;

use App\WhatsApp\Contracts\WhatsAppProviderContract;

/**
 * Provider de test/desarrollo — nunca envía nada real. Registra cada envío
 * en memoria para que los tests puedan verificar qué se intentó mandar sin
 * depender de red ni de credenciales de Meta. Mismo rol que
 * App\Payment\FakePaymentProvider para pagos.
 */
class FakeWhatsAppProvider implements WhatsAppProviderContract
{
    /** @var array<int, array{to: string, template: string, params: array, client_reference: ?string}> */
    public array $sent = [];

    public function sendTemplate(string $to, string $templateKey, array $params, ?string $clientReference = null): bool
    {
        $this->sent[] = ['to' => $to, 'template' => $templateKey, 'params' => $params, 'client_reference' => $clientReference];

        return true;
    }
}
