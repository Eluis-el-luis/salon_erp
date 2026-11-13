<?php

// Administración acotada. Nunca inicia Laravel ni ejecuta migraciones/seeders.
ini_set('zend.exception_ignore_args', '1');
$stage = 'preflight';
try {
    $root = dirname(__DIR__, 2);
    $file = $root.'/storage/app/private/bootstrap-check-credentials.json';
    if (! is_file($file) || filesize($file) !== 0) {
        throw new RuntimeException('Requiere la reserva privada vacía del archivo.');
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server = $pdo->query('SELECT VERSION() AS version, CURRENT_USER() AS account, @@port AS port, @@partial_revokes AS partial_revokes')->fetch(PDO::FETCH_OBJ);
    if ($server->version !== '8.4.3' || $server->account !== 'root@localhost' || (int) $server->port !== 3306 || (int) $server->partial_revokes !== 0) {
        throw new RuntimeException('Condiciones administrativas distintas de las inspeccionadas.');
    }
    if ($pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='salon_erp_bootstrap_check'")->fetchColumn()
        || $pdo->query("SELECT COUNT(*) FROM mysql.user WHERE User='salon_erp_bootstrap_runner'")->fetchColumn()) {
        throw new RuntimeException('Base o cuenta ya existentes: detener sin reutilizar.');
    }
    $pdo->exec('CREATE DATABASE `salon_erp_bootstrap_check` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    $stage = 'database-created';
    $result = $pdo->query("CREATE USER 'salon_erp_bootstrap_runner'@'localhost' IDENTIFIED BY RANDOM PASSWORD")->fetch(PDO::FETCH_ASSOC);
    $password = $result['generated password'] ?? null;
    if (! is_string($password) || $password === '') {
        throw new RuntimeException('No se recibió contraseña generada; no imprimir resultado administrativo.');
    }
    $stage = 'account-created';
    $data = ['database' => 'salon_erp_bootstrap_check', 'username' => 'salon_erp_bootstrap_runner',
        'host' => '127.0.0.1', 'port' => 3306, 'password' => $password,
        'app_key' => 'base64:'.base64_encode(random_bytes(32))];
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    if (file_put_contents($file, $json, LOCK_EX) !== strlen($json)) {
        throw new RuntimeException('No se pudieron custodiar credenciales locales.');
    }
    unset($password, $result, $data, $json);
    // partial_revokes=0: escapar cada underscore para limitar el esquema EXACTO.
    $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON `salon\\_erp\\_bootstrap\\_check`.* TO 'salon_erp_bootstrap_runner'@'localhost'");
    echo "PREPARED=salon_erp_bootstrap_check; ACCOUNT=salon_erp_bootstrap_runner@localhost\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Preparation stopped; stage='.$stage.'; code='.(string) $error->getCode().". No limpiar ni reintentar automáticamente.\n");
    exit(1);
}
