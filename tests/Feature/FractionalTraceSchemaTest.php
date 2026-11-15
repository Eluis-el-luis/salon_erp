<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Lote;
use App\Models\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryH02Fixtures;
use Tests\TestCase;

class FractionalTraceSchemaTest extends TestCase
{
    use DatabaseTransactions,InventoryH02Fixtures;

    private Articulo $item;

    private Lote $lot;

    private StockLedger $movement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actorH02();
        $this->item = $this->stockH02(2, true);
        $this->lot = Lote::where('item_id', $this->item->id)->firstOrFail();
        // Fixtures canónicos explícitos. No son escritores nuevos de la aplicación.
        DB::table('items')->where('id', $this->item->id)->update(['inventory_representation_version' => 2, 'existencia_actual' => 1, 'current_volume' => 900]);
        DB::table('lotes')->where('id', $this->lot->id)->update(['inventory_representation_version' => 2, 'cantidad_disponible' => 1,
            'volumen_abierto' => 900, 'volumen_entrada' => 2000, 'capacidad_envase' => 1000, 'unidad_nativa' => 'ml']);
        $this->movement = $this->fixtureMovement(-0.1,-100,-2);
        DB::table('stock_ledger_entries')->where('id', $this->movement->id)->update(['inventory_representation_version' => 2]);
    }

    /** Fixture SQL de esquema, deliberadamente ajeno a escritores operativos. */
    private function fixtureMovement(float $units,float $volume,float $value): StockLedger
    {
        $item=$this->item->refresh();$last=StockLedger::where('item_id',$item->id)->latest('id')->first();
        $entry=new StockLedger;
        $entry->forceFill(['item_id'=>$item->id,'inventory_representation_version'=>2,'fecha'=>now(),'movimiento'=>$volume<0?'salida':'entrada',
            'origen'=>'merma','referencia_tipo'=>'merma','cantidad'=>$units,'volumen'=>$volume,'valor'=>$value,'costo_unitario'=>0.02,
            'saldo_cantidad'=>$item->existencia_actual+$item->current_volume/$item->total_volume,
            'saldo_volumen'=>$item->existencia_actual*$item->total_volume+$item->current_volume,
            'saldo_valor'=>($last?->saldo_valor??0)+$value,'costo_promedio'=>0.02,'usuario_id'=>auth()->id()])->save();
        return $entry;
    }

    private function allocation(array $extra = []): array
    {
        return array_replace(['item_id' => $this->item->id, 'stock_ledger_entry_id' => $this->movement->id, 'lote_id' => $this->lot->id,
            'sale_detail_id' => null, 'physical_delta' => -100, 'native_unit' => 'ml', 'container_capacity' => 1000,
            'closed_before' => 2, 'closed_after' => 1, 'open_before' => 0, 'open_after' => 900, 'value_delta' => -2, 'reverses_allocation_id' => null], $extra);
    }

    private function rejected(callable $action, int $code): void
    {
        try {
            $action();
            $this->fail('SQL debía rechazar el fixture inválido.');
        } catch (QueryException $e) {
            $this->assertSame($code, $e->errorInfo[1]);
        }
    }

    public function test_coherent_allocation_and_exact_cent_unit_storage(): void
    {
        $id = DB::table('stock_ledger_lot_allocations')->insertGetId($this->allocation());
        $this->assertSame('-100.00', DB::table('stock_ledger_lot_allocations')->where('id', $id)->value('physical_delta'));
        DB::table('items')->where('id', $this->item->id)->update(['current_volume' => 899.99]);
        DB::table('lotes')->where('id', $this->lot->id)->update(['volumen_abierto' => 899.99]);
        $cent = $this->fixtureMovement(-0.00001,-0.01,0);
        DB::table('stock_ledger_entries')->where('id', $cent->id)->update(['inventory_representation_version' => 2]);
        $id = DB::table('stock_ledger_lot_allocations')->insertGetId($this->allocation(['stock_ledger_entry_id' => $cent->id, 'physical_delta' => -0.01, 'closed_before' => 1, 'closed_after' => 1, 'open_before' => 900, 'open_after' => 899.99, 'value_delta' => 0]));
        $this->assertSame('899.99', DB::table('stock_ledger_lot_allocations')->where('id', $id)->value('open_after'));
    }

    public function test_invalid_foreign_keys_and_cross_item_links_are_rejected(): void
    {
        foreach (['lote_id', 'stock_ledger_entry_id', 'sale_detail_id', 'reverses_allocation_id'] as $field) {
            $this->rejected(fn () => DB::table('stock_ledger_lot_allocations')->insert($this->allocation([$field => 999999999])), 1452);
        }
        $other = $this->stockH02();
        $this->rejected(fn () => DB::table('stock_ledger_lot_allocations')->insert($this->allocation(['item_id' => $other->id])), 1452);
    }

    public function test_parent_deletion_and_key_update_do_not_cascade(): void
    {
        DB::table('stock_ledger_lot_allocations')->insert($this->allocation());
        $this->rejected(fn () => DB::table('lotes')->where('id', $this->lot->id)->delete(), 1451);
        $this->rejected(fn () => DB::table('stock_ledger_entries')->where('id', $this->movement->id)->delete(), 1451);
        $other = $this->stockH02();
        $this->rejected(fn () => DB::table('stock_ledger_entries')->where('id', $this->movement->id)->update(['item_id' => $other->id]), 1451);
        $this->assertSame(1, DB::table('stock_ledger_lot_allocations')->where('item_id',$this->item->id)->count());
    }

    public function test_checks_reject_inconsistent_physical_states_and_limits(): void
    {
        foreach ([['open_after' => 1000.01], ['physical_delta' => -99], ['container_capacity' => 0], ['native_unit' => 'unknown'],
            ['physical_delta' => 0], ['closed_after' => 100000000, 'physical_delta' => -100]] as $extra) {
            $this->rejected(fn () => DB::table('stock_ledger_lot_allocations')->insert($this->allocation($extra)), 3819);
        }
        $this->rejected(fn () => DB::table('lotes')->where('id', $this->lot->id)->update(['cantidad_disponible' => 0.5]), 3819);
        $this->rejected(fn () => DB::table('lotes')->where('id', $this->lot->id)->update(['inventory_representation_version' => 3]), 3819);
        $this->rejected(fn () => DB::table('items')->where('id', $this->item->id)->update(['current_volume' => 1000.01]), 3819);
    }

    public function test_one_open_lot_per_article_is_enforced_by_unique_index(): void
    {
        $closed = Lote::create(['item_id' => $this->item->id, 'lote' => 'H03 otro', 'fecha_entrada' => today(), 'cantidad_entrada' => 1, 'cantidad_disponible' => 1, 'costo_unitario' => 20]);
        DB::table('lotes')->where('id', $closed->id)->update(['inventory_representation_version' => 2, 'volumen_abierto' => 0,
            'volumen_entrada' => 1000, 'capacidad_envase' => 1000, 'unidad_nativa' => 'ml']);
        $this->rejected(fn () => DB::table('lotes')->where('id', $closed->id)->update(['cantidad_disponible' => 0, 'volumen_abierto' => 100]), 1062);
        $this->assertSame(1, DB::table('lotes')->where('item_id', $this->item->id)->where('volumen_abierto', '>', 0)->count());
        DB::table('lotes')->where('id', $this->lot->id)->update(['volumen_abierto' => 1000]);
        $this->assertSame('1000.00', DB::table('lotes')->where('id', $this->lot->id)->value('volumen_abierto'));
    }

    public function test_reversal_references_preserve_original_and_cannot_duplicate(): void
    {
        $original = DB::table('stock_ledger_lot_allocations')->insertGetId($this->allocation());
        DB::table('items')->where('id', $this->item->id)->update(['current_volume' => 1000]);
        DB::table('lotes')->where('id', $this->lot->id)->update(['volumen_abierto' => 1000]);
        $entry = $this->fixtureMovement(0.1,100,2);
        DB::table('stock_ledger_entries')->where('id', $entry->id)->update(['inventory_representation_version' => 2]);
        $reverse = $this->allocation(['stock_ledger_entry_id' => $entry->id, 'physical_delta' => 100, 'closed_before' => 1, 'closed_after' => 1, 'open_before' => 900, 'open_after' => 1000, 'value_delta' => 2, 'reverses_allocation_id' => $original]);
        DB::table('stock_ledger_lot_allocations')->insert($reverse);
        $this->rejected(fn () => DB::table('stock_ledger_lot_allocations')->insert($reverse), 1062);
        $this->rejected(fn () => DB::table('stock_ledger_lot_allocations')->where('id', $original)->delete(), 1451);
    }

    public function test_nullable_additions_do_not_classify_existing_writers(): void
    {
        $item = $this->stockH02(2, true);
        $this->assertNull($item->inventory_representation_version);
        $lot = Lote::where('item_id',$item->id)->firstOrFail();
        foreach (['inventory_representation_version', 'volumen_abierto', 'volumen_entrada', 'capacidad_envase', 'unidad_nativa', 'open_item_guard'] as $field) {
            $this->assertNull($lot->$field);
        }
        $this->assertSame(2000.0,(float) $item->current_volume); // H03-B2 no está activado.
    }
}
