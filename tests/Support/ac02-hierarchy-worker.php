<?php

ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/bootstrap.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mysql=config('database.connections.mysql');
$app['config']->set('database.connections',['mysql'=>$mysql]);
Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),config('database'));
$db=$app['db']->connection();$exit=1;$plan=null;$identity=null;$resultPath=null;
try {
    $identity=$db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CONNECTION_ID() AS connection_id');
    Tests\Support\TestDatabaseSafety::assertServerIdentity($identity);
    $plan=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
    if (realpath(dirname($plan['result']))!==realpath(dirname(__DIR__,2).'/storage/app/private')
        || !preg_match('/^ac02-race-[a-f0-9]+\.result$/D',basename($plan['result']))) throw new RuntimeException('Ruta de resultado no autorizada');
    $resultPath=$plan['result'];
    $service=$app->make(App\Services\AccountIntegrityService::class);
    echo "READY\n";flush();fgets(STDIN);
    $action=function () use ($service,$plan) {return $service->save($plan['data'],(int)$plan['id']);};
    if ($plan['hold']) {
        $service->catalogue(function () use ($action) {echo "LOCKED\n";flush();fgets(STDIN);return $action();});
    } else {echo "ATTEMPT\n";flush();$action();}
    echo "RESULT:accepted\n";$exit=0;
} catch (Illuminate\Validation\ValidationException $e) {echo "RESULT:rejected\n";$exit=2;}
catch (Throwable $e) {echo 'RESULT:error '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL;}
finally {
    if ($identity && $resultPath!==null) file_put_contents($resultPath,json_encode(['exit'=>$exit,'connection_id'=>$identity->connection_id]),LOCK_EX);
    $db->disconnect();
}
exit($exit);
