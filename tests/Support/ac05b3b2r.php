<?php

ini_set('zend.exception_ignore_args','1'); $exit=1;
try {
    require __DIR__.'/ac05b3b2-bootstrap.php';
    $root=dirname(__DIR__,2); $mode=$argv[1]??'';
    if (!in_array($mode,['inspect','preflight','before','after'],true)) throw new RuntimeException('Explicit reader mode required.');
    $pdo=Tests\Support\AccountingSchemaInspection::restricted();
    $metadata=Tests\Support\AccountingSchemaInspection::metadata($pdo);
    $snap=Tests\Support\AccountingSchemaInspection::snapshot();
    $approved=json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2-isolation-after.json'),true,512,JSON_THROW_ON_ERROR);
    if ($approved !== $snap) throw new RuntimeException('Live database differs from accepted B2 evidence; do not legitimize it.');
    $history=Tests\Support\AccountingSchemaInspection::verifyDrafts($pdo);
    $oldBackup=json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2-backup.json'),true,512,JSON_THROW_ON_ERROR);
    if ($oldBackup['tables']!==89 || $oldBackup['migrations']!==82 || filesize($oldBackup['file'])!==$oldBackup['bytes']
        || hash_file('sha256',$oldBackup['file'])!==$oldBackup['sha256']) throw new RuntimeException('Accepted B2 backup invalid.');
    if ($mode==='inspect') {
        $path=$root.'/storage/app/private/ac05b3b2r-verified-source.json';
        $f=fopen($path,'xb'); if (!$f) throw new RuntimeException('Source evidence already exists.');
        fwrite($f,json_encode(['metadata'=>$metadata,'approved_baseline_sha256'=>hash_file('sha256',$root.'/storage/app/private/ac05b3b2-isolation-after.json'),'history'=>$history],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fclose($f);
    } else {
        Tests\Support\AccountingTestSchemaProfiles::assertSchema(Tests\Support\AccountingTestSchemaProfiles::CURRENT,$metadata);
        $state=['profile'=>Tests\Support\AccountingTestSchemaProfiles::CURRENT,'snapshot'=>$snap,'history'=>$history];
        if ($mode==='before') {
            Tests\Support\AccountingTestSchemaProfiles::verifyCurrentBackup();
            $path=$root.'/storage/app/private/ac05b3b2r-isolation-before.json';
            $f=fopen($path,'xb');if(!$f)throw new RuntimeException('R baseline exists.');fwrite($f,json_encode($state,JSON_THROW_ON_ERROR));fclose($f);
        } elseif ($mode==='after') {
            $before=json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2r-isolation-before.json'),true,512,JSON_THROW_ON_ERROR);
            Tests\Support\AccountingTestSchemaProfiles::assertPreserved($before,$state);
            $path=$root.'/storage/app/private/ac05b3b2r-isolation-after.json';
            $f=fopen($path,'xb');if(!$f)throw new RuntimeException('R final evidence exists.');fwrite($f,json_encode($state,JSON_THROW_ON_ERROR));fclose($f);
        }
    }
    echo json_encode(['mode'=>$mode,'tables'=>count($metadata['tables']),'migrations'=>count($metadata['migrations']),'history'=>$history,'B2_snapshot_exact'=>true,'SQL_writes'=>0]),PHP_EOL;
    foreach ($snap as $schema=>$tables) echo json_encode(['schema'=>$schema,'tables'=>count($tables),'rows'=>array_sum(array_map(fn($t)=>count($t['rows']),$tables))]),PHP_EOL;
    $exit=0;
} catch (Throwable $e) { fwrite(STDERR,'Schema reconciliation stopped: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL); }
finally { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
exit($exit);
