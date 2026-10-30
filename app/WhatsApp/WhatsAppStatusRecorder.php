<?php

namespace App\WhatsApp;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica un cambio de estado de entrega a whatsapp_messages. Lógica común de TODOS los webhooks de proveedores (Meta,
 * WAHA): deduplica el evento, correlaciona SOLO por provider_message_id (nunca por client_reference, que no es único),
 * nunca retrocede el estado y trata Failed/Skipped como terminales. Extraída tal cual de WhatsAppWebhookController.
 */
final class WhatsAppStatusRecorder
{
    public function record(string $provider, string $messageId, WhatsAppMessageStatus $newStatus, Carbon $eventAt, string $eventKey): void
    {
        // Un ack que llega antes de que la fila tenga su id NO se marca como procesado: WAHA lo reintenta y se aplicará después.
        if (! WhatsAppMessage::where('provider', $provider)->where('provider_message_id', $messageId)->exists()) {
            Log::info('[WhatsApp/Webhook] Estado para un mensaje no registrado localmente.', ['provider' => $provider, 'id' => $messageId]);

            return;
        }

        if (! $this->recordNewEvent($messageId, $newStatus, $eventKey)) {
            Log::info('[WhatsApp/Webhook] Entrega de evento duplicada (el proveedor reenvió el mismo evento) — ignorada.', [
                'provider' => $provider,
                'id' => $messageId,
                'status' => $newStatus->value,
            ]);

            return;
        }

        DB::transaction(function () use ($provider, $messageId, $newStatus, $eventAt) {
            $message = WhatsAppMessage::where('provider', $provider)
                ->where('provider_message_id', $messageId)
                ->lockForUpdate()
                ->first();

            if (! $message) {
                Log::info('[WhatsApp/Webhook] Estado para un mensaje no registrado localmente.', ['provider' => $provider, 'id' => $messageId]);

                return;
            }

            if (! $this->shouldApply($message->status, $newStatus)) {
                Log::info('[WhatsApp/Webhook] Evento fuera de orden ignorado (no retrocede el estado).', [
                    'id' => $messageId,
                    'estado_actual' => $message->status->value,
                    'evento_recibido' => $newStatus->value,
                ]);

                return;
            }

            $update = ['status' => $newStatus, 'status_updated_at' => $eventAt];
            if ($newStatus === WhatsAppMessageStatus::Delivered) {
                $update['delivered_at'] = $eventAt;
            } elseif ($newStatus === WhatsAppMessageStatus::Read) {
                $update['read_at'] = $eventAt;
            }

            $message->update($update);
        });
    }

    private function recordNewEvent(string $messageId, WhatsAppMessageStatus $status, string $eventKey): bool
    {
        try {
            WhatsAppWebhookEvent::create([
                'event_key' => $eventKey,
                'provider_message_id' => $messageId,
                'status' => $status->value,
                'received_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function shouldApply(WhatsAppMessageStatus $current, WhatsAppMessageStatus $incoming): bool
    {
        if ($current === WhatsAppMessageStatus::Failed || $current === WhatsAppMessageStatus::Skipped) {
            return false;
        }

        if ($incoming === WhatsAppMessageStatus::Failed) {
            return ! in_array($current, [WhatsAppMessageStatus::Delivered, WhatsAppMessageStatus::Read], true);
        }

        $currentRank = $current->deliveryRank() ?? 0; // Unknown también entra aquí, siempre superable
        $incomingRank = $incoming->deliveryRank();

        return $incomingRank !== null && $incomingRank > $currentRank;
    }
}
