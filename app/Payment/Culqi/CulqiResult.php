<?php

namespace App\Payment\Culqi;

/**
 * Resultado normalizado de una llamada HTTP a Culqi. Solo transporta lo
 * necesario para decidir; nunca guarda cabeceras de autorización ni el cuerpo
 * de la petición.
 */
final class CulqiResult
{
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
        public readonly ?string $trackingId = null,
        public readonly ?string $environment = null,
        public readonly ?string $transportError = null,
    ) {
    }

    public function successful(): bool
    {
        return $this->transportError === null && $this->status >= 200 && $this->status < 300 && $this->body !== null;
    }

    /**
     * Resultado financiero incierto: la petición pudo haberse procesado en
     * Culqi aunque aquí no lo sepamos (corte de red, timeout, 5xx, 408, 429
     * o 2xx sin cuerpo utilizable). NUNCA se reintenta una escritura en este
     * caso: se reconcilia consultando la API.
     */
    public function uncertain(): bool
    {
        if ($this->transportError !== null) {
            return true;
        }

        if ($this->status >= 200 && $this->status < 300) {
            return $this->body === null;
        }

        return $this->status >= 500 || in_array($this->status, [0, 408, 429], true);
    }

    /** Error definitivo y documentado de Culqi (objeto `error` con 4xx): la operación NO se realizó. */
    public function definitiveRejection(): bool
    {
        return ! $this->uncertain() && ! $this->successful();
    }

    public function errorType(): ?string
    {
        return $this->body['type'] ?? null;
    }

    public function errorCode(): ?string
    {
        return $this->body['code'] ?? $this->body['decline_code'] ?? null;
    }

    public function userMessage(): ?string
    {
        $message = $this->body['user_message'] ?? $this->body['merchant_message'] ?? null;

        return is_string($message) ? mb_substr($message, 0, 200) : null;
    }
}
