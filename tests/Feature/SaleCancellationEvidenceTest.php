<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\DetalleVenta;
use App\Models\Servicio;
use App\Models\Venta;
use App\Services\AprobacionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InventoryH02Fixtures;
use Tests\TestCase;

class SaleCancellationEvidenceTest extends TestCase
{
    public function test_paid_service_without_bom_needs_no_stock_return(): void
    {
        $service = Servicio::create(['name' => 'H04A sin insumos', 'price' => 100, 'duration' => 30]);
        $id = $this->postJson('/sales', ['cart' => [['id' => $service->id, 'type' => 'servicio', 'quantity' => 1, 'precio_c' => 100]]])->assertCreated()->json('sale_id');
        $ledger = DB::table('stock_ledger_entries')->count();
        $asientos = DB::table('asientos_contables')->count();
        $this->cancel($id)->assertRedirect()->assertSessionHas('success');
        $this->assertSame($ledger, DB::table('stock_ledger_entries')->count());
        $this->assertSame($asientos + 1, DB::table('asientos_contables')->count());
    }

    public function test_credit_sale_has_applied_stock_without_payments(): void
    {
        $item = $this->stockH02();
        $client = Cliente::create(['name' => 'H04A crédito']);
        $id = $this->sale($item, ['action' => 'credito', 'client_id' => $client->id]);
        $this->assertSame('emitida', Venta::findOrFail($id)->status);
        $this->assertSame(0, DB::table('pagos')->where('venta_id', $id)->count());
        $this->cancel($id)->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, (int) $item->fresh()->existencia_actual);
    }

    public function test_changed_recipe_cannot_return_an_undocumented_quantity(): void
    {
        $item = $this->stockH02();
        $service = Servicio::create(['name' => 'H04A receta', 'price' => 100, 'duration' => 30]);
        DB::table('service_formulas')->insert(['service_id' => $service->id, 'item_id' => $item->id, 'quantity_used' => 1]);
        $id = $this->postJson('/sales', ['cart' => [['id' => $service->id, 'type' => 'servicio', 'quantity' => 1, 'precio_c' => 100]]])->assertCreated()->json('sale_id');
        DB::table('service_formulas')->where('service_id', $service->id)->update(['quantity_used' => 2]);
        $before = $this->effects();
        $this->cancel($id)->assertRedirect()->assertSessionHas('error');
        $this->assertSame($before, $this->effects());
        $this->assertSame('pagada', Venta::findOrFail($id)->status);
    }

    use DatabaseTransactions, InventoryH02Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actorH02();
    }

    private function effects(): array
    {
        $rows = [];
        foreach (['items', 'lotes', 'stock_ledger_entries', 'pagos', 'asientos_contables', 'detalle_asientos', 'comisiones_generadas'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }

    private function cancel(int $id)
    {
        return $this->post('/ventas/'.$id.'/anular', ['motivo' => 'H04A fixture', 'pin_supervisor' => '4826']);
    }

    private function sale(Articulo $item, array $extra = []): int
    {
        return $this->postJson('/sales', array_replace(['cart' => [$this->cartH02($item)], 'payments' => [['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 100, 'tasa' => 1]]], $extra))->assertCreated()->json('sale_id');
    }

    public static function unapplied(): array
    {
        return [['borrador', false], ['pendiente', false], ['borrador', true], ['pendiente', true]];
    }

    #[DataProvider('unapplied')]
    public function test_unapplied_documents_never_generate_returns(string $mode, bool $fractional): void
    {
        $item = $this->stockH02(2, $fractional);
        $id = $this->sale($item, $mode === 'borrador' ? ['action' => 'cotizar'] : ['requiere_aprobacion' => true]);
        $before = $this->effects();
        $this->cancel($id)->assertRedirect()->assertSessionHas('success');
        $this->assertSame($before, $this->effects());
        $this->assertSame('anulada', Venta::findOrFail($id)->status);
        $this->assertNull(Venta::findOrFail($id)->payload_aprobacion);
        $this->cancel($id)->assertRedirect()->assertSessionHas('error');
        $this->assertSame($before, $this->effects());
    }

    public function test_rejecting_retained_sale_has_no_stock_or_financial_effects(): void
    {
        $id = $this->sale($this->stockH02(), ['requiere_aprobacion' => true]);
        $before = $this->effects();
        app(AprobacionService::class)->rechazarVenta($id, 'H04A fixture', auth()->id());
        $this->assertSame($before, $this->effects());
        $this->assertSame('anulada', Venta::findOrFail($id)->status);
    }

    public function test_approved_sale_reverses_once_and_keeps_fractional_algorithm(): void
    {
        foreach ([false, true] as $fractional) {
            $item = $this->stockH02(2, $fractional);
            $id = $this->sale($item, ['requiere_aprobacion' => true]);
            app(AprobacionService::class)->aprobarVenta($id, auth()->id());
            $this->assertSame(1, (int) $item->fresh()->existencia_actual);
            $count = DB::table('asientos_contables')->count();
            $this->cancel($id)->assertRedirect()->assertSessionHas('success');
            $this->assertSame(2, (int) $item->fresh()->existencia_actual);
            $this->assertSame(2.0, (float) DB::table('lotes')->where('item_id', $item->id)->sum('cantidad_disponible'));
            $this->assertSame(1, DB::table('stock_ledger_entries')->where('referencia_tipo', 'venta')->where('referencia_id', $id)->where('origen', 'reversion_venta')->count());
            $this->assertSame($count + 2, DB::table('asientos_contables')->count());
            if ($fractional) {
                $this->assertSame(2000.0, (float) $item->fresh()->current_volume);
            }
            $before = $this->effects();
            $this->cancel($id)->assertRedirect()->assertSessionHas('error');
            $this->assertSame($before, $this->effects());
        }
    }

    public function test_pending_state_with_original_effects_is_rejected_without_compensation(): void
    {
        $id = $this->sale($this->stockH02());
        Venta::findOrFail($id)->update(['status' => 'pendiente_aprobacion']); // Inconsistencia deliberada de fixture.
        $before = $this->effects();
        $this->cancel($id)->assertRedirect()->assertSessionHas('error');
        $this->assertSame($before, $this->effects());
        $this->assertSame('pendiente_aprobacion', Venta::findOrFail($id)->status);
    }

    public function test_paid_state_without_original_stock_evidence_requires_reconciliation(): void
    {
        $item = $this->stockH02();
        $sale = Venta::create(['cashier_id' => auth()->id(), 'subtotal' => 100, 'discount' => 0, 'total' => 100, 'status' => 'pagada']);
        DetalleVenta::create(['sale_id' => $sale->id, 'item_id' => $item->id, 'quantity' => 1, 'unit_price' => 100]);
        $before = $this->effects();
        $this->cancel($sale->id)->assertRedirect()->assertSessionHas('error');
        $this->assertSame($before,$this->effects());
        $this->assertSame('pagada',$sale->fresh()->status);
    }
}
