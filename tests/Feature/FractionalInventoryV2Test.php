<?php

namespace Tests\Feature;

use App\Exceptions\ContabilidadException;
use App\Models\Articulo;
use App\Models\Lote;
use App\Models\Proveedor;
use App\Models\StockLedger;
use App\Models\Venta;
use App\Services\AprobacionService;
use App\Services\CapturaConsumoService;
use App\Services\CompraService;
use App\Services\InventarioFraccionableService;
use App\Services\InventarioService;
use App\Support\InventoryDecimal as D;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FractionalV2Fixtures;
use Tests\Support\InventoryH02Fixtures;
use Tests\TestCase;

class FractionalInventoryV2Test extends TestCase
{
    use DatabaseTransactions,FractionalV2Fixtures,InventoryH02Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actorH02();
        config(['salon.inventory_fractional_v2' => true]);
    }

    private function sale(array $lines, array $extra = [])
    {
        return $this->postJson('/sales', array_replace(['cart' => $lines], $extra));
    }

    private function consume(Articulo $i, $q): int
    {
        return $this->sale([$this->lineV2($this->serviceV2([$i->id => $q]))])->assertCreated()->json('sale_id');
    }

    private function reconcile(Articulo $i, int $closed, string $open, string $volume, string $value): void
    {
        $i->refresh();
        $this->assertSame($closed, (int) $i->existencia_actual);
        $this->assertSame(D::scaled($open), D::scaled($i->current_volume));
        $s = app(InventarioFraccionableService::class)->estado($i);
        $this->assertSame(D::scaled($volume), $s['q']);
        $this->assertSame(D::scaled($value), $s['v']);
        $this->assertLessThanOrEqual(1, DB::table('lotes')->where('item_id', $i->id)->where('volumen_abierto', '>', 0)->count());
    }

    private function effects(): array
    {
        $r = [];
        foreach (['items', 'lotes', 'stock_ledger_entries', 'stock_ledger_lot_allocations', 'sales', 'sale_details', 'sale_detail_consumptions', 'pagos', 'asientos_contables', 'detalle_asientos', 'comisiones_generadas', 'mermas', 'purchase_invoices', 'cuentas_por_pagar'] as $t) {
            $r[$t] = DB::table($t)->orderBy('id')->get()->toJson();
        }

        return $r;
    }

    public function test_purchase_100_bottles_and_authoritative_documentary_value(): void
    {
        $i = $this->itemV2();
        $f = $this->buyV2($i, 100);
        $this->reconcile($i, 100, '0', '75000', '1723.00');
        $this->assertSame('1723.00', $f->lineas->first()->getRawOriginal('valor_neto'));
        $this->assertEquals(1723, DB::table('detalle_asientos')->where('asiento_id', $f->asiento_contable_id)->sum('debe'));
    }

    public function test_landed_multiple_lines_and_rounding_concile_without_altering_tax(): void
    {
        $a = $this->itemV2();
        $b = $this->itemV2(100, 'gr');
        $f = $this->buyV2($a, 2, '17.23', ['descuento' => 1.01, 'gastos_envio' => 2.02, 'articulos' => [
            ['id' => $a->id, 'cantidad' => 3, 'costo' => '17.23', 'impuesto_porcentaje' => 15], ['id' => $b->id, 'cantidad' => 7, 'costo' => '1.23']]]);
        $this->assertEquals(round($f->total - $f->impuestos, 2), DB::table('stock_ledger_entries')->where('referencia_tipo', 'factura_compra')->where('referencia_id', $f->id)->sum('valor'));
        foreach ($f->lineas as $l) {
            $this->assertEquals($l->valor_neto, StockLedger::where('item_id', $l->item_id)->latest('id')->first()->valor);
        }
    }

    public static function amounts(): array
    {
        return [['100', 1, '650', '1400', '32.16'], ['750', 1, '0', '750', '17.23'], ['900', 0, '600', '600', '13.78'], ['1500', 0, '0', '0', '0']];
    }

    #[DataProvider('amounts')]
    public function test_physical_consumption_once($q, $closed, $open, $volume, $value): void
    {
        $i = $this->itemV2();
        $this->buyV2($i);
        $id = $this->consume($i, $q);
        $this->reconcile($i, $closed, $open, $volume, $value);
        $e = StockLedger::where('referencia_tipo', 'venta')->where('referencia_id', $id)->firstOrFail();
        $this->assertNotNull($e->sale_detail_id);
        $this->assertSame(-D::scaled($q), D::signed($e->getRawOriginal('volumen')));
    }

    public function test_cross_lots_different_cost_and_cent_residue_allocation(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 1, '0.01');
        $this->buyV2($i, 1, '0.02');
        $this->consume($i, 150);
        $this->reconcile($i, 0, '50', '50', '0.01');
        $e = StockLedger::where('item_id', $i->id)->latest('id')->first();
        $a = DB::table('stock_ledger_lot_allocations')->where('stock_ledger_entry_id', $e->id)->orderBy('id')->get();
        $this->assertSame(2, $a->count());
        $this->assertSame(['-0.02', '0.00'], $a->pluck('value_delta')->all());
        $this->consume($i, 50);
        $this->reconcile($i, 0, '0', '0', '0');
    }

    public function test_merma_and_last_cent_of_open_volume(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 1, '10');
        $this->consume($i, '99.99');
        $this->assertSame('0.100000', StockLedger::where('item_id', $i->id)->latest('id')->first()->getRawOriginal('costo_unitario'));
        $this->reconcile($i, 0, '0.01', '0.01', '0');
        $this->post('/mermas', ['item_id' => $i->id, 'tipo' => 'volumen', 'cantidad' => '0.01', 'fecha' => today()->toDateString(), 'motivo' => 'fixture'])->assertRedirect()->assertSessionHas('success');
        $this->reconcile($i, 0, '0', '0', '0');
    }

    public function test_insufficient_and_repeated_demand_roll_back_all_documents(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 1);
        $s = $this->serviceV2([$i->id => 60]);
        $before = $this->effects();
        $this->sale([$this->lineV2($s), $this->lineV2($s)])->assertStatus(422);
        $this->assertSame($before, $this->effects());
    }

    public static function captures(): array
    {
        return [[[0, 0], 0], [[0, 20], 1], [[20, 10], 2], [[100, 50], 2], [null, 2]];
    }

    #[DataProvider('captures')]
    public function test_complete_capture_and_absence_have_independent_physical_cogs(?array $capture, int $count): void
    {
        $a = $this->itemV2();
        $b = $this->itemV2();
        $this->buyV2($a);
        $this->buyV2($b);
        $s = $this->serviceV2([$a->id => 100, $b->id => 50]);
        $line = $this->lineV2($s, $capture === null ? null : [$a->id => $capture[0], $b->id => $capture[1]]);
        $id = $this->sale([$line])->assertCreated()->json('sale_id');
        $v = Venta::with('detalles')->find($id);
        $this->assertSame($capture === null ? 'standard' : 'complete', $v->detalles->first()->consumption_snapshot['mode']);
        $this->assertSame($count, StockLedger::where('referencia_tipo', 'venta')->where('referencia_id', $id)->count());
        $this->assertSame($capture === null ? 0 : 2, DB::table('sale_detail_consumptions')->where('sale_detail_id', $v->detalles->first()->id)->count());
        if (! $count) {
            $this->assertSame(0, DB::table('asientos_contables')->where('numero_asiento', 'CST-'.str_pad($id, 5, '0', STR_PAD_LEFT))->count());
        }
    }

    public static function invalid(): array
    {
        return [['partial'], ['duplicate'], ['foreign'], ['empty'], ['invalid'], ['negative'], ['precision']];
    }

    #[DataProvider('invalid')]
    public function test_invalid_captures_rejected_without_partial_effects(string $case): void
    {
        $a = $this->itemV2();
        $b = $this->itemV2();
        $this->buyV2($a);
        $this->buyV2($b);
        $s = $this->serviceV2([$a->id => 100, $b->id => 50]);
        $rows = [['item_id' => $a->id, 'cantidad' => '0'], ['item_id' => $b->id, 'cantidad' => '20']];
        if ($case === 'partial') {
            array_pop($rows);
        }
        if ($case === 'duplicate') {
            $rows[] = $rows[0];
        }
        if ($case === 'foreign') {
            $rows[] = ['item_id' => $this->itemV2()->id, 'cantidad' => 1];
        }
        if (in_array($case, ['empty', 'invalid', 'negative', 'precision'])) {
            $rows[0]['cantidad'] = match ($case) {
                'empty' => '','invalid' => 'x','negative' => '-1','precision' => '0.001'
            };
        }
        $before = $this->effects();
        $l = $this->lineV2($s);
        $l['consumo'] = $rows;
        $this->sale([$l])->assertStatus(422);
        $this->assertSame($before, $this->effects());
    }

    public function test_retained_composition_survives_formula_changes(): void
    {
        $i = $this->itemV2();
        $this->buyV2($i);
        $s = $this->serviceV2([$i->id => 100]);
        $id = $this->sale([$this->lineV2($s, [$i->id => 20])], ['requiere_aprobacion' => true])->assertCreated()->json('sale_id');
        DB::table('service_formulas')->where('service_id', $s->id)->update(['quantity_used' => 200]);
        app(AprobacionService::class)->aprobarVenta($id, auth()->id());
        $this->reconcile($i, 1, '730', '1480', '34.00');
        $this->assertEquals(20, DB::table('sale_detail_consumptions')->whereIn('sale_detail_id', Venta::find($id)->detalles->pluck('id'))->value('quantity_actual'));
    }

    public function test_legacy_unclassified_fractional_stock_is_not_converted(): void
    {
        config(['salon.inventory_fractional_v2' => false]);
        $i = $this->stockH02(2, true);
        config(['salon.inventory_fractional_v2' => true]);
        $s = $this->serviceV2([$i->id => 10]);
        $before = $this->effects();
        $this->sale([$this->lineV2($s)])->assertStatus(422);
        $this->assertSame($before, $this->effects());
    }

    public function test_v2_reversals_and_legacy_opening_are_controlled_rejections(): void
    {
        $i = $this->itemV2();
        $f = $this->buyV2($i);
        $id = $this->consume($i, 100);
        $before = $this->effects();
        $this->post('/ventas/'.$id.'/anular', ['motivo' => 'fixture', 'pin_supervisor' => '4826'])->assertRedirect()->assertSessionHas('error');
        $this->assertSame($before, $this->effects());
        try {
            app(CompraService::class)->anularFactura($f->id, 'fixture');
            $this->fail('H04-B required');
        } catch (ContabilidadException $e) {
            $this->assertStringContainsString('H04-B', $e->getMessage());
        }
        $this->assertSame($before, $this->effects());
        try {
            StockLedger::registrarApertura($i);
            $this->fail('H01-B required');
        } catch (ContabilidadException $e) {
            $this->assertStringContainsString('H01-B', $e->getMessage());
        }
    }

    public static function lineOrders(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('lineOrders')]
    public function test_cogs_by_detail_is_independent_of_line_order(bool $reverse): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 2, '10');
        $s = $this->serviceV2([$i->id => 100]);
        $lines = [$this->lineV2($s, [$i->id => 0]), $this->lineV2($s, [$i->id => 20])];
        if ($reverse) {
            $lines = array_reverse($lines);
        }
        $id = $this->sale($lines)->assertCreated()->json('sale_id');
        $this->reconcile($i, 1, '80', '180', '18');
        $details = Venta::findOrFail($id)->detalles;
        $zero = $details->first(fn ($d) => $d->consumption_snapshot['ingredients'][0]['actual'] === '0.00');
        $this->assertSame(0, StockLedger::where('sale_detail_id', $zero->id)->count());
        $aid = DB::table('asientos_contables')->where('numero_asiento', 'CST-'.str_pad($id, 5, '0', STR_PAD_LEFT))->value('id');
        $this->assertEquals(2, DB::table('detalle_asientos')->where('asiento_id', $aid)->sum('debe'));
        $this->assertEquals(2, DB::table('detalle_asientos')->where('asiento_id', $aid)->sum('haber'));
    }

    public function test_same_item_as_service_ingredient_and_product_has_separate_cogs(): void
    {
        config(['salon.inventory_fractional_v2' => false]);
        $i = $this->stockH02(3);
        config(['salon.inventory_fractional_v2' => true]);
        $s = $this->serviceV2([$i->id => 1]);
        $id = $this->sale([$this->cartH02($i), $this->lineV2($s)])->assertCreated()->json('sale_id');
        foreach (['CST-', 'CMV-'] as $prefix) {
            $aid = DB::table('asientos_contables')->where('numero_asiento', $prefix.str_pad($id, 5, '0', STR_PAD_LEFT))->value('id');
            $this->assertEquals(20, DB::table('detalle_asientos')->where('asiento_id', $aid)->sum('debe'));
        }
    }

    public function test_retained_standard_and_zero_preserve_composition_at_approval(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 2, '10');
        $s = $this->serviceV2([$i->id => 30]);
        $id = $this->sale([$this->lineV2($s), $this->lineV2($s, [$i->id => 0])], ['requiere_aprobacion' => true])->assertCreated()->json('sale_id');
        DB::table('service_formulas')->where('service_id', $s->id)->delete();
        app(AprobacionService::class)->aprobarVenta($id, auth()->id());
        $this->reconcile($i, 1, '70', '170', '17');
    }

    public function test_reject_and_cancel_unapplied_documents_create_no_compensation(): void
    {
        $i = $this->itemV2();
        $this->buyV2($i);
        $s = $this->serviceV2([$i->id => 100]);
        foreach (['reject', 'cancel'] as $action) {
            $id = $this->sale([$this->lineV2($s, [$i->id => 0])], ['requiere_aprobacion' => true])->assertCreated()->json('sale_id');
            $ledger = DB::table('stock_ledger_entries')->count();
            $entries = DB::table('asientos_contables')->count();
            if ($action === 'reject') {
                app(AprobacionService::class)->rechazarVenta($id, 'fixture', auth()->id());
            } else {
                $this->post('/ventas/'.$id.'/anular', ['motivo' => 'fixture', 'pin_supervisor' => '4826'])->assertRedirect()->assertSessionHas('success');
            }
            $this->assertSame($ledger, DB::table('stock_ledger_entries')->count());
            $this->assertSame($entries, DB::table('asientos_contables')->count());
        }
        $this->reconcile($i, 2, '0', '1500', '34.46');
    }

    public function test_ambiguous_historical_approval_is_rejected_without_reconstructing_capture(): void
    {
        config(['salon.inventory_fractional_v2' => false]);
        $i = $this->stockH02(2, true);
        $s = $this->serviceV2([$i->id => 100]);
        $id = $this->sale([$this->lineV2($s, [$i->id => 0])], ['requiere_aprobacion' => true])->assertCreated()->json('sale_id');
        config(['salon.inventory_fractional_v2' => true]);
        $before = $this->effects();
        try {
            app(AprobacionService::class)->aprobarVenta($id, auth()->id());
            $this->fail('Ambiguous history must not be interpreted');
        } catch (ContabilidadException $e) {
            $this->assertStringContainsString('histórica', $e->getMessage());
        }
        $this->assertSame($before, $this->effects());
    }

    public static function failures(): array
    {
        return [['stock_ledger_lot_allocations'], ['detalle_asientos']];
    }

    #[DataProvider('failures')]
    public function test_injected_failure_rolls_back_physical_and_financial_effects(string $table): void
    {
        $i = $this->itemV2();
        $this->buyV2($i);
        $s = $this->serviceV2([$i->id => 100]);
        $before = $this->effects();
        $armed = true;
        DB::connection()->beforeExecuting(function ($sql) use ($table, &$armed) {
            if ($armed && str_starts_with($sql, 'insert into `'.$table.'`')) {
                $armed = false;
                throw new ContabilidadException('H03 injected rollback');
            }
        });
        $this->sale([$this->lineV2($s)])->assertStatus(422);
        $this->assertFalse($armed);
        $this->assertSame($before, $this->effects());
    }

    public function test_full_open_remainder_is_not_automatically_closed(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 1, '10');
        // Fixture canónico: envase ya abierto completo, sin inventar un movimiento cero.
        DB::table('items')->where('id', $i->id)->update(['existencia_actual' => 0, 'current_volume' => 100]);
        DB::table('lotes')->where('item_id', $i->id)->update(['cantidad_disponible' => 0, 'volumen_abierto' => 100]);
        DB::table('stock_ledger_lot_allocations')->where('item_id', $i->id)->update(['closed_after' => 0, 'open_after' => 100]);
        $this->reconcile($i, 0, '100', '100', '10');
        $this->consume($i, 1);
        $this->reconcile($i, 0, '99', '99', '9.90');
    }

    public function test_open_remainder_has_priority_over_older_closed_fifo_lot(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 1, '10');
        $this->consume($i, 10); // abierto 90.
        $f = $this->buyV2($i, 1, '20', ['fecha_factura' => today()->subDay()->toDateString()]);
        $closed = $f->lineas->first()->lotes()->first();
        $this->consume($i, 20);
        $this->assertEquals(1, $closed->fresh()->cantidad_disponible);
        $this->assertEquals(0, $closed->fresh()->volumen_abierto);
        $this->assertEquals(70, $i->fresh()->current_volume);
    }

    public function test_readers_include_open_only_lot_and_native_unit(): void
    {
        $i = $this->itemV2(100, 'gr');
        $this->buyV2($i, 1, '10');
        $this->consume($i, 10);
        $i->refresh();
        $this->assertEquals(0.9, $i->unidadesEquivalentesDisponibles());
        $this->assertFalse($i->stockCritico());
        $this->assertEquals(0.1, app(InventarioService::class)->calcularCostoPromedioVolumen($i));
        $this->assertEquals(10, $i->costoUnitarioActual());
        $this->get('/inventario/kardex?item_id='.$i->id)->assertOk()->assertSee('90.00 gr abiertos');
    }

    public function test_stale_composition_reference_is_rejected_before_consumption(): void
    {
        $i = $this->itemV2();
        $this->buyV2($i);
        $s = $this->serviceV2([$i->id => 100]);
        $line = $this->lineV2($s, [$i->id => 20]);
        $line['composition_reference'] = app(CapturaConsumoService::class)->foto($line)['reference'];
        DB::table('service_formulas')->where('service_id', $s->id)->update(['quantity_used' => 200]);
        $before = $this->effects();
        $this->sale([$line])->assertStatus(422);
        $this->assertSame($before, $this->effects());
    }

    public static function invalidQuantities(): array
    {
        return [['0'], ['-1'], ['1.2'], ['1.00001'], ['100000000'], ['']];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_purchase_container_quantities_do_not_reach_pdo_inserts(string $q): void
    {
        $i = $this->itemV2();
        $provider = Proveedor::create(['name' => 'H03 fixture']);
        $before = $this->effects();
        $inserted = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$inserted) {
            if (str_starts_with($sql, 'insert into `purchase_')) {
                $inserted = true;
            }
        });
        try {
            app(CompraService::class)->registrarFactura(['provider_id' => $provider->id, 'articulos' => [['id' => $i->id, 'cantidad' => $q, 'costo' => 1]]]);
            $this->fail('Invalid input accepted');
        } catch (ContabilidadException $e) {
            $this->assertFalse($inserted);
            $this->assertSame($before, $this->effects());
        }
    }

    public function test_sealed_product_sale_leaves_open_remainder_untouched(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 2, '10');
        $this->consume($i, 20);
        $this->sale([$this->cartH02($i)])->assertCreated();
        $this->reconcile($i, 0, '80', '80', '8');
        $before = $this->effects();
        $this->sale([$this->cartH02($i)])->assertStatus(422);
        $this->assertSame($before, $this->effects());
    }

    public function test_expiry_alert_includes_only_open_remainder_with_native_unit(): void
    {
        $i = $this->itemV2(100, 'gr');
        $this->buyV2($i, 1, '10', ['articulos' => [['id' => $i->id, 'cantidad' => 1, 'costo' => 10, 'fecha_vencimiento' => today()->addDays(5)->toDateString()]]]);
        $this->consume($i, 10);
        $response = $this->getJson('/inventario/alertas-caducidad')->assertOk();
        $group = collect($response->json('alertas'))->first(fn ($g) => (int) $g['articulo']['id'] === $i->id);
        $this->assertNotNull($group);
        $this->assertSame('90.00', $group['lotes'][0]['volumen_abierto']);
        $this->assertSame('gr', $group['lotes'][0]['unidad_nativa']);
        $this->assertGreaterThanOrEqual(1, $response->json('total_alertas'));
    }

    public function test_native_unit_cost_overflow_is_rejected_without_partial_effects(): void
    {
        $i = $this->itemV2(1);
        DB::table('items')->where('id', $i->id)->update(['total_volume' => '0.01']);
        $provider = Proveedor::create(['name' => 'H03 precision fixture']);
        $before = $this->effects();
        try {
            app(CompraService::class)->registrarFactura(['provider_id' => $provider->id, 'articulos' => [['id' => $i->id, 'cantidad' => 1, 'costo' => '10000000']]]);
            $this->fail('Native cost limit ignored');
        } catch (ContabilidadException $e) {
            $this->assertStringContainsString('precisión del kárdex', $e->getMessage());
        }
        $this->assertSame($before, $this->effects());
    }

    public function test_mixed_legacy_lot_does_not_get_silently_converted(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 2, '10');
        $s = $this->serviceV2([$i->id => 20]);
        Lote::create(['item_id' => $i->id, 'lote' => 'H03 legacy inconsistent fixture', 'fecha_entrada' => today(),
            'cantidad_entrada' => 1, 'cantidad_disponible' => 1, 'costo_unitario' => 10]);
        $before = $this->effects();
        $this->sale([$this->lineV2($s)])->assertStatus(422);
        $this->assertSame($before, $this->effects());
    }

    public function test_oz_is_used_as_native_unit_without_automatic_conversion(): void
    {
        $i = $this->itemV2(100, 'oz');
        $this->buyV2($i, 2, '10');
        $this->consume($i, 20);
        $this->reconcile($i, 1, '80', '180', '18');
        $movement = StockLedger::where('item_id', $i->id)->latest('id')->first();
        $this->assertSame('oz', DB::table('stock_ledger_lot_allocations')->where('stock_ledger_entry_id', $movement->id)->value('native_unit'));
    }

    public function test_archived_recipe_ingredient_does_not_disable_the_pos_or_allow_consumption(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 2, '10');
        $s = $this->serviceV2([$i->id => 20]);
        $i->delete();
        $this->get('/pos')->assertOk();
        $this->assertNull(app(CapturaConsumoService::class)->referenciaDisponible($s->id));
        $before = $this->effects();
        $this->sale([$this->lineV2($s)])->assertStatus(422);
        $this->assertSame($before, $this->effects());
    }

    public function test_empty_new_v2_catalog_article_can_be_changed_to_nonfractional_without_stock(): void
    {
        $this->postJson('/inventario', ['producto' => 'H03 empty catalog fixture', 'type' => 'producto_interno', 'precio_c' => 10,
            'is_fractionable' => true, 'unit_measure' => 'gr', 'total_volume' => '100.00'])->assertOk();
        $i = Articulo::where('producto', 'H03 empty catalog fixture')->firstOrFail();
        $this->assertSame(2, (int) $i->inventory_representation_version);
        $this->putJson('/inventario/'.$i->id, ['producto' => $i->producto, 'precio_c' => 10, 'is_fractionable' => false, 'unit_measure' => 'und', 'total_volume' => 0])->assertOk();
        $i->refresh();
        $this->assertNull($i->inventory_representation_version);
        $this->assertSame(0, (int) $i->existencia_actual);
        $this->assertSame('0.00', $i->current_volume);
        $this->assertSame(0, StockLedger::where('item_id', $i->id)->count());
    }

    public function test_v2_catalog_measure_validation_does_not_require_repeating_fractional_flag(): void
    {
        $i = $this->itemV2(100);
        $before = $this->effects();
        foreach ([['total_volume' => '100.001'], ['unit_measure' => 'kg']] as $invalid) {
            $this->putJson('/inventario/'.$i->id, array_merge(['producto' => $i->producto, 'precio_c' => 100], $invalid))->assertStatus(422);
            $this->assertSame($before, $this->effects());
        }
    }

    public function test_v2_commercial_update_can_repeat_fractional_flag_without_resending_measures(): void
    {
        $i = $this->itemV2(100);
        $this->buyV2($i, 2, '10');
        $before = DB::table('stock_ledger_entries')->where('item_id', $i->id)->orderBy('id')->get()->toJson();
        $this->putJson('/inventario/'.$i->id, ['producto' => $i->producto, 'precio_c' => 120, 'is_fractionable' => true])->assertOk();
        $this->assertSame($before, DB::table('stock_ledger_entries')->where('item_id', $i->id)->orderBy('id')->get()->toJson());
        $this->reconcile($i, 2, '0', '200', '20');
    }
}
