<?php

// Canal interno de comprobación. Acceso normal del ERP sigue cerrado por mantenimiento.
try {
    if(($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1' || ($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8767') { http_response_code(503); exit('No disponible'); }
    require __DIR__.'/development-rebuild.php';
    $state=dr_read('closed');
    if(!hash_equals($state['http_nonce'],$_SERVER['HTTP_X_H13D_CHECK']??'')) { http_response_code(503); exit('No disponible'); }
    $app=dr_environment();
    if(!$app->maintenanceMode()->active() || !is_file(storage_path('framework/maintenance.php')) || !dr_maintenance_data_safe($app->maintenanceMode()->data())) throw new RuntimeException('ERP must remain closed');
    // Solo este proceso interno con nonce; se mantienen sesión, auth, roles y CSRF reales.
    \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::except(['*']);
    $app['config']->set('session.cookie','salon_h13d_verification');
    $app->handleRequest(\Illuminate\Http\Request::capture());
} catch(Throwable) { http_response_code(503); echo 'Verificación no disponible.'; }
