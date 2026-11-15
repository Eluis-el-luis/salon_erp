<?php

namespace Tests\Feature;

use App\Exceptions\ContabilidadException;
use App\Models\{Articulo, StockLedger};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Solo lecturas/locks: no genera siquiera un movimiento heredado de fixture. */
class LegacyOpeningContainmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->beforeExecuting(function ($sql) {
            if (!preg_match('/^\s*(select|show)\b/i',$sql)) throw new \LogicException('El ensayo no permite escrituras.');
        });
    }

    public function test_legacy_opening_requires_its_callers_transaction(): void
    {
        $item=Articulo::firstOrFail(); $this->assertSame(0,DB::transactionLevel());
        $this->expectException(\LogicException::class);
        StockLedger::registrarApertura($item);
    }

    public function test_stale_in_memory_legacy_version_cannot_bypass_current_v2_guard(): void
    {
        $item=Articulo::where('inventory_representation_version',2)->firstOrFail();
        $copy=clone $item; $copy->forceFill(['inventory_representation_version'=>null,'existencia_actual'=>999]);
        $locked=false;
        DB::listen(function ($q) use (&$locked) {if (str_contains($q->sql,'from `items`') && str_contains($q->sql,'for update')) $locked=true;});
        try {DB::transaction(fn()=>StockLedger::registrarApertura($copy)); $this->fail('Debe rechazar la versión actual.');}
        catch (ContabilidadException $e) {$this->assertStringContainsString('versión 2',$e->getMessage());}
        $this->assertTrue($locked); $this->assertSame($item->getAttributes(),$item->fresh()->getAttributes());
        $this->assertSame(0,DB::transactionLevel());
    }

    public function test_legacy_existing_ledger_still_returns_null_after_current_locks(): void
    {
        $id=DB::table('items as i')->join('stock_ledger_entries as e','e.item_id','=','i.id')->whereNull('i.inventory_representation_version')->value('i.id');
        $this->assertNotNull($id); $item=Articulo::findOrFail($id); $queries=[];
        DB::listen(function ($q) use (&$queries) {if (str_contains($q->sql,'for update')) $queries[]=$q->sql;});
        $this->assertNull(DB::transaction(fn()=>StockLedger::registrarApertura($item)));
        $this->assertStringContainsString('from `items`',$queries[0]);
        $this->assertStringContainsString('from `stock_ledger_entries`',$queries[1]);
    }
}
