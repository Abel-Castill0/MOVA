<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guarda de regresión — Eloquent adivina la tabla de cualquier modelo que
 * empiece con "WhatsApp" como "whats_app_..." (parte "WhatsApp" en dos
 * palabras al convertir a snake_case), no "whatsapp_...", que es como
 * están nombradas las migraciones reales. Esto YA causó un bug real dos
 * veces en esta misma ronda (WhatsAppMessage, luego WhatsAppWebhookEvent)
 * — cada uno enmascarado en el log porque el código que los usa nunca debe
 * hacer fallar la operación principal por un fallo de auditoría. Esta
 * prueba existe para que un tercer modelo "WhatsApp*" futuro no repita el
 * mismo error en silencio.
 */
class WhatsAppModelTableNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_message_resolves_to_the_real_table(): void
    {
        $this->assertSame('whatsapp_messages', (new WhatsAppMessage())->getTable());
    }

    public function test_whatsapp_webhook_event_resolves_to_the_real_table(): void
    {
        $this->assertSame('whatsapp_webhook_events', (new WhatsAppWebhookEvent())->getTable());
    }
}
