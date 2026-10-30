<?php

namespace App\Console\Commands;

use App\WhatsApp\Waha\WahaClient;
use Illuminate\Console\Command;

/**
 * Comprobación de alcance de WAHA desde el propio entorno de MOVA (p. ej. `az containerapp exec` en staging).
 *
 * SOLO LECTURA: consulta el estado de la sesión por la misma ruta que usa el proveedor. No envía mensajes, no reinicia la sesión
 * y no imprime la API key.
 */
class WahaCheck extends Command
{
    protected $signature = 'mova:waha-check';

    protected $description = 'Comprueba que MOVA alcanza la API de WAHA y muestra el estado de la sesión (solo lectura)';

    public function handle(): int
    {
        $host = parse_url((string) config('services.waha.url'), PHP_URL_HOST) ?: '(sin WAHA_BASE_URL)';
        $this->line('Proveedor: '.config('services.whatsapp.provider').' · canal '.(config('services.whatsapp.enabled') ? 'ACTIVO' : 'desactivado'));
        $this->line('Destino WAHA: '.$host.' · sesión '.config('services.waha.session'));

        $info = WahaClient::fromConfig()->sessionStatus();

        if (! $info['ok']) {
            $this->error('No alcanzable: '.($info['error'] ?? 'sin detalle'));

            return self::FAILURE;
        }

        $this->info('Alcanzable. Estado de la sesión: '.($info['status'] ?? 'desconocido'));

        return self::SUCCESS;
    }
}
