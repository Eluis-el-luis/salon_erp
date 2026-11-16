<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class BankChargeRefundRollbackGuardTest extends TestCase
{
    public function test_evidence_prevents_schema_rollback_before_ddl(): void {
        $count=DB::table('bank_charge_reversals')->count();$this->assertGreaterThan(0,$count);
        DB::connection()->beforeExecuting(function($sql){if(!preg_match('/^\s*(select|show)\b/i',$sql))throw new \LogicException('No ejecutar DDL con evidencia.');});
        $m=require base_path('database/migrations/2026_11_16_000003_create_bank_charge_refunds.php');
        try{$m->down();$this->fail('Rollback debía bloquearse');}catch(\RuntimeException $e){$this->assertStringContainsString('Evidencia',$e->getMessage());}$this->assertSame($count,DB::table('bank_charge_reversals')->count());
    }
}
