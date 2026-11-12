<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Solo ejecutar este archivo: nunca utiliza el cuerpo real de RefreshDatabase. */
final class IsolationReadOnlyTest extends TestCase
{
    public function test_bootstrap_laravel_identity_and_refresh_rejection(): void
    {
        self::assertTrue(defined('SALON_TEST_DATABASE_BOOTSTRAPPED'));
        self::assertFalse(SALON_TEST_DATABASE_RESET_ALLOWED);
        $probe = new ReadOnlyIsolationProbe('unusedTestBody');
        try {
            $identity = $probe->initializeReadOnly();
            self::assertSame('testing', $identity['environment']);
            self::assertSame(realpath(dirname(__DIR__, 2)), realpath($identity['root']));
            self::assertSame('salon_erp_test', $identity['database_name']);
            self::assertSame('salon_erp_tester@localhost', $identity['account_name']);
            self::assertSame(3306, (int) $identity['server_port']);
            $rejected = false;
            try { $probe->invokeProtectedTraits(); }
            catch (RuntimeException $e) {
                $rejected = $e->getMessage() === 'Recreación/truncado bloqueado. Requiere autorización y SALON_TEST_DB_RESET_ALLOWED=1 en .env.testing.';
            }
            self::assertTrue($rejected, 'Debe rechazar antes de despachar RefreshDatabase');
            self::assertSame(0, $probe->refreshCalls);
            self::assertSame(0, $probe->blockedSql);
            self::assertSame(1, $probe->selects);
        } finally {
            $probe->disconnectReadOnly();
            // Arranque manual: devolver los handlers globales al runner externo.
            \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
        }
    }
}

final class ReadOnlyIsolationProbe extends \Tests\TestCase
{
    use RefreshDatabase;

    public int $refreshCalls = 0;
    public int $blockedSql = 0;
    public int $selects = 0;

    // Sustitución obligatoria: aunque falle el guardia, nunca invoca el trait real.
    public function refreshDatabase()
    {
        $this->refreshCalls++;
        throw new RuntimeException('SENTINEL: no se permite ejecutar RefreshDatabase');
    }

    public function initializeReadOnly(): array
    {
        if (! defined('SALON_TEST_DATABASE_RESET_ALLOWED') || SALON_TEST_DATABASE_RESET_ALLOWED !== false) {
            throw new RuntimeException('Ensayo solo permitido con RESET_ALLOWED=0');
        }
        $this->app = $this->createApplication();
        $connection = $this->app['db']->connection('mysql');
        $connection->beforeExecuting(function ($sql): void {
            if ($sql !== 'SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, @@port AS server_port') {
                $this->blockedSql++;
                throw new RuntimeException('SQL fuera de la lista de lectura del ensayo');
            }
            $this->selects++;
        });
        return array_merge([
            'environment' => $this->app->environment(), 'root' => $this->app->basePath(),
        ], (array) $connection->selectOne('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, @@port AS server_port'));
    }

    public function invokeProtectedTraits(): void { $this->setUpTraits(); }

    public function disconnectReadOnly(): void
    {
        if ($this->app) { $this->app['db']->disconnect('mysql'); }
    }

    public function unusedTestBody(): void { throw new RuntimeException('No ejecutar como test independiente'); }
}
