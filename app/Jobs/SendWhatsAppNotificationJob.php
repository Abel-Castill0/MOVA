<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de intentos permitidos.
     */
    public int $tries = 3;

    /**
     * Reintentos con retraso exponencial progresivo (segundos).
     */
    public array $backoff = [10, 30, 60];

    /**
     * Crear una nueva instancia del Job.
     */
    public function __construct(
        public string $phone,
        public string $message
    ) {}

    /**
     * Ejecuta el trabajo asíncrono.
     */
    public function handle(WhatsAppService $whatsAppService): void
    {
        // Recheck at delivery time: a job may have been queued before closure.
        $normalized = \App\Models\User::normalizePhone($this->phone);
        if ($normalized && \App\Models\User::whereNotNull('account_closed_at')->where('phone_verified_normalized', $normalized)->exists()) {
            return;
        }
        Log::info('[SendWhatsAppNotificationJob] Iniciando envío de WhatsApp asíncrono', [
            'phone'   => $this->phone,
            'attempt' => $this->attempts(),
        ]);

        $result = $whatsAppService->sendText($this->phone, $this->message);

        if (!$result['success']) {
            Log::warning('[SendWhatsAppNotificationJob] Falló el intento de envío', [
                'phone'   => $this->phone,
                'attempt' => $this->attempts(),
                'error'   => $result['error'] ?? 'Error desconocido',
            ]);

            // Lanza excepción para que la cola aplique el backoff y reintento
            throw new \RuntimeException(
                "Error enviando WhatsApp a {$this->phone}: " . ($result['error'] ?? 'Desconocido')
            );
        }

        Log::info('[SendWhatsAppNotificationJob] Notificación de WhatsApp procesada con éxito', [
            'phone' => $this->phone,
        ]);
    }

    /**
     * Maneja el caso en que el trabajo falle definitivamente tras agotar intentos.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('[SendWhatsAppNotificationJob] Trabajo fallido definitivamente tras agotar intentos', [
            'phone'     => $this->phone,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
