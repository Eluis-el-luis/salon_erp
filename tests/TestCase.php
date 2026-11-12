<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    final public function createApplication()
    {
        if (! defined('SALON_TEST_DATABASE_BOOTSTRAPPED')) {
            throw new RuntimeException('Use phpunit.xml y tests/bootstrap.php; no se permite cargar el entorno ordinario.');
        }

        foreach ([$_ENV['APP_BASE_PATH'] ?? '', $_SERVER['APP_BASE_PATH'] ?? '', getenv('APP_BASE_PATH') ?: ''] as $root) {
            TestDatabaseSafety::assertApplicationRoot(dirname(__DIR__), $root);
        }

        $app = parent::createApplication();
        TestDatabaseSafety::assertApplicationRoot(dirname(__DIR__), $app->basePath());
        $mysql = $app['config']->get('database.connections.mysql', []);
        TestDatabaseSafety::assertConnection($mysql);

        // Solo en el proceso de pruebas: ninguna conexión alternativa queda disponible.
        $app['config']->set('database.connections', ['mysql' => $mysql]);
        TestDatabaseSafety::assertConfiguration($app->environment(), $app['config']->get('database'));

        return $app;
    }

    final protected function setUpTraits()
    {
        // Este punto precede a TODOS los traits, incluyendo migrate:fresh de RefreshDatabase.
        // beforeRefreshingDatabase() en la clase padre sería ocultado por el trait del hijo.
        $connections = property_exists($this, 'connectionsToTransact')
            ? $this->connectionsToTransact
            : ['mysql'];

        TestDatabaseSafety::assertConfiguration(
            $this->app->environment(),
            $this->app['config']->get('database'),
            $connections,
        );

        foreach ($this->app['db']->getConnections() as $name => $connection) {
            if ($name !== 'mysql') {
                throw new RuntimeException('Conexión ya resuelta fuera de la lista permitida de pruebas.');
            }
            TestDatabaseSafety::assertConnection($connection->getConfig());
        }

        $uses = class_uses_recursive(static::class);
        foreach ([RefreshDatabase::class, DatabaseMigrations::class, DatabaseTruncation::class] as $trait) {
            if (isset($uses[$trait])) {
                TestDatabaseSafety::assertResetAuthorized(
                    defined('SALON_TEST_DATABASE_RESET_ALLOWED') && SALON_TEST_DATABASE_RESET_ALLOWED,
                );
            }
        }

        // Cuando se autorice ejecutar pruebas: comprobar identidad con SELECT, antes del DDL.
        // No hay fallback a root, a otra cuenta local ni a otra base.
        $identity = $this->app['db']->connection('mysql')->selectOne(
            'SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, @@port AS server_port'
        );
        TestDatabaseSafety::assertServerIdentity($identity);

        return parent::setUpTraits();
    }
}
