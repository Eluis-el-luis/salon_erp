<?php

namespace Tests\Support;

use App\Services\FinancialPresentationContractValidator;
use PDO;
use RuntimeException;

/** Read-only metadata and row evidence. No installer, fixture writer or financial consumer. */
final class AccountingSchemaInspection
{
    public static function restricted(): PDO
    {
        $pdo = SyntheticPresentationHarness::connect();
        SyntheticPresentationHarness::preflight($pdo, 91);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        return $pdo;
    }

    public static function metadata(PDO $pdo): array
    {
        if($pdo->inTransaction())throw new RuntimeException('Metadata reader must own its snapshot.');
        $identity = (array)$pdo->query('SELECT DATABASE() database_name,CURRENT_USER() account_name,@@port server_port,CURRENT_ROLE() roles,VERSION() version')->fetch(PDO::FETCH_OBJ);
        TestDatabaseSafety::assertServerIdentity((object)$identity);
        if($identity['roles']!=='NONE'||$identity['version']!=='8.4.3'||(int)$pdo->query('SELECT @@SESSION.transaction_read_only')->fetchColumn()!==1)throw new RuntimeException('Restricted read-only metadata session required.');
        $pdo->beginTransaction();
        try {
            $tables = [];
            foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                self::identifier($table);
                $tables[$table] = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
            }
            $migrations = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
            $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN); sort($grants);
            $pdo->commit();
            return ['identity'=>$identity,'tables'=>$tables,'migrations'=>$migrations,'grants'=>$grants];
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Administrative reader is only for independent comparisons of all three databases. */
    public static function snapshot(): array
    {
        SyntheticPresentationHarness::boundary();
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $i = $pdo->query('SELECT CURRENT_USER() actor,@@port port,VERSION() version')->fetch(PDO::FETCH_OBJ);
        if ($i->actor !== 'root@localhost' || (int)$i->port !== 3306 || $i->version !== '8.4.3') throw new RuntimeException('Unexpected administrative reader.');
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET SESSION TRANSACTION READ ONLY'); $pdo->beginTransaction();
        try {
            $snap = [];
            foreach (['salon_erp','salon_erp_test','salon_erp_bootstrap_check'] as $schema) {
                foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                    self::identifier($table); $rows = [];
                    foreach ($pdo->query("SELECT * FROM `$schema`.`$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = hash('sha256',serialize($r));
                    sort($rows); $ddl = $pdo->query("SHOW CREATE TABLE `$schema`.`$table`")->fetch(PDO::FETCH_NUM)[1];
                    $snap[$schema][$table] = ['rows'=>$rows,'ddl'=>hash('sha256',$ddl)];
                }
            }
            $pdo->commit(); return $snap;
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function verifyDrafts(PDO $pdo): array
    {
        $pdo->beginTransaction();
        try {
            $drafts = $pdo->query('SELECT * FROM test_financial_presentation_drafts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $audits = $pdo->query('SELECT * FROM test_financial_presentation_draft_audits ORDER BY draft_id,revision')->fetchAll(PDO::FETCH_ASSOC);
            if (count($drafts) !== 42 || count($audits) !== 48) throw new RuntimeException('Synthetic row counts changed.');
            $validator = new FinancialPresentationContractValidator; $used = [];
            foreach ($drafts as $d) {
                $v = $validator->validate(json_decode($d['canonical_content'],true,512,JSON_THROW_ON_ERROR));
                $p = json_decode($d['provenance'],true,512,JSON_THROW_ON_ERROR);
                if ($v['hash'] !== $d['content_hash'] || $v['canonical_json'] !== $d['canonical_content']
                    || $d['purpose'] !== 'simulation' || $d['origin'] !== 'synthetic_fixture' || $d['installation_scope'] !== 'TEST-FIXTURE-ONLY'
                    || $d['status'] !== 'draft' || $v['canonical_content']['author'] !== 'SYNTHETIC:executor_'.$d['actor_id']
                    || $v['canonical_content']['family_uuid'] !== $d['family_uuid'] || $v['canonical_content']['version_number'] !== (int)$d['version_number']
                    || ($p['contract']??null) !== SyntheticPresentationHarness::CONTRACT || ($p['synthetic']??null) !== true
                    || ($p['actor_selection']??null) !== 'first_active_accounting_harness_actor') throw new RuntimeException('Draft integrity changed.');
                $history = array_values(array_filter($audits,fn($a)=>(int)$a['draft_id']===(int)$d['id'])); $previous = null;
                foreach ($history as $index=>$a) {
                    $av = $validator->validate(json_decode($a['after_content'],true,512,JSON_THROW_ON_ERROR));
                    if ((int)$a['revision'] !== $index+1 || (int)$a['expected_revision'] !== $index || $a['event'] !== ($index===0?'created':'revised')
                        || (int)$a['actor_id'] !== (int)$d['actor_id'] || $av['hash'] !== $a['after_hash'] || $av['canonical_json'] !== $a['after_content']
                        || $a['before_hash'] !== ($previous['after_hash']??null) || $a['before_content'] !== ($previous['after_content']??null)
                        || ($index===0 && $a['request_uuid'] !== $d['document_uuid'])) throw new RuntimeException('Audit chain changed.');
                    foreach (['family_uuid','version_number','purpose','origin','installation_scope','author','prepared_at'] as $field) {
                        if ($av['canonical_content'][$field] !== $v['canonical_content'][$field]) throw new RuntimeException('Audit identity changed.');
                    }
                    $used[$a['id']] = true; $previous = $a;
                }
                if (!$previous || count($history) !== (int)$d['revision'] || $previous['after_hash'] !== $d['content_hash'] || $previous['after_content'] !== $d['canonical_content']) throw new RuntimeException('Audit coverage changed.');
            }
            if (count($used) !== count($audits)) throw new RuntimeException('Orphan audit.');
            $prepared = $pdo->query('SELECT * FROM accounting_event_operations WHERE id=131')->fetch(PDO::FETCH_ASSOC);
            if (!$prepared || $prepared['status'] !== 'prepared' || (int)$prepared['bank_account_id'] !== 221 || $prepared['result_movement_id'] !== null || $prepared['result_asiento_id'] !== null) throw new RuntimeException('Prepared 131 changed.');
            $pdo->commit();
            return ['drafts'=>42,'audits'=>48,'chains_valid'=>true,'prepared_131_hash'=>hash('sha256',serialize($prepared)),'business_authority'=>false];
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    private static function identifier(string $name): void { if (!preg_match('/^[a-z_0-9]+$/D',$name)) throw new RuntimeException('Unexpected table identifier.'); }
}
