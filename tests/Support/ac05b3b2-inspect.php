<?php

try {
    require __DIR__.'/ac05b3b2-bootstrap.php';
    $pdo = Tests\Support\SyntheticPresentationHarness::connect();
    foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo json_encode(['id'=>$row['Id'],'command'=>$row['Command'],'time'=>$row['Time'],'state'=>$row['State'],
            'sql_head'=>substr($row['Info'] ?? '',0,95)]),PHP_EOL;
    }
} finally { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
