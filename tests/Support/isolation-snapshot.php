<?php

// Inspección independiente: SELECT/SHOW, sin Laravel y sin consultas de escritura.
ini_set('zend.exception_ignore_args', '1');
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $snapshot = [];
    $phase = $argv[2] ?? '05';
    $schemas = in_array($phase, ['H16', 'H15', 'H01A', 'H02A', 'H04A', 'H03B1', 'H03B2', 'H03B2C', 'H04B', 'H01B2A', 'AC02', 'AC03A', 'AC03B'], true) ? ['salon_erp', 'salon_erp_test', 'salon_erp_bootstrap_check'] : ['salon_erp', 'salon_erp_test'];
    foreach ($schemas as $schema) {
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) { throw new RuntimeException('Identificador inesperado'); }
            $rows = [];
            $query = $pdo->query("SELECT * FROM `$schema`.`$table`");
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) { $rows[] = hash('sha256', serialize($row)); }
            sort($rows, SORT_STRING);
            $ddl = $pdo->query("SHOW CREATE TABLE `$schema`.`$table`")->fetch(PDO::FETCH_NUM)[1];
            $snapshot[$schema][$table] = ['count' => count($rows), 'content' => hash('sha256', implode('', $rows)), 'schema' => hash('sha256', $ddl)]
                + (in_array($phase,['AC02','AC03A','AC03B'],true) ? ['structure' => hash('sha256', preg_replace('/AUTO_INCREMENT=[0-9]+/', 'AUTO_INCREMENT=*', $ddl))] : [])
                + (in_array($phase,['AC02','AC03A','AC03B'],true) && $schema === 'salon_erp_test' ? ['without_sequence' => hash('sha256', preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', $ddl))] : [])
                + ($phase==='AC03B' && $schema==='salon_erp_test' && in_array($table,['accounting_policy_versions','accounting_account_bindings','accounting_policy_audits'],true)?['row_fingerprints'=>$rows]:[]);
        }
    }
    if (! in_array($phase, ['05', '06', 'H12', 'H14A', 'H13', 'H13B', 'H13C', 'H16', 'H15', 'H01A', 'H02A', 'H04A', 'H03B1', 'H03B2', 'H03B2C', 'H04B', 'H01B2A', 'AC02', 'AC03A', 'AC03B'], true)) { throw new RuntimeException('Fase inválida'); }
    $file = dirname(__DIR__, 2).'/storage/app/private/isolation-'.$phase.'-before.json';
    if (($argv[1] ?? '') === 'before') {
        $handle = fopen($file, 'x');
        if (! $handle) { throw new RuntimeException('Baseline existente; no sobrescribir'); }
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json)) { throw new RuntimeException('Baseline incompleto'); }
        fclose($handle);
    } elseif (($argv[1] ?? '') === 'after') {
        $baseline = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if ($phase === 'AC03B') {
            if(array_keys($baseline['salon_erp_test'])!==array_keys($snapshot['salon_erp_test']))throw new RuntimeException('AC03B: cambio de tablas');
            foreach($baseline['salon_erp_test'] as $table=>$before) {
                $after=$snapshot['salon_erp_test'][$table];
                foreach(['structure','count','content'] as $key) {
                    if($key!=='structure' && isset($before['row_fingerprints']))continue;
                    if($before[$key]!==$after[$key] && !($key==='structure' && $before['schema']===$after['without_sequence']))throw new RuntimeException('AC03B: cambió tabla anterior '.$table.' '.$key);
                }
                if(isset($before['row_fingerprints']) && array_diff($before['row_fingerprints'],$after['row_fingerprints']))throw new RuntimeException('AC03B: cambió evidencia previa');
            }
            echo "TEST_BUSINESS_HISTORY_STRUCTURE_AND_80_MIGRATIONS_EQUAL=True\nAC03A_EXISTING_EVIDENCE_PRESERVED=True\n";
        }
        if ($phase === 'AC03A') {
            foreach($baseline['salon_erp_test'] as $table=>$before) {
                if($table==='migrations')continue;
                $after=$snapshot['salon_erp_test'][$table]??[];
                foreach(['count','content','structure'] as $key)if(($before[$key]??null)!==($after[$key]??null)) {
                    if($key==='structure' && $before['schema']===($after['without_sequence']??null))continue;
                    throw new RuntimeException('AC03A: cambio en tabla anterior: '.$table.' '.$key);
                }
            }
            $added=array_values(array_diff(array_keys($snapshot['salon_erp_test']),array_keys($baseline['salon_erp_test'])));sort($added);
            if($added!==['accounting_account_bindings','accounting_policy_audits','accounting_policy_versions'] || $snapshot['salon_erp_test']['migrations']['count']!==80)throw new RuntimeException('AC03A: esquema final inesperado');
            $old=[];$q=$pdo->query("SELECT * FROM salon_erp_test.migrations WHERE migration <> '2026_11_16_000001_create_versioned_accounting_policies'");
            while($r=$q->fetch(PDO::FETCH_ASSOC))$old[]=hash('sha256',serialize($r));sort($old);
            if(count($old)!==$baseline['salon_erp_test']['migrations']['count'] || hash('sha256',implode('',$old))!==$baseline['salon_erp_test']['migrations']['content'])throw new RuntimeException('AC03A: cambiaron las 79 migraciones anteriores');
            echo "TEST_OLD_CONTENT_STRUCTURE_AND_79_MIGRATIONS_EQUAL=True\n";
        }
        if ($phase === 'AC02') {
            $ac02Changed=false;
            foreach ($baseline['salon_erp_test'] as $table=>$before) {
                $after=$snapshot['salon_erp_test'][$table]??[];
                foreach (['count','content','structure'] as $key) if (($before[$key]??null)!==($after[$key]??null)) {
                    // MySQL omite AUTO_INCREMENT=1 cuando nunca se insertaron fixtures.
                    // Aceptar solo si el DDL actual sin esa cláusula coincide exactamente
                    // con el hash del DDL original, no por asumir que toda diferencia es secuencia.
                    if ($key==='structure' && $before['schema']===($after['without_sequence']??null)) continue;
                    echo json_encode(['table'=>$table,'changed'=>$key,'before_count'=>$before['count'],'after_count'=>$after['count']??null]).PHP_EOL;
                    $ac02Changed=true;
                }
            }
            if ($ac02Changed) throw new RuntimeException('AC02: diferencias en test');
            if (array_keys($baseline['salon_erp_test'])!==array_keys($snapshot['salon_erp_test'])) throw new RuntimeException('AC02: cambi? la lista de tablas');
            echo "TEST_COUNTS_CONTENT_AND_STRUCTURE_EQUAL=True; autoincrement may advance in rolled-back fixtures\n";
        }
        if ($phase === 'H01B2A') {
            foreach ($baseline['salon_erp_test'] as $table => $original) {
                if ($table === 'migrations') continue;
                if (($snapshot['salon_erp_test'][$table] ?? null) !== $original) {
                    throw new RuntimeException('Cambió una tabla histórica de test: '.$table);
                }
            }
            $expectedNew = ['inventory_opening_lines','inventory_openings'];
            $newTables = array_values(array_diff(array_keys($snapshot['salon_erp_test']),array_keys($baseline['salon_erp_test'])));
            sort($newTables);
            if ($newTables !== $expectedNew || $snapshot['salon_erp_test']['migrations']['count'] !== 79) {
                throw new RuntimeException('Esquema documental final inesperado');
            }
            $oldRows = [];
            $q = $pdo->query("SELECT * FROM salon_erp_test.migrations WHERE migration <> '2026_11_14_000005_create_inventory_opening_documents'");
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) $oldRows[] = hash('sha256',serialize($row));
            sort($oldRows,SORT_STRING);
            if (count($oldRows) !== $baseline['salon_erp_test']['migrations']['count']
                || hash('sha256',implode('',$oldRows)) !== $baseline['salon_erp_test']['migrations']['content']) {
                throw new RuntimeException('Cambió el registro de las 78 migraciones previas');
            }
            echo "TEST_HISTORICAL_COUNTS_CONTENT_SCHEMA_AND_78_MIGRATION_ROWS_EQUAL=True\n";
        }
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
            if (in_array($phase, ['H16', 'H15', 'H01A', 'H02A', 'H04A', 'H03B1', 'H03B2', 'H03B2C', 'H04B', 'H01B2A', 'AC02', 'AC03A', 'AC03B'], true)) {
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
