<?php

// Solo esquema/fixtures documentales; nunca llama al escritor de stock ni RefreshDatabase.
ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2); $changed=false; $exit=1;
try {
    $mode=$argv[1]??'';
    $selections=['preflight'=>[], 'install'=>['OpeningDocumentInstallationTest'], 'empty-rollback'=>['OpeningDocumentEmptyRollbackTest'],
        'structure'=>['OpeningDocumentSchemaTest','LegacyOpeningContainmentTest'], 'guard'=>['OpeningDocumentGuardTest']];
    if (!isset($selections[$mode]) || PHP_VERSION!=='8.3.30') throw new RuntimeException('Modo o PHP no autorizado');
    require $root.'/tests/bootstrap.php';
    if (SALON_TEST_DATABASE_RESET_ALLOWED) throw new RuntimeException('El indicador debe comenzar en cero');
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4','salon_erp_tester',$_ENV['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $id=$pdo->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CURRENT_ROLE() AS roles')->fetch(PDO::FETCH_OBJ);
    Tests\Support\TestDatabaseSafety::assertServerIdentity($id);
    if ($id->roles!=='NONE') throw new RuntimeException('Roles SQL no autorizados');
    $grants=$pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $expected=['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX','REFERENCES']; sort($expected);
    if (count($grants)!==2 || !in_array('GRANT USAGE ON *.* TO `salon_erp_tester`@`localhost`',$grants,true)) throw new RuntimeException('Privilegios globales no autorizados');
    foreach ($grants as $grant) if (!str_starts_with($grant,'GRANT USAGE ')) {
        [$prefix,$scope]=explode(' ON ',$grant,2); $privileges=explode(', ',substr($prefix,6)); sort($privileges);
        $exact='`'.str_replace('_',chr(92).'_','salon_erp_test').'`.* TO `salon_erp_tester`@`localhost`';
        if ($privileges!==$expected || $scope!==$exact) throw new RuntimeException('Privilegios de esquema inesperados');
    }
    foreach (['salon_erp','salon_erp_bootstrap_check'] as $schema) {
        try {$pdo->query("SELECT 1 FROM `$schema`.`users` LIMIT 0"); throw new RuntimeException('Base ajena accesible');}
        catch (PDOException $e) {if (($e->errorInfo[1]??0)!==1142) throw $e;}
    }
    $migration='2026_11_14_000005_create_inventory_opening_documents';
    $files=array_map(fn ($f)=>basename($f,'.php'),glob($root.'/database/migrations/*.php'));
    if (count($files)!==79) throw new RuntimeException('Lista de migraciones inesperada');
    if (in_array($mode,['preflight','install'],true)) $files=array_values(array_diff($files,[$migration]));
    $applied=$pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    sort($files); sort($applied);
    if ($files!==$applied) throw new RuntimeException('Migraciones aplicadas/pendientes no coinciden con el alcance');
    $backup='E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql';
    if (!is_file($backup) || filesize($backup)===0 || !preg_match('/^([a-fA-F0-9]{64})\s/',file_get_contents($backup.'.sha256'),$m)
        || strtolower($m[1])!==hash_file('sha256',$backup)) throw new RuntimeException('Respaldo ausente o SHA256 inválido');
    echo 'PREFLIGHT: salon_erp_test / salon_erp_tester@localhost / 3306; roles NONE; privilegios exactos; ambas bases ajenas rechazadas; '.count($applied).' migraciones; SHA256 verificado'.PHP_EOL;
    $pdo=null;
    if ($mode==='preflight') {
        $exit=0;
    } else {
        $envFile=$root.'/.env.testing';
        $enabled=preg_replace('/^SALON_TEST_DB_RESET_ALLOWED=0\r?$/m','SALON_TEST_DB_RESET_ALLOWED=1',file_get_contents($envFile),1,$count);
        if ($count!==1) throw new RuntimeException('Indicador no habilitable de forma exacta');
        $changed=true;
        if (file_put_contents($envFile,$enabled,LOCK_EX)!==strlen($enabled)) throw new RuntimeException('No se escribió el indicador');
        unset($enabled);
        $args=[PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1',$root.'/vendor/phpunit/phpunit/phpunit',
            '--configuration',$root.'/phpunit.xml','--do-not-cache-result','--no-progress','--stop-on-error','--stop-on-failure'];
        foreach ($selections[$mode] as $test) $args[]=$root.'/tests/Feature/'.$test.'.php';
        $log=$root.'/storage/app/private/h01b2a-'.$mode.'-phpunit.log';
        $proc=proc_open($args,[0=>['pipe','r'],1=>['file',$log,'w'],2=>['redirect',1]],$pipes,$root);
        if (!is_resource($proc)) throw new RuntimeException('No se inició PHPUnit');
        fclose($pipes[0]); $exit=proc_close($proc);
        echo 'PHPUNIT_EXIT_CODE='.$exit.PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR,'H01-B2A detenido: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);
} finally {
    if ($changed) {
        $envFile=$root.'/.env.testing';
        $disabled=preg_replace('/^SALON_TEST_DB_RESET_ALLOWED=[01]\r?$/m','SALON_TEST_DB_RESET_ALLOWED=0',file_get_contents($envFile),1,$count);
        if ($count!==1 || file_put_contents($envFile,$disabled,LOCK_EX)!==strlen($disabled)) {$exit=1; fwrite(STDERR,'RESTORE_FAILED'.PHP_EOL);}
    }
    $zero=preg_match('/^SALON_TEST_DB_RESET_ALLOWED=0\r?$/m',file_get_contents($root.'/.env.testing'))===1;
    echo 'SALON_TEST_DB_RESET_ALLOWED='.($zero?'0':'RESTORE_FAILED').PHP_EOL;
    if (!$zero) $exit=1;
}
exit($exit);
