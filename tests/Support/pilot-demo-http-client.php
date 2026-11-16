<?php
/** Subproceso de transporte HTTP. Recibe cookies por pipe, nunca por argv o archivos. */
require dirname(__DIR__,2).'/vendor/autoload.php';
$p=json_decode(fgets(STDIN),true,512,JSON_THROW_ON_ERROR);$client=new Tests\Support\PilotHttpClient($p['port']);$client->cookie=$p['cookie'];$client->token=$p['token'];
echo "READY\n";flush();if(trim(fgets(STDIN))!=='go')exit(1);echo "ATTEMPT\n";flush();
$r=$client->confirm($p['payload']);unset($r['body']);$r['headers']=array_intersect_key($r['headers'],array_flip(['location','x-synthetic-connection','x-synthetic-process']));echo json_encode($r,JSON_THROW_ON_ERROR).PHP_EOL;
