<?php

// Solo funciones PHP y lectura del archivo de mantenimiento: no inicia Laravel/SQL.
require __DIR__.'/development-rebuild.php';
$valid=['secret'=>null,'except'=>['/up'],'template'=>'maintenance','status'=>503,'redirect'=>null];
$cases=['standard health exception'=>[$valid,true], 'no exclusions'=>[array_replace($valid,['except'=>[]]),true]];
foreach ([['/up','/api/*'],['*'],['/'],['/up/*'],['/login'],['up'],['']] as $i=>$paths) $cases['extra/wildcard exclusion '.$i]=[array_replace($valid,['except'=>$paths]),false];
foreach (['bypass','0','',false] as $i=>$secret) $cases['bypass value '.$i]=[array_replace($valid,['secret'=>$secret]),false];
$cases['missing template']=[array_replace($valid,['template'=>null]),false];
$cases['wrong status']=[array_replace($valid,['status'=>200]),false];
$cases['redirect']=[array_replace($valid,['redirect'=>'/login']),false];
$cases['invalid exclusions type']=[array_replace($valid,['except'=>'/up']),false];
$cases['actual maintenance file']=[json_decode(file_get_contents(dirname(__DIR__,2).'/storage/framework/down'),true,512,JSON_THROW_ON_ERROR),true];
$failed=0;
foreach($cases as $label=>[$data,$expected]) {
    $pass=dr_maintenance_data_safe($data)===$expected;
    echo ($pass?'PASS ':'FAIL ').$label.PHP_EOL;
    if(!$pass) ++$failed;
}
echo 'SCENARIOS='.count($cases).'; FAILED='.$failed.PHP_EOL;
exit($failed===0?0:1);
