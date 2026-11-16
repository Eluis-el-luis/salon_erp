<?php

// Runner puramente unitario: no inicia Laravel ni SQL. Nunca habilita RESET_ALLOWED.
ini_set('zend.exception_ignore_args', '1');
$root = dirname(__DIR__, 2);
$exit = 1;
try {
    if (PHP_VERSION !== '8.3.30') throw new RuntimeException('Use PHP 8.3.30 de Laragon.');
    if (!in_array('PDO', array_map('trim', explode(',', ini_get('disable_classes'))), true)) {
        throw new RuntimeException('Ejecutar con -d disable_classes=PDO,mysqli para impedir conexiones SQL reales.');
    }
    require $root.'/tests/bootstrap.php';
    if (SALON_TEST_DATABASE_RESET_ALLOWED) throw new RuntimeException('Esta fase exige RESET_ALLOWED=0.');
    $exit = (new PHPUnit\TextUI\Application)->run(['phpunit','--configuration',$root.'/phpunit.xml',
        '--do-not-cache-result','--no-progress',$root.'/tests/Unit/OpeningDocumentContractTest.php',
        $root.'/tests/Unit/OpeningEligibilityTest.php',$root.'/tests/Unit/OpeningIdentityTest.php',
        $root.'/tests/Unit/OpeningCatalogueGuardTest.php']);
} finally {
    putenv('SALON_TEST_DB_RESET_ALLOWED=0');
    $_ENV['SALON_TEST_DB_RESET_ALLOWED'] = $_SERVER['SALON_TEST_DB_RESET_ALLOWED'] = '0';
    echo 'RESET_ALLOWED=0; SQL_CONNECTIONS_DISABLED; EXIT='.$exit.PHP_EOL;
}
exit($exit);
