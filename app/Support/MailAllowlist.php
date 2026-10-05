<?php

namespace App\Support;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Allowlist de destinatarios para entornos NO productivos (staging).
 *
 * `MAIL_ALLOWLIST` (config `mail.allowlist`): lista separada por comas de correos
 * exactos o de dominios (`@dominio.com`). Con la lista definida, el correo SOLO
 * llega a esos destinatarios: el resto se descarta antes de salir del servidor
 * (Mailer → transporte), sea cual sea el transporte (smtp, Gmail API, etc.). Si
 * tras filtrar no queda ningún destinatario, el envío se cancela por completo.
 *
 * Vacía/no definida → no se filtra nada (producción). Pensada para que un staging
 * con cuentas de prueba nunca pueda escribir a direcciones que existen de verdad.
 * No registra direcciones: solo cuántas se descartaron.
 */
class MailAllowlist
{
    /** @return list<string> entradas normalizadas en minúsculas */
    public static function entries(): array
    {
        $raw = (string) config('mail.allowlist', '');

        return array_values(array_filter(array_map(
            static fn (string $e) => strtolower(trim($e)),
            explode(',', $raw)
        )));
    }

    public static function isActive(): bool
    {
        return self::entries() !== [];
    }

    public static function allows(string $email): bool
    {
        $entries = self::entries();
        if ($entries === []) {
            return true;
        }

        $email = strtolower(trim($email));
        foreach ($entries as $entry) {
            if ($entry === $email) {
                return true;
            }
            if (str_starts_with($entry, '@') && str_ends_with($email, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Listener de MessageSending: devuelve false para cancelar el envío.
     */
    public function handle(MessageSending $event): ?bool
    {
        if (! self::isActive()) {
            return null;
        }

        $message = $event->message;
        if (! $message instanceof Email) {
            return false; // tipo de mensaje desconocido con allowlist activa: fail closed
        }

        $dropped = 0;
        $kept = [];
        foreach (['To' => 'getTo', 'Cc' => 'getCc', 'Bcc' => 'getBcc'] as $field => $getter) {
            $allowed = [];
            foreach ($message->{$getter}() as $address) {
                /** @var Address $address */
                if (self::allows($address->getAddress())) {
                    $allowed[] = $address;
                } else {
                    $dropped++;
                }
            }
            $kept[$field] = $allowed;
        }

        $message->to(...$kept['To']);
        $message->cc(...$kept['Cc']);
        $message->bcc(...$kept['Bcc']);

        if ($kept['To'] === [] && $kept['Cc'] === [] && $kept['Bcc'] === []) {
            Log::info('[MailAllowlist] Correo cancelado: ningún destinatario está en MAIL_ALLOWLIST.', ['descartados' => $dropped]);

            return false;
        }

        if ($dropped > 0) {
            Log::info('[MailAllowlist] Destinatarios fuera de MAIL_ALLOWLIST descartados.', ['descartados' => $dropped]);
        }

        return null;
    }
}
