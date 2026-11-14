<?php

namespace Tests\Feature;

use App\Exceptions\ContabilidadException;
use App\Models\Articulo;
use App\Models\Lote;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Models\StockLedger;
use App\Models\Venta;
use App\Services\AprobacionService;
use App\Services\CompraService;
use App\Services\InventarioService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryH02Fixtures;
use Tests\TestCase;

class InventoryUnitIntegrityTest extends TestCase
{
    public function test_unit_guard_does_not_apply_to_unused_standard_ingredients(): void
    {
        $unit = $this->stockH02();
        $fractional = $this->stockH02(2, true);
        $service = Servicio::create(['name' => 'H02 guard scope', 'price' => 100, 'duration' => 30]);
        foreach ([$unit, $fractional] as $item) {
            DB::table('service_formulas')->insert(['service_id' => $service->id, 'item_id' => $item->id, 'quantity_used' => 1]);
        }
        app(InventarioService::class)->bloquearCarrito([['id' => $service->id, 'type' => 'servicio', 'quantity' => 0.5,
            'consumo' => [['item_id' => $fractional->id, 'cantidad' => 0.5]]]]);
        $this->assertSame(2, (int) $unit->fresh()->existencia_actual);
        $this->assertSame(2000.0, (float) $fractional->fresh()->current_volume);
    }

    public function test_lots_without_ledger_cannot_create_a_negative_ledger_balance(): void
    {
        $item = Articulo::create(['producto' => 'H02 sin kárdex', 'type' => 'producto_venta', 'precio_c' => 100, 'existencia_actual' => 1]);
        Lote::create(['item_id' => $item->id, 'lote' => 'H02 fixture', 'fecha_entrada' => today(),
            'cantidad_entrada' => 1, 'cantidad_disponible' => 1, 'costo_unitario' => 20]);
        $before = $this->content();
        $this->postJson('/sales', ['cart' => [$this->cartH02($item)]])->assertStatus(422);
        $this->assertSame($before, $this->content());
    }

