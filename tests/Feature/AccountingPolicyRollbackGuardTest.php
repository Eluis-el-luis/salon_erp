<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class AccountingPolicyRollbackGuardTest extends TestCase
{
    public function test_real_evidence_blocks_down_before_any_drop(): void
    {
        $this->assertSame(0,DB::transactionLevel());
        $counts=[];foreach(['accounting_policy_versions','accounting_account_bindings','accounting_policy_audits'] as $table)$counts[$table]=DB::table($table)->count();
        $this->assertGreaterThan(0,$counts['accounting_policy_versions']);$this->assertGreaterThan(0,$counts['accounting_policy_audits']);
        DB::connection()->beforeExecuting(function($sql){if(!preg_match('/^\s*(select|show)\b/i',$sql))throw new \LogicException('Guardia no puede alcanzar DDL/DML.');});
        $migration=require base_path('database/migrations/2026_11_16_000001_create_versioned_accounting_policies.php');
        try{$migration->down();$this->fail('Debe conservar evidencia.');}
        catch(\RuntimeException $e){$this->assertStringContainsString('evidencia en uso',$e->getMessage());}
        foreach($counts as $table=>$count){$this->assertTrue(Schema::hasTable($table));$this->assertSame($count,DB::table($table)->count());}
    }
}
