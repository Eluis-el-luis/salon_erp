<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Frontera del arnés AC-04B2A: no depende solo de APP_ENV o de un booleano. */
final class AccountingSimulationBoundary
{
    public const CONTRACT='AC04B2A_INTERNAL_SIMULATION_V1';
    public static function assert(string $contract): void {
        if($contract!==self::CONTRACT||PHP_SAPI!=='cli'||!defined('SALON_TEST_DATABASE_BOOTSTRAPPED')||!SALON_TEST_DATABASE_BOOTSTRAPPED
            ||!defined('SALON_TEST_DATABASE_RESET_ALLOWED')||SALON_TEST_DATABASE_RESET_ALLOWED||!class_exists(\Tests\Support\TestDatabaseSafety::class))throw new \LogicException('Arnés de simulación no autorizado.');
        \Tests\Support\TestDatabaseSafety::assertApplicationRoot(dirname(__DIR__,2),app()->basePath());
        \Tests\Support\TestDatabaseSafety::assertConfiguration(app()->environment(),config('database'));
        foreach(DB::getConnections() as $name=>$c){if($name!=='mysql')throw new \LogicException('Conexión alternativa rechazada.');\Tests\Support\TestDatabaseSafety::assertConnection($c->getConfig());}
        $i=DB::selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CURRENT_ROLE() AS roles');
        \Tests\Support\TestDatabaseSafety::assertServerIdentity($i);if($i->roles!=='NONE')throw new \LogicException('Roles SQL no autorizados.');
        $u=auth()->id()?DB::table('users')->where('id',auth()->id())->first():null;
        if(!$u||!$u->is_active||!in_array($u->role,['alvaro','contador'],true))abort(403);
    }
}
