<?php
/** Información de fixture existente para el navegador local; nunca emite secretos. */
ini_set('zend.exception_ignore_args','1');
try {
    if(PHP_SAPI!=='cli'||PHP_VERSION!=='8.3.30')throw new RuntimeException('CLI/PHP no autorizado.');
    require dirname(__DIR__).'/bootstrap.php';
    $app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$app['config']->set('database.connections',['mysql'=>config('database.connections.mysql')]);
    Tests\Support\TestDatabaseSafety::assertConfiguration($app->environment(),config('database'));
    $db=$app['db']->connection();Tests\Support\TestDatabaseSafety::assertServerIdentity($db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port'));
    $db->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
    if(SALON_TEST_DATABASE_RESET_ALLOWED||$db->table('migrations')->count()!==82)throw new RuntimeException('Guardias no válidos.');
    $f=json_decode(file_get_contents(__DIR__.'/../../storage/app/private/ac04b2b4b-http-fixture.json'),true,512,JSON_THROW_ON_ERROR);
    $u=$db->table('users')->where('id',$f['actor'])->first(['id','name','email','role','is_active']);
    if(!$u||!$u->is_active||!in_array($u->role,['alvaro','contador'],true)||!str_contains($u->name,'SYNTHETIC')||!str_ends_with($u->email,'@example.test'))throw new RuntimeException('Solo identidad sintética preexistente.');
    echo json_encode(['actor_id'=>$u->id,'email'=>$u->email,'uuid'=>$f['input']['uuid']],JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,'Inspección de navegador bloqueada: '.get_class($e).PHP_EOL);exit(1);}
finally{putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
