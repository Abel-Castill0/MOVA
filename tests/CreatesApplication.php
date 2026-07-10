<?php

namespace Tests;

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

        if ($defaultConnection !== 'sqlite' || $defaultDatabase !== ':memory:') {
            throw new RuntimeException(
                'Unsafe testing database configuration. PHPUnit must use sqlite :memory: for the safe local suite.'
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
