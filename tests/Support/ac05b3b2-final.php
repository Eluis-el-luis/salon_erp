<?php

ini_set('zend.exception_ignore_args','1'); $exit=1;
try {
    require __DIR__.'/ac05b3b2-bootstrap.php';
    $pdo=Tests\Support\SyntheticPresentationHarness::connect();
    $repo=App\Services\SyntheticFinancialPresentationDraftRepository::forHarness();
    $rows=$pdo->query('SELECT id,document_uuid,family_uuid,version_number,status,revision,content_hash,actor_id,created_at FROM test_financial_presentation_drafts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $audits=$pdo->query('SELECT id,draft_id,request_uuid,event,revision,expected_revision,before_hash,after_hash,actor_id,recorded_at FROM test_financial_presentation_draft_audits ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row){$r=$repo->get($row['document_uuid']);if(count($r['history'])!==(int)$row['revision'] || $r['business_authority'] || $r['publishable'])throw new RuntimeException('Draft integrity failure.');}
    if((int)$pdo->query('SELECT COUNT(*) FROM test_financial_presentation_draft_audits a LEFT JOIN test_financial_presentation_drafts d ON d.id=a.draft_id WHERE d.id IS NULL')->fetchColumn()!==0)throw new RuntimeException('Orphan audit.');
    $races=[];foreach(glob(dirname(__DIR__,2).'/storage/app/private/ac05b3b2-race-result-*.json') as $path){$r=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!$r['processes_closed']||$r['first_connection']===$r['second_connection'])throw new RuntimeException('Race evidence invalid.');$races[]=basename($path);}
    $manifest=['contract'=>Tests\Support\SyntheticPresentationHarness::CONTRACT,'migrations'=>82,'tables'=>91,'drafts'=>$rows,'audits'=>$audits,'race_results'=>$races,'business_authority'=>false];
    $path=dirname(__DIR__,2).'/storage/app/private/ac05b3b2-final-manifest.json';$f=fopen($path,'xb');if(!$f)throw new RuntimeException('Final manifest exists.');fwrite($f,json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fclose($f);
    echo json_encode(['drafts'=>count($rows),'audits'=>count($audits),'all_histories_verified'=>true,'orphan_audits'=>0,'race_evidence_files'=>count($races),'manifest'=>basename($path)]),PHP_EOL;
    foreach($rows as $row)echo json_encode(['draft_id'=>$row['id'],'revision'=>$row['revision']]),PHP_EOL;
    $exit=0;
}catch(Throwable $e){fwrite(STDERR,'Final verification stopped: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);}
finally{putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
exit($exit);
