<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // GUARDA-CHUVA DE SEGURANÇA: força sempre a BD de teste dedicada.
        // O container injeta o .env como variáveis reais do SO (env_file no
        // docker-compose), que vencem o phpunit.xml mesmo com force="true".
        // Sem isto, o RefreshDatabase corre na BD de DEV (`nexus`) e apaga-a.
        config([
            'database.connections.pgsql.database' => 'nexus_testing',
            'mail.default' => 'array',
            'queue.default' => 'sync',
        ]);

        // Tripwire: aborta se, por alguma razão, a BD de teste não for a esperada.
        if (config('database.connections.pgsql.database') !== 'nexus_testing') {
            throw new \RuntimeException('Os testes têm de correr na BD nexus_testing.');
        }

        return $app;
    }
}
