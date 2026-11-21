<?php

namespace Tests\Unit;

use App\Exceptions\ContabilidadException;
use App\Http\Controllers\VentaController;
use App\Models\{Cita, DetalleVenta, EsquemaComision, EsquemaRango, Usuario, Venta};
use App\Services\{AgendaService, AprobacionService, CapturaConsumoService, CarritoVentaService, ComisionService, NotificationService, PrestacionCompartidaService};
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\{Collection, Model};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\{DateFactory, Facades\Facade};
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\{Factory, PresenceVerifierInterface};
use Mockery;
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunTestsInSeparateProcesses};
use PHPUnit\Framework\TestCase;
use Tests\Support\H06SaleMemory;

/** Controlador, carrito H05, fotografía H03, comisiones y libro reales sobre memoria sin PDO.
 * La capacidad H03 es un doble explícito: no habilita V2 ni demuestra inventario/rollback InnoDB.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PrestacionCompartidaTest extends TestCase
{
    private H06SaleMemory $db;
    private VentaController $controller;
    private Factory $validator;
    private $previousResolver;
    private bool $held = false;
    private ?string $fail = null;
    private int $stylists = 2;
    private array $reached = [];
    private const KEY = '7a46b294-7c3d-4adc-a9c4-7e278170a001';
    private const PERFORMANCE = '7a46b294-7c3d-4adc-a9c4-7e278170b001';

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 2)); $app->instance('env', 'testing');
        $app->instance('config', new Repository(['salon' => ['shared_services' => ['enabled' => true, 'sql_verified' => true], 'inventory_fractional_v2' => false]]));
        $app->instance('date', new DateFactory); Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = new H06SaleMemory(true); $app->instance('db', $this->db);
        $this->previousResolver = Model::getConnectionResolver(); $resolver = new ConnectionResolver(['mysql' => $this->db]); $resolver->setDefaultConnection('mysql'); Model::setConnectionResolver($resolver);
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Factory::class);
        $auth->shouldReceive('id')->andReturn(70); $auth->shouldReceive('user')->andReturn((object)['role' => 'admin']);
        $app->instance('auth', $auth); $app->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
        $this->validator = new Factory(new Translator(new ArrayLoader, 'es'));
        $presence = Mockery::mock(PresenceVerifierInterface::class); $presence->shouldReceive('getCount')->andReturn(1);
        $this->validator->setPresenceVerifier($presence); $app->instance('validator', $this->validator);
        $responses = Mockery::mock(ResponseFactory::class); $responses->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new JsonResponse($data, $status)); $app->instance(ResponseFactory::class, $responses);
        $log = Mockery::mock(); $log->shouldReceive('info', 'warning', 'error')->andReturnUsing(function (...$args) { $this->reached['log'][] = $args; }); $app->instance('log', $log);
        $app->instance(PrestacionCompartidaService::class, new H06SharedCapability);
        $app->instance(CarritoVentaService::class, new H06SharedCart);
        $agenda = Mockery::mock(AgendaService::class);
        $agenda->shouldReceive('bloquearCitas', 'validarParaFacturar', 'validarVinculosDocumentales')->andReturnUsing(function () {
            $cita = new Cita; $cita->setRawAttributes(['id' => 7, 'client_id' => 50, 'service_id' => 20, 'status' => 'pendiente'], true);
            $users = []; for ($id = 1; $id <= $this->stylists; ++$id) { $u = new Usuario; $u->setRawAttributes($this->db->rows['users'][$id], true); $users[] = $u; }
            return collect([7 => $cita->setRelation('estilistas', new Collection($users))]);
        });
        $agenda->shouldReceive('bloquearVenta')->andReturnUsing(fn ($id) => Venta::findOrFail($id));
        $agenda->shouldReceive('completarDesdeVenta')->andReturnUsing(function () { $this->db->effects['appointment'] = true; }); $app->instance(AgendaService::class, $agenda);
        $approval = Mockery::mock(AprobacionService::class); $approval->shouldReceive('requiereAprobacionVenta')->andReturnUsing(fn () => $this->held); $app->instance(AprobacionService::class, $approval);
        $inventory = Mockery::mock('overload:App\Services\InventarioService');
        $inventory->shouldReceive('bloquearCarrito')->andReturnUsing(fn ($cart) => array_map(fn ($line) => app(CapturaConsumoService::class)->foto($line), $cart));
        $inventory->shouldReceive('descontarPorVenta')->andReturnUsing(function ($sale) {
            app(PrestacionCompartidaService::class)->validarDocumento($sale);
            foreach ($sale->detalles as $d) if ($d->service_id) foreach ($d->consumption_snapshot['ingredients'] as $i) $this->db->effects['physical'][] = ['item' => $i['item_id'], 'amount' => $i['actual'], 'quantity' => $d->quantity];
            $this->stage('inventory'); return [];
        });
        $fx = Mockery::mock('overload:App\Services\DivisaService'); $fx->shouldReceive('ingresarDivisas', 'egresarDivisas')->andReturnUsing(function () { $this->stage('payments'); });
        $account = Mockery::mock('overload:App\Services\ContabilidadService');
        $account->shouldReceive('contabilizarVenta', 'contabilizarVentaCredito')->andReturnUsing(function () { $this->stage('accounting'); }); $account->shouldReceive('notificaciones')->andReturn([]);
        $app->instance(ComisionService::class, new H06CommissionSentinel(function () { $this->stage('commissions'); }));
        $notifications = Mockery::mock(NotificationService::class); $notifications->shouldReceive('createForCashier')->andReturnNull(); $app->instance(NotificationService::class, $notifications);
        $this->controller = new H06SharedController($notifications);
        $this->db->rows['cash_sessions'][1] = ['id' => 1, 'user_id' => 70, 'estado' => 'abierta'];
        foreach ([1 => '10', 2 => '15', 3 => '20', 70 => '0'] as $id => $pct) $this->db->rows['users'][$id] = ['id' => $id, 'name' => 'Synthetic '.$id, 'comision_servicio' => $pct];
        $this->db->rows['services'][20] = ['id' => 20, 'name' => 'Synthetic service', 'is_active' => 1, 'price' => '1000.00'];
        $this->db->rows['items'][30] = ['id' => 30, 'producto' => 'Synthetic input', 'type' => 'producto_interno', 'is_fractionable' => false, 'unit_measure' => 'unidad', 'total_volume' => null, 'deleted_at' => null, 'precio_c' => '20.00'];
        $this->db->rows['service_formulas'][1] = ['id' => 1, 'service_id' => 20, 'item_id' => 30, 'quantity_used' => '2.00', 'deleted_at' => null];
        $this->db->rows['service_variants'][4] = ['id' => 4, 'service_id' => 20, 'name' => 'Synthetic variant', 'price' => '1200.00', 'is_active' => 1];
        $this->db->rows['clients'][50] = ['id' => 50, 'credit_limit' => null, 'credit_days' => 30];
    }
    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver); else Model::unsetConnectionResolver();
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); \Illuminate\Container\Container::setInstance(null);
    }
    private function stage(string $name): void
    {
        $this->reached[$name] = true; $this->db->effects[$name] = true;
        if ($this->fail === $name) { $this->db->beginTransaction(); throw new \Error('h06p1.sentinel.'.$name); }
    }
    private function request(array $data): Request
    {
        $r = new class extends Request { public Factory $factory; public function validate(array $rules, ...$params): array { return $this->factory->make($this->all(), $rules)->validate(); } };
        $r->factory = $this->validator; $r->replace($data); return $r;
    }
    private function payload(array $line = [], array $root = []): array
    {
        return array_replace(['service_contract_version' => 1, 'creation_key' => self::KEY, 'action' => 'pagar', 'discount' => '0.00',
            'cart' => [array_replace(['id' => 20, 'type' => 'servicio', 'quantity' => 1, 'precio_c' => '1000.00', 'performance_key' => self::PERFORMANCE, 'participants' => [1,2]], $line)]], $root);
    }
    private function submit(array $data, int $expected = 201): JsonResponse
    {
        $response = $this->controller->store($this->request($data));
        self::assertSame($expected, $response->getStatusCode(), json_encode([$response->getData(true), $this->reached['log'] ?? []])); return $response;
    }
    public static function counts(): array { return [[1, ['100.00']], [2, ['100.00','150.00']], [3, ['100.00','150.00','200.00']]]; }
    #[DataProvider('counts')]
    public function test_one_canonical_line_and_full_gross_commission_for_each_participant(int $n, array $amounts): void
    {
        $this->submit($this->payload(['participants' => range(1, $n)]));
        self::assertCount(1, $this->db->rows['sale_details']); self::assertCount($n, $this->db->rows['service_participations']);
        self::assertSame('1000.00', $this->db->rows['sales'][1]['total']); self::assertCount(1, $this->db->effects['physical']);
        self::assertSame($amounts, array_column($this->db->rows['comisiones_generadas'], 'monto_comision'));
        self::assertSame(array_fill(0, $n, '1000.00'), array_column($this->db->rows['comisiones_generadas'], 'monto_venta')); self::assertSame(0, $this->db->transactionLevel());
    }
    public function test_discount_does_not_reduce_gross_commission(): void
    {
        $this->submit($this->payload([], ['discount' => '100.00']));
        self::assertSame('900.00', $this->db->rows['sales'][1]['total']); self::assertSame(['100.00','150.00'], array_column($this->db->rows['comisiones_generadas'], 'monto_comision'));
    }
    public function test_homogeneous_quantity_scales_recipe_and_base_without_scaling_participation(): void
    {
        $this->submit($this->payload(['quantity' => 2])); self::assertCount(1, $this->db->rows['sale_details']);
        self::assertSame(2, $this->db->effects['physical'][0]['quantity']); self::assertSame('2.00', $this->db->effects['physical'][0]['amount']);
        self::assertSame(['200.00','300.00'], array_column($this->db->rows['comisiones_generadas'], 'monto_comision'));
    }
    public function test_mixed_product_and_two_independent_services(): void
    {
        $data = $this->payload(); $second = $data['cart'][0]; $second['performance_key'] = '7a46b294-7c3d-4adc-a9c4-7e278170b002'; $second['participants'] = [3];
        $data['cart'][] = $second; $data['cart'][] = ['id' => 30, 'type' => 'producto', 'quantity' => 1, 'precio_c' => '20.00'];
        $this->submit($data); self::assertCount(3, $this->db->rows['sale_details']); self::assertSame('2020.00', $this->db->rows['sales'][1]['total']); self::assertCount(2, $this->db->effects['physical']);
    }
    public function test_same_participants_can_perform_two_distinct_services(): void
    {
        $data = $this->payload(); $data['cart'][] = $data['cart'][0]; $data['cart'][1]['performance_key'] = '7a46b294-7c3d-4adc-a9c4-7e278170b002';
        $this->submit($data); self::assertCount(4, $this->db->rows['comisiones_generadas']); self::assertSame('2000.00', $this->db->rows['sales'][1]['total']);
    }
    public function test_credit_has_no_invented_payments(): void
    {
        $this->submit($this->payload([], ['action' => 'credito', 'client_id' => 50]));
        self::assertSame('emitida', $this->db->rows['sales'][1]['status']); self::assertSame([], $this->db->rows['pagos']); self::assertCount(2, $this->db->rows['comisiones_generadas']);
    }
    public function test_complete_zero_photo_keeps_explicit_zero(): void
    {
        $this->submit($this->payload(['consumo' => [['item_id' => 30, 'cantidad' => '0']]]));
        self::assertSame('0.00', $this->db->effects['physical'][0]['amount']); self::assertSame('0.00', $this->db->rows['sale_detail_consumptions'][1]['quantity_actual']);
        self::assertSame('complete', json_decode($this->db->rows['sale_details'][1]['consumption_snapshot'], true)['mode']);
    }
    public function test_variant_is_charged_once_and_snapshotted(): void
    {
        $this->submit($this->payload(['variant_id' => 4, 'precio_c' => '1200.00'])); self::assertSame('1200.00', $this->db->rows['sales'][1]['total']);
        self::assertSame('Synthetic variant', json_decode($this->db->rows['sale_details'][1]['commercial_identity_snapshot'], true)['variant_name']);
    }
    public static function invalidLines(): array { return [
        'price' => [['precio_c' => '500.00']], 'duplicate participants' => [['participants' => [1,1]]], 'participant missing' => [['participants' => [900]]],
        'legacy stylist' => [['stylist_id' => 1]], 'unknown variant' => [['variant_id' => 900]], 'zero quantity' => [['quantity' => 0]],
        'contradictory captures' => [['participant_consumptions' => [[1,2],[3,4]]]],
        'duplicate input' => [['consumo' => [['item_id' => 30, 'cantidad' => 1],['item_id' => 30, 'cantidad' => 0]]]],
    ]; }
    #[DataProvider('invalidLines')]
    public function test_invalid_input_creates_no_effects(array $line): void
    {
        $before = $this->db->rows;
        try { $this->submit($this->payload($line), 422); }
        catch (\Illuminate\Validation\ValidationException $e) { self::assertNotEmpty($e->errors()); } // Handler HTTP de Laravel: 422.
        self::assertSame($before, $this->db->rows); self::assertSame([], $this->db->effects); self::assertSame(0, $this->db->transactionLevel());
    }
    public function test_same_creation_key_replays_but_different_intent_conflicts(): void
    {
        $data = $this->payload(); $this->submit($data); $before = $this->db->rows;
        $response = $this->submit($data, 200); self::assertTrue($response->getData(true)['replayed']);
        $this->submit($this->payload([], ['discount' => '1.00']), 409); self::assertSame($before, $this->db->rows);
        $this->submit($this->payload([], ['requiere_aprobacion' => true]), 409); self::assertSame($before, $this->db->rows);
    }
    public function test_commission_precision_rejects_before_physical_or_financial_effects(): void
    {
        $this->db->rows['services'][20]['price'] = '99999999.99'; $this->db->rows['users'][1]['comision_servicio'] = '200.00';
        $before = $this->db->rows; $this->submit($this->payload(['precio_c' => '99999999.99', 'participants' => [1]]), 422);
        self::assertSame($before, $this->db->rows); self::assertSame([], $this->db->effects); self::assertArrayNotHasKey('inventory', $this->reached);
    }
    public function test_display_metadata_and_participant_order_are_not_new_intentions(): void
    {
        $s = app(PrestacionCompartidaService::class);
        self::assertSame($s->entrada($this->payload())['hash'], $s->entrada($this->payload(['participants' => [2,1], 'producto' => 'display only', 'participants_display' => ['ignored']]))['hash']);
    }
    public function test_draft_edit_preserves_detail_identity_and_revision_and_requires_explicit_replacement(): void
    {
        $data = $this->payload([], ['action' => 'cotizar']); $this->submit($data); self::assertSame([], $this->db->effects);
        $data['draft_id'] = 1; $data['document_revision'] = 1; $data['cart'][0]['participants'] = [3]; $this->submit($data);
        self::assertSame([1], array_keys($this->db->rows['sale_details'])); self::assertSame(2, $this->db->rows['sales'][1]['document_revision']);
        $before = $this->db->rows; $this->submit($data, 409); self::assertSame($before, $this->db->rows);
        $data['document_revision'] = 2; $data['cart'][0]['performance_key'] = '7a46b294-7c3d-4adc-a9c4-7e278170b002';
        $this->submit($data, 409); self::assertSame($before, $this->db->rows);
    }
    public function test_draft_can_be_applied_once_using_same_canonical_identity(): void
    {
        $this->submit($this->payload([], ['action' => 'cotizar']));
        $this->submit($this->payload([], ['draft_id' => 1, 'document_revision' => 1]));
        self::assertSame('pagada', $this->db->rows['sales'][1]['status']); self::assertCount(1, $this->db->rows['sale_details']); self::assertCount(2, $this->db->rows['comisiones_generadas']);
        $this->submit($this->payload([], ['draft_id' => 1, 'document_revision' => 2]), 422);
    }
    public function test_retained_document_has_no_effects_until_real_approval_service(): void
    {
        $this->held = true; $this->submit($this->payload()); self::assertSame([], $this->db->effects); self::assertSame([], $this->db->rows['comisiones_generadas']);
        self::assertTrue($this->submit($this->payload(), 200)->getData(true)['pendiente_aprobacion']);
        (new AprobacionService)->aprobarVenta(1, 70);
        self::assertSame('pagada', $this->db->rows['sales'][1]['status']); self::assertCount(2, $this->db->rows['comisiones_generadas']); self::assertCount(1, $this->db->rows['pagos']);
    }
    #[DataProvider('stages')]
    public function test_approval_fault_restores_retained_proposal_in_memory(string $stage): void
    {
        $this->held = true;
        $this->submit($this->payload([], ['payments' => [['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => '25', 'tasa' => '40']]]));
        $before = $this->db->rows; $this->fail = $stage;
        try { (new AprobacionService)->aprobarVenta(1, 70); self::fail('Centinela esperado.'); }
        catch (\Error $e) { self::assertSame('h06p1.sentinel.'.$stage, $e->getMessage()); }
        self::assertTrue($this->reached[$stage] ?? false); self::assertSame($before, $this->db->rows); self::assertSame([], $this->db->effects); self::assertSame(0, $this->db->transactionLevel());
    }
    public function test_real_approval_mutex_locks_participants_before_sale_without_appointment(): void
    {
        $this->held = true; $this->submit($this->payload());
        // Sustituir solo el despacho de bloqueo: consulta y orden del caso real.
        app()->instance(AgendaService::class, new AgendaService);
        $start = count($this->db->trace);
        $this->db->beginTransaction();
        try { $sale = app(AgendaService::class)->bloquearVenta(1, true); self::assertSame(1, $sale->id); }
        finally { $this->db->rollBack(0); }
        $locks = array_values(array_filter(array_slice($this->db->trace, $start), fn ($q) => str_contains($q, 'for update')));
        self::assertCount(4, $locks);
        self::assertStringContainsString('from `users`', $locks[0]); self::assertStringContainsString('from `users`', $locks[1]); self::assertStringContainsString('from `users`', $locks[2]);
        self::assertStringContainsString('from `sales`', $locks[3]); // Llamadas simuladas, no serialización InnoDB.
        self::assertSame(0, $this->db->transactionLevel());
    }
    public function test_zero_commission_has_explicit_resolution_without_economic_row(): void
    {
        $this->submit($this->payload(['participants' => [70]])); self::assertSame([], $this->db->rows['comisiones_generadas']);
        self::assertSame('0.00', json_decode($this->db->rows['service_participations'][1]['commission_resolution_snapshot'], true)['amount']);
    }
    public function test_duplicate_commission_and_conflicting_photo_projection_reject(): void
    {
        $this->submit($this->payload(['consumo' => [['item_id' => 30, 'cantidad' => 0]]]));
        $sale = Venta::findOrFail(1); $before = $this->db->rows;
        $this->db->beginTransaction();
        try {
            app(ComisionService::class)->prepararParticipaciones($sale);
            try { app(ComisionService::class)->registrarPorVenta($sale); self::fail('Duplicidad.'); } catch (ContabilidadException $e) { self::assertStringContainsString('dos veces', $e->getMessage()); }
        } finally { $this->db->rollBack(0); }
        self::assertSame($before, $this->db->rows);
        $this->db->rows['sale_detail_consumptions'][1]['quantity_actual'] = '1.00';
        $this->expectException(ContabilidadException::class); app(PrestacionCompartidaService::class)->validarDocumento(Venta::findOrFail(1));
    }
    public function test_commercial_identity_survives_deleted_variant_and_changed_catalogue(): void
    {
        $this->submit($this->payload(['variant_id' => 4, 'precio_c' => '1200.00']));
        $this->db->rows['sale_details'][1]['service_variant_id'] = null; $this->db->rows['services'][20]['name'] = 'Changed';
        $detail = DetalleVenta::findOrFail(1); self::assertSame('Synthetic service (Synthetic variant)', $detail->nombreComercial());
        app(PrestacionCompartidaService::class)->validarDocumento(Venta::findOrFail(1));
    }
    public function test_two_full_price_appointment_lines_are_not_new_executions(): void
    {
        $data = $this->payload(['appointment_id' => 7]); $data['cart'][] = $data['cart'][0]; $data['cart'][1]['performance_key'] = '7a46b294-7c3d-4adc-a9c4-7e278170b002';
        $this->submit($data, 422); self::assertSame([], $this->db->rows['sales']);
    }
    public function test_draft_cannot_be_converted_between_legacy_and_new_contracts(): void
    {
        $this->submit($this->payload([], ['action' => 'cotizar'])); $before = $this->db->rows;
        $legacy = $this->payload([], ['draft_id' => 1]); unset($legacy['service_contract_version'], $legacy['creation_key']); unset($legacy['cart'][0]['performance_key'], $legacy['cart'][0]['participants']);
        $this->submit($legacy, 422); self::assertSame($before, $this->db->rows);
    }
    public function test_unknown_contract_version_rejects_before_persistence(): void
    {
        $this->submit($this->payload([], ['service_contract_version' => 2]), 422); self::assertSame([], $this->db->rows['sales']); self::assertSame(0, $this->db->transactionLevel());
    }
    public static function stages(): array { return [['inventory'], ['payments'], ['accounting'], ['commissions']]; }
    #[DataProvider('stages')]
    public function test_fault_after_effect_restores_memory_and_closes_nested_levels(string $stage): void
    {
        $this->fail = $stage; $before = $this->db->rows;
        $this->submit($this->payload([], ['payments' => [['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => '25', 'tasa' => '40']]]), 500);
        self::assertTrue($this->reached[$stage] ?? false); self::assertSame($before, $this->db->rows); self::assertSame([], $this->db->effects); self::assertSame(0, $this->db->transactionLevel());
        if ($stage === 'commissions') self::assertContains('insert:comisiones_generadas', $this->db->trace);
    }
    public function test_direct_route_uses_same_store_contract_and_replays(): void
    {
        $input = ['service_contract_version' => 1, 'creation_key' => self::KEY, 'performance_key' => self::PERFORMANCE];
        $r = $this->controller->facturarCita($this->request($input), 7); self::assertSame(201, $r->getStatusCode(), json_encode([$r->getData(true), $this->reached]));
        self::assertCount(1, $this->db->rows['sale_details']); self::assertSame(7, $this->db->rows['sale_details'][1]['appointment_id']);
        self::assertTrue($this->controller->facturarCita($this->request($input), 7)->getData(true)['replayed']);
        self::assertSame(409, $this->controller->facturarCita($this->request($input), 8)->getStatusCode());
        self::assertCount(1, $this->db->rows['sales']);
    }
    public function test_closed_gate_and_h03_dependency_do_not_fall_back(): void
    {
        config(['salon.shared_services.enabled' => false]); $this->submit($this->payload(), 422); self::assertSame([], $this->db->rows['sales']);
        config(['salon.shared_services.enabled' => true]); app()->instance(PrestacionCompartidaService::class, new PrestacionCompartidaService);
        $this->submit($this->payload(), 422); self::assertFalse(CapturaConsumoService::activa()); self::assertSame([], $this->db->rows['sales']);
    }
    public function test_existing_scheme_and_range_precedence_with_decimal_boundaries(): void
    {
        $u = new Usuario; $u->setRawAttributes(['id' => 1, 'comision_servicio' => '99']);
        $s = new EsquemaComision; $s->setRawAttributes(['id' => 1, 'porcentaje_comision' => '15']);
        $r = new EsquemaRango; $r->setRawAttributes(['id' => 2, 'desde' => '1000', 'hasta' => '2000', 'porcentaje' => '12.50', 'orden' => 1]); $s->setRelation('rangos', new Collection([$r]));
        $service = new H06CommissionSentinel(fn () => null);
        self::assertSame(1250, $service->resolve($u, $s, 100000)['rate']); self::assertSame(1500, $service->resolve($u, $s, 99999)['rate']); self::assertSame(9900, $service->resolve($u, null, 100000)['rate']);
    }
}

final class H06SharedCapability extends PrestacionCompartidaService { protected function composicionDisponible(): bool { return true; } }
final class H06SharedCart extends CarritoVentaService { protected function cita(int $id): ?Cita { return app(AgendaService::class)->bloquearCitas([$id])->get($id); } }
final class H06SharedController extends VentaController { protected function notifyVentaPendiente(Venta $sale): void {} }
final class H06CommissionSentinel extends ComisionService
{
    public function __construct(private \Closure $after) {}
    public function registrarPorVenta(Venta $sale): void { parent::registrarPorVenta($sale); ($this->after)(); }
    public function resolve(Usuario $u, $scheme, int $base): array { return $this->resolver($u, $scheme, $base); }
}
