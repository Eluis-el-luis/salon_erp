<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Lote;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Servicio;
use App\Models\StockLedger;
use App\Models\Usuario;
use App\Services\CompraService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** H01-A: únicamente fixtures SQL en la conexión de pruebas; rollback por caso. */
class CatalogInventoryProtectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->actingAs(Usuario::create(['name' => 'H01A', 'email' => 'catalog@h01a.example.test', 'password' => 'fixture-password',
            'role' => 'admin', 'role_id' => Rol::where('name', 'Administrador')->firstOrFail()->id, 'is_active' => true]));
        $this->app->bind(PreventRequestForgery::class, EnforcedH01ACsrf::class);
        $this->withSession(['_token' => 'h01a-fixture']);
        $this->withHeaders(['Accept' => 'application/json', 'X-CSRF-TOKEN' => 'h01a-fixture']);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['producto' => 'Artículo H01A', 'type' => 'producto_interno', 'precio_c' => 120, 'precio_usd' => 3,
            'stock_min' => 0, 'is_fractionable' => true, 'unit_measure' => 'ml', 'total_volume' => 1000], $extra);
    }

    private function item(array $extra = []): Articulo
    {
        return Articulo::create(array_replace($this->payload(), ['existencia_actual' => 0, 'current_volume' => 0], $extra))->refresh();
    }

    public function test_creation_initializes_zero_without_lots_or_movements(): void
    {
        $counts = [];
        foreach (['lotes', 'stock_ledger_entries', 'purchase_invoices', 'asientos_contables'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $this->postJson('/inventario', $this->payload(['id' => 987654, 'costo_promedio' => 999, 'deleted_at' => now()->toDateTimeString()]))->assertOk();
        $item = Articulo::where('producto', 'Artículo H01A')->firstOrFail();
        $this->assertSame(0, (int) $item->existencia_actual);
        $this->assertSame(0.0, (float) $item->current_volume);
        $this->assertSame(1000.0, (float) $item->total_volume);
        $this->assertNotEquals(987654, $item->id);
        $this->assertNull($item->deleted_at);
        $this->assertSame(0.0, (float) $item->costo_promedio);
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public static function quantities(): array
    {
        $cases = [];
        foreach (['existencia_actual', 'current_volume'] as $field) {
            foreach ([12, 0, null] as $value) {
                $cases[$field.'-'.json_encode($value)] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('quantities')]
    public function test_physical_fields_are_rejected_even_zero_or_null(string $field, mixed $value): void
    {
        $count = Articulo::count();
        $this->postJson('/inventario', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($count, Articulo::count());
        $item = $this->item(['existencia_actual' => 4, 'current_volume' => 120]);
        $before = $item->getAttributes();
        $this->putJson('/inventario/'.$item->id, $this->payload([$field => $value, 'producto' => 'No guardar']))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $item->fresh()->getAttributes());
    }

    public function test_commercial_edit_preserves_physical_values_lots_and_ledger(): void
    {
        $item = $this->item(['existencia_actual' => 4, 'current_volume' => 120]);
        $lot = Lote::create(['item_id' => $item->id, 'lote' => 'H01A', 'fecha_entrada' => today(), 'cantidad_entrada' => 4, 'cantidad_disponible' => 4, 'costo_unitario' => 50]);
        StockLedger::registrarApertura($item, auth()->id());
        $before = DB::table('stock_ledger_entries')->where('item_id', $item->id)->get()->toJson();
        $lotBefore = $lot->refresh()->getAttributes();
        $this->putJson('/inventario/'.$item->id, $this->payload(['producto' => 'Nuevo nombre', 'precio_c' => 200, 'categoria' => 'Nueva',
            'marca' => 'Marca', 'ubicacion' => 'Estante', 'total_volume' => '1000.00', 'is_fractionable' => 1, 'costo_promedio' => 999]))->assertOk();
        $item->refresh();
        $this->assertSame('Nuevo nombre', $item->producto);
        $this->assertSame(200.0, (float) $item->precio_c);
        $this->assertSame(4, (int) $item->existencia_actual);
        $this->assertSame(120.0, (float) $item->current_volume);
        $this->assertSame(0, (int) $item->stock_min);
        $this->assertSame($before, DB::table('stock_ledger_entries')->where('item_id', $item->id)->get()->toJson());
        $this->assertSame($lotBefore, $lot->fresh()->getAttributes());
    }

    public static function structureChanges(): array
    {
        return [['type', 'producto_venta'], ['unit_measure', 'oz'], ['is_fractionable', false], ['total_volume', 500]];
    }

    #[DataProvider('structureChanges')]
    public function test_reinterpretation_is_rejected_with_physical_stock(string $field, mixed $value): void
    {
        $item = $this->item(['current_volume' => 1]);
        $before = $item->getAttributes();
        $this->putJson('/inventario/'.$item->id, $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $item->fresh()->getAttributes());
    }

    public function test_depleted_lot_history_still_freezes_interpretation(): void
    {
        $item = $this->item();
        Lote::create(['item_id' => $item->id, 'lote' => 'Agotado', 'fecha_entrada' => today(), 'cantidad_entrada' => 1, 'cantidad_disponible' => 0, 'costo_unitario' => 50]);
        $this->putJson('/inventario/'.$item->id, $this->payload(['total_volume' => 500]))->assertUnprocessable()->assertJsonValidationErrors('total_volume');
    }

    public function test_ledger_history_without_stock_or_lots_freezes_interpretation(): void
    {
        $item = $this->item();
        StockLedger::registrar($item, 'entrada', 'ajuste', 0, 0, 0, usuarioId: auth()->id(), notas: 'Fixture sin saldo');
        $this->putJson('/inventario/'.$item->id, $this->payload(['unit_measure' => 'oz']))->assertUnprocessable()->assertJsonValidationErrors('unit_measure');
    }

    public function test_formula_with_zero_stock_freezes_interpretation(): void
    {
        $item = $this->item();
        $service = Servicio::create(['name' => 'Servicio H01A', 'price' => 100, 'duration' => 30]);
        DB::table('service_formulas')->insert(['service_id' => $service->id, 'item_id' => $item->id, 'quantity_used' => 20]);
        $this->putJson('/inventario/'.$item->id, $this->payload(['total_volume' => 500]))->assertUnprocessable()->assertJsonValidationErrors('total_volume');
    }

    public function test_retained_purchase_freezes_interpretation_before_stock_is_received(): void
    {
        $item = $this->item();
        $provider = Proveedor::create(['name' => 'Proveedor H01A']);
        $invoice = app(CompraService::class)->registrarFactura(['provider_id' => $provider->id, 'condicion_pago' => 'credito',
            'requiere_aprobacion' => true, 'articulos' => [['id' => $item->id, 'cantidad' => 1, 'costo' => 50]]]);
        $this->assertSame('pendiente_aprobacion', $invoice->estado);
        $this->assertSame(0, (int) $item->fresh()->existencia_actual);
        $this->assertSame(0, Lote::where('item_id', $item->id)->count());
        $this->putJson('/inventario/'.$item->id, $this->payload(['total_volume' => 500]))->assertUnprocessable()->assertJsonValidationErrors('total_volume');
    }

    public function test_non_fractionable_creation_and_catalog_view(): void
    {
        $this->postJson('/inventario', $this->payload(['is_fractionable' => false, 'total_volume' => 0, 'type' => 'producto_venta']))->assertOk();
        $item = Articulo::where('producto', 'Artículo H01A')->firstOrFail();
        $this->assertSame(0, (int) $item->existencia_actual);
        $this->assertSame(0.0, (float) $item->current_volume);
        $this->get('/inventario')->assertOk()->assertSee('El catálogo no ingresa existencias.');
    }

    public function test_pristine_article_can_change_interpretation_without_creating_stock(): void
    {
        $item = $this->item();
        $this->putJson('/inventario/'.$item->id, $this->payload(['type' => 'producto_venta', 'is_fractionable' => false, 'unit_measure' => 'und', 'total_volume' => 0]))->assertOk();
        $item->refresh();
        $this->assertSame('producto_venta', $item->type);
        $this->assertSame(0, (int) $item->existencia_actual);
        $this->assertSame(0.0, (float) $item->current_volume);
    }

    public function test_invalid_capacity_is_rejected_but_legacy_commercial_edit_remains_possible(): void
    {
        $this->postJson('/inventario', $this->payload(['total_volume' => 0]))->assertUnprocessable()->assertJsonValidationErrors('total_volume');
        $item = $this->item(['total_volume' => 0, 'existencia_actual' => 2]);
        $this->putJson('/inventario/'.$item->id, ['producto' => 'Histórico', 'precio_c' => 130])->assertOk();
        $this->assertSame(2, (int) $item->fresh()->existencia_actual);
    }

    public function test_catalog_still_requires_csrf(): void
    {
        $this->withHeader('X-CSRF-TOKEN', 'incorrecto')->postJson('/inventario', $this->payload())->assertStatus(419);
    }

    public function test_catalog_preserves_authentication_and_role_restrictions(): void
    {
        $actor = auth()->user();
        auth()->logout();
        $this->getJson('/inventario')->assertUnauthorized();
        $actor->update(['role' => 'estilista', 'role_id' => Rol::where('name', 'Estilista')->firstOrFail()->id]);
        $this->actingAs($actor)->postJson('/inventario', $this->payload())->assertForbidden();
    }
}

class EnforcedH01ACsrf extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
