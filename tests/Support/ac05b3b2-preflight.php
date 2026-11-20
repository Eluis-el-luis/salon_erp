<?php

ini_set('zend.exception_ignore_args', '1'); $exit = 1;
try {
    require dirname(__DIR__).'/bootstrap.php';
    define('SALON_SYNTHETIC_DRAFT_HARNESS', Tests\Support\SyntheticPresentationHarness::CONTRACT);
    $pdo = Tests\Support\SyntheticPresentationHarness::connect();
    $result = Tests\Support\SyntheticPresentationHarness::preflight($pdo, 89);
    $f = fopen(dirname(__DIR__, 2).'/storage/app/private/ac05b3b2-preflight.json', 'xb');
    if (!$f) throw new RuntimeException('Preflight evidence exists.');
    fwrite($f, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); fclose($f);
    unset($result['structures']); echo json_encode($result), PHP_EOL; $exit = 0;
} catch (Throwable $e) { fwrite(STDERR, 'Preflight stopped: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL); }
finally { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
exit($exit);
