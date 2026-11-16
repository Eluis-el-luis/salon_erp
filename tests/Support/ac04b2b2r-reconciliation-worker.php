<?php

ini_set('zend.exception_ignore_args','1');if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/bootstrap.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('database.connections',['mysql'=>config('database.connections.mysql')]);$app['config']->set('session.driver','array');$app['config']->set('logging.default','null');
$db=$app['db']->connection();$exit=1;$path=null;$identity=null;$lockIds=[];
try {
    Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),config('database'));
    $identity=$db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CONNECTION_ID() AS connection_id');Tests\Support\TestDatabaseSafety::assertServerIdentity($identity);
    if(PHP_VERSION!=='8.3.30'||SALON_TEST_DATABASE_RESET_ALLOWED||$db->table('migrations')->count()!==81)throw new RuntimeException('Guardia de entorno');
    $p=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
    if(realpath(dirname($p['result']))!==realpath(dirname(__DIR__,2).'/storage/app/private')||!preg_match('/^ac04b2b2r-recon-[a-f0-9]+\.result$/D',basename($p['result'])))throw new RuntimeException('Ruta inválida');$path=$p['result'];
    Carbon\Carbon::setTestNow($p['day'].' 10:00:00');auth()->setUser(App\Models\Usuario::findOrFail((int)$p['actor']));
    $session=$app['session']->driver();$session->start();$request=Illuminate\Http\Request::create('/internal-reconciliation-race','POST',$p['payload']);$request->setLaravelSession($session);$app->instance('request',$request);$app['url']->setRequest($request);$app['redirect']->setSession($session);
    $pending=false;$paused=false;
    $db->beforeExecuting(function($sql,$bindings)use($p,&$pending,&$paused,&$lockIds){
        if(preg_match('/^\s*(create|alter|drop|truncate|delete)\b/i',$sql))throw new LogicException('DDL/borrado prohibido');
        if(preg_match('/^\s*(insert|update|replace)\b/i',$sql)&&!preg_match('/^(?:insert into|update) `(conciliaciones_bancarias|movimientos_bancarios|cuentas_bancarias|transferencias|asientos_contables|detalle_asientos|operaciones_cambio|saldos_moneda|diferenciales_cambiarios|accounting_event_operations|accounting_entry_line_resolutions)`/i',$sql))throw new LogicException('Escritura fuera de la carrera');
        // La pausa ocurre DESPUÉS del SELECT banco X real: en la siguiente consulta.
        if($p['hold']&&$pending&&!$paused){$paused=true;echo "LOCKED\n";flush();if(trim(fgets(STDIN)?:'')!=='release')throw new RuntimeException('Barrera no liberada');}
        if(preg_match('/^select .* from `cuentas_bancarias`.*for update$/i',$sql)){$pending=true;$lockIds[]=(int)$bindings[0];}
    });
    echo "READY\n";flush();stream_set_timeout(STDIN,30);if(trim(fgets(STDIN)?:'')!=='go')throw new RuntimeException('Inicio inválido');if(!$p['hold']){echo "ATTEMPT\n";flush();}
    switch($p['action']){
        case 'reconcile':app(App\Http\Controllers\BancoController::class)->guardarConciliacion($request,App\Models\CuentaBancaria::findOrFail((int)$p['bank']));break;
        case 'charge':app(App\Http\Controllers\BancoController::class)->registrarCargo($request);break;
        case 'credit':app(App\Http\Controllers\BancoController::class)->registrarAbono($request);break;
        case 'deposit':app(App\Http\Controllers\BancoController::class)->depositar($request);break;
        case 'transfer':app(App\Http\Controllers\BancoController::class)->transferir($request);break;
        case 'exchange':app(App\Http\Controllers\MesaCambioController::class)->store($request);break;
        case 'pilot':app(App\Services\AccountingBankChargeSimulation::class)->executeForInternalHarness((int)$p['grant'],$p['payload'],App\Support\AccountingSimulationBoundary::CONTRACT);$session->flash('success','Synthetic pilot');break;
        default:throw new RuntimeException('Acción inválida');
    }
    if($db->transactionLevel()!==0)throw new RuntimeException('Transacción pendiente');$exit=$session->has('success')&&!$session->has('errors')?0:2;echo $exit===0?"RESULT:accepted\n":"RESULT:rejected\n";
}catch(Illuminate\Validation\ValidationException $e){echo "RESULT:rejected\n";$exit=2;}
catch(Throwable $e){echo 'RESULT:error '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL;}
finally{while($db->transactionLevel()>0)$db->rollBack();if($path&&$identity)file_put_contents($path,json_encode(['exit'=>$exit,'connection_id'=>$identity->connection_id,'pid'=>getmypid(),'bank_lock_order'=>array_values(array_unique($lockIds))]),LOCK_EX);$db->disconnect();Carbon\Carbon::setTestNow();putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
exit($exit);
