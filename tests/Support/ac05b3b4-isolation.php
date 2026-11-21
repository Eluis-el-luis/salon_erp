<?php

// Independent administrative comparison only; never imported by the document reader.
ini_set('zend.exception_ignore_args', '1');
try {
    require __DIR__.'/ac05b3b2-bootstrap.php';
    $mode = $argv[1] ?? ''; $run = $argv[2] ?? '';
    if (!in_array($mode, ['before','after'], true) || !preg_match('/^[a-f0-9]{16}$/D', $run)) throw new RuntimeException('Explicit unique run required.');
    $root = dirname(__DIR__, 2);
    $pdo = Tests\Support\AccountingSchemaInspection::restricted();
    Tests\Support\AccountingTestSchemaProfiles::verifyCurrentBackup();
    Tests\Support\AccountingTestSchemaProfiles::assertSchema(Tests\Support\AccountingTestSchemaProfiles::CURRENT, Tests\Support\AccountingSchemaInspection::metadata($pdo));
    $state = ['profile'=>Tests\Support\AccountingTestSchemaProfiles::CURRENT,
        'snapshot'=>Tests\Support\AccountingSchemaInspection::snapshot(),
        'history'=>Tests\Support\AccountingSchemaInspection::verifyDrafts($pdo)];
    $approved = json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2r-isolation-after.json'), true, 512, JSON_THROW_ON_ERROR);
    Tests\Support\AccountingTestSchemaProfiles::assertPreserved($approved, $state);
    $prefix = $root.'/storage/app/private/ac05b3b4-'.$run;
    if ($mode === 'after') Tests\Support\AccountingTestSchemaProfiles::assertPreserved(json_decode(file_get_contents($prefix.'-before.json'), true, 512, JSON_THROW_ON_ERROR), $state);
    $path = $prefix.'-'.$mode.'.json'; $f = fopen($path, 'xb');
    if (!$f) throw new RuntimeException('Evidence exists.');
    fwrite($f, json_encode($state, JSON_THROW_ON_ERROR)); fclose($f);
    echo json_encode(['mode'=>$mode,'run'=>$run,'sha256'=>hash_file('sha256',$path),'history'=>$state['history'],'preserved'=>true]), PHP_EOL;
    foreach ($state['snapshot'] as $schema=>$tables) echo json_encode(['schema'=>$schema,'tables'=>count($tables),'rows'=>array_sum(array_map(fn($t)=>count($t['rows']),$tables))]), PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, 'B4 isolation rejected: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL); exit(1); }
finally { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
