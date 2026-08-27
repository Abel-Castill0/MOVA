<?php

namespace App\WhatsApp;

/**
 * Estados del ciclo de vida de un WhatsAppMessage. 'sent' significa que Meta
 * ACEPTÓ el envío Y que MOVA tiene el wamid con el que correlacionar un
 * webhook futuro (200 + `messages[0].id` presente) — no que el usuario lo
 * recibió, eso llega por webhook como 'delivered'/'read'. Sin ese id, la
 * fila nunca podría avanzar (el webhook busca por provider_message_id), así
 * que NO es 'sent': ver el caso F-23 más abajo.
 *
 * 'unknown' es un resultado genuinamente incierto — **no exclusivamente**
 * "hubo una excepción de red antes de la respuesta" (el caso original y más
 * común), sino, en general, cualquier situación donde el resultado externo
 * no puede determinarse con certeza suficiente. F-23 amplió este segundo
 * caso: un 2xx de Meta sin `messages[0].id` en el body también cae aquí —
 * Meta dijo éxito HTTP, pero MOVA no puede afirmar qué mensaje es. Ambos
 * casos comparten la misma consecuencia operativa: no reintentar a ciegas
 * (podría duplicar un envío que sí llegó) y no poder correlacionar un
 * webhook posterior. Deliberadamente distinto de 'failed' (un rechazo
 * definitivo de Meta) — ver el comentario en la migración de
 * whatsapp_messages y MetaCloudApiProvider::sendTemplate().
 *
 * 'skipped' — MOVA decidió NO llamar a Meta en absoluto (sin consentimiento).
 * Antes ese caso no dejaba ninguna fila: solo un Log::debug. Eso confundía
 * dos preguntas distintas — "¿por qué no le llegó el aviso a este padre?"
 * podía significar "Meta lo rechazó" (failed) o "el sistema nunca lo
 * intentó porque no había consentimiento" (skipped) — sin ninguna forma de
 * distinguirlas desde whatsapp_messages ni desde mova:reconcile-whatsapp.
 */
enum WhatsAppMessageStatus: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Skipped = 'skipped';

    /**
     * Orden de avance del ciclo de entrega normal — usado para no dejar que
     * un evento de webhook fuera de orden retroceda un estado más avanzado
     * (Meta advierte explícitamente que los eventos pueden llegar
     * desordenados). Failed/Unknown no tienen rango: son resultados
     * aparte del ciclo sent→delivered→read, no una posición en él.
     */
    public function deliveryRank(): ?int
    {
        return match ($this) {
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed, self::Unknown, self::Skipped => null,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Failed || $this === self::Skipped;
    }

    /**
     * 'skipped' NUNCA debe leerse como un fallo del proveedor — es una
     * decisión propia de MOVA, no un rechazo de Meta. Ninguna métrica de
     * salud de entrega (mova:reconcile-whatsapp) debe tratarlo como
     * anomalía; es una tasa de adopción del opt-in, no un problema técnico.
     */
    public function isProviderFailure(): bool
    {
        return $this === self::Failed;
    }
}
