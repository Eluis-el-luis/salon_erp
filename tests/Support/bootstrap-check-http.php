<?php

// Router exclusivo: nunca servir archivos del workspace ni cargar .env ordinario.
try {
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8766') {
        http_response_code(403);
        exit('Origen no autorizado');
    }
    require __DIR__.'/bootstrap-check-environment.php';
    $app = bootstrap_check_environment();
    $app->handleRequest(\Illuminate\Http\Request::capture());
} catch (Throwable) {
    http_response_code(503);
    echo 'Entorno de ensayo no disponible.';
}
