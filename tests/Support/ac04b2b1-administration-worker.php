<?php

ini_set('zend.exception_ignore_args','1');
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/bootstrap.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('database.connections',['mysql'=>config('database.connections.mysql')]);$app['config']->set('logging.default','null');
$db=$app['db']->connection();$path=null;$identity=null;$exit=1;$result=null;
try {
    Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),config('database'));
    $identity=$db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CONNECTION_ID() AS connection_id');Tests\Support\TestDatabaseSafety::assertServerIdentity($identity);
    if(PHP_VERSION!=='8.3.30'||SALON_TEST_DATABASE_RESET_ALLOWED||$db->table('migrations')->count()!==81)throw new RuntimeException('Guardia de ensayo');
    $p=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
    if(realpath(dirname($p['result']))!==realpath(dirname(__DIR__,2).'/storage/app/private')||!preg_match('/^ac04b2b1-admin-[a-f0-9]+\.result$/D',basename($p['result'])))throw new RuntimeException('Ruta inválida');$path=$p['result'];
    auth()->setUser(App\Models\Usuario::findOrFail((int)$p['actor']));
    $db->beforeExecuting(function($sql){
        if(preg_match('/^\s*(create|alter|drop|truncate|delete)\b/i',$sql))throw new LogicException('DDL/borrado prohibido');
        if(preg_match('/^\s*(insert|update|replace)\b/i',$sql)&&!preg_match('/^(?:insert into|update) `(accounting_policy_authorizations|accounting_policy_versions|accounting_policy_audits|cuentas_contables)`/i',$sql))throw new LogicException('Escritor ajeno a administración');
    });
    $s=app(App\Services\AccountingEventAuthorizationService::class);
    $action=function()use($p,$s){return match($p['action']){
        'approve'=>$s->approveForAdministration((int)$p['version'],(int)$p['bank'],$p['hash'],$p['from'],$p['until'],'SYNTHETIC-RACE','Ensayo documental concurrente'),
        'enable'=>$s->enableForAdministration((int)$p['target'],$p['from'],$p['until'],'SYNTHETIC-RACE','Habilitación de ensayo'),
        'revoke'=>$s->revokeForAdministration((int)$p['target'],'SYNTHETIC-RACE','Retirada de ensayo'),
        'publish'=>app(App\Services\AccountingPolicyService::class)->publish((int)$p['version'],$p['hash'],'SYNTHETIC-PUBLICATION','Publicación concurrente'),
        'account'=>app(App\Services\AccountIntegrityService::class)->save($p['account_data'],(int)$p['account']),
        default=>throw new LogicException('Acción inválida'),
    };};
    $wait=function(){echo "LOCKED\n";flush();if(trim(fgets(STDIN)?:'')!=='release')throw new RuntimeException('Barrera no liberada');};
    echo "READY\n";flush();stream_set_timeout(STDIN,25);if(trim(fgets(STDIN)?:'')!=='go')throw new RuntimeException('Inicio inválido');
    if($p['action']==='read'){
        $db->beginTransaction();$one=$s->reviewForAdministration((int)$p['version'],(int)$p['bank']);$wait();$two=$s->reviewForAdministration((int)$p['version'],(int)$p['bank']);
        if(json_encode($one)!==json_encode($two))throw new LogicException('Lectura documental inestable');$db->commit();
    }elseif($p['hold']){
        $result=app(App\Services\AccountIntegrityService::class)->withAdministrationLock(function()use($db,$p,$action,$wait){$db->table('accounting_policy_versions')->where('id',$p['version'])->lockForUpdate()->first();$wait();return $action();});
    }else{echo "ATTEMPT\n";flush();$result=$action();}
    if($db->transactionLevel()!==0)throw new LogicException('Transacción pendiente');$result=$result?['id'=>$result->id]:null;echo "RESULT:accepted\n";$exit=0;
}catch(Illuminate\Validation\ValidationException $e){echo "RESULT:rejected\n";$exit=2;}
catch(Throwable $e){echo 'RESULT:error '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL;}
finally{while($db->transactionLevel()>0)$db->rollBack();if($path&&$identity)file_put_contents($path,json_encode(['exit'=>$exit,'connection_id'=>$identity->connection_id,'pid'=>getmypid(),'result'=>$result]),LOCK_EX);$db->disconnect();putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
exit($exit);
