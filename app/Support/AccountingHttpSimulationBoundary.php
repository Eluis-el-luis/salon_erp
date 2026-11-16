<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Solo el servidor HTTP de ensayo puede adquirir este contrato. Sin puentes CLI. */
final class AccountingHttpSimulationBoundary
{
    public const CONTRACT = 'AC04B2B4B_HTTP_SYNTHETIC_CHARGE_V1';

    public static function assert(string $contract): void
    {
        if ($contract !== self::CONTRACT || PHP_SAPI !== 'cli-server'
            || !defined('SALON_HTTP_SYNTHETIC_CHARGE') || SALON_HTTP_SYNTHETIC_CHARGE !== self::CONTRACT
            || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
            || !in_array($_SERVER['HTTP_HOST'] ?? '', ['127.0.0.1:8775', '127.0.0.1:8776'], true)
            || !defined('SALON_TEST_DATABASE_BOOTSTRAPPED') || !SALON_TEST_DATABASE_BOOTSTRAPPED
            || !defined('SALON_TEST_DATABASE_RESET_ALLOWED') || SALON_TEST_DATABASE_RESET_ALLOWED) {
            abort(404);
        }
        \Tests\Support\TestDatabaseSafety::assertApplicationRoot(dirname(__DIR__, 2), app()->basePath());
        \Tests\Support\TestDatabaseSafety::assertConfiguration(app()->environment(), config('database'));
        foreach (DB::getConnections() as $name => $connection) {
            if ($name !== 'mysql') throw new \LogicException('Conexión alternativa prohibida.');
            \Tests\Support\TestDatabaseSafety::assertConnection($connection->getConfig());
        }
        $identity = DB::selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CURRENT_ROLE() AS roles');
        \Tests\Support\TestDatabaseSafety::assertServerIdentity($identity);
        if ($identity->roles !== 'NONE') throw new \LogicException('Roles SQL incompatibles.');
        $request = request();
        if (!$request->isMethod('POST') || $request->path() !== '__pilot-demo/confirm'
            || !$request->hasSession() || !is_string($request->input('_token'))
            || !hash_equals($request->session()->token(), $request->input('_token'))) abort(419);
        $actor = auth()->id() ? DB::table('users')->where('id', auth()->id())->first() : null;
        if (!$actor || !$actor->is_active || !in_array($actor->role, ['alvaro', 'contador'], true)) abort(403);
    }
}
