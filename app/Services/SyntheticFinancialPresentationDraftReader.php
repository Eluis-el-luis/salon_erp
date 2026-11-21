<?php

namespace App\Services;

use Closure;
use DomainException;
use PDO;
use Tests\Support\AccountingTestSchemaProfiles;

/** Internal test-only reader. Fixed destination; no caller-supplied PDO, DSN or SQL. */
final class SyntheticFinancialPresentationDraftReader
{
    public const CONTRACT = 'AC05B3B4_READ_ONLY_V1';

    private function __construct(private PDO $pdo, private ?Closure $probe) {}

    public static function forHarness(?Closure $probe = null): self
    {
        self::boundary();
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4',
            'salon_erp_tester', $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_STRINGIFY_FETCHES=>false]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        return new self($pdo, $probe);
    }

    public static function boundary(): void
    {
        if (PHP_SAPI !== 'cli' || PHP_VERSION !== '8.3.30'
            || !defined('SALON_SYNTHETIC_DRAFT_READER') || SALON_SYNTHETIC_DRAFT_READER !== self::CONTRACT
            || !defined('SALON_TEST_DATABASE_BOOTSTRAPPED') || !SALON_TEST_DATABASE_BOOTSTRAPPED
            || !defined('SALON_TEST_DATABASE_RESET_ALLOWED') || SALON_TEST_DATABASE_RESET_ALLOWED
            || getenv('SALON_TEST_DB_RESET_ALLOWED') !== '0' || getenv('APP_ENV') !== 'testing'
            || realpath(getcwd()) !== realpath(dirname(__DIR__, 2))) throw new DomainException('reader.boundary');
    }

    public function read(string $uuid): SyntheticPresentationDraftSnapshot
    {
        self::boundary();
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $uuid)) throw new DomainException('reader.uuid');
        if ($this->pdo->inTransaction()) throw new DomainException('reader.snapshot_must_be_owned');
        if ((int)$this->pdo->query('SELECT @@SESSION.transaction_read_only')->fetchColumn() !== 1
            || $this->pdo->query('SELECT @@SESSION.transaction_isolation')->fetchColumn() !== 'REPEATABLE-READ') throw new DomainException('reader.snapshot_settings');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            AccountingTestSchemaProfiles::assertSchema(AccountingTestSchemaProfiles::CURRENT, $this->metadata());
            foreach (['salon_erp','salon_erp_bootstrap_check'] as $schema) {
                try { $this->pdo->query("SELECT 1 FROM `$schema`.users LIMIT 0"); throw new DomainException('reader.foreign_database_accessible'); }
                catch (\PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1142) throw $e; }
            }
            $actor = $this->pdo->query("SELECT id FROM users WHERE is_active=1 AND role IN ('alvaro','contador') ORDER BY id LIMIT 1")->fetchColumn();
            if ($actor === false) throw new DomainException('reader.actor_unavailable');
            $s = $this->pdo->prepare('SELECT * FROM test_financial_presentation_drafts WHERE document_uuid=?');
            $s->execute([$uuid]); $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new DomainException('document.not_found');
            if ($this->probe) ($this->probe)('after_header');
            $s = $this->pdo->prepare('SELECT * FROM test_financial_presentation_draft_audits WHERE draft_id=? ORDER BY revision');
            $s->execute([$row['id']]); $audits = $s->fetchAll(PDO::FETCH_ASSOC);
            $validation = (new SyntheticPresentationDraftIntegrity)->verify($row, $audits, (int)$actor);
            $snapshot = ['contract'=>self::CONTRACT,'schema_profile'=>AccountingTestSchemaProfiles::CURRENT,
                'isolation'=>'REPEATABLE READ','read_only'=>true,'owned'=>true,
                'connection_id'=>(int)$this->pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
                'business_authority'=>false,'publishable'=>false,'issued_statement'=>false];
            $this->pdo->commit();
            return new SyntheticPresentationDraftSnapshot($row, $audits, $validation, $snapshot);
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        finally { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
    }

    private function metadata(): array
    {
        $identity = $this->pdo->query('SELECT DATABASE() database_name,CURRENT_USER() account_name,@@port server_port,CURRENT_ROLE() roles,VERSION() version')->fetch(PDO::FETCH_ASSOC);
        $tables = [];
        foreach ($this->pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (!preg_match('/^[a-z_0-9]+$/D', $name)) throw new DomainException('reader.identifier');
            $tables[$name] = $this->pdo->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM)[1];
        }
        return ['identity'=>$identity,'tables'=>$tables,
            'migrations'=>$this->pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN),
            'grants'=>$this->pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)];
    }
}
