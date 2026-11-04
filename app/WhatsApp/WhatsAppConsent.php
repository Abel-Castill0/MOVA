<?php

namespace App\WhatsApp;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Consentimiento de avisos por WhatsApp: el texto exacto que acepta la persona, su versión y su finalidad, y el registro
 * auditable de cada alta o baja. El estado vigente vive en `users.whatsapp_opt_in_at` / `whatsapp_opt_out_at`.
 */
final class WhatsAppConsent
{
    public const VERSION = '2026-11-04';

    public const PURPOSE = 'class_notifications';

    public const TEXT = 'Quiero recibir por WhatsApp notificaciones de MOVA sobre mis reservas, confirmaciones, cambios de horario y recordatorios de clases. Puedo desactivarlas cuando quiera.';

    /** Estados que muestra la interfaz. Se calculan siempre en el servidor (nunca desde una casilla del navegador). */
    public const ACTIVE = 'active';                       // consentimiento vigente + teléfono verificado

    public const NEEDS_VERIFICATION = 'needs_verification'; // consentimiento vigente, falta verificar (o añadir) el teléfono

    public const NOT_AUTHORIZED = 'not_authorized';       // nunca dio consentimiento

    public const REVOKED = 'revoked';                     // se dio de baja

    public static function status(User $user): string
    {
        if ($user->whatsapp_opt_out_at !== null) {
            return self::REVOKED;
        }
        if ($user->whatsapp_opt_in_at === null) {
            return self::NOT_AUTHORIZED;
        }

        return $user->phone_verified_at !== null ? self::ACTIVE : self::NEEDS_VERIFICATION;
    }

    /** Registra el evento. Lanza si no puede: quien llama lo hace dentro de la misma transacción que el cambio de estado. */
    public static function log(User $user, string $action, string $source): void
    {
        DB::table('whatsapp_consent_events')->insert([
            'user_id' => $user->id,
            'action' => $action,
            'source' => $source,
            'text_version' => $action === 'opt_in' ? self::VERSION : null,
            'purpose' => self::PURPOSE,
            'created_at' => now(),
        ]);
    }
}
