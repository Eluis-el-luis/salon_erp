<?php

/** Dedicated report reader on8778. Never serves repository paths or ordinary ERP routes. */
try {
    if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||(string)($_SERVER['SERVER_PORT']??'')!=='8778'||($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8778'){http_response_code(404);exit;}
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'';$root=dirname(__DIR__,2);
    if($path==='/__report-demo/assets/app.css'&&$method==='GET'){
        $m=json_decode(file_get_contents($root.'/public/build/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        $f=realpath($root.'/public/build/'.$m['resources/css/app.css']['file']);$base=realpath($root.'/public/build');
        if(!$f||!str_starts_with($f,$base.DIRECTORY_SEPARATOR)||pathinfo($f,PATHINFO_EXTENSION)!=='css')throw new RuntimeException;
        header('Content-Type: text/css');header('X-Content-Type-Options: nosniff');readfile($f);exit;
    }
    if(!(($method==='GET'&&in_array($path,['/__report-demo','/__report-demo/login'],true))
        ||($method==='POST'&&in_array($path,['/__report-demo/login','/__report-demo/logout','/__report-demo/compare'],true)))){http_response_code(404);exit;}
    require __DIR__.'/../bootstrap.php';define('SALON_REPORT_DEMO_READONLY','AC05B3A_REPORT_READONLY_V1');
    $app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $app->bind(Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,Tests\Support\PilotDemoHttpCsrf::class);
    $app['config']->set('database.connections',['mysql'=>config('database.connections.mysql')]);
    $dir=$root.'/storage/app/private/report-demo';foreach(['sessions','cache','locks'] as $d)if(!is_dir($dir.'/'.$d)&&!mkdir($dir.'/'.$d,0700,true))throw new RuntimeException;
    $app['config']->set(['app.debug'=>false,'session.driver'=>'file','session.files'=>$dir.'/sessions',
        'session.cookie'=>'report_demo_readonly_session','session.path'=>'/__report-demo','session.http_only'=>true,'session.same_site'=>'strict',
        'cache.default'=>'file','cache.stores.file.path'=>$dir.'/cache','cache.stores.file.lock_path'=>$dir.'/locks','logging.default'=>'null']);
    App\Support\ReportDemoBoundary::installation();$db=$app['db']->connection('mysql');
    $i=$db->selectOne('SELECT DATABASE() database_name,CURRENT_USER() account_name,@@port server_port,CURRENT_ROLE() roles');
    Tests\Support\TestDatabaseSafety::assertServerIdentity($i);if($i->roles!=='NONE')throw new RuntimeException;
    foreach(['salon_erp','salon_erp_bootstrap_check'] as $schema){try{$db->select("SELECT 1 FROM `$schema`.users LIMIT 0");throw new RuntimeException('Other database accessible.');}
        catch(Illuminate\Database\QueryException $e){if(($e->errorInfo[1]??0)!==1142)throw $e;}}
    $db->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
    $db->beforeExecuting(function($sql){if(!preg_match('/^\s*(select|show)\b/i',$sql)&&trim($sql)!=='SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')throw new LogicException('Report demo is read only.');});
    $router=$app['router'];$router->setRoutes(new Illuminate\Routing\RouteCollection);
    $router->middleware('web')->group(function()use($router){
        $c=App\Http\Controllers\ReportPresentationDemoController::class;
        $router->get('/__report-demo',[$c,'page']);$router->get('/__report-demo/login',[$c,'page']);
        $router->post('/__report-demo/login',[$c,'login'])->middleware('throttle:10,1');
        $router->post('/__report-demo/logout',[$c,'logout']);$router->post('/__report-demo/compare',[$c,'compare'])->middleware('throttle:30,1');
    });
    $router->getRoutes()->refreshNameLookups();
    header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    $app->handleRequest(Illuminate\Http\Request::capture());
}catch(Throwable $e){error_log('AC05B3A '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine());http_response_code(503);echo 'Demostración de lectura no disponible. No se generó un reporte completo.';}
finally{putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
