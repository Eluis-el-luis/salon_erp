<?php

namespace Tests\Support;

use RuntimeException;

/** Lista permitida fija. No abre conexiones ni ejecuta SQL. */
final class TestDatabaseSafety
{
    public static function assertResetAuthorized(bool $authorized): void
    {
        if (! $authorized) {
            throw new RuntimeException('Recreación/truncado bloqueado. Requiere autorización y SALON_TEST_DB_RESET_ALLOWED=1 en .env.testing.');
        }
    }

    public static function assertApplicationRoot(string $expected, string $candidate): void
    {
        if ($candidate === '') {
            throw new RuntimeException('APP_BASE_PATH no puede estar vacío.');
        }
        $expectedPath = realpath($expected);
        $candidatePath = realpath($candidate);
        if ($expectedPath === false || $candidatePath === false) {
            throw new RuntimeException('Raíz de pruebas inexistente o no autorizada.');
        }
        $matches = PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($expectedPath, $candidatePath) === 0
            : $expectedPath === $candidatePath;
        if (! $matches) {
            throw new RuntimeException('La raíz de aplicación no coincide con este proyecto.');
        }
    }

    public static function assertConnection(array $connection): void
    {
        $expected = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'salon_erp_test',
            'username' => 'salon_erp_tester',
        ];

        foreach ($expected as $key => $value) {
            if (($connection[$key] ?? null) !== $value) {
                // No incluir valores de configuración: pueden contener secretos.
                throw new RuntimeException('Conexión de pruebas rechazada: campo '.$key.' no autorizado.');
            }
        }

        if (! in_array($connection['port'] ?? null, [3306, '3306'], true)) {
            throw new RuntimeException('Puerto SQL no autorizado para pruebas.');
        }

        foreach (['url', 'unix_socket', 'prefix'] as $key) {
            if (! in_array($connection[$key] ?? null, [null, ''], true)) {
                throw new RuntimeException('Redirección de conexión no permitida: '.$key.'.');
            }
        }

        foreach (['read', 'write', 'options'] as $key) {
            if (isset($connection[$key]) && $connection[$key] !== []) {
                throw new RuntimeException('Opciones o destinos adicionales no permitidos: '.$key.'.');
            }
        }
    }

    public static function assertConfiguration(string $environment, array $database, array $transactionConnections = ['mysql']): void
    {
        if ($environment !== 'testing' || ($database['default'] ?? null) !== 'mysql') {
            throw new RuntimeException('Solo se permite el entorno testing y la conexión predeterminada mysql.');
        }

        if (array_keys($database['connections'] ?? []) !== ['mysql'] || $transactionConnections !== ['mysql']) {
            throw new RuntimeException('Las pruebas deben utilizar exclusivamente la conexión mysql autorizada.');
        }

        self::assertConnection($database['connections']['mysql']);
    }

    public static function assertServerIdentity(object $identity): void
    {
        if (($identity->database_name ?? null) !== 'salon_erp_test'
            || ($identity->account_name ?? null) !== 'salon_erp_tester@localhost'
            || (int) ($identity->server_port ?? 0) !== 3306) {
            throw new RuntimeException('El servidor SQL no confirmó la base, cuenta efectiva y puerto autorizados.');
        }
    }
}
