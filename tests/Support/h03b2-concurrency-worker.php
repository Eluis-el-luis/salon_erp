<?php

use App\Models\Usuario;
use App\Services\CapturaConsumoService;
use App\Services\CompraService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Tests\Support\TestDatabaseSafety;

ini_set('zend.exception_ignore_args', '1');
try {
    require dirname(__DIR__).'/bootstrap.php';
    TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $mysql = $app['config']->get('database.connections.mysql');
    TestDatabaseSafety::assertConnection($mysql);
    $app['config']->set('database.connections', ['mysql' => $mysql]);
    TestDatabaseSafety::assertConfiguration($app->environment(), $app['config']->get('database'));
    $db = $app['db']->connection();
    TestDatabaseSafety::assertServerIdentity($db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port'));
    $app['config']->set('salon.inventory_fractional_v2', true);
    if (! CapturaConsumoService::activa()) {
        throw new RuntimeException('V2 scope rejected');
    }
    $input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! in_array($input['operation'], ['purchase', 'sale', 'merma'], true)) {
        throw new RuntimeException('Operation rejected');
    }
    $connection = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    echo json_encode(['event' => 'ready', 'connection' => $connection]).PHP_EOL;
    fflush(STDOUT);
    $held = false;
    $db->beforeExecuting(function ($sql) {
        if (str_contains($sql, 'from `items`') && str_contains($sql, 'for update')) {
            echo '{"event":"attempt"}'.PHP_EOL;
            fflush(STDOUT);
        }
    });
    $db->listen(function ($q) use ($input, &$held) {
        if ($input['hold'] && ! $held && str_contains($q->sql, 'from `items`') && str_contains($q->sql, 'for update')) {
            $held = true;
            echo '{"event":"locked"}'.PHP_EOL;
            fflush(STDOUT);
            if (trim((string) fgets(STDIN)) !== 'RELEASE') {
                throw new RuntimeException('Release required');
            }
        }
    });
    $app['auth']->guard('web')->setUser(Usuario::findOrFail($input['actor']));
    $db->beginTransaction();
    $db->table('items')->count(); // Lectura antigua deliberada.
    if ($input['operation'] === 'purchase') {
        $doc = app(CompraService::class)->registrarFactura($input['payload'], $input['actor']);
        $status = 201;
        $ok = true;
        $id = $doc->id;
    } else {
        $request = Request::create($input['operation'] === 'sale' ? '/sales' : '/mermas', 'POST', $input['payload'], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $json = json_decode($response->getContent(), true);
        $id = $json['sale_id'] ?? null;
        $ok = $input['operation'] === 'sale' ? $status === 201 : ($status === 302 && ! $request->session()->has('errors') && $request->session()->has('success'));
    }
    if ($ok) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    if (isset($kernel)) {
        $kernel->terminate($request, $response);
    }
    echo json_encode(['event' => 'result', 'status' => $status, 'ok' => $ok, 'id' => $id, 'connection' => $connection]).PHP_EOL;
    $db->disconnect();
} catch (Throwable $e) {
    if (isset($db)) {
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }$db->disconnect();
    }
    echo json_encode(['event' => 'failure', 'class' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine()]).PHP_EOL;
    exit(2);
}
