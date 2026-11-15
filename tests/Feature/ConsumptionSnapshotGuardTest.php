<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConsumptionSnapshotGuardTest extends TestCase
{
    public function test_used_evidence_rejects_down_before_ddl(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(DB::table('sale_details')->whereNotNull('consumption_snapshot')->exists(), 'Ejecutar después del ensayo concurrente, sin crear ni borrar evidencia.');
        $ddl = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$ddl) {
            if (preg_match('/^\s*(ALTER|DROP|CREATE|TRUNCATE)\b/i', $sql)) {
                $ddl = true;
                throw new \RuntimeException('Emergency test DDL blocker');
            }
        });
        $migration = require base_path('database/migrations/2026_11_14_000003_add_consumption_composition_evidence.php');
        try {
            $migration->down();
            $this->fail('Evidence deletion allowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('Evidencia de consumo en uso; no se elimina.', $e->getMessage());
        }
        $this->assertFalse($ddl);
        $this->assertTrue(Schema::hasColumn('sale_details', 'consumption_snapshot'));
        $this->assertTrue(Schema::hasColumn('stock_ledger_entries', 'sale_detail_id'));
    }
}
