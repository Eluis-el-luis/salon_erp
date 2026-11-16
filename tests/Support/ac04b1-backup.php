<?php

ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2);$exit=1;
try {
    $phase=$argv[1]??'AC04B1';if(!in_array($phase,['AC04B1','AC04B2A','AC04B2B2R','AC04B2B3A','AC04B2B4A','AC04B2B4B'],true))throw new RuntimeException('Fase inválida');
    $expectedMigrations=$phase==='AC04B1'?80:(in_array($phase,['AC04B2B4A','AC04B2B4B'],true)?82:81);
    if(PHP_VERSION!=='8.3.30'||realpath(getcwd())!==realpath($root))throw new RuntimeException('Raíz/PHP inválido');
    require $root.'/tests/bootstrap.php';
    if(SALON_TEST_DATABASE_RESET_ALLOWED)throw new RuntimeException('Reset debe estar deshabilitado');
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $i=$pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@port AS port,VERSION() AS version')->fetch(PDO::FETCH_OBJ);
    if($i->db!=='salon_erp_test'||$i->account!=='root@localhost'||(int)$i->port!==3306||$i->version!=='8.4.3')throw new RuntimeException('Identidad administrativa distinta');
    if((int)$pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn()!==$expectedMigrations)throw new RuntimeException('Migraciones inesperadas');
    $snapshot=function()use($pdo):array {
        $out=[];
        foreach($pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='salon_erp_test' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if($t['ENGINE']!=='InnoDB'||!preg_match('/^[a-z_0-9]+$/D',$t['TABLE_NAME']))throw new RuntimeException('Tabla no transaccional');
            $name=$t['TABLE_NAME'];$rows=[];foreach($pdo->query("SELECT * FROM `$name`")->fetchAll(PDO::FETCH_ASSOC) as $r)$rows[]=hash('sha256',serialize($r));sort($rows);
            $out[$name]=[count($rows),hash('sha256',implode('',$rows)),hash('sha256',$pdo->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM)[1])];
        }return $out;
    };
    $before=$snapshot();$dir='E:/salon-erp-backups';if(str_replace('\\','/',realpath($dir))!==$dir)throw new RuntimeException('Directorio de respaldo inválido');
    $file=$dir.'/salon_erp_test_'.$phase.'_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(6)).'.sql';$partial=$file.'.partial';
    $h=fopen($partial,'xb');if(!$h)throw new RuntimeException('No se reservó archivo único');fclose($h);
    $args=['E:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe','--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=3306','--user=root','--skip-password',
        '--single-transaction','--quick','--skip-lock-tables','--no-tablespaces','--set-gtid-purged=OFF','--column-statistics=0','--routines','--events','--triggers',
        '--hex-blob','--default-character-set=utf8mb4','--skip-add-drop-table','--skip-add-locks','--result-file='.$partial,'salon_erp_test'];
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);if(!is_resource($p))throw new RuntimeException('No se inició exportación');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $dumpExit=proc_close($p);clearstatcache(true,$partial);
    // MySQL 8.4 también emite este aviso con --skip-password (sin secreto en argv).
    $expectedWarning='mysqldump: [Warning] Using a password on the command line interface can be insecure.';
    if($dumpExit!==0||($error!==''&&trim($error)!==$expectedWarning)||!filesize($partial)) {
        file_put_contents($root.'/storage/app/private/ac04b1-backup-diagnostic.json',json_encode(['exit'=>$dumpExit,'stderr'=>$error,'bytes'=>filesize($partial)]),LOCK_EX);
        throw new RuntimeException('Exportación fallida: conservar partial');
    }
    $body=file_get_contents($partial);
    if(substr_count($body,'CREATE TABLE `')!==count($before)||!str_contains($body,'-- Dump completed on ')
        ||str_contains($body,'CREATE DATABASE')||preg_match('/^USE /m',$body)||$before!==$snapshot())throw new RuntimeException('Verificación de exportación fallida');
    $hash=hash_file('sha256',$partial);if(is_file($file)||!rename($partial,$file))throw new RuntimeException('Finalización de respaldo fallida');
    $sha=fopen($file.'.sha256','xb');fwrite($sha,$hash.'  '.basename($file).PHP_EOL);fclose($sha);
    if(hash_file('sha256',$file)!==$hash)throw new RuntimeException('SHA256 no coincide');
    $manifest=['file'=>$file,'sha256'=>$hash,'bytes'=>filesize($file),'tables'=>count($before),'migrations'=>$expectedMigrations,'rows'=>array_sum(array_column($before,0)),'source_unchanged'=>true,'restore_tested'=>false,'empty_password_warning'=>$error!==''];
    $h=fopen($root.'/storage/app/private/'.strtolower($phase).'-backup.json','xb');if(!$h)throw new RuntimeException('Manifiesto ya existe');fwrite($h,json_encode($manifest,JSON_PRETTY_PRINT));fclose($h);
    echo json_encode($manifest,JSON_PRETTY_PRINT),PHP_EOL;$exit=0;
} catch(Throwable $e) {fwrite(STDERR,'Respaldo detenido: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);}
finally {putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
exit($exit);
