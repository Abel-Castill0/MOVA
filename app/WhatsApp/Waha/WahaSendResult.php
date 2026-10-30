<?php

namespace App\WhatsApp\Waha;

/** Resultado de POST /api/sendText. «Aceptado por WAHA» NO es «entregado»: la entrega llega por el webhook message.ack. */
final class WahaSendResult
{
    private function __construct(
        public readonly string $outcome, // sent | failed | uncertain
        public readonly ?string $messageId,
        public readonly ?int $httpStatus,
        public readonly ?string $error,
    ) {
    }

    public static function sent(string $messageId, ?int $httpStatus = 200): self
    {
        return new self('sent', $messageId, $httpStatus, null);
    }

    public static function failed(?int $httpStatus, string $error): self
    {
        return new self('failed', null, $httpStatus, $error);
    }

    /** No se sabe si el mensaje salió (timeout, corte, 2xx sin id): jamás se reintenta a ciegas. */
    public static function uncertain(string $error, ?int $httpStatus = null): self
    {
        return new self('uncertain', null, $httpStatus, $error);
    }

    public function isSent(): bool
    {
        return $this->outcome === 'sent';
    }

    public function isFailed(): bool
    {
        return $this->outcome === 'failed';
    }

    public function isUncertain(): bool
    {
        return $this->outcome === 'uncertain';
    }
}
