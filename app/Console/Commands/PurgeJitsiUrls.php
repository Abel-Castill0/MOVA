<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpieza histórica de C-3.
 *
 * Antes de la corrección, ClassReminderNotification y ClassConfirmedNotification
 * guardaban en notifications.data una clave `jitsi_url` con la sala real de la
 * clase en la instancia PÚBLICA de Jitsi. Ese valor es un token de acceso a una
 * videollamada donde participa un menor, así que no debe permanecer almacenado.
 *
 * Es un comando y no una migración a propósito: mutar datos de usuario desde una
 * migración no es re-ejecutable ni auditable, y aquí queremos poder inspeccionar
 * con --dry-run antes de tocar nada.
 *
 * Solo elimina esa clave. No borra filas, no toca clases, usuarios ni créditos.
 */
class PurgeJitsiUrls extends Command
{
    protected $signature = 'mova:purge-jitsi-urls {--dry-run : Solo informa cuántos registros se modificarían}';

    protected $description = 'Elimina la clave jitsi_url de los payloads históricos de notificaciones (C-3)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Filtro amplio a nivel SQL (LIKE sobre el JSON crudo) y decisión real en
        // PHP tras decodificar: así no dependemos de las funciones JSON de MySQL
        // ni fallamos en SQLite, que es lo que usa la suite de tests.
        $candidates = DB::table('notifications')
            ->where('data', 'like', '%jitsi_url%')
            ->select('id', 'data')
            ->get();

        $updated = 0;
        $skipped = 0;

        foreach ($candidates as $row) {
            $data = json_decode($row->data, true);

            if (!is_array($data) || !array_key_exists('jitsi_url', $data)) {
                // La cadena aparecía en el JSON pero no como clave de primer
                // nivel (p. ej. dentro de un mensaje). No la tocamos.
                $skipped++;
                continue;
            }

            unset($data['jitsi_url']);

            if (!$dryRun) {
                DB::table('notifications')
                    ->where('id', $row->id)
                    ->update(['data' => json_encode($data)]);
            }

            $updated++;
        }

        if ($dryRun) {
            $this->info("[dry-run] Se modificarían {$updated} notificaciones. Ninguna fue tocada.");
        } else {
            $this->info("Se eliminó jitsi_url de {$updated} notificaciones.");
        }

        if ($skipped > 0) {
            $this->warn("{$skipped} registros contenían la cadena pero no la clave; se dejaron intactos.");
        }

        return self::SUCCESS;
    }
}
