<?php

ini_set('zend.exception_ignore_args','1');require dirname(__DIR__).'/bootstrap.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('database.connections',['mysql'=>config('database.connections.mysql')]);
Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),config('database'));
$db=$app['db']->connection();$exit=1;$path=null;$identity=null;
try {
    if(PHP_VERSION!=='8.3.30'||SALON_TEST_DATABASE_RESET_ALLOWED)throw new RuntimeException('Guardia de entorno');
    $identity=$db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CONNECTION_ID() AS connection_id');Tests\Support\TestDatabaseSafety::assertServerIdentity($identity);
    if($db->table('migrations')->count()!==81)throw new RuntimeException('Esquema inesperado');$p=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
    if(realpath(dirname($p['result']))!==realpath(dirname(__DIR__,2).'/storage/app/private')||!preg_match('/^ac04b1-auth-[a-f0-9]+\.result$/D',basename($p['result'])))throw new RuntimeException('Ruta inválida');$path=$p['result'];
    auth()->setUser(App\Models\Usuario::findOrFail((int)$p['actor']));
    $db->beforeExecuting(function($sql){if(preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i',$sql)&&!str_starts_with($sql,'insert into `accounting_policy_authorizations`'))throw new LogicException('Carrera exclusivamente documental');});
    $s=app(App\Services\AccountingEventAuthorizationService::class);$at=new DateTimeImmutable('2033-01-15T00:00:00Z');
    $action=fn()=>match($p['action']) {
        'approve'=>$s->approveForSimulation((int)$p['version'],(int)$p['bank'],'SYNTHETIC-RACE','No aprobación empresarial'),
        'revoke'=>$s->revokeForSimulation((int)$p['target'],'SYNTHETIC-WITHDRAWAL','Retirada de ensayo'),
        default=>throw new RuntimeException('Acción inválida'),
    };
    echo "READY\n";flush();stream_set_timeout(STDIN,20);if(trim(fgets(STDIN)?:'')!=='go')throw new RuntimeException('Barrera');
    if($p['action']==='read') {
        $db->beginTransaction();$a=$s->inspectSimulation((int)$p['target'],$at);echo "LOCKED\n";flush();if(trim(fgets(STDIN)?:'')!=='release')throw new RuntimeException('Liberación');
        $b=$s->inspectSimulation((int)$p['target'],$at);if($a!=$b)throw new RuntimeException('Lectura inestable');$db->commit();
    } elseif($p['hold']) {
        app(App\Services\AccountIntegrityService::class)->withAdministrationLock(function()use($db,$p,$action){$db->table('accounting_policy_versions')->where('id',$p['version'])->lockForUpdate()->first();echo "LOCKED\n";flush();if(trim(fgets(STDIN)?:'')!=='release')throw new RuntimeException('Liberación');return $action();});
    } else {echo "ATTEMPT\n";flush();$action();}
    echo "RESULT:accepted\n";$exit=0;
}catch(Illuminate\Validation\ValidationException $e){echo "RESULT:rejected\n";$exit=2;}
catch(Throwable $e){echo 'RESULT:error '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL;}
finally {while($db->transactionLevel()>0)$db->rollBack();if($path&&$identity)file_put_contents($path,json_encode(['exit'=>$exit,'connection_id'=>$identity->connection_id,'pid'=>getmypid()]),LOCK_EX);$db->disconnect();putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
exit($exit);
