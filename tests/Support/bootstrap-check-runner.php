<?php

require_once __DIR__.'/bootstrap-check-environment.php';

function bootstrap_check_assert(bool $condition, string $label): void
{
    if (!$condition) {
        bootstrap_check_record('assertion-failure', ['label' => $label]);
        throw new RuntimeException($label);
    }
    echo 'PASS '.$label.PHP_EOL;
}

function bootstrap_check_record(string $stage, array $data): void
{
    $file = dirname(__DIR__, 2).'/storage/app/private/bootstrap-check-'.$stage.'.json';
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
}

function bootstrap_check_data(\Illuminate\Foundation\Application $app): array
{
    $snapshot = [];
    foreach ($app['db']->select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\' ORDER BY TABLE_NAME') as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table->name)) throw new RuntimeException('Nombre inesperado');
        $hashes = [];
        foreach ($app['db']->table($table->name)->get() as $row) $hashes[] = hash('sha256', serialize((array) $row));
        sort($hashes, SORT_STRING);
        $snapshot[$table->name] = [count($hashes), hash('sha256', implode('', $hashes))];
    }
    return $snapshot;
}

function bootstrap_check_command(\Illuminate\Foundation\Application $app, string $command, array $arguments): void
{
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $code = $kernel->call($command, $arguments);
    file_put_contents(dirname(__DIR__, 2).'/storage/app/private/bootstrap-check-console.log', $kernel->output(), FILE_APPEND);
    bootstrap_check_assert($code === 0, $command.' exit 0');
}

