<?php

// Independent, rollback-only fixture writer. No financial service or historical update.
ini_set('zend.exception_ignore_args','1');$pdo=null;$exit=1;
try {
    if(PHP_SAPI!=='cli'||PHP_VERSION!=='8.3.30'||realpath(getcwd())!==realpath(dirname(__DIR__,2)))throw new RuntimeException('Invalid fixture process.');
    require dirname(__DIR__).'/bootstrap.php';
    if(SALON_TEST_DATABASE_RESET_ALLOWED)throw new RuntimeException('Reset forbidden.');
    $ids=array_map(fn($v)=>ctype_digit($v)?(int)$v:0,array_slice($argv,1));
    if(count($ids)!==4||min($ids)<1)throw new RuntimeException('Expected account/account/actor/currency fixture IDs.');
    [$expense,$bank,$actor,$currency]=$ids;
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4','salon_erp_tester',$_ENV['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $i=$pdo->query('SELECT DATABASE() database_name,CURRENT_USER() account_name,@@port server_port,CONNECTION_ID() connection_id')->fetch(PDO::FETCH_OBJ);
    Tests\Support\TestDatabaseSafety::assertServerIdentity($i);
    if((int)$pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn()!==82)throw new RuntimeException('Unexpected schema.');
    $s=$pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND is_active=1 AND role IN ('alvaro','contador')");$s->execute([$actor]);
    if((int)$s->fetchColumn()!==1)throw new RuntimeException('Unauthorized fixture actor.');
    $pdo->beginTransaction();
    $s=$pdo->prepare("INSERT INTO periodos_contables(nombre,fecha_inicio,fecha_fin,estado,created_at,updated_at) VALUES(?,'2033-09-01','2033-09-30','abierto',NOW(),NOW())");
    $s->execute(['AC05B2 ROLLBACK ONLY SNAPSHOT FIXTURE']);$period=(int)$pdo->lastInsertId();
    $s=$pdo->prepare("INSERT INTO asientos_contables(numero_asiento,fecha,concepto,modulo_origen,periodo_id,usuario_id,estado,created_at,updated_at) VALUES(?,'2033-09-15',?,'manual',?,?,'contabilizado',NOW(),NOW())");
    $s->execute(['AC05B2-SNAPSHOT-'.bin2hex(random_bytes(6)),'SYNTHETIC ROLLBACK ONLY',$period,$actor]);$entry=(int)$pdo->lastInsertId();
    $s=$pdo->prepare('INSERT INTO detalle_asientos(asiento_id,cuenta_id,moneda_id,debe,haber,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())');
    $s->execute([$entry,$expense,$currency,'0.01','0.00']);$s->execute([$entry,$bank,$currency,'0.00','0.01']);
    echo json_encode(['ready'=>true,'connection_id'=>(int)$i->connection_id,'entry_id'=>$entry,'period_id'=>$period]),PHP_EOL;flush();
    stream_set_timeout(STDIN,20);$command=trim((string)fgets(STDIN));
    if($command!=='rollback')throw new RuntimeException('Fixture requires rollback command.');
    $pdo->rollBack();echo "ROLLED_BACK\n";flush();$exit=0;
}catch(Throwable $e){fwrite(STDERR,'Snapshot fixture stopped: '.get_class($e).PHP_EOL);}
finally{if($pdo?->inTransaction())$pdo->rollBack();putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
exit($exit);
