<?php

ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4','salon_erp_tester',$_ENV['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try {
    $i=$pdo->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port')->fetch(PDO::FETCH_OBJ);
    Tests\Support\TestDatabaseSafety::assertServerIdentity($i);
    echo json_encode(['migrations'=>(int)$pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn(),
        'operations'=>$pdo->query('SELECT status,COUNT(*) AS total FROM accounting_event_operations GROUP BY status')->fetchAll(PDO::FETCH_ASSOC),
        'resolutions'=>(int)$pdo->query('SELECT COUNT(*) FROM accounting_entry_line_resolutions')->fetchColumn(),
        'attributed_headers'=>(int)$pdo->query('SELECT COUNT(*) FROM asientos_contables WHERE accounting_policy_version_id IS NOT NULL')->fetchColumn(),
        'business_authorizations'=>(int)$pdo->query("SELECT COUNT(*) FROM accounting_policy_authorizations WHERE purpose <> 'simulation'")->fetchColumn(),
        'policy_years'=>$pdo->query('SELECT YEAR(valid_from) AS year,COUNT(*) AS total FROM accounting_policy_versions GROUP BY YEAR(valid_from)')->fetchAll(PDO::FETCH_ASSOC),
        'prepared'=>$pdo->query("SELECT o.id,o.bank_account_id,o.result_movement_id,o.result_asiento_id,b.saldo_actual,(SELECT COUNT(*) FROM movimientos_bancarios m WHERE m.cuenta_bancaria_id=o.bank_account_id) AS bank_movements FROM accounting_event_operations o JOIN cuentas_bancarias b ON b.id=o.bank_account_id WHERE o.status='prepared'")->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR),PHP_EOL;
} finally {$pdo=null;putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
