<?php

// Ensayo independiente: sin Composer, Laravel, PHPUnit, credenciales ni SQL.
require_once __DIR__.'/Support/TestDatabaseSafety.php';

use Tests\Support\TestDatabaseSafety as Safety;

$failures = 0;
$cases = 0;
$check = function (string $name, callable $operation, bool $mustReject = true) use (&$failures, &$cases): void {
    $cases++;
    try {
        $operation();
        $passed = ! $mustReject;
    } catch (RuntimeException) {
        $passed = $mustReject;
    } catch (Throwable) {
        $passed = false;
    }
    $failures += $passed ? 0 : 1;
    echo ($passed ? 'OK ' : 'FAIL ').$name.PHP_EOL;
};

$connection = [
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306',
    'database' => 'salon_erp_test', 'username' => 'salon_erp_tester',
    'url' => '', 'unix_socket' => '', 'prefix' => '', 'options' => [],
];
$database = ['default' => 'mysql', 'connections' => ['mysql' => $connection]];
$root = dirname(__DIR__);
$check('raíz correcta', fn () => Safety::assertApplicationRoot($root, $root), false);
$check('otra raíz', fn () => Safety::assertApplicationRoot($root, __DIR__));
$check('raíz vacía', fn () => Safety::assertApplicationRoot($root, ''));
// Simula cada fuente heredada, sin arrancar el bootstrap ni alterar el entorno real.
foreach (['getenv', '_ENV', '_SERVER'] as $source) {
    $inherited = [$source => ['APP_BASE_PATH' => __DIR__]];
    $check('APP_BASE_PATH heredado '.$source, fn () => Safety::assertApplicationRoot($root, $inherited[$source]['APP_BASE_PATH']));
}
$check('recreación sin autorización', fn () => Safety::assertResetAuthorized(false));
// Solo valida el booleano ficticio: no activa flags ni ejecuta una recreación.
$check('autorización ficticia aceptada', fn () => Safety::assertResetAuthorized(true), false);
$check('configuración permitida', fn () => Safety::assertConfiguration('testing', $database), false);
foreach ([
    'driver' => 'sqlite', 'host' => 'localhost', 'port' => '3307',
    'database' => 'salon_erp', 'username' => 'root',
    'url' => 'mysql://invalid.example/forbidden', 'unix_socket' => 'forbidden',
    'prefix' => 'other_', 'read' => ['host' => 'other'],
    'write' => ['database' => 'salon_erp'], 'options' => [999 => 'forbidden'],
] as $field => $value) {
    $check('rechazo '.$field, fn () => Safety::assertConnection(array_replace($connection, [$field => $value])));
}
$check('entorno ordinario', fn () => Safety::assertConfiguration('local', $database));
$check('conexión predeterminada distinta', fn () => Safety::assertConfiguration('testing', array_replace($database, ['default' => 'sqlite'])));
$extra = $database;
$extra['connections']['other'] = $connection;
$check('conexión adicional', fn () => Safety::assertConfiguration('testing', $extra));
$check('transacción en otra conexión', fn () => Safety::assertConfiguration('testing', $database, ['other']));
$identity = (object) ['database_name' => 'salon_erp_test', 'account_name' => 'salon_erp_tester@localhost', 'server_port' => 3306];
$check('identidad permitida', fn () => Safety::assertServerIdentity($identity), false);
foreach (['database_name' => 'salon_erp', 'account_name' => 'root@localhost', 'server_port' => 3307] as $field => $value) {
    $check('identidad '.$field, fn () => Safety::assertServerIdentity((object) array_replace((array) $identity, [$field => $value])));
}
echo 'Casos: '.$cases.'; fallos: '.$failures.PHP_EOL;
exit($failures === 0 ? 0 : 1);
