<?php

namespace Tests\Feature;

use App\Exceptions\ContabilidadException;
use App\Models\Articulo;
use App\Models\Venta;
use App\Services\AprobacionService;
use App\Services\InventarioFraccionableService;
use App\Support\InventoryDecimal as D;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FractionalV2Fixtures;
use Tests\Support\InventoryH02Fixtures;
use Tests\TestCase;

class InventoryAccountConsistencyTest extends TestCase
{
    use DatabaseTransactions, FractionalV2Fixtures, InventoryH02Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actorH02();
        config(['salon.inventory_fractional_v2' => true]);
    }

    private function item(bool $fractional, string $type = 'producto_venta'): Articulo
    {
        if ($fractional) {
            $i = $this->itemV2(100);
            $i->update(['type' => $type]); // Fixture vacío, antes de cualquier documento.

            return $i;
        }

        return Articulo::create(['producto' => 'H03 account fixture', 'type' => $type, 'precio_c' => 100,
            'is_fractionable' => false, 'total_volume' => 0, 'current_volume' => 0, 'existencia_actual' => 0, 'unit_measure' => 'und', 'stock_min' => 0]);
    }

    /** Saldos y movimientos en centavos, incluyendo cuentas todavía sin movimientos. */
    private function balances(?int $asientoId = null): array
    {
        $result = array_fill_keys(['1.1.4', '1.1.5', '5.1', '5.2'], ['debe' => 0, 'haber' => 0]);
        $query = DB::table('detalle_asientos as d')->join('cuentas_contables as c', 'c.id', '=', 'd.cuenta_id')
            ->whereIn('c.codigo', array_keys($result));
        if ($asientoId !== null) {
            $query->where('d.asiento_id', $asientoId);
        }
        foreach ($query->select('c.codigo', 'd.debe', 'd.haber')->get() as $r) {
            $result[$r->codigo]['debe'] += D::stored($r->debe);
            $result[$r->codigo]['haber'] += D::stored($r->haber);
        }

        return $result;
    }

    private function assertBalanced(int $sale): void
    {
        foreach (DB::table('asientos_contables')->where('referencia_id', $sale)->whereIn('modulo_origen', ['ventas', 'inventario'])->get() as $a) {
            $debe = $haber = 0;
            foreach (DB::table('detalle_asientos')->where('asiento_id', $a->id)->get() as $d) {
                $debe += D::stored($d->debe);
                $haber += D::stored($d->haber);
            }
            $this->assertSame($debe, $haber);
        }
    }

    public static function cases(): array
    {
        return [[true, false], [false, false], [true, true], [false, true]];
    }

    #[DataProvider('cases')]
    public function test_purchase_and_output_use_same_inventory_account(bool $fractional, bool $service): void
    {
        $item = $this->item($fractional);
        $before = $this->balances();
        $invoice = $this->buyV2($item, 2, '10');
        $afterPurchase = $this->balances();
        $inventory = $fractional ? '1.1.5' : '1.1.4';
        $costAccount = $service ? '5.2' : '5.1';
        $cost = $service && $fractional ? 200 : 1000;
        $this->assertSame(2000, $afterPurchase[$inventory]['debe'] - $before[$inventory]['debe']);
        $line = $service ? $this->lineV2($this->serviceV2([$item->id => $fractional ? 20 : 1])) : $this->cartH02($item);
        $id = $this->postJson('/sales', ['cart' => [$line]])->assertCreated()->json('sale_id');
        $afterSale = $this->balances();
        $this->assertSame($cost, $afterSale[$inventory]['haber'] - $afterPurchase[$inventory]['haber']);
        $this->assertSame($cost, $afterSale[$costAccount]['debe'] - $afterPurchase[$costAccount]['debe']);
        $other = $inventory === '1.1.4' ? '1.1.5' : '1.1.4';
        $this->assertSame($afterPurchase[$other], $afterSale[$other]);
        $movements = DB::table('stock_ledger_entries')->where('referencia_tipo', 'venta')->where('referencia_id', $id)->get();
        $this->assertSame(-$cost, $movements->sum(fn ($m) => D::signed($m->valor)));
        $this->assertSame(2000, $invoice->lineas->sum(fn ($l) => D::stored($l->getRawOriginal('valor_neto'))));
        if ($fractional) {
            app(InventarioFraccionableService::class)->estado($item->refresh());
            foreach ($movements as $m) {
                $this->assertSame(D::signed($m->valor), DB::table('stock_ledger_lot_allocations')->where('stock_ledger_entry_id', $m->id)->get()->sum(fn ($r) => D::signed($r->value_delta)));
            }
        }
        $this->assertBalanced($id);
        file_put_contents(storage_path('app/private/h03b2-account-evidence.jsonl'), json_encode([
            'fractional' => $fractional, 'service' => $service, 'account' => $inventory, 'cogs_account' => $costAccount,
            'before' => $before, 'after_purchase' => $afterPurchase, 'after_sale' => $afterSale,
            'ledger_value_cents' => -$cost, 'balanced' => true,
        ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function test_mixed_purchase_and_sale_group_inventory_by_article_before_cogs(): void
    {
        $a = $this->item(true);
        $b = $this->item(false);
        $c = $this->item(false, 'producto_interno');
        $d = $this->item(true, 'producto_interno');
        $before = $this->balances();
        $invoice = $this->buyV2($a, 2, '10', ['articulos' => collect([$a, $b, $c, $d])->map(fn ($i) => ['id' => $i->id, 'cantidad' => 2, 'costo' => 10])->all()]);
        $afterPurchase = $this->balances();
        $this->assertSame(2000, $afterPurchase['1.1.4']['debe'] - $before['1.1.4']['debe']);
        $this->assertSame(6000, $afterPurchase['1.1.5']['debe'] - $before['1.1.5']['debe']);
        $service = $this->serviceV2([$a->id => 20, $b->id => 1, $c->id => 1, $d->id => 30]);
        $id = $this->postJson('/sales', ['cart' => [$this->cartH02($a), $this->cartH02($b), $this->cartH02($c),
            $this->lineV2($service, [$a->id => 0, $b->id => 1, $c->id => 1, $d->id => 30])]])->assertCreated()->json('sale_id');
        $afterSale = $this->balances();
        $this->assertSame(2000, $afterSale['1.1.4']['haber'] - $afterPurchase['1.1.4']['haber']);
        $this->assertSame(3300, $afterSale['1.1.5']['haber'] - $afterPurchase['1.1.5']['haber']);
        foreach (['CST-' => ['5.2', 2300, 1000, 1300], 'CMV-' => ['5.1', 3000, 1000, 2000]] as $prefix => [$cost,$total,$a14,$a15]) {
            $aid = DB::table('asientos_contables')->where('numero_asiento', $prefix.str_pad($id, 5, '0', STR_PAD_LEFT))->value('id');
            $rows = $this->balances($aid);
            $this->assertSame($total, $rows[$cost]['debe']);
            $this->assertSame($a14, $rows['1.1.4']['haber']);
            $this->assertSame($a15, $rows['1.1.5']['haber']);
        }
        $this->assertSame(-5300, DB::table('stock_ledger_entries')->where('referencia_tipo', 'venta')->where('referencia_id', $id)->get()->sum(fn ($r) => D::signed($r->valor)));
        $this->assertBalanced($id);
        foreach ([$a, $d] as $i) {
            app(InventarioFraccionableService::class)->estado($i->refresh());
        }
        file_put_contents(storage_path('app/private/h03b2-account-evidence.jsonl'), json_encode(['mixed' => true, 'before' => $before, 'after_purchase' => $afterPurchase, 'after_sale' => $afterSale, 'balanced' => true], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function test_retained_approval_keeps_original_recipe_and_account(): void
    {
        $i = $this->item(true);
        $this->buyV2($i, 2, '10');
        $s = $this->serviceV2([$i->id => 50]);
        $before = $this->balances();
        $id = $this->postJson('/sales', ['cart' => [$this->lineV2($s, [$i->id => 20])], 'requiere_aprobacion' => true])->assertCreated()->json('sale_id');
        $this->assertSame($before, $this->balances());
        DB::table('service_formulas')->where('service_id', $s->id)->update(['quantity_used' => 80]);
        app(AprobacionService::class)->aprobarVenta($id, auth()->id());
        $after = $this->balances();
        $this->assertSame(200, $after['1.1.5']['haber'] - $before['1.1.5']['haber']);
        $this->assertSame(200, $after['5.2']['debe'] - $before['5.2']['debe']);
        $this->assertBalanced($id);
    }

    public function test_ambiguous_historical_capture_is_not_reclassified_or_approved(): void
    {
        config(['salon.inventory_fractional_v2' => false]);
        $i = $this->stockH02();
        $s = $this->serviceV2([$i->id => 1]);
        $id = $this->postJson('/sales', ['cart' => [$this->lineV2($s)], 'requiere_aprobacion' => true])->assertCreated()->json('sale_id');
        $before = $this->balances();
        $ledger = DB::table('stock_ledger_entries')->orderBy('id')->get()->toJson();
        config(['salon.inventory_fractional_v2' => true]);
        try {
            app(AprobacionService::class)->aprobarVenta($id, auth()->id());
            $this->fail('Ambiguous snapshot accepted');
        } catch (ContabilidadException $e) {
            $this->assertStringContainsString('histórica', $e->getMessage());
        }
        $this->assertSame($before, $this->balances());
        $this->assertSame($ledger, DB::table('stock_ledger_entries')->orderBy('id')->get()->toJson());
        $this->assertNull(Venta::find($id)->detalles->first()->consumption_snapshot);
    }

    public function test_uncontrolled_classification_change_cannot_reclassify_a_retained_sale(): void
    {
        $i = $this->item(false);
        $this->buyV2($i, 2, '10');
        $id = $this->postJson('/sales', ['cart' => [$this->cartH02($i)], 'requiere_aprobacion' => true])->assertCreated()->json('sale_id');
        // Simula una importación histórica inconsistente; el CRUD H01-A prohíbe este cambio.
        DB::table('items')->where('id', $i->id)->update(['type' => 'producto_interno']);
        $before = $this->balances();
        $ledger = DB::table('stock_ledger_entries')->orderBy('id')->get()->toJson();
        try {
            app(AprobacionService::class)->aprobarVenta($id, auth()->id());
            $this->fail('Uncontrolled reclassification accepted');
        } catch (ContabilidadException $e) {
            $this->assertStringContainsString('clasificación', $e->getMessage());
        }
        $this->assertSame($before, $this->balances());
        $this->assertSame($ledger, DB::table('stock_ledger_entries')->orderBy('id')->get()->toJson());
    }

    public function test_ledger_detail_foreign_key_restricts_deletion_and_invalid_references(): void
    {
        $i = $this->item(false);
        $this->buyV2($i, 2, '10');
        $id = $this->postJson('/sales', ['cart' => [$this->cartH02($i)]])->assertCreated()->json('sale_id');
        $detail = Venta::findOrFail($id)->detalles->first();
        $movement = DB::table('stock_ledger_entries')->where('sale_detail_id', $detail->id)->first();
        foreach (['invalid_reference', 'delete_detail'] as $operation) {
            try {
                if ($operation === 'invalid_reference') {
                    DB::table('stock_ledger_entries')->where('id', $movement->id)->update(['sale_detail_id' => 999999999]);
                } else {
                    DB::table('sale_details')->where('id', $detail->id)->delete();
                }
                $this->fail('FK restriction bypassed');
            } catch (QueryException $e) {
                $this->assertSame($operation === 'invalid_reference' ? 1452 : 1451, $e->errorInfo[1]);
            }
        }
        $this->assertSame($detail->id, (int) DB::table('stock_ledger_entries')->where('id', $movement->id)->value('sale_detail_id'));
    }

    public function test_archived_retained_product_cannot_be_approved_without_stock_output(): void
    {
        $i = $this->item(false);
        $this->buyV2($i, 2, '10');
        $id = $this->postJson('/sales', ['cart' => [$this->cartH02($i)], 'requiere_aprobacion' => true])->assertCreated()->json('sale_id');
        $i->delete();
        $before = $this->balances();
        $ledger = DB::table('stock_ledger_entries')->orderBy('id')->get()->toJson();
        try {
            app(AprobacionService::class)->aprobarVenta($id, auth()->id());
            $this->fail('Archived product approved without consumption');
        } catch (ContabilidadException $e) {
            $this->assertStringContainsString('archivado', $e->getMessage());
        }
        $this->assertSame($before, $this->balances());
        $this->assertSame($ledger, DB::table('stock_ledger_entries')->orderBy('id')->get()->toJson());
    }
}
