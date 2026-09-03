<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MOVA MYSQL QA GATE — punto único que decide si es seguro ejecutar un
 * comando destructivo (migrate:fresh, mova:concurrency-probe, etc.) contra
 * una conexión MySQL real.
 *
 * Falla cerrado en DOS dimensiones independientes, ambas obligatorias:
 *   1. Entorno: APP_ENV debe ser 'local' o 'testing'. Ni --force ni ninguna
 *      otra bandera lo saltan — la única forma de pasar este guard es que
 *      el entorno de verdad sea uno de esos dos.
 *   2. Base de datos: el nombre real de la base de datos activa en la
 *      conexión indicada debe coincidir EXACTAMENTE con el esperado (sin
 *      case-insensitive, sin trim silencioso de espacios).
 *
 * Si cualquiera de las dos falla, lanza una excepción y el comando que
 * llamó a este guard nunca llega a ejecutar la operación destructiva.
 */
final class QaDatabaseGuard
{
    private const SAFE_ENVIRONMENTS = ['local', 'testing'];

    public static function assertDatabase(string $connection, string $expectedDatabase): void
    {
        self::assertSafeEnvironment();

        $actual = DB::connection($connection)->getDatabaseName();

        if ($actual !== $expectedDatabase) {
            throw new RuntimeException(
                "MOVA QA GUARD: se esperaba la base de datos [{$expectedDatabase}] en la conexión [{$connection}], ".
                "pero la conexión activa apunta a [{$actual}]. Comando destructivo abortado antes de ejecutarse."
            );
        }
    }

    /**
     * Comprobado por separado (no solo dentro de assertDatabase()) para que
     * un comando pueda fallar cerrado por entorno incluso antes de intentar
     * resolver ninguna conexión de base de datos.
     */
    public static function assertSafeEnvironment(): void
    {
        $environment = app()->environment();

        if (! in_array($environment, self::SAFE_ENVIRONMENTS, true)) {
            throw new RuntimeException(
                "MOVA QA GUARD: entorno actual [{$environment}] no es 'local' ni 'testing'. ".
                'Comando destructivo abortado antes de ejecutarse — esto NUNCA se salta con --force.'
            );
        }
    }
}
