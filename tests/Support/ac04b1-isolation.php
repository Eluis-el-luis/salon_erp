<?php

ini_set('zend.exception_ignore_args','1');$root=dirname(__DIR__,2);
try {
    $mode=$argv[1]??'';if(!in_array($mode,['before','after'],true))throw new RuntimeException('Modo inválido');
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $file=$root.'/storage/app/private/ac04b1-isolation-before.json';$base=$mode==='after'?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):[];$snap=[];
    foreach(['salon_erp','salon_erp_test','salon_erp_bootstrap_check'] as $schema) {
        foreach($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if(!preg_match('/^[a-z_0-9]+$/D',$table))throw new RuntimeException('Identificador inválido');
            $cols=$pdo->query("SHOW COLUMNS FROM `$schema`.`$table`")->fetchAll(PDO::FETCH_ASSOC);
            $names=array_column($base[$schema][$table]['columns']??$cols,'Field');$select=implode(',',array_map(fn($s)=>'`'.$s.'`',$names));$rows=[];
            $sql="SELECT $select FROM `$schema`.`$table`";
            if($mode==='after'&&$schema==='salon_erp_test'&&$table==='migrations')$sql.=" WHERE migration <> '2026_11_16_000002_add_accounting_event_documentation'";
            foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r)$rows[]=hash('sha256',serialize($r));sort($rows);
            $ddl=$pdo->query("SHOW CREATE TABLE `$schema`.`$table`")->fetch(PDO::FETCH_NUM)[1];
            $snap[$schema][$table]=['columns'=>$cols,'rows'=>$rows,'ddl'=>hash('sha256',preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$ddl))];
        }
    }
    if($mode==='before'){$h=fopen($file,'xb');if(!$h)throw new RuntimeException('Baseline existente');fwrite($h,json_encode($snap,JSON_THROW_ON_ERROR));fclose($h);}
    else {
        foreach(['salon_erp','salon_erp_bootstrap_check'] as $s)if($snap[$s]!==$base[$s])throw new RuntimeException('Base ajena modificada');
        $indexed=['accounting_policy_versions','accounting_account_bindings','asientos_contables','detalle_asientos','movimientos_bancarios'];
        $append=['accounting_policy_versions','accounting_account_bindings','accounting_policy_audits'];
        foreach($base['salon_erp_test'] as $table=>$b) {
            $a=$snap['salon_erp_test'][$table]??throw new RuntimeException('Tabla previa ausente');
            if(in_array($table,$append,true)?array_diff($b['rows'],$a['rows']):$b['rows']!==$a['rows'])throw new RuntimeException('Filas previas cambiaron: '.$table);
            if(!in_array($table,$indexed,true)&&$a['ddl']!==$b['ddl'])throw new RuntimeException('DDL fuera de alcance');
            $old=array_column($b['columns'],null,'Field');$current=array_column($a['columns'],null,'Field');foreach($old as $key=>$col)if($current[$key]!==$col)throw new RuntimeException('Columna previa alterada');
        }
        $added=array_values(array_diff(array_keys($snap['salon_erp_test']),array_keys($base['salon_erp_test'])));sort($added);
        if($added!==['accounting_entry_line_resolutions','accounting_event_operations','accounting_policy_authorizations'])throw new RuntimeException('Tablas nuevas inesperadas');
        if((int)$pdo->query('SELECT COUNT(*) FROM salon_erp_test.migrations')->fetchColumn()!==81)throw new RuntimeException('Migraciones distintas');
        if((int)$pdo->query('SELECT COUNT(*) FROM salon_erp_test.asientos_contables WHERE accounting_policy_version_id IS NOT NULL OR accounting_policy_hash IS NOT NULL OR accounting_authorization_id IS NOT NULL')->fetchColumn()!==0)throw new RuntimeException('Se atribuyó política a asiento');
        echo "OTHER_DATABASES_EXACT=True; OLD_ROWS_PRESERVED=True; LEGACY_ENTRIES_UNATTRIBUTED=True\n";
    }
    foreach($snap as $schema=>$tables)echo json_encode(['schema'=>$schema,'tables'=>count($tables),'rows'=>array_sum(array_map(fn($t)=>count($t['rows']),$tables))+($mode==='after'&&$schema==='salon_erp_test'?1:0),'migrations'=>(int)$pdo->query("SELECT COUNT(*) FROM `$schema`.migrations")->fetchColumn()]),PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,'Aislamiento detenido: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);exit(1);}
