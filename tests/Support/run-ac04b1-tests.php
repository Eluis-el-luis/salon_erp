<?php

ini_set('zend.exception_ignore_args','1');$root=dirname(__DIR__,2);$exit=1;
try {
    $mode=$argv[1]??'preflight';$groups=['preflight'=>[],'install'=>['AccountingEventInstallationTest'],'core'=>['AccountingEventDocumentationTest'],
        'concurrency'=>['AccountingAuthorizationConcurrencyTest'],'guard'=>['AccountingEventRollbackGuardTest'],
        'regressions'=>['AccountIntegrityTest','AccountHierarchyConcurrencyTest','AccountingPolicyTest','AccountingAccountResolverTest','BankBalanceIntegrityTest']];
    if(!isset($groups[$mode])||PHP_VERSION!=='8.3.30'||realpath(getcwd())!==realpath($root))throw new RuntimeException('Modo/raíz/PHP inválido');
    require $root.'/tests/bootstrap.php';if(SALON_TEST_DATABASE_RESET_ALLOWED)throw new RuntimeException('Reset debe ser cero');
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4','salon_erp_tester',$_ENV['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $i=$pdo->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CURRENT_ROLE() AS roles,VERSION() AS version')->fetch(PDO::FETCH_OBJ);
    Tests\Support\TestDatabaseSafety::assertServerIdentity($i);if($i->roles!=='NONE'||$i->version!=='8.4.3')throw new RuntimeException('Roles/servidor inesperados');
    $grants=$pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);$expected=['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX','REFERENCES'];sort($expected);
    if(count($grants)!==2||!in_array('GRANT USAGE ON *.* TO `salon_erp_tester`@`localhost`',$grants,true))throw new RuntimeException('Privilegios globales');
    foreach($grants as $g)if(!str_starts_with($g,'GRANT USAGE ')){[$p,$s]=explode(' ON ',$g,2);$ps=explode(', ',substr($p,6));sort($ps);if($ps!==$expected||$s!=='`'.str_replace('_',chr(92).'_','salon_erp_test').'`.* TO `salon_erp_tester`@`localhost`')throw new RuntimeException('Privilegios inesperados');}
    foreach(['salon_erp','salon_erp_bootstrap_check'] as $s){try{$pdo->query("SELECT 1 FROM `$s`.users LIMIT 0");throw new RuntimeException('Base ajena accesible');}catch(PDOException $e){if(($e->errorInfo[1]??0)!==1142)throw $e;}}
    $files=array_map(fn($f)=>basename($f,'.php'),glob($root.'/database/migrations/*.php'));sort($files);
    if(count($files)!==81)throw new RuntimeException('Archivos de migraciones');
    if(in_array($mode,['preflight','install'],true))$files=array_values(array_diff($files,['2026_11_16_000002_add_accounting_event_documentation']));
    if($files!==$pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN))throw new RuntimeException('Migraciones aplicadas distintas');
    $b=json_decode(file_get_contents($root.'/storage/app/private/ac04b1-backup.json'),true,512,JSON_THROW_ON_ERROR);
    if(!str_starts_with($b['file'],'E:/salon-erp-backups/salon_erp_test_AC04B1_')||!$b['source_unchanged']||$b['migrations']!==80||filesize($b['file'])!==$b['bytes']||hash_file('sha256',$b['file'])!==$b['sha256'])throw new RuntimeException('Nuevo respaldo inválido');
    if(!str_starts_with(file_get_contents($b['file'].'.sha256'),$b['sha256'].'  '))throw new RuntimeException('Sidecar inválido');
    echo 'PREFLIGHT: PHP 8.3.30; MySQL 8.4.3; salon_erp_test / salon_erp_tester@localhost /3306; roles NONE; privilegios exactos; bases ajenas denegadas; '.count($files).' migraciones; NUEVO respaldo SHA256 válido'.PHP_EOL;$pdo=null;
    if(!$groups[$mode])$exit=0;
    else {
        $args=[PHP_BINARY,'-d','zend.exception_ignore_args=1',$root.'/vendor/phpunit/phpunit/phpunit','--configuration',$root.'/phpunit.xml','--do-not-cache-result','--no-progress','--stop-on-error','--stop-on-failure'];
        foreach($groups[$mode] as $t)$args[]=$root.'/tests/Feature/'.$t.'.php';$p=proc_open($args,[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,$root);if(!is_resource($p))throw new RuntimeException('PHPUnit no iniciado');fclose($pipes[0]);$exit=proc_close($p);
    }
}catch(Throwable $e){fwrite(STDERR,'AC04B1 detenido: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);}
finally {putenv('SALON_TEST_DB_RESET_ALLOWED=0');$_ENV['SALON_TEST_DB_RESET_ALLOWED']=$_SERVER['SALON_TEST_DB_RESET_ALLOWED']='0';if(!preg_match('/^SALON_TEST_DB_RESET_ALLOWED=0\r?$/m',file_get_contents($root.'/.env.testing')))$exit=1;echo 'RESET_ALLOWED=0; EXIT='.$exit.PHP_EOL;}
exit($exit);
