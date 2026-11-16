<?php

ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2);$exit=1;$changed=false;
try {
    $mode=$argv[1]??'preflight';
    $groups=['preflight'=>[],'install'=>['AccountingPolicyInstallationTest'],'core'=>['AccountingPolicyTest'],
        'concurrency'=>['AccountingPolicyConcurrencyTest'],'guard'=>['AccountingPolicyRollbackGuardTest'],
        'ac02'=>['AccountIntegrityTest','AccountHierarchyConcurrencyTest'],
        'preflight-current'=>[],'resolver'=>['AccountingAccountResolverTest'],'resolver-race'=>['AccountingResolverConcurrencyTest']];
    if(!isset($groups[$mode]) || PHP_VERSION!=='8.3.30')throw new RuntimeException('Modo/PHP no autorizado');
    require $root.'/tests/bootstrap.php';
    if(SALON_TEST_DATABASE_RESET_ALLOWED)throw new RuntimeException('El indicador inicial debe ser cero');
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4','salon_erp_tester',$_ENV['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $identity=$pdo->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CURRENT_ROLE() AS roles,VERSION() AS version')->fetch(PDO::FETCH_OBJ);
    Tests\Support\TestDatabaseSafety::assertServerIdentity($identity);
    if($identity->roles!=='NONE')throw new RuntimeException('Roles SQL inesperados');
    $grants=$pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $expected=['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX','REFERENCES'];sort($expected);
    if(count($grants)!==2 || !in_array('GRANT USAGE ON *.* TO `salon_erp_tester`@`localhost`',$grants,true))throw new RuntimeException('Privilegios globales inesperados');
    foreach($grants as $g)if(!str_starts_with($g,'GRANT USAGE ')) {
        [$prefix,$scope]=explode(' ON ',$g,2);$privileges=explode(', ',substr($prefix,6));sort($privileges);
        if($privileges!==$expected || $scope!=='`'.str_replace('_',chr(92).'_','salon_erp_test').'`.* TO `salon_erp_tester`@`localhost`')throw new RuntimeException('Privilegios de esquema inesperados');
    }
    foreach(['salon_erp','salon_erp_bootstrap_check'] as $schema) {
        try{$pdo->query("SELECT 1 FROM `$schema`.`users` LIMIT 0");throw new RuntimeException('Base ajena accesible');}
        catch(PDOException $e){if(($e->errorInfo[1]??0)!==1142)throw $e;}
    }
    $migration='2026_11_16_000001_create_versioned_accounting_policies';
    $files=array_map(fn($f)=>basename($f,'.php'),glob($root.'/database/migrations/*.php'));sort($files);
    if(count($files)!==80)throw new RuntimeException('Lista de migraciones inesperada');
    if(in_array($mode,['preflight','install'],true))$files=array_values(array_diff($files,[$migration]));
    if($files!==$pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN))throw new RuntimeException('Estado de migraciones inesperado');
    $backup='E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql';
    if(!is_file($backup)||!filesize($backup)||!preg_match('/^([a-fA-F0-9]{64})\s/',file_get_contents($backup.'.sha256'),$m)
        ||strtolower($m[1])!==hash_file('sha256',$backup))throw new RuntimeException('Respaldo/SHA256 inválido');
    echo 'PREFLIGHT: salon_erp_test / salon_erp_tester@localhost /3306; roles NONE; privilegios exactos; bases ajenas rechazadas; '.count($files).' migraciones; MySQL '.$identity->version.'; respaldo SHA256 válido'.PHP_EOL;
    $pdo=null;
    if($groups[$mode]===[])$exit=0;
    else {
        if($mode==='install') {
            $file=$root.'/.env.testing';$body=preg_replace('/^SALON_TEST_DB_RESET_ALLOWED=0\r?$/m','SALON_TEST_DB_RESET_ALLOWED=1',file_get_contents($file),1,$count);
            if($count!==1)throw new RuntimeException('Indicador no habilitable');$changed=true;
            if(file_put_contents($file,$body,LOCK_EX)!==strlen($body))throw new RuntimeException('No se habilitó indicador');
        }
        $args=[PHP_BINARY,'-d','zend.exception_ignore_args=1',$root.'/vendor/phpunit/phpunit/phpunit','--configuration',$root.'/phpunit.xml',
            '--do-not-cache-result','--no-progress','--stop-on-error','--stop-on-failure'];
        foreach($groups[$mode] as $test)$args[]=$root.'/tests/Feature/'.$test.'.php';
        $p=proc_open($args,[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,$root);
        if(!is_resource($p))throw new RuntimeException('No se inició PHPUnit');fclose($pipes[0]);$exit=proc_close($p);
    }
}catch(Throwable $e){fwrite(STDERR,'AC-03A detenido: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);}
finally {
    if($changed){$file=$root.'/.env.testing';$body=preg_replace('/^SALON_TEST_DB_RESET_ALLOWED=[01]\r?$/m','SALON_TEST_DB_RESET_ALLOWED=0',file_get_contents($file),1,$count);
        if($count!==1||file_put_contents($file,$body,LOCK_EX)!==strlen($body))$exit=1;}
    putenv('SALON_TEST_DB_RESET_ALLOWED=0');$_ENV['SALON_TEST_DB_RESET_ALLOWED']=$_SERVER['SALON_TEST_DB_RESET_ALLOWED']='0';
    if(!preg_match('/^SALON_TEST_DB_RESET_ALLOWED=0\r?$/m',file_get_contents($root.'/.env.testing')))$exit=1;
    echo 'RESET_ALLOWED=0; EXIT='.$exit.PHP_EOL;
}
exit($exit);
