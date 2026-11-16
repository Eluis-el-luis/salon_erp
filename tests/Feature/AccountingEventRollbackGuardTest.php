<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class AccountingEventRollbackGuardTest extends TestCase
{
    public function test_evidence_blocks_rollback_before_any_ddl(): void {
        $n=DB::table('accounting_policy_authorizations')->count();$this->assertGreaterThan(0,$n);
        DB::connection()->beforeExecuting(function($sql){if(!preg_match('/^\s*(select|show)\b/i',$sql))throw new \LogicException('Ningún DDL o escritura permitidos.');});
        $m=require base_path('database/migrations/2026_11_16_000002_add_accounting_event_documentation.php');
        try{$m->down();$this->fail('Debía rechazar');}catch(\RuntimeException $e){$this->assertStringContainsString('Evidencia',$e->getMessage());}
        $this->assertSame($n,DB::table('accounting_policy_authorizations')->count());$this->assertTrue(Schema::hasTable('accounting_entry_line_resolutions'));
        $this->assertTrue(Schema::hasColumn('asientos_contables','accounting_policy_version_id'));
    }
}
