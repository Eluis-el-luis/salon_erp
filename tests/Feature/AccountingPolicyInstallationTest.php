<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class AccountingPolicyInstallationTest extends TestCase
{
    private function fingerprint(): array
    {
        $tables=DB::table('information_schema.TABLES')->where('TABLE_SCHEMA',DB::connection()->getDatabaseName())->where('TABLE_TYPE','BASE TABLE')->orderBy('TABLE_NAME')->pluck('TABLE_NAME');
        $out=[];
        foreach($tables as $table) {
            if(str_starts_with($table,'accounting_') || $table==='migrations')continue;
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new \RuntimeException('Tabla inesperada');
            $rows=array_map(fn($r)=>hash('sha256',serialize((array)$r)),DB::table($table)->get()->all());sort($rows);
            $ddl=(array)DB::selectOne("SHOW CREATE TABLE `$table`");
            $out[$table]=[hash('sha256',implode('',$rows)),hash('sha256',array_values($ddl)[1])];
        }
        return $out;
    }
    public function test_install_empty_rollback_and_reinstall_are_additive(): void
    {
        TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(0,DB::transactionLevel());$this->assertSame(79,DB::table('migrations')->count());
        $before=$this->fingerprint();$rows=DB::table('migrations')->orderBy('id')->get()->toJson();
        $name='2026_11_16_000001_create_versioned_accounting_policies';$path='database/migrations/'.$name.'.php';
        foreach(['accounting_policy_versions','accounting_account_bindings','accounting_policy_audits'] as $table)$this->assertFalse(Schema::hasTable($table));
        DB::connection()->beforeExecuting(function($sql,$bindings)use($name){
            if(preg_match('/^\s*(select|show)\b/i',$sql))return;
            if(preg_match('/^(?:create|alter|drop) table (?:if exists )?`?(accounting_policy_versions|accounting_account_bindings|accounting_policy_audits)`?(?:\s|\(|$)/i',$sql))return;
            if(preg_match('/^(insert into|delete from) `migrations`/i',$sql) && in_array($name,$bindings,true))return;
            throw new \LogicException('DDL/DML fuera del esquema nuevo AC-03A.');
        });
        $this->artisan('migrate',['--path'=>$path,'--force'=>true])->assertExitCode(0);
        $this->assertSame(80,DB::table('migrations')->count());$this->assertSame($before,$this->fingerprint());
        $this->artisan('migrate:rollback',['--path'=>$path,'--step'=>1,'--force'=>true])->assertExitCode(0);
        $this->assertSame(79,DB::table('migrations')->count());$this->assertFalse(Schema::hasTable('accounting_policy_versions'));
        $this->assertSame($rows,DB::table('migrations')->orderBy('id')->get()->toJson());
        $this->artisan('migrate',['--path'=>$path,'--force'=>true])->assertExitCode(0);
        $this->assertSame(80,DB::table('migrations')->count());$this->assertSame($before,$this->fingerprint());
        $this->assertSame($rows,DB::table('migrations')->where('migration','<>',$name)->orderBy('id')->get()->toJson());
        $this->assertFalse(Schema::hasColumn('asientos_contables','accounting_policy_version_id'));
        foreach(['accounting_policy_versions','accounting_account_bindings','accounting_policy_audits'] as $table)$this->assertSame(0,DB::table($table)->count());
    }
}