    public function test_user_fk_locks_precede_item_mutex_for_sale_and_merma(): void
    {
        $item = $this->stockH02(2);
        $locks = [];
        DB::listen(function ($q) use (&$locks) {
            if (str_contains($q->sql, 'lock in share mode') && str_contains($q->sql, 'from `users`')) {
                $locks[] = 'user';
            }
            if (str_contains($q->sql, 'for update') && str_contains($q->sql, 'from `items`')) {
                $locks[] = 'item';
            }
        });
        $cart = $this->cartH02($item);
        $cart['stylist_id'] = auth()->id();
        $this->postJson('/sales', ['cart' => [$cart]])->assertCreated();
        $this->assertSame('user', $locks[0]);
        $this->assertContains('item', $locks);
        $locks = [];
        $this->post('/mermas', ['item_id' => $item->id, 'fecha' => today()->toDateString(), 'tipo' => 'unidad', 'cantidad' => 1, 'motivo' => 'H02 FK locks'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('user', $locks[0]);
        $this->assertContains('item', $locks);
    }

    public function test_failure_after_physical_debit_rolls_back_all_tables(): void
    {
        $item = $this->stockH02();
        $before = $this->content();
        StockLedger::creating(function ($entry) {
            if ($entry->origen === 'venta') {
                throw new ContabilidadException('H02 injected ledger failure');
            }
        });
        try {
            $this->postJson('/sales', ['cart' => [$this->cartH02($item)]])->assertStatus(422);
            $this->assertSame($before, $this->content());
        } finally {
            StockLedger::flushEventListeners();
        }
    }

    public function test_fractional_volume_merma_keeps_existing_partial_lot_behavior(): void
    {
        $item = $this->stockH02(2, true);
        $this->post('/mermas', ['item_id' => $item->id, 'fecha' => today()->toDateString(), 'tipo' => 'volumen', 'cantidad' => 0.5, 'motivo' => 'H02 fractional regression'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, (int) $item->fresh()->existencia_actual);
        $this->assertSame(1999.5, (float) $item->fresh()->current_volume);
        $this->assertSame(2.0, (float) DB::table('lotes')->where('item_id', $item->id)->sum('cantidad_disponible'));
    }

    public function test_purchase_and_purchase_reversal_lock_multiple_articles_in_order(): void
    {
        $one = $this->stockH02();
        $two = $this->stockH02();
        $ids = [];
        DB::listen(function ($q) use (&$ids) {
            if (str_contains($q->sql, 'from `items`') && str_contains($q->sql, 'for update')) {
                $ids[] = (int) $q->bindings[0];
            }
        });
        $invoice = app(CompraService::class)->registrarFactura([
            'provider_id' => Proveedor::create(['name' => 'H02 multi'])->id, 'condicion_pago' => 'credito',
            'articulos' => [['id' => $two->id, 'cantidad' => 1, 'costo' => 20], ['id' => $one->id, 'cantidad' => 1, 'costo' => 20]]]);
        $this->assertSame([$one->id, $two->id], array_slice($ids, 0, 2));
        $ids = [];
        app(CompraService::class)->anularFactura($invoice->id, 'H02 fixture');
        $this->assertSame([$one->id, $two->id], array_slice($ids, 0, 2));
        $this->assertSame(2, (int) $one->fresh()->existencia_actual);
        $this->assertSame(2, (int) $two->fresh()->existencia_actual);
    }

    use DatabaseTransactions, InventoryH02Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actorH02();
    }

    private function content(): array
    {
        $result = [];
        foreach (['items', 'lotes', 'stock_ledger_entries', 'sales', 'sale_details', 'sale_detail_consumptions', 'pagos',
            'asientos_contables', 'detalle_asientos', 'comisiones_generadas', 'mermas', 'purchase_invoices'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    public function test_exact_stock_repeated_demand_and_fifo(): void
    {
        $item = $this->stockH02(3);
        $this->postJson('/sales', ['cart' => [$this->cartH02($item, 1), $this->cartH02($item, 2)]])->assertCreated();
        $this->assertSame(0, (int) $item->fresh()->existencia_actual);
        $this->assertSame(0.0, (float) DB::table('lotes')->where('item_id', $item->id)->sum('cantidad_disponible'));
        $this->assertSame(0.0, (float) DB::table('stock_ledger_entries')->where('item_id', $item->id)->orderByDesc('id')->value('saldo_cantidad'));
    }

    public function test_insufficient_repeated_demand_rolls_back_every_effect(): void
    {
        $item = $this->stockH02(2);
        $before = $this->content();
        $this->postJson('/sales', ['cart' => [$this->cartH02($item, 2), $this->cartH02($item, 1)]])->assertStatus(422);
        $this->assertSame($before, $this->content());
    }

    public function test_invalid_quantities_are_rejected_before_integer_casts(): void
    {
        $item = $this->stockH02();
        foreach ([-1, 0, 0.5] as $quantity) {
            $before = $this->content();
            $this->postJson('/sales', ['cart' => [$this->cartH02($item, $quantity)]])->assertStatus(422);
            $this->assertSame($before, $this->content());
        }
    }

    public function test_legacy_stock_without_lots_is_rejected(): void
    {
        $item = Articulo::create(['producto' => 'H02 heredado', 'type' => 'producto_venta', 'precio_c' => 100, 'existencia_actual' => 3]);
        $before = $this->content();
        $this->postJson('/sales', ['cart' => [$this->cartH02($item)]])->assertStatus(422)->assertJsonFragment(['error' => 'Lotes insuficientes para H02 heredado. Requiere conciliación documentada.']);
        $this->assertSame($before, $this->content());
    }

    public function test_incomplete_fifo_rolls_back_instead_of_accepting_aggregate(): void
    {
        $item = $this->stockH02(2);
        DB::table('lotes')->where('item_id', $item->id)->update(['cantidad_disponible' => 1]);
        $before = $this->content();
        $this->postJson('/sales', ['cart' => [$this->cartH02($item, 2)]])->assertStatus(422);
        $this->assertSame($before, $this->content());
    }

    public function test_excessive_and_fractional_merma_roll_back(): void
    {
        $item = $this->stockH02();
        foreach ([3, 0.5] as $quantity) {
            $before = $this->content();
            $this->post('/mermas', ['item_id' => $item->id, 'fecha' => today()->toDateString(), 'tipo' => 'unidad', 'cantidad' => $quantity, 'motivo' => 'H02 fixture'])
                ->assertRedirect()->assertSessionHasErrors('error');
            $this->assertSame($before, $this->content());
        }
    }

    public function test_bom_and_direct_product_accumulate_together(): void
    {
        $item = $this->stockH02(3);
        $service = Servicio::create(['name' => 'H02 servicio', 'price' => 100, 'duration' => 30]);
        DB::table('service_formulas')->insert(['service_id' => $service->id, 'item_id' => $item->id, 'quantity_used' => 2]);
        $before = $this->content();
        $this->postJson('/sales', ['cart' => [$this->cartH02($item, 2), ['id' => $service->id, 'type' => 'servicio', 'precio_c' => 100, 'quantity' => 1]]])->assertStatus(422);
        $this->assertSame($before, $this->content());
    }

    public function test_fractionable_physics_remain_unchanged_and_mixed_failure_is_atomic(): void
    {
        $fractional = $this->stockH02(2, true);
        $unit = $this->stockH02(1);
        $before = $this->content();
        $this->postJson('/sales', ['cart' => [$this->cartH02($fractional), $this->cartH02($unit, 2)]])->assertStatus(422);
        $this->assertSame($before, $this->content());
        $this->postJson('/sales', ['cart' => [$this->cartH02($fractional)]])->assertCreated();
        $this->assertSame(1, (int) $fractional->fresh()->existencia_actual);
        $this->assertSame(2000.0, (float) $fractional->fresh()->current_volume); // H03 permanece pendiente.
    }

    public function test_sale_reversal_keeps_quantities_and_latest_lot_policy(): void
    {
        $item = $this->stockH02();
        $id = $this->postJson('/sales', ['cart' => [$this->cartH02($item)]])->assertCreated()->json('sale_id');
        $this->post('/ventas/'.$id.'/anular', ['motivo' => 'H02 fixture', 'pin_supervisor' => '4826'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, (int) $item->fresh()->existencia_actual);
        $this->assertSame(2.0, (float) DB::table('lotes')->where('item_id', $item->id)->sum('cantidad_disponible'));
    }

    public function test_locks_are_ascending_even_when_cart_is_reversed(): void
    {
        $one = $this->stockH02();
        $two = $this->stockH02();
        $ids = [];
        DB::listen(function ($q) use (&$ids) {
            if (str_contains($q->sql, 'from `items`') && str_contains($q->sql, 'for update')) {
                $ids[] = (int) $q->bindings[0];
            }
        });
        $this->postJson('/sales', ['cart' => [$this->cartH02($two), $this->cartH02($one)]])->assertCreated();
        $this->assertSame([$one->id, $two->id], array_slice($ids, 0, 2));
    }

    public function test_approval_with_insufficient_stock_leaves_pending_document_unchanged(): void
    {
        $item = $this->stockH02(1);
        $id = $this->postJson('/sales', ['cart' => [$this->cartH02($item, 2)], 'requiere_aprobacion' => true])->assertCreated()->json('sale_id');
        $before = $this->content();
        try {
            app(AprobacionService::class)->aprobarVenta($id);
            $this->fail('Expected insufficient stock');
        } catch (ContabilidadException $e) {
            $this->assertStringContainsString('Existencias insuficientes', $e->getMessage());
        }
        $this->assertSame($before, $this->content());
        $this->assertSame('pendiente_aprobacion', Venta::findOrFail($id)->status);
    }
}
