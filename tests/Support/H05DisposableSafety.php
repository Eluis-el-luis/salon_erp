<?php

namespace Tests\Support;

use RuntimeException;

/** Contrato puro del PLAN H05-V0. No carga Laravel, secretos ni conexiones. */
final class H05DisposableSafety
{
    public const SCHEMA = 'salon_erp_h05_disposable';
    public const USER = 'h05_runner';
    public const PORT = 3307;
    public const DATADIR = 'E:/salon-erp-h05-disposable/run-h05v1/mysql/data';

    public static function assertConfiguration(array $config): void
    {
        if (($config['environment'] ?? null) !== 'testing'
            || ($config['reset_allowed'] ?? null) !== false
            || ($config['inventory_fractional_v2'] ?? null) !== false) {
            throw new RuntimeException('h05.plan.environment');
        }
        $database = $config['database'] ?? [];
        if (($database['default'] ?? null) !== 'mysql'
            || array_keys($database['connections'] ?? []) !== ['mysql']
            || !empty($database['redis'])) {
            throw new RuntimeException('h05.plan.connections');
        }
        $connection = $database['connections']['mysql'];
        foreach (['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => self::SCHEMA,
            'username' => self::USER, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci',
            'strict' => true] as $key => $value) {
            if (($connection[$key] ?? null) !== $value) throw new RuntimeException('h05.plan.connection.'.$key);
        }
        if (!in_array($connection['port'] ?? null, [self::PORT, (string) self::PORT], true)) {
            throw new RuntimeException('h05.plan.port');
        }
        foreach (['url', 'unix_socket', 'prefix'] as $key) {
            if (!in_array($connection[$key] ?? null, [null, ''], true)) throw new RuntimeException('h05.plan.redirect');
        }
        foreach (['read', 'write', 'options'] as $key) {
            if (isset($connection[$key]) && $connection[$key] !== []) throw new RuntimeException('h05.plan.options');
        }
        foreach (['cache' => 'array', 'session' => 'array', 'mail' => 'array', 'log' => 'null',
            'queue' => 'sync', 'broadcast' => 'null', 'workers' => false, 'scheduler' => false,
            'external_network' => false, 'shared_storage' => false] as $key => $value) {
            if (($config['effects'][$key] ?? null) !== $value) throw new RuntimeException('h05.plan.effects.'.$key);
        }
    }

    /** Futura lectura SELECT: UUID esperado obtenido del manifiesto aprobado, nunca del mismo SELECT. */
    public static function assertServerIdentity(object $identity, string $expectedUuid): void
    {
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $expectedUuid)) {
            throw new RuntimeException('h05.plan.uuid');
        }
        $path = str_replace('\\', '/', $identity->data_directory ?? '');
        if (($identity->database_name ?? null) !== self::SCHEMA
            || ($identity->account_name ?? null) !== self::USER.'@127.0.0.1'
            || !in_array($identity->server_port ?? null, [self::PORT, (string) self::PORT], true)
            || ($identity->server_uuid ?? null) !== $expectedUuid
            || strcasecmp(rtrim($path, '/'), self::DATADIR) !== 0
            || ($identity->role_name ?? null) !== 'NONE'
            || ($identity->mysql_version ?? null) !== '8.4.3') {
            throw new RuntimeException('h05.plan.identity');
        }
    }

    /** Inventario de permisos NORMALIZADO por un lector futuro: aquí no se analiza SHOW GRANTS. */
    public static function assertRuntimeGrants(array $grants): void
    {
        $found = false;
        foreach ($grants as $grant) {
            $privileges = $grant['privileges'] ?? [];
            sort($privileges);
            if (($grant['grantable'] ?? null) !== false) throw new RuntimeException('h05.plan.grant_option');
            if (($grant['scope'] ?? null) === 'global' && $privileges === ['USAGE']) continue;
            if (($grant['scope'] ?? null) !== 'schema' || ($grant['schema'] ?? null) !== self::SCHEMA
                || $privileges !== ['DELETE', 'INSERT', 'SELECT', 'UPDATE']) {
                throw new RuntimeException('h05.plan.grants');
            }
            $found = true;
        }
        if (!$found) throw new RuntimeException('h05.plan.grants_missing');
    }
}
