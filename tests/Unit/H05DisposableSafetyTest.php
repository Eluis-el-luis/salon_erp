<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\H05DisposableSafety as Guard;

/** Solo matrices y lecturas simuladas. No importa el bootstrap SQL existente. */
final class H05DisposableSafetyTest extends TestCase
{
    private const UUID = '11111111-2222-3333-4444-555555555555';

    private static function config(): array
    {
        return ['environment' => 'testing', 'reset_allowed' => false, 'inventory_fractional_v2' => false,
            'database' => ['default' => 'mysql', 'connections' => ['mysql' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307,
                'database' => Guard::SCHEMA, 'username' => Guard::USER,
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci', 'strict' => true,
            ]]], 'effects' => ['cache' => 'array', 'session' => 'array', 'mail' => 'array', 'log' => 'null',
                'queue' => 'sync', 'broadcast' => 'null', 'workers' => false, 'scheduler' => false,
                'external_network' => false, 'shared_storage' => false]];
    }

    private static function identity(): object
    {
        return (object) ['database_name' => Guard::SCHEMA, 'account_name' => Guard::USER.'@127.0.0.1',
            'server_port' => 3307, 'server_uuid' => self::UUID, 'role_name' => 'NONE',
            'data_directory' => Guard::DATADIR.'/', 'mysql_version' => '8.4.3'];
    }

    public function test_accepts_only_the_explicit_plan_and_mock_identity(): void
    {
        Guard::assertConfiguration(self::config());
        $reader = $this->createMock(H05MockIdentityReader::class);
        $reader->expects(self::once())->method('read')->willReturn(self::identity());
        Guard::assertServerIdentity($reader->read(), self::UUID);
        self::assertSame('salon_erp_h05_disposable', Guard::SCHEMA);
    }

    public static function unauthorizedConnections(): array
    {
        $cases = [];
        foreach (['salon_erp', 'salon_erp_test', 'salon_erp_bootstrap_check', '', 'other'] as $schema) {
            $cases['schema '.$schema] = [['database' => $schema]];
        }
        foreach (['port' => 3306, 'username' => 'root', 'host' => 'localhost', 'driver' => 'sqlite',
            'url' => 'mysql://dummy@127.0.0.1:3306/salon_erp_test', 'unix_socket' => 'MySQL',
            'prefix' => 'salon_erp.', 'read' => ['host' => '127.0.0.1'],
            'write' => ['database' => 'salon_erp'], 'options' => [12 => true], 'strict' => false] as $key => $value) {
            $cases[$key] = [[$key => $value]];
        }
        return $cases;
    }

    #[DataProvider('unauthorizedConnections')]
    public function test_rejects_destination_before_any_identity_reader(array $override): void
    {
        $config = self::config();
        $config['database']['connections']['mysql'] = array_replace($config['database']['connections']['mysql'], $override);
        $reader = $this->createMock(H05MockIdentityReader::class);
        $reader->expects(self::never())->method('read');
        $this->expectException(RuntimeException::class);
        Guard::assertConfiguration($config);
        $reader->read();
    }

    public static function unauthorizedRuntime(): array
    {
        return [['environment', 'local'], ['reset_allowed', true], ['inventory_fractional_v2', true],
            ['alternate', true], ['redis', true], ['effects', true]];
    }

    #[DataProvider('unauthorizedRuntime')]
    public function test_rejects_unsafe_runtime(string $key, $value): void
    {
        $config = self::config();
        if ($key === 'alternate') $config['database']['connections']['legacy'] = [];
        elseif ($key === 'redis') $config['database']['redis'] = ['default' => ['host' => '127.0.0.1']];
        elseif ($key === 'effects') $config['effects']['shared_storage'] = true;
        else $config[$key] = $value;
        $this->expectException(RuntimeException::class);
        Guard::assertConfiguration($config);
    }

    public static function unauthorizedIdentity(): array
    {
        return [['database_name', 'salon_erp'], ['database_name', 'salon_erp_test'],
            ['database_name', 'salon_erp_bootstrap_check'], ['account_name', 'root@localhost'],
            ['server_port', 3306], ['server_uuid', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            ['data_directory', 'E:/laragon/data/mysql-8'], ['role_name', 'admin'], ['mysql_version', '8.0.0']];
    }

    #[DataProvider('unauthorizedIdentity')]
    public function test_rejects_unexpected_server_even_with_testing_configuration(string $key, $value): void
    {
        $identity = self::identity(); $identity->$key = $value;
        $this->expectException(RuntimeException::class);
        Guard::assertServerIdentity($identity, self::UUID);
    }

    public function test_runtime_grants_exclude_ddl_global_access_and_roles(): void
    {
        $grant = ['scope' => 'schema', 'schema' => Guard::SCHEMA,
            'privileges' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], 'grantable' => false];
        Guard::assertRuntimeGrants([$grant]);
        foreach ([[], [array_replace($grant, ['schema' => 'salon_erp_test'])],
            [array_replace($grant, ['privileges' => ['ALL PRIVILEGES']])],
            [array_replace($grant, ['grantable' => true])],
            [array_replace($grant, ['scope' => 'global'])],
            [array_replace($grant, ['scope' => 'role'])]] as $bad) {
            try {
                Guard::assertRuntimeGrants($bad);
                self::fail('Permisos demasiado amplios.');
            } catch (RuntimeException $e) { self::assertStringStartsWith('h05.plan.', $e->getMessage()); }
        }
    }
}

interface H05MockIdentityReader
{
    public function read(): object;
}
