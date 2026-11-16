<?php
ini_set('zend.exception_ignore_args','1');if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/bootstrap.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('database.connections',['mysql'=>config('database.connections.mysql')]);$app['config']->set('session.driver','array');$app['config']->set('logging.default','null');
$db=$app['db']->connection();$path=null;$i=null;$exit=1;$result=null;$locks=[];
try{
    Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),config('database'));$i=$db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CONNECTION_ID() AS connection_id');Tests\Support\TestDatabaseSafety::assertServerIdentity($i);
    if(PHP_VERSION!=='8.3.30'||SALON_TEST_DATABASE_RESET_ALLOWED||$db->table('migrations')->count()!==82)throw new RuntimeException('Guardia de devolución');$p=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
    if(realpath(dirname($p['result']))!==realpath(dirname(__DIR__,2).'/storage/app/private')||!preg_match('/^ac04b2b3a-refund-[a-f0-9]+\.result$/D',basename($p['result'])))throw new RuntimeException('Ruta inválida');$path=$p['result'];
    auth()->setUser(App\Models\Usuario::findOrFail((int)$p['actor']));Carbon\Carbon::setTestNow($p['day'].' 10:00:00');$session=$app['session']->driver();$session->start();$request=Illuminate\Http\Request::create('/internal-refund','POST',$p['payload']);$request->setLaravelSession($session);$app->instance('request',$request);$app['url']->setRequest($request);$app['redirect']->setSession($session);
    $pending=false;$paused=false;$db->beforeExecuting(function($sql,$bindings)use($p,&$pending,&$paused,&$locks){
        if(preg_match('/^\s*(create|alter|drop|truncate|delete)\b/i',$sql))throw new LogicException('DDL/borrado prohibido');
        if(preg_match('/^\s*(insert|update|replace)\b/i',$sql)&&!preg_match('/^(?:insert into|update) `(bank_charge_reversals|bank_charge_reversal_lines|movimientos_bancarios|asientos_contables|detalle_asientos|cuentas_bancarias|conciliaciones_bancarias)`/i',$sql))throw new LogicException('Escritor ajeno al ensayo');
        if($p['hold']&&$pending&&!$paused){$paused=true;echo "LOCKED\n";flush();if(trim(fgets(STDIN)?:'')!=='release')throw new RuntimeException('Barrera');}
        if(preg_match('/^select .* from `cuentas_bancarias`.*for update$/i',$sql)){$pending=true;$locks[]=(int)$bindings[0];}
    });
    echo "READY\n";flush();stream_set_timeout(STDIN,30);if(trim(fgets(STDIN)?:'')!=='go')throw new RuntimeException('Inicio');if(!$p['hold']){echo "ATTEMPT\n";flush();}
    if($p['action']==='refund'){$d=app(App\Services\BankChargeRefundSimulation::class)->execute((int)$p['original'],$p['payload'],App\Services\BankChargeRefundSimulation::CONTRACT);$result=['document'=>$d->id,'movement'=>$d->result_movement_id,'entry'=>$d->result_asiento_id];$exit=0;}
    elseif($p['action']==='reconcile'){app(App\Http\Controllers\BancoController::class)->guardarConciliacion($request,App\Models\CuentaBancaria::findOrFail((int)$p['bank']));$exit=$session->has('success')&&!$session->has('errors')?0:2;}
    else throw new LogicException('Acción inválida');
    if($db->transactionLevel()!==0)throw new LogicException('Transacción abierta');echo $exit===0?"RESULT:accepted\n":"RESULT:rejected\n";
}catch(Illuminate\Validation\ValidationException $e){echo "RESULT:rejected\n";$exit=2;}
catch(Throwable $e){echo 'RESULT:error '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL;}
finally{while($db->transactionLevel()>0)$db->rollBack();if($path&&$i)file_put_contents($path,json_encode(['exit'=>$exit,'connection_id'=>$i->connection_id,'pid'=>getmypid(),'locks'=>$locks,'result'=>$result]),LOCK_EX);$db->disconnect();Carbon\Carbon::setTestNow();putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
exit($exit);
