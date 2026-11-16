<?php

/** Servidor HTTP sintético aislado, distinto del servidor de solo lectura. Nunca retornar false ni servir el workspace. */
try {
    if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||!in_array($_SERVER['HTTP_HOST']??'',['127.0.0.1:8775','127.0.0.1:8776'],true)){http_response_code(404);exit;}
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'';
    if($path==='/__pilot-demo/assets/app.css'&&$method==='GET'){
        $root=dirname(__DIR__,2);$manifest=json_decode(file_get_contents($root.'/public/build/manifest.json'),true,512,JSON_THROW_ON_ERROR);$file=realpath($root.'/public/build/'.$manifest['resources/css/app.css']['file']);$base=realpath($root.'/public/build');
        if(!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||pathinfo($file,PATHINFO_EXTENSION)!=='css')throw new RuntimeException('Asset inválido');header('Content-Type: text/css');readfile($file);exit;
    }
    $get=in_array($path,['/__pilot-demo','/__pilot-demo/','/__pilot-demo/login','/__pilot-demo/lookup'],true)||preg_match('~^/__pilot-demo/operations/[a-fA-F0-9-]{36}$~D',$path);
    $post=in_array($path,['/__pilot-demo/login','/__pilot-demo/logout','/__pilot-demo/review','/__pilot-demo/confirm'],true);
    if(!(($method==='GET'&&$get)||($method==='POST'&&$post))){http_response_code(404);exit;}
    require __DIR__.'/../bootstrap.php';define('SALON_PILOT_DEMO_READONLY','AC04B2B4A_READ_ONLY_DEMO_V1');define('SALON_HTTP_SYNTHETIC_CHARGE','AC04B2B4B_HTTP_SYNTHETIC_CHARGE_V1');
    $app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $app->bind(Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,Tests\Support\PilotDemoHttpCsrf::class);
    $app['config']->set('database.connections',['mysql'=>config('database.connections.mysql')]);
    $private=dirname(__DIR__,2).'/storage/app/private/pilot-demo-http-synthetic';foreach(['sessions','cache','locks'] as $dir)if(!is_dir($private.'/'.$dir)&&!mkdir($private.'/'.$dir,0700,true))throw new RuntimeException('No se pudo preparar sesión aislada');
    $app['config']->set(['session.driver'=>'file','session.files'=>$private.'/sessions','session.cookie'=>'pilot_demo_http_synthetic_session','session.path'=>'/__pilot-demo','session.http_only'=>true,'session.same_site'=>'strict',
        'cache.default'=>'file','cache.stores.file.path'=>$private.'/cache','cache.stores.file.lock_path'=>$private.'/locks','logging.default'=>'null']);
    App\Services\PilotDemoReadOnly::installation();
    $db=$app['db']->connection();$i=$db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port');Tests\Support\TestDatabaseSafety::assertServerIdentity($i);
    if($path!=='/__pilot-demo/confirm')$db->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
    $db->beforeExecuting(function($sql)use($path){
        if(preg_match('/^\s*(select|show)\b/i',$sql))return;
        if($path!=='/__pilot-demo/confirm'||!preg_match('/^(?:insert into|update) `(accounting_event_operations|accounting_entry_line_resolutions|movimientos_bancarios|asientos_contables|detalle_asientos|cuentas_bancarias|periodos_contables)`/i',$sql))throw new LogicException('SQL fuera del evento sintético.');
    });
    $app->handleRequest(Illuminate\Http\Request::capture());
}catch(Throwable){http_response_code(503);echo 'Demostración temporalmente no disponible. No se confirmó ninguna operación por esta interfaz.';}
