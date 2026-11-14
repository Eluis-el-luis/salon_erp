<?php

// Inspección independiente: SELECT/SHOW, sin Laravel y sin consultas de escritura.
ini_set('zend.exception_ignore_args', '1');
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $snapshot = [];
    $phase = $argv[2] ?? '05';
    $schemas = in_array($phase, ['H16', 'H15', 'H01A', 'H02A'], true) ? ['salon_erp', 'salon_erp_test', 'salon_erp_bootstrap_check'] : ['salon_erp', 'salon_erp_test'];
    foreach ($schemas as $schema) {
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) { throw new RuntimeException('Identificador inesperado'); }
            $rows = [];
            $query = $pdo->query("SELECT * FROM `$schema`.`$table`");
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) { $rows[] = hash('sha256', serialize($row)); }
            sort($rows, SORT_STRING);
            $ddl = $pdo->query("SHOW CREATE TABLE `$schema`.`$table`")->fetch(PDO::FETCH_NUM)[1];
            $snapshot[$schema][$table] = ['count' => count($rows), 'content' => hash('sha256', implode('', $rows)), 'schema' => hash('sha256', $ddl)];
        }
    }
    if (! in_array($phase, ['05', '06', 'H12', 'H14A', 'H13', 'H13B', 'H13C', 'H16', 'H15', 'H01A', 'H02A'], true)) { throw new RuntimeException('Fase inválida'); }
    $file = dirname(__DIR__, 2).'/storage/app/private/isolation-'.$phase.'-before.json';
    if (($argv[1] ?? '') === 'before') {
        $handle = fopen($file, 'x');
        if (! $handle) { throw new RuntimeException('Baseline existente; no sobrescribir'); }
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json)) { throw new RuntimeException('Baseline incompleto'); }
        fclose($handle);
    } elseif (($argv[1] ?? '') === 'after') {
        $baseline = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (! in_array($phase, ['05', 'H13C'], true)) {
            if ($snapshot['salon_erp'] !== $baseline['salon_erp']) {
                foreach (array_unique(array_merge(array_keys($baseline['salon_erp']), array_keys($snapshot['salon_erp']))) as $table) {
                    $before = $baseline['salon_erp'][$table] ?? null;
                    $after = $snapshot['salon_erp'][$table] ?? null;
                    if ($before !== $after) {
                        echo json_encode(['schema'=>'salon_erp','table'=>$table,'before_count'=>$before['count'] ?? null,
                            'after_count'=>$after['count'] ?? null,'content_equal'=>($before['content'] ?? null)===($after['content'] ?? null),
                            'structure_equal'=>($before['schema'] ?? null)===($after['schema'] ?? null)], JSON_THROW_ON_ERROR).PHP_EOL;
                    }
                }
                $developmentChanged = true;
            }
            if (! ($developmentChanged ?? false)) echo "DEVELOPMENT_ALL_COUNTS_CONTENT_AND_SCHEMA_EQUAL=True\n";
            if (in_array($phase, ['H16', 'H15', 'H01A', 'H02A'], true)) {
                if ($snapshot['salon_erp_bootstrap_check'] !== $baseline['salon_erp_bootstrap_check']) { throw new RuntimeException('Diferencias en ensayo bootstrap'); }
                echo "BOOTSTRAP_CHECK_ALL_COUNTS_CONTENT_AND_SCHEMA_EQUAL=True\n";
            }
        } else {
            if ($snapshot !== $baseline) { throw new RuntimeException('Diferencias antes/después'); }
            echo "ALL_TABLE_COUNTS_CONTENT_AND_SCHEMA_EQUAL=True\n";
        }
    } else { throw new RuntimeException('Modo inválido'); }
    foreach ($snapshot as $schema => $tables) {
        echo json_encode(['schema' => $schema, 'tables' => count($tables), 'rows' => array_sum(array_column($tables, 'count')), 'migrations' => $tables['migrations']['count'] ?? null], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    if ($developmentChanged ?? false) throw new RuntimeException('Diferencias en desarrollo');
} catch (Throwable $e) {
    fwrite(STDERR, 'Snapshot failed; code='.(string)$e->getCode().PHP_EOL);
    exit(1);
}
