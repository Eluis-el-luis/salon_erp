<?php

namespace Tests\Unit;

use App\Http\Controllers\{InventarioController, MermaController};
use App\Models\{Articulo, FacturaCompra, StockLedger};
use App\Services\{CompraService, OpeningDocumentContract, PrestacionCompartidaService};
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\{DateFactory, Facades\Facade};
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\{Factory, PresenceVerifierInterface, ValidationException};
use Mockery;
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunTestsInSeparateProcesses};
use PHPUnit\Framework\TestCase;
use Tests\Support\H01InventoryMemory;

/** Casos de uso reales sobre memoria. Contabilidad/redirect son dobles; no hay PDO ni kernel HTTP. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class H01InventoryTraceTest extends TestCase
{
    private H01InventoryMemory $db;
    private Factory $validator;
    private $previousResolver;
    private array $messages = [];
    private array $accounting = [];
    private array $reached = [];
    private array $visibleAtFailure = [];
    private bool $accountingError = false;

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 2)); $app->instance('env', 'testing');
        $app->instance('config', new Repository(['salon' => ['inventory_fractional_v2' => false, 'shared_services' => ['enabled' => false, 'sql_verified' => false]]]));
        $app->instance('date', new DateFactory); Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = new H01InventoryMemory; $app->instance('db', $this->db);
        $this->previousResolver = Model::getConnectionResolver(); $resolver = new ConnectionResolver(['mysql' => $this->db]); $resolver->setDefaultConnection('mysql'); Model::setConnectionResolver($resolver);
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Factory::class); $auth->shouldReceive('id')->andReturn(70); $app->instance('auth', $auth);
        $this->validator = new Factory(new Translator(new ArrayLoader, 'es'));
        $presence = Mockery::mock(PresenceVerifierInterface::class); $presence->shouldReceive('getCount')->andReturn(1); $this->validator->setPresenceVerifier($presence);
        $response = Mockery::mock(ResponseFactory::class); $response->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new JsonResponse($data, $status)); $app->instance(ResponseFactory::class, $response);
        $redirectResponse = Mockery::mock(RedirectResponse::class); $redirectResponse->shouldReceive('with', 'withErrors')->andReturnUsing(function (...$args) use ($redirectResponse) { $this->messages[] = $args; return $redirectResponse; });
        $redirect = Mockery::mock(); $redirect->shouldReceive('back')->andReturn($redirectResponse); $app->instance('redirect', $redirect);
        $log = Mockery::mock(); $log->shouldReceive('error')->andReturnNull(); $app->instance('log', $log);
        $accounting = Mockery::mock('overload:App\Services\ContabilidadService');
        $accounting->shouldReceive('contabilizarMerma', 'contabilizarFacturaCompra')->andReturnUsing(function ($document) {
            $this->accounting[] = $document->getTable();
            if ($this->accountingError) { $this->db->beginTransaction(); throw new \Error('h01.accounting.sentinel'); }
            return (object)['id' => 900];
        });
        $this->db->rows['users'][70] = ['id' => 70];
        $this->db->rows['providers'][20] = ['id' => 20, 'credit_days' => 30];
    }
    protected function tearDown(): void
    {
        if ($this->db->transactionLevel()) $this->db->rollBack(0); // Limpieza del doble; nunca oculta una aserción de nivel.
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver); else Model::unsetConnectionResolver();
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); \Illuminate\Container\Container::setInstance(null);
    }
    private function request(array $data): Request
    {
        $request = new class extends Request {
            public Factory $factory;
            public function validate(array $rules, array $messages = []): array { return $this->factory->make($this->all(), $rules, $messages)->validate(); }
        }; $request->factory = $this->validator; $request->replace($data); return $request;
    }
    private function physical(bool $ledger = true): Articulo
    {
        $this->db->rows['items'][10] = ['id' => 10, 'producto' => 'Synthetic units', 'type' => 'producto_venta', 'existencia_actual' => 5,
            'current_volume' => 0, 'is_fractionable' => 0, 'total_volume' => 0, 'unit_measure' => 'unidad', 'costo_promedio' => '3.000000', 'precio_c' => '999.00', 'deleted_at' => null, 'stock_min' => 0];
        $this->db->rows['lotes'][1] = ['id' => 1, 'item_id' => 10, 'purchase_invoice_line_id' => 80, 'cantidad_entrada' => 5, 'cantidad_disponible' => 5, 'costo_unitario' => '3.000000', 'fecha_entrada' => '2026-01-01'];
        if ($ledger) $this->db->rows['stock_ledger_entries'][1] = ['id' => 1, 'item_id' => 10, 'origen' => 'compra', 'movimiento' => 'entrada', 'referencia_tipo' => 'factura_compra', 'referencia_id' => 80,
            'cantidad' => 5, 'volumen' => null, 'valor' => 15, 'saldo_cantidad' => 5, 'saldo_valor' => 15, 'saldo_volumen' => null, 'costo_unitario' => 3, 'costo_promedio' => 3];
        return Articulo::findOrFail(10);
    }
    private function fault(string $operation, string $table, string $kind): void
    {
        $this->db->afterWrite = function ($op, $target) use ($operation, $table, $kind) {
            if ($op === $operation && $table === $target) {
                $this->reached[] = "$op:$target"; $this->visibleAtFailure = $this->db->rows;
                throw $kind === 'error' ? new \Error('h01.sentinel') : new \RuntimeException('h01.sentinel');
            }
        };
    }
    public function test_catalogue_creation_is_empty_and_ignores_protected_cost_and_marker(): void
    {
        (new InventarioController)->store($this->request(['producto' => 'Synthetic empty', 'precio_c' => '20.00', 'type' => 'producto_venta', 'costo_promedio' => 500, 'inventory_representation_version' => 2]));
        $item = $this->db->rows['items'][1]; self::assertSame(0, $item['existencia_actual']); self::assertSame(0, $item['current_volume']);
        self::assertArrayNotHasKey('costo_promedio', $item); self::assertArrayNotHasKey('inventory_representation_version', $item);
        self::assertSame([], $this->db->rows['stock_ledger_entries']); self::assertSame([], $this->db->rows['lotes']); self::assertSame([], $this->accounting);
    }
    public static function catalogueInputs(): array
    {
        $cases = []; foreach (['store','update'] as $method) foreach (['existencia_actual','current_volume'] as $field) foreach ([0, null, 9] as $value) $cases[] = [$method, $field, $value]; return $cases;
    }
    #[DataProvider('catalogueInputs')]
    public function test_direct_stock_http_input_is_rejected_without_any_effect(string $method, string $field, $value): void
    {
        $this->physical(); $before = $this->db->rows;
        try { (new InventarioController)->$method($this->request(['producto' => 'Synthetic', 'precio_c' => '20', 'type' => 'producto_venta', $field => $value]), 10); self::fail('Debe rechazar.'); }
        catch (ValidationException $e) { self::assertArrayHasKey($field, $e->errors()); }
        self::assertSame($before, $this->db->rows); self::assertSame(0, $this->db->transactionLevel());
    }
    public function test_commercial_update_keeps_physical_stock_lots_and_ledger(): void
    {
        $this->physical(); $before = $this->db->rows;
        (new InventarioController)->update($this->request(['producto' => 'Synthetic renamed', 'precio_c' => '40', 'costo_promedio' => 999]), 10);
        self::assertSame(5, $this->db->rows['items'][10]['existencia_actual']); self::assertSame('3.000000', $this->db->rows['items'][10]['costo_promedio']);
        self::assertSame($before['lotes'], $this->db->rows['lotes']); self::assertSame($before['stock_ledger_entries'], $this->db->rows['stock_ledger_entries']); self::assertSame([], $this->accounting);
    }
    public function test_legacy_opening_snapshots_known_cost_once_without_creating_stock_or_purchase(): void
    {
        $item = $this->physical(false); $before = $this->db->rows;
        $first = $this->db->transaction(fn () => StockLedger::registrarApertura($item, 70));
        $second = $this->db->transaction(fn () => StockLedger::registrarApertura($item, 70));
        self::assertNull($second); self::assertSame('apertura', $first->origen); self::assertSame('apertura', $first->referencia_tipo); self::assertSame(10, $first->referencia_id); self::assertSame(70, $first->usuario_id);
        self::assertSame(15.0, $first->valor); self::assertSame(5.0, $first->saldo_cantidad); self::assertCount(1, $this->db->rows['stock_ledger_entries']);
        self::assertSame($before['items'], $this->db->rows['items']); self::assertSame($before['lotes'], $this->db->rows['lotes']); self::assertSame([], $this->db->rows['purchase_invoices']); self::assertSame([], $this->accounting);
    }
    public function test_opening_uses_current_locked_article_instead_of_stale_argument(): void
    {
        $item = $this->physical(false); $this->db->rows['items'][10]['existencia_actual'] = 7; // Interleaving sintético previo al mutex.
        $entry = $this->db->transaction(fn () => StockLedger::registrarApertura($item, 70));
        self::assertSame(7.0, $entry->cantidad); self::assertSame(21.0, $entry->valor); self::assertSame(7, $this->db->rows['items'][10]['existencia_actual']);
    }
    public function test_empty_legacy_opening_is_only_a_zero_adjustment_not_new_initial_stock(): void
    {
        $item = $this->physical(false); $this->db->rows['items'][10]['existencia_actual'] = 0; $this->db->rows['lotes'] = [];
        $entry = $this->db->transaction(fn () => StockLedger::registrarApertura($item, 70));
        self::assertSame('ajuste', $entry->movimiento); self::assertSame('apertura', $entry->origen); self::assertSame(0.0, $entry->cantidad); self::assertSame(0.0, $entry->valor);
        self::assertSame(0, $this->db->rows['items'][10]['existencia_actual']); self::assertSame([], $this->accounting);
    }
    public function test_opening_http_does_not_accept_new_physical_amounts_as_authority_and_requires_item(): void
    {
        $this->physical(false); $before = $this->db->rows['items'];
        (new InventarioController)->registrarApertura($this->request(['item_id' => 10, 'cantidad' => 500, 'existencia_actual' => 500, 'costo' => 1, 'current_volume' => 900]));
        self::assertSame($before, $this->db->rows['items']); self::assertEquals(5, $this->db->rows['stock_ledger_entries'][1]['cantidad']); self::assertEquals(15, $this->db->rows['stock_ledger_entries'][1]['valor']);
        try { (new InventarioController)->registrarApertura($this->request(['cantidad' => 500])); self::fail('Sin artículo de origen.'); }
        catch (ValidationException $e) { self::assertArrayHasKey('item_id', $e->errors()); }
        self::assertCount(1, $this->db->rows['stock_ledger_entries']);
    }
    public function test_opening_locks_actor_before_article_and_ledger(): void
    {
        $item = $this->physical(false); $start = count($this->db->trace);
        $this->db->transaction(fn () => StockLedger::registrarApertura($item, 70));
        $locks = array_values(array_filter(array_slice($this->db->trace, $start), fn ($q) => is_array($q) && (str_contains($q[0], 'for update') || str_contains($q[0], 'lock in share mode'))));
        self::assertStringContainsString('from `users`', $locks[0][0]); self::assertStringContainsString('from `items`', $locks[1][0]); self::assertStringContainsString('from `stock_ledger_entries`', $locks[2][0]);
    }
    public function test_opening_requires_transaction_and_keeps_v2_blocked(): void
    {
        $item = $this->physical(false);
        try { StockLedger::registrarApertura($item, 70); self::fail('Sin transacción.'); } catch (\LogicException $e) { self::assertStringContainsString('transacción', $e->getMessage()); }
        $this->db->rows['items'][10]['inventory_representation_version'] = 2;
        try { $this->db->transaction(fn () => StockLedger::registrarApertura($item, 70)); self::fail('V2.'); } catch (\App\Exceptions\ContabilidadException $e) { self::assertStringContainsString('versión 2', $e->getMessage()); }
        self::assertSame([], $this->db->rows['stock_ledger_entries']);
    }
    public static function failures(): array { return [['error'], ['exception']]; }
    #[DataProvider('failures')]
    public function test_opening_controller_rolls_back_a_failure_after_ledger_insert(string $kind): void
    {
        $this->physical(false); $before = $this->db->rows; $this->fault('insert', 'stock_ledger_entries', $kind);
        $escaped = null;
        try { (new InventarioController)->registrarApertura($this->request(['item_id' => 10])); } catch (\Throwable $e) { $escaped = $e; }
        self::assertNull($escaped, 'Throwable debe cerrar la transacción de apertura.'); self::assertSame(['insert:stock_ledger_entries'], $this->reached);
        self::assertCount(1, $this->visibleAtFailure['stock_ledger_entries']);
        self::assertSame(0, $this->db->transactionLevel()); self::assertSame($before, $this->db->rows); self::assertSame([], $this->accounting);
    }
    public static function mermaFailures(): array { return [['update','items','error'], ['insert','stock_ledger_entries','error'], ['insert','stock_ledger_entries','exception']]; }
    #[DataProvider('mermaFailures')]
    public function test_real_merma_controller_closes_and_restores_stock_lots_and_document_on_failure(string $op, string $table, string $kind): void
    {
        $this->physical(); $before = $this->db->rows; $this->fault($op, $table, $kind); $escaped = null;
        try { (new MermaController)->store($this->request(['item_id' => 10, 'fecha' => '2026-11-21', 'tipo' => 'unidad', 'cantidad' => 1, 'motivo' => 'Synthetic damaged item'])); } catch (\Throwable $e) { $escaped = $e; }
        self::assertNull($escaped, $escaped?->getMessage() ?? ''); self::assertSame(["$op:$table"], $this->reached); self::assertSame(0, $this->db->transactionLevel());
        self::assertEquals(4, $this->visibleAtFailure['items'][10]['existencia_actual']);
        self::assertCount($table === 'stock_ledger_entries' ? 2 : 1, $this->visibleAtFailure['stock_ledger_entries']);
        self::assertSame($before, $this->db->rows); self::assertSame([], $this->accounting);
    }
    public function test_documented_merma_has_source_and_keeps_original_movement(): void
    {
        $this->physical(); $original = $this->db->rows['stock_ledger_entries'][1];
        (new MermaController)->store($this->request(['item_id' => 10, 'fecha' => '2026-11-21', 'tipo' => 'unidad', 'cantidad' => 1, 'motivo' => 'Synthetic damaged item']));
        self::assertSame(4, $this->db->rows['items'][10]['existencia_actual']); self::assertSame(4.0, (float)$this->db->rows['lotes'][1]['cantidad_disponible']);
        $entry = $this->db->rows['stock_ledger_entries'][2]; self::assertSame('merma', $entry['origen']); self::assertSame(1, $entry['referencia_id']); self::assertEquals(-3, $entry['valor']);
        self::assertSame($original, $this->db->rows['stock_ledger_entries'][1]); self::assertSame(['mermas'], $this->accounting);
    }
    public static function ownerLevels(): array { return [[0], [1]]; }
    #[DataProvider('ownerLevels')]
    public function test_merma_rolls_back_to_its_owner_level_when_accounting_leaks_a_nested_error(int $outerLevel): void
    {
        $this->physical(); if ($outerLevel) $this->db->beginTransaction();
        $before = $this->db->rows; $this->accountingError = true;
        (new MermaController)->store($this->request(['item_id' => 10, 'fecha' => '2026-11-21', 'tipo' => 'unidad', 'cantidad' => 1, 'motivo' => 'Synthetic damaged item']));
        self::assertSame(['mermas'], $this->accounting); self::assertContains('insert:stock_ledger_entries', $this->db->trace);
        self::assertSame($outerLevel, $this->db->transactionLevel()); self::assertSame($before, $this->db->rows);
    }
    public function test_purchase_service_records_stock_lot_moving_average_and_referenced_ledger(): void
    {
        $this->physical();
        $invoice = (new CompraService)->registrarFactura(['provider_id' => 20, 'condicion_pago' => 'credito', 'numero_documento' => 'H01-SYNTHETIC', 'fecha_factura' => '2026-11-21',
            'articulos' => [['id' => 10, 'cantidad' => 5, 'costo' => '7.00']]], 70);
        self::assertSame(10.0, $this->db->rows['items'][10]['existencia_actual']); self::assertSame(5.0, $this->db->rows['items'][10]['costo_promedio']); self::assertCount(2, $this->db->rows['lotes']);
        $entry = $this->db->rows['stock_ledger_entries'][2]; self::assertSame('compra', $entry['origen']); self::assertSame('factura_compra', $entry['referencia_tipo']); self::assertSame($invoice->id, $entry['referencia_id']);
        self::assertEquals(50, $entry['saldo_valor']); self::assertSame(['purchase_invoices'], $this->accounting); self::assertCount(1, $this->db->rows['cuentas_por_pagar']); self::assertSame(0, $this->db->transactionLevel());
    }
    public function test_unknown_opening_policy_cannot_confirm_and_shared_gate_stays_closed(): void
    {
        foreach (['getPdo', 'getReadPdo'] as $method) {
            try { $this->db->$method(); self::fail('No debe resolver PDO.'); } catch (\LogicException $e) { self::assertStringContainsString('PDO prohibido', $e->getMessage()); }
        }
        self::assertFalse(app(PrestacionCompartidaService::class)->disponible()); self::assertFalse(\App\Services\CapturaConsumoService::activa());
        $this->expectException(\LogicException::class); (new OpeningDocumentContract)->assertConfirmationUnavailable();
    }
}