function bootstrap_check_schema(\Illuminate\Foundation\Application $app): void
{
    $migrator = $app['migrator'];
    $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
    $ran = $migrator->getRepository()->getRan();
    bootstrap_check_assert(count($files) === 74 && count($ran) === 74 && array_diff($files, $ran) === [], '74 migrations; none pending');
    $fk = $app['db']->selectOne("SELECT REFERENCED_TABLE_NAME AS target FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointments' AND COLUMN_NAME='service_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
    bootstrap_check_assert($fk && $fk->target === 'services', 'appointments FK references services');
}

function bootstrap_check_clean_business(\Illuminate\Foundation\Application $app): void
{
    foreach (['sales', 'sale_details', 'pagos', 'purchase_invoices', 'cuentas_por_pagar', 'asientos_contables', 'detalle_asientos', 'lotes', 'stock_ledger_entries', 'saldos_moneda', 'movimientos_bancarios', 'salary_slips'] as $table) {
        bootstrap_check_assert($app['db']->table($table)->count() === 0, $table.' empty');
    }
}

function bootstrap_check_essential(\Illuminate\Foundation\Application $app): void
{
    bootstrap_check_schema($app);
    bootstrap_check_command($app, 'db:seed', ['--database' => 'mysql', '--class' => 'DatabaseSeeder', '--force' => true]);
    $before = bootstrap_check_data($app);
    bootstrap_check_command($app, 'db:seed', ['--database' => 'mysql', '--class' => 'DatabaseSeeder', '--force' => true]);
    bootstrap_check_assert($before === bootstrap_check_data($app), 'essential repeated: all table data equal');
    bootstrap_check_assert($app['db']->table('roles')->count() === 5 && $app['db']->table('roles')->distinct()->count('name') === 5, 'five unique roles');
    bootstrap_check_assert($app['db']->table('users')->count() === 0, 'no default credentials');
    foreach (['3.3', '1.1.1', '1.1.1.3', '1.1.7', '2.1.5', '2.1.6', '6.8', '6.9', '7.5'] as $code) {
        bootstrap_check_assert($app['db']->table('cuentas_contables')->where('codigo', $code)->where('is_system_account', true)->exists(), 'protected account '.$code);
    }
    bootstrap_check_assert($app['db']->table('cuentas_contables')->where('codigo', '3.3')->where('tipo', 'patrimonio')->where('naturaleza', 'deudora')->exists(), '3.3 patrimonial');
    bootstrap_check_assert($app['db']->table('cuentas_contables')->where('codigo', '7.2')->where('activa', false)->where('permite_movimiento', false)->exists(), '7.2 disabled');
    bootstrap_check_clean_business($app);
    bootstrap_check_record('essential', ['passed' => true, 'counts' => array_map(fn ($row) => $row[0], $before)]);
}

function bootstrap_check_demo(\Illuminate\Foundation\Application $app): void
{
    bootstrap_check_command($app, 'db:seed', ['--database' => 'mysql', '--class' => 'DemoSeeder', '--force' => true]);
    $before = bootstrap_check_data($app);
    bootstrap_check_command($app, 'db:seed', ['--database' => 'mysql', '--class' => 'DemoSeeder', '--force' => true]);
    bootstrap_check_assert($before === bootstrap_check_data($app), 'demo repeated: all table data equal');
    bootstrap_check_assert(\App\Models\Usuario::count() === 5 && \App\Models\Empleado::count() === 5, 'five users and linked employees');
    foreach (\App\Models\Usuario::all() as $user) {
        $name = ['alvaro' => 'Álvaro (Propietario)', 'admin' => 'Administrador', 'contador' => 'Contador', 'recepcion' => 'Recepción / Caja', 'estilista' => 'Estilista'][$user->role];
        bootstrap_check_assert($user->role_id === \App\Models\Rol::where('name', $name)->sole()->id && \App\Models\Empleado::where('user_id', $user->id)->count() === 1, 'role and employee coherent user '.$user->id);
    }
    bootstrap_check_assert(\App\Models\Articulo::count() === 2 && \App\Models\Articulo::where('existencia_actual', '<>', 0)->count() === 0 && \App\Models\Articulo::where('current_volume', '<>', 0)->count() === 0, 'demo products zero stock and open volume');
    bootstrap_check_assert(\App\Models\Servicio::count() === 2 && \App\Models\FormulaServicio::count() === 1, 'demo catalog counts');
    $formula = \App\Models\FormulaServicio::sole();
    bootstrap_check_assert($formula->servicio->name === \Database\Seeders\ServiceSeeder::ALISADO && $formula->articulo->codigo === 'KER-001', 'formula exact fixture keys');
    bootstrap_check_clean_business($app);
    bootstrap_check_record('demo', ['passed' => true, 'counts' => array_map(fn ($row) => $row[0], $before)]);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $stage = $argv[1] ?? '';
    try {
        $app = bootstrap_check_environment();
        if ($stage === 'install') {
            $marker = dirname(__DIR__, 2).'/storage/app/private/bootstrap-check-install-started.json';
            bootstrap_check_assert(!is_file($marker), 'installation not attempted before');
            bootstrap_check_assert((int) $app['db']->selectOne('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->total === 0, 'schema empty');
            $handle = fopen($marker, 'x');
            if (!$handle) throw new RuntimeException('No se pudo reservar intento');
            fwrite($handle, json_encode(['started_at' => gmdate('c')]));
            fclose($handle);
            bootstrap_check_command($app, 'migrate', ['--database' => 'mysql', '--force' => true]);
            bootstrap_check_schema($app);
            bootstrap_check_record('install', ['passed' => true, 'migrations' => 74]);
            bootstrap_check_essential($app);
        } elseif ($stage === 'owner') {
            bootstrap_check_assert(is_file(dirname(__DIR__, 2).'/storage/app/private/bootstrap-check-essential.json'), 'essential stage complete');
            // STDIN/STDOUT reales: ninguna contraseña se introduce mediante herramientas.
            $code = $app->make(\Illuminate\Contracts\Console\Kernel::class)->handle(
                new \Symfony\Component\Console\Input\ArgvInput(['bootstrap-check', 'salon:propietario']),
                new class extends \Symfony\Component\Console\Output\ConsoleOutput {
                    protected function doWrite(string $message, bool $newline): void
                    {
                        // Registrar solo el diagnóstico estructural, nunca entradas ni trazas.
                        if (preg_match('/Diagnóstico local: ([A-Za-z0-9_\\\\]+) en ([A-Za-z0-9_.-]+):(\d+)\./u', $message, $match)) {
                            bootstrap_check_record('owner-diagnostic', ['class' => $match[1], 'file' => $match[2], 'line' => (int) $match[3]]);
                        }
                        parent::doWrite($message, $newline);
                    }
                }
            );
            bootstrap_check_assert($code === 0, 'real owner command exit 0');
            $owner = \App\Models\Usuario::where('role', 'alvaro')->sole();
            bootstrap_check_assert((bool) $owner->is_active && $owner->role_id === \App\Models\Rol::where('name', 'Álvaro (Propietario)')->sole()->id, 'owner active and coherent');
            bootstrap_check_assert(\Illuminate\Support\Facades\Hash::isHashed($owner->password) && \Illuminate\Support\Facades\Hash::isHashed($owner->pin_seguridad), 'owner password and PIN hashed');
            bootstrap_check_record('owner', ['passed' => true, 'owner_id' => $owner->id]);
            bootstrap_check_demo($app);
            require __DIR__.'/bootstrap-check-functional.php';
            bootstrap_check_functional($app, $owner);
            echo 'RUNNER_EXIT_CODE=0'.PHP_EOL;
        } else {
            throw new RuntimeException('Etapa no autorizada.');
        }
    } catch (Throwable $error) {
        bootstrap_check_record('failure', ['stage' => $stage, 'class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()]);
        fwrite(STDERR, 'Runner stopped: '.$stage.'; '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().". No recrear automáticamente.\n");
        fwrite(STDERR, "RUNNER_EXIT_CODE=1\n");
        exit(1);
    }
}
