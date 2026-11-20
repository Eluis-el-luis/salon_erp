<?php

ini_set('zend.exception_ignore_args', '1'); $exit = 1;
try {
    require dirname(__DIR__).'/bootstrap.php';
    define('SALON_SYNTHETIC_DRAFT_HARNESS', Tests\Support\SyntheticPresentationHarness::CONTRACT);
    if (($argv[1] ?? '') !== 'install-and-verify-empty-rollback') throw new RuntimeException('Explicit installation contract required.');
    $pdo = Tests\Support\SyntheticPresentationHarness::connect();
    Tests\Support\SyntheticPresentationDraftSchema::install($pdo);
    Tests\Support\SyntheticPresentationDraftSchema::removeEmpty($pdo);
    Tests\Support\SyntheticPresentationDraftSchema::install($pdo);
    Tests\Support\SyntheticPresentationHarness::preflight($pdo, 91);
    echo "INSTALLED_TEST_ONLY=true; EMPTY_ROLLBACK_VERIFIED=true; MIGRATIONS=82; TABLES=91\n"; $exit = 0;
} catch (Throwable $e) { fwrite(STDERR, 'Installation stopped: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine().PHP_EOL); }
finally { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
exit($exit);
