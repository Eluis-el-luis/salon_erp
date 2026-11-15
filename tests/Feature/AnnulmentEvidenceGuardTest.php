<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

/** Solo lectura: la barrera impide cualquier DDL incluso si el down tiene un defecto. */
class AnnulmentEvidenceGuardTest extends TestCase
{
    public function test_committed_evidence_blocks_down_without_reaching_ddl(): void
    {
        $this->assertSame(0,DB::transactionLevel());
        DB::connection()->beforeExecuting(function ($sql) {
            if (!preg_match('/^\s*(select|show)\b/i',$sql)) throw new \LogicException('Escritura prohibida en ensayo de guardia.');
        });
        $n=DB::table('sales')->whereNotNull('annulment_evidence')->count();
        $this->assertGreaterThan(0,$n,'Ejecutar después de las carreras H04-B confirmadas.');
        $migration=require database_path('migrations/2026_11_14_000004_add_sale_annulment_evidence.php');
        try {$migration->down(); $this->fail('La evidencia no se puede eliminar.');}
        catch (\RuntimeException $e) {$this->assertStringContainsString('evidencia en uso',$e->getMessage());}
        $this->assertTrue(Schema::hasColumn('sales','annulment_evidence'));
        $this->assertSame($n,DB::table('sales')->whereNotNull('annulment_evidence')->count());
    }
}
