<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{Artisan,DB,Schema};
use Tests\TestCase;

class AccountingEventInstallationTest extends TestCase
{
    public function test_additive_install_empty_rollback_and_reinstall_without_backfill(): void
    {
        $this->assertSame(0,DB::transactionLevel());$this->assertFalse(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(80,DB::table('migrations')->count());
        $before=DB::table('asientos_contables')->orderBy('id')->get()->toJson();$columns=Schema::getColumnListing('asientos_contables');
        $old=DB::table('migrations')->orderBy('id')->get()->toJson();
        $path='database/migrations/2026_11_16_000002_add_accounting_event_documentation.php';
        $tables=['accounting_policy_authorizations','accounting_event_operations','accounting_entry_line_resolutions'];
        DB::connection()->beforeExecuting(function($sql){if(preg_match('/^\s*(insert|update|delete|replace)\b/i',$sql)&&!preg_match('/^(?:insert into|delete from) `migrations`/i',$sql))throw new \LogicException('Solo registro de la migración; sin backfill.');});
        $this->assertSame(0,Artisan::call('migrate',['--path'=>$path,'--force'=>true]));
        foreach($tables as $t){$this->assertTrue(Schema::hasTable($t));$this->assertSame(0,DB::table($t)->count());}
        $this->assertSame($before,DB::table('asientos_contables')->select($columns)->orderBy('id')->get()->toJson());
        $this->assertSame(0,DB::table('asientos_contables')->whereNotNull('accounting_policy_version_id')->count());
        $this->assertSame(0,Artisan::call('migrate:rollback',['--path'=>$path,'--step'=>1,'--force'=>true]));
        foreach($tables as $t)$this->assertFalse(Schema::hasTable($t));
        $this->assertSame($old,DB::table('migrations')->orderBy('id')->get()->toJson());
        $this->assertSame(0,Artisan::call('migrate',['--path'=>$path,'--force'=>true]));
        $this->assertSame(81,DB::table('migrations')->count());
        $this->assertSame($before,DB::table('asientos_contables')->select($columns)->orderBy('id')->get()->toJson());
    }
}
