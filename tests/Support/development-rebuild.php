<?php

// H13-D: runner explícito de desarrollo. Nunca usar PHPUnit ni retirar marcadores.
ini_set('zend.exception_ignore_args', '1');

function dr_file(string $stage): string { return dirname(__DIR__, 2).'/storage/app/private/h13d-'.$stage.'.json'; }
function dr_record(string $stage, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents(dr_file($stage), $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Evidence write failed');
}
function dr_read(string $stage): array { return json_decode(file_get_contents(dr_file($stage)), true, 512, JSON_THROW_ON_ERROR); }
function dr_assert(bool $ok, string $label): void
{
    if (!$ok) { dr_record('assertion-failure', ['label' => $label]); throw new RuntimeException($label); }
    echo 'PASS '.$label.PHP_EOL;
}
function dr_manifest(): array
{
    $root = dirname(__DIR__, 2);
    $files = array_merge(glob($root.'/database/migrations/*.php'), glob($root.'/database/seeders/*.php'), glob($root.'/tests/Support/development-rebuild*.php'), [
        $root.'/.env', $root.'/.env.testing', $root.'/config/database.php', $root.'/bootstrap/app.php',
        $root.'/routes/web.php', $root.'/routes/api.php', $root.'/app/Http/Controllers/CitaController.php',
        $root.'/app/Models/Cita.php', $root.'/app/Models/Servicio.php', $root.'/app/Models/Usuario.php',
        $root.'/app/Models/Articulo.php', $root.'/resources/views/appointments/index.blade.php',
        $root.'/app/Console/Commands/ConfigureOwner.php',
    ]);
    $result = [];
    foreach ($files as $file) $result[str_replace($root.'/', '', $file)] = hash_file('sha256', $file);
    ksort($result);
    return $result;
}
function dr_environment(): \Illuminate\Foundation\Application
{
    $root = dirname(__DIR__, 2);
    if (PHP_VERSION !== '8.3.30' || str_replace('\\', '/', realpath($root)) !== 'E:/laragon/www/salon-erp') throw new RuntimeException('PHP/root mismatch');
    require_once $root.'/vendor/autoload.php';
    $e = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    $expected = ['APP_ENV' => 'local', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'salon_erp', 'DB_USERNAME' => 'root'];
    foreach ($expected as $key => $value) {
        if (($e[$key] ?? null) !== $value) throw new RuntimeException('Unexpected local configuration');
    }
    if (empty($e['APP_KEY']) || !empty($e['DB_URL']) || !empty($e['DB_SOCKET']) || !empty($e['MYSQL_ATTR_SSL_CA'])
        || ($e['APP_MAINTENANCE_DRIVER'] ?? 'file') !== 'file') throw new RuntimeException('Unexpected local options');
    foreach (['APP_BASE_PATH', 'APP_ENV', 'APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'DB_SOCKET', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
        foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $value) {
            if ($value !== null && $value !== false && ($key === 'APP_BASE_PATH' ? realpath($value) !== realpath($root) : $value !== ($e[$key] ?? null))) throw new RuntimeException('Inherited override rejected');
        }
    }
    foreach (['.env.local', 'bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php', 'database/schema/mysql-schema.sql'] as $file) {
        if (is_file($root.'/'.$file)) throw new RuntimeException('Alternate environment/cache/schema dump rejected');
    }
    $testing = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.testing'));
    if (($testing['SALON_TEST_DB_RESET_ALLOWED'] ?? '') !== '0') throw new RuntimeException('Test reset flag must be zero');
    // Solo este proceso; .env y APP_KEY ordinarios se conservan.
    foreach (['APP_DEBUG' => 'false', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => '"null"'] as $key => $value) {
        putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $mysql = $app['config']->get('database.connections.mysql');
    if (realpath($app->basePath()) !== realpath($root) || !$app->environment('local') || $app['config']->get('database.default') !== 'mysql'
        || $mysql['driver'] !== 'mysql' || $mysql['database'] !== 'salon_erp' || $mysql['username'] !== 'root'
        || $mysql['host'] !== '127.0.0.1' || (int)$mysql['port'] !== 3306 || !empty($mysql['url']) || !empty($mysql['read']) || !empty($mysql['write'])
        || !empty($mysql['unix_socket']) || !empty($mysql['prefix']) || !empty($mysql['options'])
        || $app['config']->get('app.key') !== $e['APP_KEY'] || $app['config']->get('app.maintenance.driver') !== 'file') throw new RuntimeException('Resolved configuration rejected');
    $app['config']->set('database.connections', ['mysql' => $mysql]);
    dr_identity($app);
    unset($e, $testing);
    return $app;
}
function dr_identity($app): array
{
    $id = (array)$app['db']->selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@port AS port, VERSION() AS version, CURRENT_ROLE() AS roles');
    if ($id['db'] !== 'salon_erp' || $id['account'] !== 'root@localhost' || (int)$id['port'] !== 3306 || $id['version'] !== '8.4.3' || $id['roles'] !== 'NONE') throw new RuntimeException('Effective SQL identity rejected');
    return $id;
}
function dr_maintenance_data_safe(array $data): bool
{
    return ($data['secret'] ?? null) === null
        && is_array($data['except'] ?? null)
        && array_diff($data['except'], ['/up']) === []
        && is_string($data['template'] ?? null) && $data['template'] !== ''
        && ($data['status'] ?? null) === 503
        && ($data['redirect'] ?? null) === null;
}
function dr_closed($app): void
{
    dr_assert($app->maintenanceMode()->active() && is_file(storage_path('framework/maintenance.php')), 'ERP maintenance active');
    $data = $app->maintenanceMode()->data();
    // Laravel registra /up automáticamente. Solo esa ruta de salud, sin web/sesión,
    // puede quedar exceptuada; ninguna ruta operativa ni un bypass secreto.
    dr_assert(dr_maintenance_data_safe($data), 'HTTP maintenance excludes only standard health endpoint');
}
function dr_command($app, string $command, array $args = []): void
{
    dr_identity($app);
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $code = $kernel->call($command, $args);
    file_put_contents(dirname(__DIR__, 2).'/storage/app/private/h13d-console.log', $kernel->output(), FILE_APPEND);
    dr_assert($code === 0, $command.' exit 0');
}
function dr_snapshot($app): array
{
    $out = [];
    foreach (['salon_erp', 'salon_erp_test', 'salon_erp_bootstrap_check'] as $schema) {
        $tables = $app['db']->select('SELECT TABLE_NAME AS name, TABLE_TYPE AS kind FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME', [$schema]);
        foreach ($tables as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table->name)) throw new RuntimeException('Unexpected identifier');
            $hashes = [];
            foreach ($app['db']->select("SELECT * FROM `$schema`.`{$table->name}`") as $row) $hashes[] = hash('sha256', serialize((array)$row));
            sort($hashes, SORT_STRING);
            $ddl = array_values((array)$app['db']->selectOne("SHOW CREATE TABLE `$schema`.`{$table->name}`"))[1];
            $out[$schema][$table->name] = ['count' => count($hashes), 'content' => hash('sha256', implode('', $hashes)), 'schema' => hash('sha256', $ddl)];
        }
    }
    return $out;
}
function dr_other_integrity($app): void
{
    $before = dr_read('before'); $after = dr_snapshot($app);
    foreach (['salon_erp_test', 'salon_erp_bootstrap_check'] as $schema) dr_assert($before[$schema] === $after[$schema], $schema.' all counts/content/schema equal');
    dr_assert(dr_grants($app) === dr_read('grants-before'), 'SQL accounts and grants unchanged');
    dr_assert(dr_manifest() === dr_read('preflight')['manifest'], 'reviewed files and APP_KEY unchanged');
    dr_record('integrity', ['passed' => true, 'other_databases_equal' => true, 'env_and_key_unchanged' => true]);
}
function dr_grants($app): array
{
    $result=[];
    $accounts=$app['db']->select('SELECT User, Host FROM mysql.user ORDER BY User, Host');
    foreach ($accounts as $account) {
        $user=$app['db']->getPdo()->quote($account->User);
        $host=$app['db']->getPdo()->quote($account->Host);
        $grants=array_map(fn($r)=>array_values((array)$r)[0],$app['db']->select("SHOW GRANTS FOR $user@$host"));
        sort($grants,SORT_STRING);
        $result[$account->User.'@'.$account->Host]=hash('sha256',json_encode($grants,JSON_THROW_ON_ERROR));
    }
    return $result;
}
function dr_schema($app): void
{
    $m = $app['migrator']; $files = array_keys($m->getMigrationFiles(database_path('migrations'))); $ran = $m->getRepository()->getRan();
    dr_assert(count($files) === 74 && count($ran) === 74 && array_diff($files, $ran) === [], '74 migrations; none pending');
    $fk = $app['db']->selectOne("SELECT REFERENCED_TABLE_SCHEMA AS db, REFERENCED_TABLE_NAME AS target FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointments' AND COLUMN_NAME='service_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
    dr_assert($fk && $fk->db === 'salon_erp' && $fk->target === 'services', 'appointments FK to salon_erp.services');
}
function dr_no_financial($app): void
{
    foreach (['sales','sale_details','pagos','purchase_invoices','purchase_invoice_lines','cuentas_por_pagar','asientos_contables','detalle_asientos','lotes','stock_ledger_entries','saldos_moneda','salary_slips','payrolls','cash_sessions','movimientos_bancarios','advances','mermas'] as $table) dr_assert($app['db']->table($table)->count() === 0, $table.' empty');
}
function dr_catalogs($app, bool $demo): void
{
    dr_assert($app['db']->table('roles')->count() === 5 && $app['db']->table('roles')->distinct()->count('name') === 5, 'five unique roles');
    foreach (['3.3','1.1.1','1.1.1.3','1.1.7','2.1.5','2.1.6','6.8','6.9','7.5'] as $code) dr_assert($app['db']->table('cuentas_contables')->where('codigo',$code)->where('is_system_account',true)->exists(), 'protected account '.$code);
    dr_assert($app['db']->table('cuentas_contables')->where('codigo','3.3')->where('tipo','patrimonio')->where('naturaleza','deudora')->exists(), '3.3 patrimonial');
    dr_assert($app['db']->table('cuentas_contables')->where('codigo','7.2')->where('activa',false)->where('permite_movimiento',false)->exists(), '7.2 disabled');
    if ($demo) {
        dr_assert(\App\Models\Usuario::count() === 5 && \App\Models\Empleado::count() === 5, 'five users and employees');
        $names = ['alvaro'=>'Álvaro (Propietario)','admin'=>'Administrador','contador'=>'Contador','recepcion'=>'Recepción / Caja','estilista'=>'Estilista'];
        foreach (\App\Models\Usuario::all() as $u) dr_assert($u->role_id === \App\Models\Rol::where('name',$names[$u->role])->sole()->id && \App\Models\Empleado::where('user_id',$u->id)->count() === 1, 'coherent role and employee user '.$u->id);
        dr_assert(\App\Models\Articulo::count() === 2 && \App\Models\Articulo::where('existencia_actual','<>',0)->orWhere('current_volume','<>',0)->count() === 0, 'two products; zero stock and open volume');
        dr_assert(\App\Models\Servicio::count() === 2 && \App\Models\FormulaServicio::count() === 1, 'two demo services and formula');
        $f=\App\Models\FormulaServicio::sole();
        dr_assert($f->servicio->name === \Database\Seeders\ServiceSeeder::ALISADO && $f->articulo->codigo === 'KER-001', 'formula deterministic references');
    }
    dr_no_financial($app);
}
function dr_preflight($app): void
{
    dr_assert(count(glob(database_path('migrations/*.php'))) === 74, '74 migration files available');
    $route = $app['router']->getRoutes()->match(\Illuminate\Http\Request::create('/api/clients','GET'));
    dr_assert(in_array('web',$route->gatherMiddleware(),true) && in_array('auth',$route->gatherMiddleware(),true), 'H12 API has web session and auth');
    dr_assert(str_contains(file_get_contents(app_path('Http/Controllers/CitaController.php')), 'exists:services,id'), 'H13 service validation present');
    dr_assert(str_contains(file_get_contents(resource_path('views/appointments/index.blade.php')), 'fecha'), 'H14-A date navigation present');
    dr_assert(str_contains(file_get_contents(database_path('seeders/DatabaseSeeder.php')), 'Configuración esencial'), 'H13-B essential initializer present');
    dr_assert($app['db']->table('migrations')->count() === 73, 'development starts with 73 migrations');
    $cross=$app['db']->selectOne("SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA='salon_erp' AND REFERENCED_TABLE_SCHEMA<>TABLE_SCHEMA) OR (REFERENCED_TABLE_SCHEMA='salon_erp' AND TABLE_SCHEMA<>REFERENCED_TABLE_SCHEMA)");
    dr_assert((int)$cross->n===0, 'no cross-schema foreign keys');
    foreach (['ROUTINES'=>'ROUTINE_SCHEMA','EVENTS'=>'EVENT_SCHEMA','TRIGGERS'=>'TRIGGER_SCHEMA'] as $table=>$column) dr_assert((int)$app['db']->selectOne("SELECT COUNT(*) AS n FROM information_schema.$table WHERE $column='salon_erp'")->n===0, 'no existing '.$table);
    dr_assert(!is_file(dr_file('rebuild-started')), 'rebuild has not been attempted');
    dr_record('preflight', ['identity'=>dr_identity($app), 'manifest'=>dr_manifest(), 'passed'=>true]);
    echo json_encode(dr_identity($app),JSON_THROW_ON_ERROR).PHP_EOL;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $stage=$argv[1]??'';
    try {
        $app=dr_environment();
        if ($stage==='inspect') dr_preflight($app);
        elseif ($stage==='resume') {
            dr_closed($app);
            dr_assert(!is_file(dr_file('closed')) && !is_file(dr_file('before')) && !is_file(dr_file('rebuild-started')), 'resume from unattempted stopped preparation');
            if (!is_file(dr_file('preflight-stopped'))) dr_record('preflight-stopped',dr_read('preflight'));
            dr_preflight($app);
            dr_record('closed',['passed'=>true,'http_nonce'=>bin2hex(random_bytes(32)),'resumed_at'=>gmdate('c')]);
        } elseif ($stage==='close') {
            dr_assert(dr_manifest()===dr_read('preflight')['manifest'],'reviewed files unchanged');
            dr_assert(!$app->maintenanceMode()->active(),'ERP not already under unrelated maintenance');
            dr_command($app,'down',['--render'=>'errors::503','--retry'=>'60']);
            dr_closed($app);
            dr_record('closed',['passed'=>true,'http_nonce'=>bin2hex(random_bytes(32))]);
        } elseif ($stage==='baseline') {
            dr_closed($app);
            dr_assert(!is_file(dr_file('before')),'baseline not overwritten');
            dr_record('before',dr_snapshot($app));
            dr_record('grants-before',dr_grants($app));
            foreach(dr_read('before') as $schema=>$tables) echo json_encode(['schema'=>$schema,'tables'=>count($tables),'rows'=>array_sum(array_column($tables,'count')),'migrations'=>$tables['migrations']['count']]).PHP_EOL;
        } elseif ($stage==='backup') {
            dr_closed($app);
            require __DIR__.'/development-rebuild-backup.php';
            dr_backup($app);
        } elseif ($stage==='rebuild') {
            dr_closed($app);
            dr_assert(dr_manifest()===dr_read('preflight')['manifest'],'reviewed files and APP_KEY unchanged');
            $backup=dr_read('backup');
            dr_assert($backup['passed'] && is_file($backup['file']) && filesize($backup['file'])===$backup['bytes'] && hash_file('sha256',$backup['file'])===$backup['sha256'],'new backup SHA256 valid');
            dr_assert(dr_snapshot($app)===dr_read('before'),'all databases unchanged since closed baseline');
            dr_assert((int)$app['db']->selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX')->n===0,'no active InnoDB transactions');
            dr_assert((int)$app['db']->selectOne("SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE DB='salon_erp' AND ID<>CONNECTION_ID()")->n===0,'no other development SQL sessions');
            $handle=fopen(dr_file('rebuild-started'),'x');
            if(!$handle) throw new RuntimeException('Destructive attempt already reserved');
            fwrite($handle,json_encode(['started_at'=>gmdate('c')])); fclose($handle);
            // Defensa adicional previa al envío de SQL. No es un parser general de SQL.
            $app['db']->connection()->beforeExecuting(function($query){
                if(preg_match('/\b(?:CREATE|DROP|ALTER)\s+(?:DATABASE|USER)\b|\b(?:GRANT|REVOKE|USE)\b/i',$query)
                    || (preg_match('/salon_erp_test|salon_erp_bootstrap_check/i',$query) && !preg_match('/^\s*(SELECT|SHOW)\b/i',$query))) throw new RuntimeException('SQL outside authorized scope');
            });
            dr_command($app,'migrate:fresh',['--database'=>'mysql','--force'=>true]);
            dr_schema($app);
            dr_record('schema',['passed'=>true,'migrations'=>74]);
            dr_command($app,'db:seed',['--database'=>'mysql','--class'=>'DatabaseSeeder','--force'=>true]);
            dr_catalogs($app,false);
            dr_assert(\App\Models\Usuario::count()===0 && \App\Models\Cita::count()===0,'no historical users or appointments');
            dr_record('essential',['passed'=>true]);
            dr_other_integrity($app);
        } elseif ($stage==='owner') {
            dr_closed($app);
            dr_assert(dr_read('essential')['passed'] && dr_manifest()===dr_read('preflight')['manifest'],'essential initialization and reviewed files valid');
            dr_assert(\App\Models\Usuario::count()===0,'owner stage starts without users');
            $code=$app->make(\Illuminate\Contracts\Console\Kernel::class)->handle(new \Symfony\Component\Console\Input\ArgvInput(['development-rebuild','salon:propietario']),new \Symfony\Component\Console\Output\ConsoleOutput());
            dr_assert($code===0,'owner real terminal command exit 0');
            $owner=\App\Models\Usuario::where('role','alvaro')->sole();
            dr_assert((bool)$owner->is_active && \Illuminate\Support\Facades\Hash::isHashed($owner->password) && \Illuminate\Support\Facades\Hash::isHashed($owner->pin_seguridad),'owner active and credentials hashed');
            dr_record('owner',['passed'=>true,'owner_id'=>$owner->id]);
            dr_command($app,'db:seed',['--database'=>'mysql','--class'=>'DemoSeeder','--force'=>true]);
            dr_catalogs($app,true);
            dr_record('demo',['passed'=>true]);
            require __DIR__.'/development-rebuild-functional.php';
            dr_functional($app,$owner);
            dr_other_integrity($app);
            echo 'RUNNER_EXIT_CODE=0'.PHP_EOL;
        } elseif ($stage==='verify') {
            dr_closed($app); dr_schema($app); dr_no_financial($app); dr_other_integrity($app);
            dr_assert(dr_manifest()===dr_read('preflight')['manifest'],'reviewed files and APP_KEY unchanged');
            dr_assert(dr_read('functional')['passed'],'functional verification complete');
            dr_record('verified',['passed'=>true]);
        } elseif ($stage==='open') {
            dr_closed($app);
            dr_assert(dr_read('verified')['passed'] && dr_read('functional')['passed'],'all required stages verified');
            dr_assert(dr_manifest()===dr_read('preflight')['manifest'],'configuration unchanged at reopening');
            dr_other_integrity($app);
            dr_command($app,'up');
            dr_assert(!$app->maintenanceMode()->active() && !is_file(storage_path('framework/maintenance.php')),'ERP available again');
            dr_record('opened',['passed'=>true,'at'=>gmdate('c')]);
        } else throw new RuntimeException('Unknown stage');
    } catch (Throwable $error) {
        dr_record('failure',['stage'=>$stage,'class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]);
        fwrite(STDERR,'H13-D STOPPED: '.$stage.'; '.get_class($error).' '.basename($error->getFile()).':'.$error->getLine().". No repetir reconstrucción ni restaurar automáticamente.\nRUNNER_EXIT_CODE=1\n");
        exit(1);
    }
}
