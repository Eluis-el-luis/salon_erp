<?php

use App\Models\Usuario;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Tests\Support\TestDatabaseSafety;

ini_set('zend.exception_ignore_args', '1');
try {
    require dirname(__DIR__).'/bootstrap.php';
    if (! SALON_TEST_DATABASE_RESET_ALLOWED) {
        throw new RuntimeException('Authorized H02 test run required');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $mysql = $app['config']->get('database.connections.mysql');
    TestDatabaseSafety::assertConnection($mysql);
    $app['config']->set('database.connections', ['mysql' => $mysql]);
    TestDatabaseSafety::assertConfiguration($app->environment(), $app['config']->get('database'));
    $db = $app['db']->connection();
    TestDatabaseSafety::assertServerIdentity($db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port'));
    $input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! in_array($input['operation'], ['sale', 'merma', 'reverse'], true)) {
        throw new RuntimeException('Invalid operation');
    }
    $connection = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    echo json_encode(['event' => 'ready', 'connection' => $connection]).PHP_EOL;
    fflush(STDOUT);
    $held = false;
    $db->beforeExecuting(function ($sql) {
        if (str_contains($sql, 'from `items`') && str_contains($sql, 'for update')) {
            echo json_encode(['event' => 'attempt']).PHP_EOL;
            fflush(STDOUT);
        }
    });
    $db->listen(function ($q) use ($input, &$held) {
        if ($input['hold'] && ! $held && str_contains($q->sql, 'from `items`') && str_contains($q->sql, 'for update')) {
            $held = true;
            echo json_encode(['event' => 'locked']).PHP_EOL;
            fflush(STDOUT);
            if (trim((string) fgets(STDIN)) !== 'RELEASE') {
                throw new RuntimeException('Release required');
            }
        }
    });
    $app['auth']->guard('web')->setUser(Usuario::findOrFail($input['actor']));
    $db->beginTransaction();
    $db->table('items')->count(); // Snapshot antiguo deliberado, antes de esperar al rival.
    $uri = match ($input['operation']) {
        'sale' => '/sales','merma' => '/mermas','reverse' => '/ventas/'.(int) $input['sale_id'].'/anular'
    };
    $request = Request::create($uri, 'POST', $input['payload'], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $json = json_decode($response->getContent(), true);
    $errors = $request->hasSession() ? $request->session()->get('errors') : null;
    $ok = $input['operation'] === 'sale' ? $response->getStatusCode() === 201 :
        ($response->getStatusCode() === 302 && ! $errors && $request->session()->has('success'));
    if ($ok) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    $kernel->terminate($request, $response);
    echo json_encode(['event' => 'result', 'status' => $response->getStatusCode(), 'ok' => $ok, 'id' => $json['sale_id'] ?? null, 'connection' => $connection]).PHP_EOL;
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
