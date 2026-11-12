<?php

// Validar y fijar la raíz ANTES de cargar Composer o arrancar Laravel.
$project = dirname(__DIR__);
require_once __DIR__.'/Support/TestDatabaseSafety.php';
foreach ([$_ENV['APP_BASE_PATH'] ?? null, $_SERVER['APP_BASE_PATH'] ?? null, getenv('APP_BASE_PATH')] as $candidateRoot) {
    if ($candidateRoot !== null && $candidateRoot !== false) {
        Tests\Support\TestDatabaseSafety::assertApplicationRoot($project, $candidateRoot);
    }
}
putenv('APP_BASE_PATH='.$project);
$_ENV['APP_BASE_PATH'] = $project;
$_SERVER['APP_BASE_PATH'] = $project;
unset($candidateRoot);

// Bootstrap exclusivo de PHPUnit: no inicia Laravel ni abre conexiones.
require $project.'/vendor/autoload.php';

$localFile = $project.'/.env.testing';

if (! is_file($localFile)) {
    throw new RuntimeException('Falta .env.testing. Consulte docs/PRUEBAS_SEGURAS.md; no se utilizará .env como alternativa.');
}

try {
    $local = Dotenv\Dotenv::parse(file_get_contents($localFile));
} catch (Throwable) {
    throw new RuntimeException('No se pudo leer .env.testing. Revise su sintaxis sin publicar su contenido.');
}

if (array_diff(array_keys($local), ['DB_PASSWORD', 'SALON_TEST_DB_RESET_ALLOWED']) !== []) {
    throw new RuntimeException('.env.testing solo admite DB_PASSWORD y SALON_TEST_DB_RESET_ALLOWED. El destino está fijado en el bootstrap.');
}

$password = $local['DB_PASSWORD'] ?? '';
if (! is_string($password) || $password === '' || $password === 'REEMPLAZAR_LOCALMENTE') {
    throw new RuntimeException('Configure localmente una contraseña exclusiva para salon_erp_tester.');
}

$resetAllowed = $local['SALON_TEST_DB_RESET_ALLOWED'] ?? '0';
if (! in_array($resetAllowed, ['0', '1'], true)) {
    throw new RuntimeException('SALON_TEST_DB_RESET_ALLOWED solo admite 0 o 1.');
}

$fixed = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    // Clave pública y desechable; nunca se reutiliza la clave del ERP.
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('0', 32)),
    'APP_MAINTENANCE_DRIVER' => 'file',
    'APP_MAINTENANCE_STORE' => 'array',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'salon_erp_test',
    'DB_USERNAME' => 'salon_erp_tester',
    'DB_PASSWORD' => $password,
    'DB_URL' => '',
    'DB_SOCKET' => '',
    'DB_CHARSET' => 'utf8mb4',
    'DB_COLLATION' => 'utf8mb4_0900_ai_ci',
    'MYSQL_ATTR_SSL_CA' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    // Las comillas evitan que env() transforme el nombre del canal en null PHP.
    'LOG_CHANNEL' => '"null"',
    'LOG_DEPRECATIONS_CHANNEL' => '"null"',
    'BROADCAST_CONNECTION' => 'null',
    'BCRYPT_ROUNDS' => '4',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
    'SALON_TEST_DB_RESET_ALLOWED' => $resetAllowed,
    // Laravel no reconoce E:\ como prefijo absoluto de caché. La raíz ya está fijada.
    'APP_CONFIG_CACHE' => 'bootstrap/cache/testing-config.php',
    'APP_ROUTES_CACHE' => 'bootstrap/cache/testing-routes.php',
    'APP_EVENTS_CACHE' => 'bootstrap/cache/testing-events.php',
    'APP_SERVICES_CACHE' => 'bootstrap/cache/services.php',
    'APP_PACKAGES_CACHE' => 'bootstrap/cache/packages.php',
];

// No borrar cachés del ERP desde una herramienta de pruebas.
foreach (['config.php', 'routes-v7.php', 'testing-config.php', 'testing-routes.php', 'testing-events.php'] as $cache) {
    if (is_file($project.'/bootstrap/cache/'.$cache)) {
        throw new RuntimeException('Hay configuración/rutas/eventos cacheados. Revise el aislamiento antes de ejecutar pruebas.');
    }
}

foreach ($fixed as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

define('SALON_TEST_DATABASE_BOOTSTRAPPED', true);
define('SALON_TEST_DATABASE_RESET_ALLOWED', $resetAllowed === '1');
unset($local, $password, $fixed, $value);
