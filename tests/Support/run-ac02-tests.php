<?php

ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2); $exit=1;
try {
    $mode=$argv[1]??'preflight';
    $groups=['preflight'=>[], 'core'=>['AccountIntegrityTest'],
        'regressions'=>['FractionalInventoryV2Test','InventoryAccountConsistencyTest','HistoricalInventoryReturnTest'],
        'concurrency'=>['AccountHierarchyConcurrencyTest']];
    if (!isset($groups[$mode]) || PHP_VERSION!=='8.3.30') throw new RuntimeException('Modo/PHP no autorizado');
    require $root.'/tests/bootstrap.php';
    if (SALON_TEST_DATABASE_RESET_ALLOWED) throw new RuntimeException('AC-02 exige indicador cero; no recrea tablas');
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4','salon_erp_tester',$_ENV['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $id=$pdo->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CURRENT_ROLE() AS roles')->fetch(PDO::FETCH_OBJ);
    Tests\Support\TestDatabaseSafety::assertServerIdentity($id);
    if ($id->roles!=='NONE') throw new RuntimeException('Roles inesperados');
    $grants=$pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $expected=['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX','REFERENCES']; sort($expected);
    if (count($grants)!==2 || !in_array('GRANT USAGE ON *.* TO `salon_erp_tester`@`localhost`',$grants,true)) throw new RuntimeException('Privilegios globales inesperados');
    foreach ($grants as $g) if (!str_starts_with($g,'GRANT USAGE ')) {
        [$prefix,$scope]=explode(' ON ',$g,2);$privileges=explode(', ',substr($prefix,6));sort($privileges);
        if ($privileges!==$expected || $scope!=='`'.str_replace('_',chr(92).'_','salon_erp_test').'`.* TO `salon_erp_tester`@`localhost`') throw new RuntimeException('Privilegios de esquema inesperados');
    }
    foreach (['salon_erp','salon_erp_bootstrap_check'] as $schema) {
        try {$pdo->query("SELECT 1 FROM `$schema`.`users` LIMIT 0");throw new RuntimeException('Base ajena accesible');}
        catch (PDOException $e) {if (($e->errorInfo[1]??0)!==1142) throw $e;}
    }
    $files=array_map(fn ($f)=>basename($f,'.php'),glob($root.'/database/migrations/*.php'));sort($files);
    if (count($files)!==79 || $files!==$pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN)) throw new RuntimeException('Esquema distinto de 79 migraciones');
    $backup='E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql';
    if (!is_file($backup) || filesize($backup)===0 || !preg_match('/^([a-fA-F0-9]{64})\s/',file_get_contents($backup.'.sha256'),$m)
        || strtolower($m[1])!==hash_file('sha256',$backup)) throw new RuntimeException('Respaldo/SHA256 inválido');
    echo "PREFLIGHT: tester@localhost / salon_erp_test / 3306; roles NONE; privilegios exactos; bases ajenas rechazadas; 79 migraciones; respaldo SHA256 válido\n";
    $pdo=null;
    if ($mode==='preflight') $exit=0;
    else {
        $args=[PHP_BINARY,'-d','zend.exception_ignore_args=1',$root.'/vendor/phpunit/phpunit/phpunit','--configuration',$root.'/phpunit.xml',
            '--do-not-cache-result','--no-progress','--stop-on-error','--stop-on-failure'];
        foreach ($groups[$mode] as $test) $args[]=$root.'/tests/Feature/'.$test.'.php';
        $p=proc_open($args,[0=>['pipe','r'],1=>['file',$root.'/storage/app/private/ac02-'.$mode.'.log','w'],2=>['redirect',1]],$pipes,$root);
        if (!is_resource($p)) throw new RuntimeException('No se inició PHPUnit');
        fclose($pipes[0]);$exit=proc_close($p);echo 'PHPUNIT_EXIT='.$exit.PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR,'AC-02 detenido: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);
} finally {
    putenv('SALON_TEST_DB_RESET_ALLOWED=0');$_ENV['SALON_TEST_DB_RESET_ALLOWED']=$_SERVER['SALON_TEST_DB_RESET_ALLOWED']='0';
    if (!preg_match('/^SALON_TEST_DB_RESET_ALLOWED=0\r?$/m',file_get_contents($root.'/.env.testing'))) $exit=1;
    echo 'RESET_ALLOWED=0; NO_REFRESH_DATABASE'.PHP_EOL;
}
exit($exit);
