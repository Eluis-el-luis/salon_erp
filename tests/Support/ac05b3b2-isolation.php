<?php

// Administrative READ ONLY baseline, separate from the restricted repository connection.
ini_set('zend.exception_ignore_args', '1');
$root = dirname(__DIR__, 2); $exit = 1;
try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION !== '8.3.30' || realpath(getcwd()) !== realpath($root)) throw new RuntimeException('Invalid baseline process.');
    $mode = $argv[1] ?? ''; if (!in_array($mode, ['before','after'], true)) throw new RuntimeException('Invalid mode.');
    require $root.'/tests/bootstrap.php';
    if (SALON_TEST_DATABASE_RESET_ALLOWED) throw new RuntimeException('Reset forbidden.');
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction(); $snap = [];
    foreach (['salon_erp','salon_erp_test','salon_erp_bootstrap_check'] as $schema) {
        foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!preg_match('/^[a-z_0-9]+$/D', $table)) throw new RuntimeException('Invalid identifier.');
            $rows = []; foreach ($pdo->query("SELECT * FROM `$schema`.`$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = hash('sha256', serialize($r)); sort($rows);
            $ddl = $pdo->query("SHOW CREATE TABLE `$schema`.`$table`")->fetch(PDO::FETCH_NUM)[1];
            $snap[$schema][$table] = ['rows' => $rows, 'ddl' => hash('sha256', $ddl)];
        }
    }
    $pdo->commit(); $file = $root.'/storage/app/private/ac05b3b2-isolation-before.json';
    if ($mode === 'before') {
        $f = fopen($file, 'xb'); if (!$f) throw new RuntimeException('Baseline already exists.');
        fwrite($f, json_encode($snap, JSON_THROW_ON_ERROR)); fclose($f);
    } else {
        $base = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        foreach (['salon_erp','salon_erp_bootstrap_check'] as $s) if ($base[$s] !== $snap[$s]) throw new RuntimeException('Foreign database changed.');
        foreach ($base['salon_erp_test'] as $table => $data) if (($snap['salon_erp_test'][$table] ?? null) !== $data) throw new RuntimeException('Original table changed: '.$table);
        $added = array_values(array_diff(array_keys($snap['salon_erp_test']), array_keys($base['salon_erp_test']))); sort($added);
        if ($added !== ['test_financial_presentation_draft_audits','test_financial_presentation_drafts']) throw new RuntimeException('Unexpected added tables.');
        $f = fopen($root.'/storage/app/private/ac05b3b2-isolation-after.json', 'xb'); if (!$f) throw new RuntimeException('Final baseline exists.');
        fwrite($f, json_encode($snap, JSON_THROW_ON_ERROR)); fclose($f);
        echo "OTHER_DATABASES_EXACT=true; ALL_ORIGINAL_ROWS_AND_DDL_EXACT=true\n";
    }
    foreach ($snap as $s => $tables) echo json_encode(['schema' => $s, 'tables' => count($tables), 'rows' => array_sum(array_map(fn ($t) => count($t['rows']), $tables))]), PHP_EOL;
    $exit = 0;
} catch (Throwable $e) { fwrite(STDERR, 'Isolation stopped: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL); }
finally { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
exit($exit);
