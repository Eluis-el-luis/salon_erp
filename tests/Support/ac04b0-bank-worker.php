<?php

ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/bootstrap.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mysql=config('database.connections.mysql');$app['config']->set('database.connections',['mysql'=>$mysql]);
$app['config']->set('session.driver','array');$app['config']->set('logging.default','null');
Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),config('database'));
$db=$app['db']->connection();$exit=1;$path=null;$identity=null;$locks=[];
try {
    if(PHP_VERSION!=='8.3.30'||SALON_TEST_DATABASE_RESET_ALLOWED)throw new RuntimeException('Entorno no autorizado');
    $identity=$db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CONNECTION_ID() AS connection_id');
    Tests\Support\TestDatabaseSafety::assertServerIdentity($identity);
    if((int)$db->table('migrations')->count()!==80)throw new RuntimeException('Esquema inesperado');
    $plan=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
    if(realpath(dirname($plan['result']))!==realpath(dirname(__DIR__,2).'/storage/app/private') || !preg_match('/^ac04b0-bank-[a-f0-9]+\.result$/D',basename($plan['result'])))throw new RuntimeException('Ruta no autorizada');
    $path=$plan['result'];Carbon\Carbon::setTestNow('2031-05-15 10:00:00');
    auth()->setUser(App\Models\Usuario::findOrFail((int)$plan['actor']));
    $db->beforeExecuting(function($sql,$bindings)use(&$locks) {
        if(preg_match('/^select .* from `cuentas_bancarias`.*for update$/i',$sql))$locks[]=(int)$bindings[0];
        if(preg_match('/^\s*(create|alter|drop|truncate)\b/i',$sql))throw new LogicException('DDL prohibido');
        if(preg_match('/^\s*(insert|update|delete|replace)\b/i',$sql)
            && !preg_match('/^(?:insert into|update) `(movimientos_bancarios|transferencias|asientos_contables|detalle_asientos|cuentas_bancarias|operaciones_cambio|diferenciales_cambiarios|saldos_moneda)`/i',$sql))throw new LogicException('Escritura fuera del alcance de la carrera');
    });
    $session=$app['session']->driver();$session->start();$request=Illuminate\Http\Request::create('/ac04b0','POST',$plan['payload']);
    $request->setLaravelSession($session);$app->instance('request',$request);$app['url']->setRequest($request);$app['redirect']->setSession($session);
    echo "READY\n";flush();stream_set_timeout(STDIN,20);if(trim(fgets(STDIN)?:'')!=='go')throw new RuntimeException('No iniciado');
    $db->beginTransaction();
    if($plan['hold']) {
        app(App\Services\BankBalanceService::class)->lockAccounts($plan['bank_ids']);
        echo "LOCKED\n";flush();if(trim(fgets(STDIN)?:'')!=='release')throw new RuntimeException('No liberado');
    } else {echo "ATTEMPT\n";flush();}
    match($plan['action']) {
        'charge'=>app(App\Http\Controllers\BancoController::class)->registrarCargo($request),
        'deposit'=>app(App\Http\Controllers\BancoController::class)->depositar($request),
        'transfer'=>app(App\Http\Controllers\BancoController::class)->transferir($request),
        'exchange'=>app(App\Http\Controllers\MesaCambioController::class)->store($request),
        default=>throw new RuntimeException('Acción no permitida'),
    };
    if($db->transactionLevel()!==1)throw new RuntimeException('Transacción exterior alterada');
    $ok=$session->has('success')&&!$session->has('errors');$db->commit();$exit=$ok?0:2;echo $ok?"RESULT:accepted\n":"RESULT:rejected\n";
}catch(Throwable $e){echo 'RESULT:error '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL;}
finally {
    while($db->transactionLevel()>0)$db->rollBack();
    if($path!==null&&$identity)file_put_contents($path,json_encode(['exit'=>$exit,'connection_id'=>$identity->connection_id,'pid'=>getmypid(),'bank_lock_order'=>array_values(array_unique($locks))]),LOCK_EX);
    $db->disconnect();Carbon\Carbon::setTestNow();putenv('SALON_TEST_DB_RESET_ALLOWED=0');
}
exit($exit);
