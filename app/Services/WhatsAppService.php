<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected string $session;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.waha.url', 'http://localhost:3000'), '/');
        $this->apiKey = config('services.waha.api_key');
        $this->session = (string) config('services.waha.session', 'default');
    }

    /**
     * Normaliza un número telefónico al formato chat ID exigido por WAHA ([numero]@c.us).
     * Aplica reglas específicas para Perú (+51) agregando el código de país si tiene 9 dígitos.
     */
    private function formatChatId(string $phone): string
    {
        // 1. Limpiar caracteres no numéricos
        $cleaned = preg_replace('/\D+/', '', $phone) ?? '';

        // 2. Si empieza con 00, eliminarlo
        if (str_starts_with($cleaned, '00')) {
            $cleaned = substr($cleaned, 2);
        }

        // 3. Regla para números móviles de Perú (9 dígitos empezando normalmente con 9)
        if (strlen($cleaned) === 9) {
            $cleaned = '51' . $cleaned;
        }

        return "{$cleaned}@c.us";
    }

    /**
     * Envía un mensaje de texto plano a través de la API de WAHA.
     *
     * @param string $phone Teléfono destino
     * @param string $message Contenido del mensaje
     * @return array Resumen del resultado del envío
     */
    public function sendText(string $phone, string $message): array
    {
        $chatId = $this->formatChatId($phone);
        $endpoint = "{$this->baseUrl}/api/sendText";

        try {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ];

            if (!empty($this->apiKey)) {
                $headers['X-Api-Key'] = $this->apiKey;
            }

            $response = Http::withHeaders($headers)
                ->timeout(15)
                ->retry(2, 500, throw: false)
                ->post($endpoint, [
                    'chatId'  => $chatId,
                    'text'    => $message,
                    'session' => $this->session,
                ]);

            if ($response->successful()) {
                Log::info('[WhatsAppService] Mensaje enviado exitosamente', [
                    'chatId'  => $chatId,
                    'session' => $this->session,
                ]);

                return [
                    'success' => true,
                    'data'    => $response->json(),
                    'error'   => null,
                ];
            }

            Log::error('[WhatsAppService] Error en respuesta de WAHA al enviar mensaje', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'chatId'  => $chatId,
            ]);

            return [
                'success' => false,
                'data'    => null,
                'error'   => "WAHA HTTP {$response->status()}: " . $response->body(),
            ];
        } catch (Throwable $e) {
            Log::error('[WhatsAppService] Excepción al comunicar con WAHA', [
                'message' => $e->getMessage(),
                'chatId'  => $chatId,
            ]);

            return [
                'success' => false,
                'data'    => null,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Consulta el estado de la sesión de WhatsApp en WAHA.
     *
     * @return array Estado de la sesión (ej. WORKING, SCAN_QR_CODE, STOPPED, etc.)
     */
    public function checkSessionStatus(): array
    {
        $endpoint = "{$this->baseUrl}/api/sessions/{$this->session}";

        try {
            $headers = [
                'Accept' => 'application/json',
            ];

            if (!empty($this->apiKey)) {
                $headers['X-Api-Key'] = $this->apiKey;
            }

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->retry(1, 300, throw: false)
                ->get($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                $status = $data['status'] ?? 'UNKNOWN';

                // Si la sesión se detuvo por expiración de QR o desvinculación (FAILED o STOPPED),
                // la reiniciamos automáticamente; y si tenía una sesión previa revocada desde el teléfono,
                // cerramos sesión (logout) y reiniciamos para generar un nuevo QR limpio.
                if (in_array($status, ['FAILED', 'STOPPED'], true)) {
                    if ($status === 'FAILED' && !empty($data['me'])) {
                        Http::withHeaders($headers)
                            ->timeout(10)
                            ->post("{$this->baseUrl}/api/sessions/{$this->session}/logout");
                        usleep(500000);
                    }

                    $restartRes = Http::withHeaders($headers)
                        ->timeout(10)
                        ->post("{$this->baseUrl}/api/sessions/{$this->session}/restart");

                    if ($restartRes->successful()) {
                        usleep(1200000); // Esperar 1.2s a que genere el QR
                        $recheck = Http::withHeaders($headers)
                            ->timeout(10)
                            ->get($endpoint);
                        if ($recheck->successful()) {
                            $data = $recheck->json();
                            $status = $data['status'] ?? $status;
                        }
                    }
                }

                return [
                    'success' => true,
                    'status'  => $status,
                    'data'    => $data,
                    'error'   => null,
                ];
            }

            Log::warning('[WhatsAppService] No se pudo obtener el estado de sesión de WAHA', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return [
                'success' => false,
                'status'  => 'UNAVAILABLE',
                'data'    => null,
                'error'   => "HTTP {$response->status()}: " . $response->body(),
            ];
        } catch (Throwable $e) {
            Log::error('[WhatsAppService] Error al consultar sesión en WAHA', [
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status'  => 'DISCONNECTED',
                'data'    => null,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
