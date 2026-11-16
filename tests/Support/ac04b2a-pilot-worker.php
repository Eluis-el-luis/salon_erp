<?php

ini_set('zend.exception_ignore_args','1');
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/bootstrap.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$app['config']->set('database.connections',['mysql'=>config('database.connections.mysql')]);
$app['config']->set('session.driver','array');$app['config']->set('logging.default','null');
Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),config('database'));
$db=$app['db']->connection();$exit=1;$path=null;$id=null;$result=null;
try {
    if(PHP_VERSION!=='8.3.30'||SALON_TEST_DATABASE_RESET_ALLOWED)throw new RuntimeException('Entorno inválido');
    $id=$db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CONNECTION_ID() AS connection_id');Tests\Support\TestDatabaseSafety::assertServerIdentity($id);
    if($db->table('migrations')->count()!==81)throw new RuntimeException('Esquema inválido');$p=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
    if(realpath(dirname($p['result']))!==realpath(dirname(__DIR__,2).'/storage/app/private')||!preg_match('/^ac04b2a-pilot-[a-f0-9]+\.result$/D',basename($p['result'])))throw new RuntimeException('Ruta inválida');$path=$p['result'];
    Carbon\Carbon::setTestNow($p['day'].' 10:00:00');auth()->setUser(App\Models\Usuario::findOrFail((int)$p['actor']));
    $session=$app['session']->driver();$session->start();$request=Illuminate\Http\Request::create('/internal-harness','POST',$p['legacy_payload']??[]);$request->setLaravelSession($session);$app->instance('request',$request);$app['url']->setRequest($request);$app['redirect']->setSession($session);
    $paused=false;$db->beforeExecuting(function($sql)use($p,&$paused) {
        if(preg_match('/^\s*(create|alter|drop|truncate|delete)\b/i',$sql))throw new LogicException('DDL/borrado prohibido');
        if(preg_match('/^\s*(insert|update|replace)\b/i',$sql)&&!preg_match('/^(?:insert into|update) `(accounting_event_operations|accounting_entry_line_resolutions|movimientos_bancarios|asientos_contables|detalle_asientos|cuentas_bancarias|accounting_policy_authorizations|accounting_policy_versions|accounting_policy_audits)`/i',$sql))throw new LogicException('Escritor fuera de alcance');
        $barrier=$p['action']==='prepare'
            ? preg_match('/^select .* from `accounting_event_operations` where `id` = /i',$sql)
            : str_starts_with($sql,'insert into `movimientos_bancarios`');
        if($p['hold']&&!$paused&&$barrier){$paused=true;echo "LOCKED\n";flush();if(trim(fgets(STDIN)?:'')!=='release')throw new RuntimeException('Barrera no liberada');}
    });
    echo "READY\n";flush();stream_set_timeout(STDIN,20);if(trim(fgets(STDIN)?:'')!=='go')throw new RuntimeException('No iniciado');if(!$p['hold']){echo "ATTEMPT\n";flush();}
    switch($p['action']) {
        case 'charge':$o=app(App\Services\AccountingBankChargeSimulation::class)->executeForInternalHarness((int)$p['grant'],$p['input'],App\Support\AccountingSimulationBoundary::CONTRACT);$result=['operation_id'=>$o->id,'movement_id'=>$o->result_movement_id,'entry_id'=>$o->result_asiento_id];break;
        case 'prepare':$o=app(App\Services\AccountingEventIdentityService::class)->prepareSimulation((int)$p['grant'],$p['input'],DateTimeImmutable::createFromInterface(now('UTC')));$result=['operation_id'=>$o->id,'status'=>$o->status];break;
        case 'revoke':$a=app(App\Services\AccountingEventAuthorizationService::class)->revokeForSimulation((int)$p['grant'],'SYNTHETIC-PILOT-RACE','Retirada concurrente');$result=['revocation'=>$a->id];break;
        case 'publish':$a=app(App\Services\AccountingPolicyService::class)->publish((int)$p['version'],$p['hash'],'SYNTHETIC-PUBLICATION','Solapamiento de ensayo');$result=['published'=>$a->id];break;
        case 'legacy':app(App\Http\Controllers\BancoController::class)->registrarCargo($request);if(!$session->has('success')||$session->has('errors'))throw new RuntimeException('Cargo heredado rechazado');break;
        default:throw new RuntimeException('Acción inválida');
    }
    if($db->transactionLevel()!==0)throw new RuntimeException('Transacción no finalizada');echo "RESULT:accepted\n";$exit=0;
}catch(Illuminate\Validation\ValidationException $e){echo "RESULT:rejected\n";$exit=2;}
catch(Throwable $e){echo 'RESULT:error '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL;}
finally{while($db->transactionLevel()>0)$db->rollBack();if($path&&$id)file_put_contents($path,json_encode(['exit'=>$exit,'connection_id'=>$id->connection_id,'pid'=>getmypid(),'result'=>$result]),LOCK_EX);$db->disconnect();Carbon\Carbon::setTestNow();putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
exit($exit);
