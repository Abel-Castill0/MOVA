<?php

namespace Tests;

use App\Support\QaDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->guardSafeTestingEnvironment($app);

        return $app;
    }

    private function guardSafeTestingEnvironment(Application $app): void
    {
        if (!$app->environment('testing')) {
            return;
        }

        $defaultConnection = config('database.default');
        $defaultDatabase = config("database.connections.{$defaultConnection}.database");

        $isSafeSqliteMemory = $defaultConnection === 'sqlite' && $defaultDatabase === ':memory:';

        // MOVA MYSQL QA GATE: única excepción a "sqlite :memory: o nada".
        // 'mysql_qa' tiene el nombre de base de datos fijo en código
        // (config/database.php), pero se reconfirma aquí en runtime vía
        // QaDatabaseGuard — el mismo guard que usan los comandos artisan
        // destructivos — para que un único punto decida qué es "seguro".
        $isSafeMysqlQa = $defaultConnection === 'mysql_qa' && $defaultDatabase === 'mova_qa';

        if ($isSafeMysqlQa) {
            QaDatabaseGuard::assertDatabase('mysql_qa', 'mova_qa');
        }

        if (!$isSafeSqliteMemory && !$isSafeMysqlQa) {
            throw new RuntimeException(
                'Unsafe testing database configuration. PHPUnit must use sqlite :memory: for the safe local suite, '.
                "or the dedicated 'mysql_qa' connection (database 'mova_qa') for the MySQL QA gate."
            );
        }

        foreach (config('database.connections', []) as $name => $connection) {
            foreach (['host', 'database', 'url'] as $key) {
                $value = strtolower((string) ($connection[$key] ?? ''));

                if ($value === '') {
                    continue;
                }

                if (preg_match('/railway|production|prod|up\.railway\.app|mova-production/', $value)) {
                    throw new RuntimeException(
                        "Unsafe testing database configuration detected in connection [{$name}]. Refusing to run tests."
                    );
                }
            }
        }
    }
}
