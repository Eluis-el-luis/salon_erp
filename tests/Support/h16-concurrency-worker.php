<?php

// Proceso compartido H16/H15. El destino NO se recibe por argumentos o stdin.
ini_set('zend.exception_ignore_args','1');
try {
    require dirname(__DIR__).'/bootstrap.php';
    if (!SALON_TEST_DATABASE_RESET_ALLOWED) throw new RuntimeException('H16 child requires authorized test run');
    $app=require dirname(__DIR__,2).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $mysql=$app['config']->get('database.connections.mysql');
    \Tests\Support\TestDatabaseSafety::assertConnection($mysql);
    $app['config']->set('database.connections',['mysql'=>$mysql]);
    \Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),$app['config']->get('database'));
    $db=$app['db']->connection();
    \Tests\Support\TestDatabaseSafety::assertServerIdentity($db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port'));
    $input=json_decode(fgets(STDIN),true,512,JSON_THROW_ON_ERROR);
    $connection=(int)$db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    echo json_encode(['event'=>'ready','connection'=>$connection]).PHP_EOL; fflush(STDOUT);
    $held=false;
    $db->beforeExecuting(function($sql) use($input) {
        if(str_contains(strtolower($sql),'from `users`') && str_contains(strtolower($sql),'for update')) {
            echo json_encode(['event'=>'attempt']).PHP_EOL; fflush(STDOUT);
        }
    });
    $db->listen(function($query) use($input,&$held) {
        if(($input['hold']??false) && !$held && str_contains(strtolower($query->sql),'from `users`') && str_contains(strtolower($query->sql),'for update')) {
            $held=true; echo json_encode(['event'=>'locked']).PHP_EOL; fflush(STDOUT);
            if(trim((string)fgets(STDIN))!=='RELEASE') throw new RuntimeException('Missing release signal');
        }
    });
    $app['auth']->guard('web')->setUser(\App\Models\Usuario::findOrFail($input['actor']));
    $db->beginTransaction();
    // Snapshot anterior deliberado: la lectura actual debe ver el commit del rival.
    $db->table('appointments')->count();
    $editing=($input['operation']??'reserve')==='edit';
    $uri=$editing?'/api/appointments/'.(int)$input['appointment_id'].'/details':'/api/appointments';
    $request=\Illuminate\Http\Request::create($uri,$editing?'PUT':'POST',$input['payload'],[],[],['HTTP_ACCEPT'=>'application/json']);
    $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
    $response=$kernel->handle($request);
    $json=json_decode($response->getContent(),true);
    if(in_array($response->getStatusCode(),[200,201],true)) $db->commit(); else $db->rollBack();
    $kernel->terminate($request,$response);
    echo json_encode(['event'=>'result','status'=>$response->getStatusCode(),'id'=>$json['appointment']['id']??null,'conflict'=>isset($json['errors']['availability'])||isset($json['errors']['edit_conflict']),'connection'=>$connection],JSON_THROW_ON_ERROR).PHP_EOL;
    $db->disconnect();
} catch(Throwable $e) {
    if(isset($db)) { while($db->transactionLevel()>0) $db->rollBack(); $db->disconnect(); }
    echo json_encode(['event'=>'failure','class'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()]).PHP_EOL;
    exit(2);
}
