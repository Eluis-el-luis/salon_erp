<?php

ini_set('zend.exception_ignore_args', '1'); $exit = 1;
try {
    require __DIR__.'/ac05b3b2-bootstrap.php';
    $file = $argv[1] ?? ''; $base = realpath(dirname(__DIR__,2).'/storage/app/private'); $real = realpath($file);
    if (!$real || dirname($real) !== $base || !preg_match('/^ac05b3b2-race-[a-f0-9]+\.json$/D', basename($real))) throw new RuntimeException('Invalid worker fixture.');
    $p = json_decode(file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
    $wait = static function (string $expected) {
        stream_set_timeout(STDIN, 20); if (trim((string)fgets(STDIN)) !== $expected) throw new RuntimeException('Worker barrier timeout.');
    };
    $repo = App\Services\SyntheticFinancialPresentationDraftRepository::forHarness(static function ($stage) use ($p,$wait) {
        if ($p['hold'] && $stage === $p['hold_stage']) { echo "LOCKED\n"; flush(); $wait('RELEASE'); }
        if (($p['fault'] ?? null) === $stage) throw new Error('SYNTHETIC:worker_fault');
    });
    echo json_encode(['ready'=>true,'connection_id'=>$repo->connectionId()]),PHP_EOL; flush(); $wait('GO');
    echo "ENTERING\n"; flush();
    try {
        $r = $p['action'] === 'create' ? $repo->create($p['uuid'],$p['content'],'SYNTHETIC:race')
            : $repo->revise($p['uuid'],$p['request_uuid'],$p['expected_revision'],$p['expected_hash'],$p['content'],'SYNTHETIC:race');
        $out = ['outcome'=>'ok','id'=>(int)$r['document']['id'],'revision'=>(int)$r['document']['revision'],
            'hash'=>$r['document']['content_hash'],'replayed'=>$r['replayed'],'audit_count'=>count($r['history'])];
    } catch (DomainException $e) { $out=['outcome'=>'conflict','reason'=>$e->getMessage()]; }
      catch (Error $e) { if ($e->getMessage() !== 'SYNTHETIC:worker_fault') throw $e; $out=['outcome'=>'rolled_back']; }
    echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL; flush(); $exit=0;
} catch (Throwable $e) { fwrite(STDERR, 'Worker stopped: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL); }
finally { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
exit($exit);
