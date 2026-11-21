<?php

// Produces review evidence only. No SQL writers, catalogue changes or financial classification.
ini_set('zend.exception_ignore_args','1');
try{
    require __DIR__.'/ac05b3b4-bootstrap.php';
    $run=$argv[1]??'';if(!preg_match('/^[a-f0-9]{16}$/D',$run))throw new RuntimeException('Unique run required.');
    $root=dirname(__DIR__,2);
    Tests\Support\AccountingTestSchemaProfiles::verifyCurrentBackup();
    $baseline=json_decode(file_get_contents($root.'/storage/app/private/ac05b4a-'.$run.'-before.json'),true,512,JSON_THROW_ON_ERROR);
    if(($baseline['profile']??null)!==Tests\Support\AccountingTestSchemaProfiles::CURRENT)throw new RuntimeException('Current baseline required.');
    $r=(new App\Services\AccountingCatalogueInventoryReader)->read();
    $write=function($path,$content){$f=fopen($path,'xb');if(!$f)throw new RuntimeException('Evidence already exists.');fwrite($f,$content);fclose($f);};
    $private=$root.'/storage/app/private/ac05b4a-'.$run.'-inventory.json';
    $write($private,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    unset($r['private_detail_evidence']);
    $write($root.'/docs/AC_05B4A_CATALOGO_ANEXO.json',json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $path=$root.'/docs/AC_05B4A_MATRIZ_CONTADOR.csv';$f=fopen($path,'xb');if(!$f)throw new RuntimeException('Matrix already exists.');
    $header=['account_id','codigo','nombre','tipo','naturaleza','padre_id','nivel','nivel_observado','imputable','activa','sistema','creada','actualizada',
        'hijos','dependencias_recuentos','detalles','primero','ultimo','debe_centavos_registrados','haber_centavos_registrados','saldo_naturaleza_centavos',
        'por_moneda_sin_conversion','estados_recuentos','origenes_recuentos','cierres','referencias_documentales_recuento','marcador_REV_recuento',
        'fuera_periodo','resultados_heredado','balance_heredado','dashboard_heredado','grupo_propuesto','subgrupo_propuesto','correctora',
        'corriente_no_corriente','observacion_tecnica','pregunta_contador','estado_revision'];
    fputcsv($f,$header,',','"','');
    $json=fn($v)=>json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    foreach($r['accounts']as $row){$a=$row['account'];$h=$row['activity'];$e=$row['economic_review'];
        $cells=[$a['id'],$a['codigo'],$a['nombre'],$a['tipo'],$a['naturaleza'],$a['cuenta_padre_id'],$a['nivel'],$row['computed_level'],$a['permite_movimiento'],$a['activa'],$a['is_system_account'],$a['created_at'],$a['updated_at'],
            $json($row['children']),$json($row['dependencies']),$h['count'],$h['first'],$h['last'],$h['debit_cents'],$h['credit_cents'],$h['nature_signed_cents'],
            $json($h['currencies']),$json($h['states']),$json($h['origins']),$h['closing'],$h['with_reference'],$h['reverse_marker'],$h['date_outside_period'],
            $row['legacy']['result']??'NO SELECCIONADA',$row['legacy']['balance']??'NO SELECCIONADA',$row['legacy']['dashboard']??'NO SELECCIONADA',
            $e['group'],$e['subgroup'],$e['corrector'],$e['current_noncurrent'],implode('; ',$row['observations']),$row['question'],$e['status']];
        // Prevent spreadsheet formulas without changing the exact metadata preserved in JSON.
        foreach($cells as &$cell)if(is_string($cell)&&preg_match('/^[=+@\-\t\r]/',$cell))$cell="'".$cell;unset($cell);
        fputcsv($f,$cells,',','"','');
    }fclose($f);
    $active=array_filter($r['accounts'],fn($a)=>$a['activity']['count']>0);
    $summary=['accounts'=>count($r['accounts']),'with_activity'=>count($active),'without_activity'=>count($r['accounts'])-count($active),
        'inactive_history'=>array_keys(array_filter($active,fn($a)=>!$a['account']['activa'])),
        'nonpostable_history'=>array_keys(array_filter($active,fn($a)=>!$a['account']['permite_movimiento'])),
        'parent_history'=>array_keys(array_filter($active,fn($a)=>count($a['children'])>0)),
        'zero_net_history'=>array_keys(array_filter($active,fn($a)=>$a['activity']['debit_minus_credit_cents']===0)),
        'controls'=>$r['controls'],'coverage'=>$r['coverage'],'private_sha256'=>hash_file('sha256',$private),
        'matrix_sha256'=>hash_file('sha256',$path),'annex_sha256'=>hash_file('sha256',$root.'/docs/AC_05B4A_CATALOGO_ANEXO.json')];
    echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Inventory stopped: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);exit(1);}
finally{putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
