<?php

// Launcher acotado: preflight de solo lectura y finally que restaura el indicador.
ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2); $changed=false; $exit=1;
try {
    if(PHP_VERSION!=='8.3.30') throw new RuntimeException('PHP version mismatch');
    require $root.'/tests/bootstrap.php';
    if(SALON_TEST_DATABASE_RESET_ALLOWED) throw new RuntimeException('Expected initial reset flag zero');
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4', 'salon_erp_tester', $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $id=$pdo->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CURRENT_ROLE() AS role_name')->fetch(PDO::FETCH_OBJ);
    \Tests\Support\TestDatabaseSafety::assertServerIdentity($id);
    if($id->role_name!=='NONE') throw new RuntimeException('Unexpected SQL roles');
    $grants=$pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $expected=['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX','REFERENCES']; sort($expected);
    if(count($grants)!==2 || !in_array('GRANT USAGE ON *.* TO `salon_erp_tester`@`localhost`',$grants,true)) throw new RuntimeException('Unexpected global privileges');
    foreach($grants as $grant) if(!str_starts_with($grant,'GRANT USAGE ')) {
        [$prefix,$scope]=explode(' ON ',$grant,2);$privileges=explode(', ',substr($prefix,6));sort($privileges);
        $exact='`'.str_replace('_',chr(92).'_','salon_erp_test').'`.* TO `salon_erp_tester`@`localhost`';
        if($privileges!==$expected || $scope!==$exact) throw new RuntimeException('Unexpected schema privileges');
    }
    foreach(['salon_erp','salon_erp_bootstrap_check'] as $schema) {
        try {$pdo->query("SELECT 1 FROM `$schema`.`users` LIMIT 0");throw new RuntimeException('Other database accessible');}
        catch(PDOException $error) {if(($error->errorInfo[1]??0)!==1142) throw $error;}
    }
    $backup='E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql';
    if(!is_file($backup) || filesize($backup)===0 || !preg_match('/^([A-Fa-f0-9]{64})\s/',file_get_contents($backup.'.sha256'),$match) || strtolower($match[1])!==hash_file('sha256',$backup)) throw new RuntimeException('Test backup missing or hash mismatch');
    echo 'PREFLIGHT: salon_erp_test / salon_erp_tester@localhost / 3306; exact privileges; both other databases denied; backup SHA256 verified'.PHP_EOL;
    $pdo=null;
    $file=$root.'/.env.testing';$before=file_get_contents($file);
    $enabled=preg_replace('/^SALON_TEST_DB_RESET_ALLOWED=0\r?$/m','SALON_TEST_DB_RESET_ALLOWED=1',$before,1,$count);
    if($count!==1) throw new RuntimeException('Cannot enable exact reset flag');
    $changed=true;
    if(file_put_contents($file,$enabled,LOCK_EX)!==strlen($enabled)) throw new RuntimeException('Cannot write reset flag');
    unset($before,$enabled);
    $mode=$argv[1]??'';
    if(!in_array($mode,['','checks-only','links-only'],true)) throw new RuntimeException('Unexpected H16 selection');
    $paths=match($mode) {
        'checks-only'=>['AgendaStateControlsTest','AppointmentConcurrencyTest'],
        'links-only'=>['AppointmentSaleLinksTest'],
        default=>['AppointmentAvailabilityTest','ApiAgendaSecurityTest','AppointmentServicesTest','AgendaNavigationTest','AgendaStateControlsTest','AppointmentConcurrencyTest','AppointmentSaleLinksTest'],
    };
    $args=[PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1',$root.'/vendor/phpunit/phpunit/phpunit','--configuration',$root.'/phpunit.xml','--do-not-cache-result','--no-progress','--stop-on-error','--stop-on-failure'];
    foreach($paths as $path) $args[]=$root.'/tests/Feature/'.$path.'.php';
    $log=$root.'/storage/app/private/h16-'.($mode!==''?str_replace('-only','',$mode).'-':'').'phpunit.log';
    $process=proc_open($args,[0=>['pipe','r'],1=>['file',$log,'w'],2=>['redirect',1]],$pipes,$root);
    if(!is_resource($process)) throw new RuntimeException('Cannot launch PHPUnit');
    fclose($pipes[0]);$exit=proc_close($process);
    echo 'PHPUNIT_EXIT_CODE='.$exit.PHP_EOL;
} catch(Throwable $error) {
    fwrite(STDERR,get_class($error).' '.basename($error->getFile()).':'.$error->getLine().PHP_EOL);
} finally {
    if($changed) {
        $current=file_get_contents($root.'/.env.testing');
        $disabled=preg_replace('/^SALON_TEST_DB_RESET_ALLOWED=[01]\r?$/m','SALON_TEST_DB_RESET_ALLOWED=0',$current,1,$count);
        if($count!==1 || file_put_contents($root.'/.env.testing',$disabled,LOCK_EX)!==strlen($disabled)) {fwrite(STDERR,'RESET FLAG RESTORE FAILED'.PHP_EOL);$exit=1;}
    }
    $restored=is_file($root.'/.env.testing') && preg_match('/^SALON_TEST_DB_RESET_ALLOWED=0\r?$/m',file_get_contents($root.'/.env.testing'))===1;
    echo 'SALON_TEST_DB_RESET_ALLOWED='.($restored?'0':'RESTORE_ERROR').PHP_EOL;
    if(!$restored) $exit=1;
}
exit($exit);
