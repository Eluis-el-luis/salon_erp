<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Read-only report demo. Independent from all AC-04 posting/HTTP contracts. */
final class ReportDemoBoundary
{
    public const CONTRACT='AC05B3A_REPORT_READONLY_V1';
    public static function installation(): void
    {
        abort_unless(PHP_SAPI==='cli-server' && defined('SALON_REPORT_DEMO_READONLY')
            && SALON_REPORT_DEMO_READONLY===self::CONTRACT && defined('SALON_TEST_DATABASE_BOOTSTRAPPED')
            && SALON_TEST_DATABASE_BOOTSTRAPPED && defined('SALON_TEST_DATABASE_RESET_ALLOWED') && !SALON_TEST_DATABASE_RESET_ALLOWED
            && ($_SERVER['REMOTE_ADDR']??'')==='127.0.0.1' && (string)($_SERVER['SERVER_PORT']??'')==='8778'
            && ($_SERVER['HTTP_HOST']??'')==='127.0.0.1:8778' && app()->environment('testing'),404);
        try {
            \Tests\Support\TestDatabaseSafety::assertApplicationRoot(dirname(__DIR__,2),app()->basePath());
            \Tests\Support\TestDatabaseSafety::assertConfiguration(app()->environment(),config('database'));
            foreach(DB::getConnections() as $name=>$c){if($name!=='mysql')throw new \LogicException;\Tests\Support\TestDatabaseSafety::assertConnection($c->getConfig());}
        } catch(\Throwable){abort(404);}
    }
    public static function actor(): void
    {
        self::installation();
        $u=auth()->id()?DB::table('users')->where('id',auth()->id())->first():null;
        abort_unless(self::allowsActor($u),403);
        abort_unless(DB::transactionLevel()===0,409,'La demostración exige su propio snapshot de lectura.');
    }
    public static function allowsActor(?object $u): bool
    {
        return $u && (bool)$u->is_active && in_array($u->role,['alvaro','contador'],true);
    }
}
