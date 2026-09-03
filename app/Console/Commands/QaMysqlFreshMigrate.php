<?php

namespace App\Console\Commands;

use App\Support\QaDatabaseGuard;
use Illuminate\Console\Command;

/**
 * MOVA MYSQL QA GATE — único punto de entrada soportado para correr la
 * cadena de migraciones desde cero contra MySQL real.
 *
 * A propósito NO acepta ni `--database` ni ninguna otra forma de apuntar a
 * una base de datos distinta: siempre usa la conexión 'mysql_qa', cuyo
 * nombre de base de datos está fijo en código como 'mova_qa'
 * (config/database.php). Antes de llamar a `migrate:fresh` reconfirma en
 * runtime que esa conexión de verdad resuelve a 'mova_qa' — si algún día
 * config/database.php cambia y deja de ser así, este comando falla cerrado
 * en vez de arriesgar la base de datos equivocada.
 */
class QaMysqlFreshMigrate extends Command
{
    protected $signature = 'mova:qa-mysql-fresh-migrate {--seed : también corre los seeders tras migrar}';

    protected $description = 'MOVA MYSQL QA GATE: migrate:fresh SOLO contra la conexión mysql_qa (mova_qa), nunca contra la base de datos de dev/producción.';

    public function handle(): int
    {
        QaDatabaseGuard::assertDatabase('mysql_qa', 'mova_qa');

        $this->components->info('Guard OK — conexión mysql_qa confirmada apuntando a mova_qa. Corriendo migrate:fresh...');

        $exitCode = $this->call('migrate:fresh', array_filter([
            '--database' => 'mysql_qa',
            '--force' => true,
            '--seed' => $this->option('seed') ?: null,
        ]));

        // migrate:fresh pudo haber cambiado la conexión por defecto que usan
        // los modelos Eloquent (Laravel reconecta según config('database.default')
        // salvo que se fuerce); reconfirmamos después de migrar, antes de
        // devolver el control, para que cualquier comando QA que se
        // encadene a este (schema checks, sondas de concurrencia) parta de
        // un estado ya verificado.
        QaDatabaseGuard::assertDatabase('mysql_qa', 'mova_qa');

        return $exitCode;
    }
}
