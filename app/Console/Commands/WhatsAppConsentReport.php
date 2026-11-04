<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Segmentación de las cuentas para la activación de WhatsApp (solo conteos, sin datos personales):
 *   A. consentimiento vigente + teléfono verificado → reciben avisos en cuanto el canal está activo.
 *   B. consentimiento vigente + teléfono sin verificar → solo les falta verificar.
 *   C. sin consentimiento documentado → se les ofrece activarlo con una acción en su perfil.
 *   D. se dieron de baja → no se les escribe.
 * No modifica nada: nunca se inventan consentimientos retroactivos.
 */
class WhatsAppConsentReport extends Command
{
    protected $signature = 'mova:whatsapp-consent-report';

    protected $description = 'Cuenta usuarios por estado de consentimiento de WhatsApp (A/B/C/D), sin datos personales.';

    public function handle(): int
    {
        $base = User::query()->whereNull('suspended_at')->whereNull('account_closed_at')->whereDoesntHave('roles', fn ($q) => $q->where('name', 'admin'));

        $rows = [
            ['A', 'consentimiento + teléfono verificado', (clone $base)->whereNull('whatsapp_opt_out_at')->whereNotNull('whatsapp_opt_in_at')->whereNotNull('phone_verified_at')->count()],
            ['B', 'consentimiento, teléfono sin verificar', (clone $base)->whereNull('whatsapp_opt_out_at')->whereNotNull('whatsapp_opt_in_at')->whereNull('phone_verified_at')->count()],
            ['C', 'sin consentimiento documentado', (clone $base)->whereNull('whatsapp_opt_out_at')->whereNull('whatsapp_opt_in_at')->count()],
            ['D', 'baja / revocado', (clone $base)->whereNotNull('whatsapp_opt_out_at')->count()],
        ];

        $this->table(['Grupo', 'Estado', 'Cuentas'], $rows);
        $this->line('Excluidas: cuentas suspendidas o cerradas y administradores.');

        return self::SUCCESS;
    }
}
