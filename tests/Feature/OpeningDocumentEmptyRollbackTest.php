<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/** Reversión por ruta exacta, exclusivamente de la instalación documental vacía. */
class OpeningDocumentEmptyRollbackTest extends TestCase
{
    public function test_framework_rollback_only_removes_the_unused_new_migration(): void
    {
        TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
        $name='2026_11_14_000005_create_inventory_opening_documents';
        $this->assertSame(0,DB::transactionLevel());
        $this->assertSame(79,DB::table('migrations')->count());
        $this->assertSame(0,DB::table('inventory_openings')->count());
        $this->assertSame(0,DB::table('inventory_opening_lines')->count());
        $old=DB::table('migrations')->where('migration','<>',$name)->orderBy('id')->get()->toJson();
        DB::connection()->beforeExecuting(function ($sql,$bindings) use ($name) {
            if (preg_match('/^\s*(select|show)\b/i',$sql)) return;
            if (preg_match('/^drop table `(inventory_openings|inventory_opening_lines)`$/i',$sql)) return;
            if (str_starts_with($sql,'delete from `migrations`') && in_array($name,$bindings,true)) return;
            throw new \LogicException('Rollback fuera de la migración documental vacía.');
        });
        $this->artisan('migrate:rollback',['--path'=>'database/migrations/'.$name.'.php','--step'=>1,'--force'=>true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('inventory_openings'));
        $this->assertFalse(Schema::hasTable('inventory_opening_lines'));
        $this->assertSame(78,DB::table('migrations')->count());
        $this->assertSame($old,DB::table('migrations')->orderBy('id')->get()->toJson());
    }
}
