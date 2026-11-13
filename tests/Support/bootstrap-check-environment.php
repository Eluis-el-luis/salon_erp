<?php

/** Bootstrap independiente: no carga tests/bootstrap.php ni .env del ERP. */
function bootstrap_check_environment(): \Illuminate\Foundation\Application
{
    ini_set('zend.exception_ignore_args', '1');
    $root = dirname(__DIR__, 2);
    foreach ([$_ENV['APP_BASE_PATH'] ?? null, $_SERVER['APP_BASE_PATH'] ?? null, getenv('APP_BASE_PATH')] as $candidate) {
        if ($candidate !== null && $candidate !== false && realpath($candidate) !== realpath($root)) {
            throw new RuntimeException('Raíz heredada no autorizada.');
        }
    }
    foreach (['config.php', 'routes-v7.php', 'bootstrap-check-config.php', 'bootstrap-check-routes.php', 'bootstrap-check-events.php'] as $cache) {
        if (is_file($root.'/bootstrap/cache/'.$cache)) {
            throw new RuntimeException('Hay caché no autorizada.');
        }
    }
    if (is_file($root.'/__NO_ENV_BOOTSTRAP_CHECK__') || is_file($root.'/__NO_ENV_BOOTSTRAP_CHECK__.local')) {
        throw new RuntimeException('Archivo de entorno inesperado.');
    }
    require_once $root.'/vendor/autoload.php';
    $testing = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.testing'));
    if (($testing['SALON_TEST_DB_RESET_ALLOWED'] ?? '') !== '0') {
        throw new RuntimeException('El indicador de PHPUnit debe permanecer en cero.');
    }
    $secret = json_decode(file_get_contents($root.'/storage/app/private/bootstrap-check-credentials.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($secret['database'] !== 'salon_erp_bootstrap_check' || $secret['username'] !== 'salon_erp_bootstrap_runner'
        || $secret['host'] !== '127.0.0.1' || $secret['port'] !== 3306
        || empty($secret['password']) || ! str_starts_with($secret['app_key'], 'base64:')) {
        throw new RuntimeException('Credenciales/destino del ensayo no autorizados.');
    }
    $fixed = [
        'APP_BASE_PATH' => $root, 'APP_ENV' => 'local', 'APP_DEBUG' => 'false',
        'APP_KEY' => $secret['app_key'], 'APP_NAME' => 'Salon Bootstrap Check', 'APP_URL' => 'http://127.0.0.1:8766',
        'APP_MAINTENANCE_DRIVER' => 'file', 'APP_MAINTENANCE_STORE' => 'array',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
        'DB_DATABASE' => 'salon_erp_bootstrap_check', 'DB_USERNAME' => 'salon_erp_bootstrap_runner',
        'DB_PASSWORD' => $secret['password'], 'DB_URL' => '', 'DB_SOCKET' => '',
        'DB_CHARSET' => 'utf8mb4', 'DB_COLLATION' => 'utf8mb4_0900_ai_ci', 'MYSQL_ATTR_SSL_CA' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'mysql',
        'SESSION_COOKIE' => 'salon_bootstrap_check_session', 'SESSION_DOMAIN' => 'null',
        'SESSION_SECURE_COOKIE' => 'false', 'SESSION_ENCRYPT' => 'false',
        'AUTH_GUARD' => 'web', 'AUTH_MODEL' => 'App\\Models\\Usuario',
        'LOG_CHANNEL' => '"null"', 'LOG_DEPRECATIONS_CHANNEL' => '"null"', 'BROADCAST_CONNECTION' => 'null',
        'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        'SALON_TEST_DB_RESET_ALLOWED' => '0',
        'APP_CONFIG_CACHE' => 'bootstrap/cache/bootstrap-check-config.php',
        'APP_ROUTES_CACHE' => 'bootstrap/cache/bootstrap-check-routes.php',
        'APP_EVENTS_CACHE' => 'bootstrap/cache/bootstrap-check-events.php',
        'APP_SERVICES_CACHE' => 'bootstrap/cache/services.php', 'APP_PACKAGES_CACHE' => 'bootstrap/cache/packages.php',
    ];
    foreach ($fixed as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('__NO_ENV_BOOTSTRAP_CHECK__');
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $mysql = $app['config']->get('database.connections.mysql');
    if (realpath($app->basePath()) !== realpath($root) || $app->environment() !== 'local'
        || $mysql['driver'] !== 'mysql' || $mysql['database'] !== 'salon_erp_bootstrap_check'
        || $mysql['username'] !== 'salon_erp_bootstrap_runner' || $mysql['host'] !== '127.0.0.1'
        || (int) $mysql['port'] !== 3306 || !empty($mysql['url']) || !empty($mysql['read']) || !empty($mysql['write'])
        || !empty($mysql['unix_socket']) || !empty($mysql['prefix']) || !empty($mysql['options'])
        || $app['config']->get('app.key') !== $secret['app_key']) {
        throw new RuntimeException('Laravel resolvió configuración no autorizada.');
    }
    $app['config']->set('database.connections', ['mysql' => $mysql]);
    $app['config']->set('database.default', 'mysql');
    $connection = $app['db']->connection('mysql');
    $id = $connection->selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@port AS port, CURRENT_ROLE() AS role_name');
    if ($id->db !== 'salon_erp_bootstrap_check' || $id->account !== 'salon_erp_bootstrap_runner@localhost' || (int) $id->port !== 3306 || $id->role_name !== 'NONE') {
        throw new RuntimeException('Identidad efectiva no autorizada.');
    }
    $grants = array_map(fn ($row) => array_values((array) $row)[0], $connection->select('SHOW GRANTS'));
    $allowed = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES'];
    sort($allowed);
    if (count($grants) !== 2) throw new RuntimeException('Privilegios o roles adicionales.');
    $usage = $scope = false;
    foreach ($grants as $grant) {
        if ($grant === 'GRANT USAGE ON *.* TO `salon_erp_bootstrap_runner`@`localhost`') {
            $usage = true;
        } else {
            $parts = explode(' ON ', $grant, 2);
            $privileges = explode(', ', substr($parts[0], 6));
            sort($privileges);
            $expected = '`'.str_replace('_', chr(92).'_', 'salon_erp_bootstrap_check').'`.* TO `salon_erp_bootstrap_runner`@`localhost`';
            if (($parts[1] ?? '') !== $expected || $privileges !== $allowed) throw new RuntimeException('Alcance SQL no autorizado.');
            $scope = true;
        }
    }
    if (!$usage || !$scope) throw new RuntimeException('Grants inesperados.');
    foreach (['salon_erp', 'salon_erp_test'] as $schema) {
        try {
            $connection->select("SELECT 1 FROM `$schema`.`users` LIMIT 0");
            throw new RuntimeException('Acceso SQL a otra base permitido.');
        } catch (\Illuminate\Database\QueryException $error) {
            if (($error->errorInfo[1] ?? 0) !== 1142) throw $error;
        }
    }
    unset($secret, $testing, $fixed, $value);
    return $app;
}
