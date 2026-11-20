<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

/** Fixed CLI test connection. Never accepts a DSN, password, actor or database from a caller. */
final class SyntheticPresentationHarness
{
    public const CONTRACT = 'AC05B3B2_SYNTHETIC_DRAFTS_CLI_V1';

    public static function boundary(): void
    {
        if (PHP_SAPI !== 'cli' || PHP_VERSION !== '8.3.30'
            || !defined('SALON_SYNTHETIC_DRAFT_HARNESS') || SALON_SYNTHETIC_DRAFT_HARNESS !== self::CONTRACT
            || !defined('SALON_TEST_DATABASE_BOOTSTRAPPED') || !SALON_TEST_DATABASE_BOOTSTRAPPED
            || !defined('SALON_TEST_DATABASE_RESET_ALLOWED') || SALON_TEST_DATABASE_RESET_ALLOWED
            || getenv('SALON_TEST_DB_RESET_ALLOWED') !== '0' || getenv('APP_ENV') !== 'testing'
            || realpath(getcwd()) !== realpath(dirname(__DIR__, 2))) {
            throw new RuntimeException('Synthetic draft CLI boundary rejected.');
        }
    }

    public static function connect(): PDO
    {
        self::boundary();
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4',
            'salon_erp_tester', $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
        self::identity($pdo);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        return $pdo;
    }

    public static function identity(PDO $pdo): void
    {
        self::boundary();
        $i = $pdo->query('SELECT DATABASE() database_name,CURRENT_USER() account_name,@@port server_port,CURRENT_ROLE() roles,VERSION() version')->fetch(PDO::FETCH_OBJ);
        TestDatabaseSafety::assertServerIdentity($i);
        if ($i->roles !== 'NONE' || $i->version !== '8.4.3') throw new RuntimeException('Unexpected SQL roles/version.');
    }

    public static function preflight(PDO $pdo, int $tables): array
    {
        self::identity($pdo);
        $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        $expected = ['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX','REFERENCES']; sort($expected);
        if (count($grants) !== 2 || !in_array('GRANT USAGE ON *.* TO `salon_erp_tester`@`localhost`', $grants, true)) throw new RuntimeException('Unexpected global grants.');
        foreach ($grants as $grant) if (!str_starts_with($grant, 'GRANT USAGE ')) {
            [$p, $s] = explode(' ON ', $grant, 2); $ps = explode(', ', substr($p, 6)); sort($ps);
            if ($ps !== $expected || $s !== '`'.str_replace('_', chr(92).'_', 'salon_erp_test').'`.* TO `salon_erp_tester`@`localhost`') throw new RuntimeException('Unexpected grants.');
        }
        foreach (['salon_erp', 'salon_erp_bootstrap_check'] as $schema) {
            try { $pdo->query("SELECT 1 FROM `$schema`.users LIMIT 0"); throw new RuntimeException('Foreign database accessible.'); }
            catch (\PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1142) throw $e; }
        }
        $files = array_map(fn ($f) => basename($f, '.php'), glob(dirname(__DIR__, 2).'/database/migrations/*.php')); sort($files);
        if (count($files) !== 82 || $files !== $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN)) throw new RuntimeException('Migration set changed.');
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchColumn() !== $tables) throw new RuntimeException('Unexpected table count.');
        $prepared = $pdo->query('SELECT status,bank_account_id,result_movement_id,result_asiento_id FROM accounting_event_operations WHERE id=131')->fetch(PDO::FETCH_ASSOC);
        if (!$prepared || $prepared['status'] !== 'prepared' || (int)$prepared['bank_account_id'] !== 221 || $prepared['result_movement_id'] !== null || $prepared['result_asiento_id'] !== null) throw new RuntimeException('Historical prepared document changed.');
        $structures = [];
        foreach (['users','cuentas_contables','asientos_contables','detalle_asientos','accounting_policy_versions','accounting_policy_audits'] as $table) {
            $structures[$table] = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
            if (!str_contains($structures[$table], 'ENGINE=InnoDB')) throw new RuntimeException('Nontransactional parent.');
        }
        return ['identity' => 'salon_erp_tester@localhost', 'database' => 'salon_erp_test', 'migrations' => 82,
            'tables' => $tables, 'other_databases_denied' => true, 'reset' => 0, 'structures' => $structures];
    }
}
