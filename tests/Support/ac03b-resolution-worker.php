<?php

ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/bootstrap.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mysql=config('database.connections.mysql');$app['config']->set('database.connections',['mysql'=>$mysql]);
Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),config('database'));
$db=$app['db']->connection();$exit=1;$path=null;$identity=null;
try {
    $identity=$db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CONNECTION_ID() AS connection_id');
    Tests\Support\TestDatabaseSafety::assertServerIdentity($identity);
    $plan=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
    if(realpath(dirname($plan['result']))!==realpath(dirname(__DIR__,2).'/storage/app/private')
        || !preg_match('/^ac03b-resolution-[a-f0-9]+\.result$/D',basename($plan['result'])))throw new RuntimeException('Ruta no autorizada');
    $path=$plan['result'];auth()->setUser(App\Models\Usuario::findOrFail((int)$plan['actor']));
    $db->beforeExecuting(function($sql){if(!preg_match('/^\s*(select|show)\b/i',$sql) || preg_match('/\b(GET_LOCK|RELEASE_LOCK)\s*\(/i',$sql))throw new LogicException('Worker de resolución solo lectura');});
    $requests=array_map(fn($role)=>['role'=>$role,'scope_type'=>'global','resource_id'=>null],App\Support\AccountingPolicyRoles::CORE);
    $db->beginTransaction();$resolver=app(App\Services\AccountingAccountResolver::class);
    $a=$resolver->inspect($requests,new DateTimeImmutable('2099-01-15T00:00:00Z'),(int)$plan['id']);
    echo "PINNED\n";flush();stream_set_timeout(STDIN,20);
    if(trim(fgets(STDIN)?:'')!=='release')throw new RuntimeException('Barrera no liberada');
    $b=$resolver->inspect($requests,new DateTimeImmutable('2099-01-15T00:00:00Z'),(int)$plan['id']);
    if($a!==$b || $a['posting_authorized']!==false)throw new RuntimeException('Selección cambió dentro de la transacción');
    $db->commit();echo "RESULT:stable\n";$exit=0;
}catch(Throwable $e){echo 'RESULT:error '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL;}
finally {
    while($db->transactionLevel()>0)$db->rollBack();
    if($path!==null && $identity)file_put_contents($path,json_encode(['exit'=>$exit,'connection_id'=>$identity->connection_id]),LOCK_EX);
    $db->disconnect();
}
exit($exit);
