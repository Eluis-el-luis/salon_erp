<?php

namespace Tests\Unit;

use App\Http\Controllers\VentaController;
use App\Models\{Cita, Usuario, Venta};
use App\Services\{AgendaService, AprobacionService, ComisionService, NotificationService};
use App\Services\CarritoVentaService;
use App\Support\InventoryDecimal as D;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\{Collection, Model};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\{DateFactory, Facades\Facade};
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\PresenceVerifierInterface;
use Mockery;
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunTestsInSeparateProcesses};
use PHPUnit\Framework\TestCase;
use Tests\Support\H06SaleMemory;

/** Controlador y LibroPagosService reales; inventario/asiento/divisas son dobles explícitos. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FacturacionCitaPaymentsTest extends TestCase
{
    private H06SaleMemory $db;
    private H06DirectController $controller;
    private bool $held = false;
    private ?string $fail = null;
    private int $stylists = 1;
    private $previousResolver;

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 2)); $app->instance('env', 'local');
        $app->instance('config', new Repository(['salon' => ['inventory_fractional_v2' => false]]));
        $app->instance('date', new DateFactory);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = new H06SaleMemory; $app->instance('db', $this->db);
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['mysql' => $this->db]); $resolver->setDefaultConnection('mysql'); Model::setConnectionResolver($resolver);
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Factory::class);
        $auth->shouldReceive('id')->andReturn(70); $auth->shouldReceive('user')->andReturn((object)['role' => 'admin']);
        $app->instance('auth', $auth);
        $app->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
        $log = Mockery::mock(); $log->shouldReceive('info', 'warning', 'error'); $app->instance('log', $log);
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new JsonResponse($data, $status)); $app->instance(ResponseFactory::class, $responses);
        $agenda = Mockery::mock(AgendaService::class);
        $agenda->shouldReceive('validarParaFacturar')->andReturnUsing(function () {
            $cita = new Cita; $cita->setRawAttributes(['id' => 7, 'client_id' => 50, 'service_id' => 20, 'status' => 'pendiente'], true);
            $users = [];
            for ($i = 1; $i <= $this->stylists; ++$i) { $u = new Usuario; $u->setRawAttributes(['id' => $i]); $users[] = $u; }
            return collect([7 => $cita->setRelation('estilistas', new Collection($users))]);
        });
        $agenda->shouldReceive('completarDesdeVenta')->andReturnUsing(function ($ids, $sale) { $this->db->effects['appointment'] = [$ids, $sale]; });
        $app->instance(AgendaService::class, $agenda);
        $approval = Mockery::mock(AprobacionService::class); $approval->shouldReceive('requiereAprobacionVenta')->andReturnUsing(fn () => $this->held);
        $app->instance(AprobacionService::class, $approval);
        $inventory = Mockery::mock('overload:App\Services\InventarioService');
        $inventory->shouldReceive('descontarPorVenta')->andReturnUsing(function ($sale) { $this->effect('inventory', count($sale->detalles)); return []; });
        $fx = Mockery::mock('overload:App\Services\DivisaService');
        $fx->shouldReceive('ingresarDivisas', 'egresarDivisas')->andReturnUsing(function (...$args) { $this->effect('fx', $args); });
        $accounting = Mockery::mock('overload:App\Services\ContabilidadService');
        $accounting->shouldReceive('contabilizarVenta')->andReturnUsing(function ($sale) { $this->effect('accounting', $sale->total); });
        $accounting->shouldReceive('notificaciones')->andReturn([]);
        $commission = Mockery::mock(ComisionService::class);
        $commission->shouldReceive('registrarPorVenta')->andReturnUsing(function ($sale) { $this->effect('commission', $sale->id); }); $app->instance(ComisionService::class, $commission);
        $this->controller = new H06DirectController(Mockery::mock(NotificationService::class));
        $this->db->rows['cash_sessions'][1] = ['id' => 1, 'user_id' => 70, 'estado' => 'abierta'];
        $this->db->rows['services'][20] = ['id' => 20, 'is_active' => true, 'price' => '100.01'];
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver); else Model::unsetConnectionResolver();
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); \Illuminate\Container\Container::setInstance(null);
    }
    private function effect(string $stage, $value): void
    {
        $this->db->effects[$stage][] = $value;
        if ($this->fail === $stage) throw new \Error('h06.sentinel.'.$stage);
    }
    private function request(array $data = []): Request
    {
        $request = new class extends Request {
            public Factory $factory;
            public function validate(array $rules, ...$params): array { return $this->factory->make($this->all(), $rules)->validate(); }
        };
        $request->factory = new Factory(new Translator(new ArrayLoader, 'es'));
        $presence = Mockery::mock(PresenceVerifierInterface::class); $presence->shouldReceive('getCount')->andReturn(1);
        $request->factory->setPresenceVerifier($presence);
        $request->replace($data); return $request;
    }
    public static function participants(): array { return [[1, ['100.01']], [2, ['50.01', '50.00']], [3, ['33.34', '33.34', '33.33']]]; }

    #[DataProvider('participants')]
    public function test_direct_nio_uses_existing_payment_book_and_exact_cent_shares(int $count, array $prices): void
    {
        $this->stylists = $count;
        $response = $this->controller->facturarCita($this->request(['subtotal' => 1, 'total' => 1, 'price' => 1, 'client_id' => 999]), 7);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($prices, array_values(array_column($this->db->rows['sale_details'], 'unit_price')));
        self::assertSame('100.01', $this->db->rows['sales'][1]['total']); self::assertSame(50, $this->db->rows['sales'][1]['client_id']);
        self::assertEquals(100.01, $this->db->rows['pagos'][1]['valor_nio']); self::assertSame(7, $this->db->rows['sales'][1]['appointment_id']);
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_usd_uses_amount_really_received_and_keeps_nio_total(): void
    {
        $response = $this->controller->facturarCita($this->request(['payments' => [['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => '3.00', 'tasa' => '40']], 'change_currency' => 'nio']), 7);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('100.01', $this->db->rows['sales'][1]['total']);
        self::assertEquals(3, $this->db->rows['pagos'][1]['monto']); self::assertEquals(120, $this->db->rows['pagos'][1]['valor_nio']);
        self::assertSame('vuelto', $this->db->rows['pagos'][2]['tipo']); self::assertEquals(19.99, $this->db->rows['pagos'][2]['valor_nio']);
        self::assertSame(3.0, $this->db->effects['fx'][0][3]);
    }

    public function test_ambiguous_legacy_usd_requires_explicit_receipt_without_effects(): void
    {
        $response = $this->controller->facturarCita($this->request(['currency' => 'usd', 'exchange_rate' => 40]), 7);
        self::assertSame(422, $response->getStatusCode()); self::assertSame([], $this->db->rows['sales']); self::assertSame([], $this->db->effects);
    }

    public function test_mixed_payment_uses_same_book_and_base_total(): void
    {
        $response = $this->controller->facturarCita($this->request(['payments' => [
            ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => '60.01', 'tasa' => 1],
            ['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => 1, 'tasa' => 40],
        ]]), 7);
        self::assertSame(200, $response->getStatusCode()); self::assertCount(2, $this->db->rows['pagos']);
        self::assertSame(10001, array_sum(array_map(fn ($p) => D::scaled($p['valor_nio']), $this->db->rows['pagos'])));
    }

    public function test_retained_invoice_keeps_explicit_receipt_without_applying_effects(): void
    {
        $this->held = true;
        $payments = [['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => '3', 'tasa' => '40']];
        $response = $this->controller->facturarCita($this->request(['payments' => $payments, 'change_currency' => 'nio']), 7);
        self::assertSame(201, $response->getStatusCode());
        $payload = json_decode($this->db->rows['sales'][1]['payload_aprobacion'], true);
        self::assertSame($payments, $payload['payments']); self::assertSame('nio', $payload['change_currency']);
        self::assertSame([], $this->db->rows['pagos']); self::assertSame([], $this->db->effects);
    }

    public static function unavailableServices(): array { return [[false], [null]]; }

    #[DataProvider('unavailableServices')]
    public function test_inactive_or_missing_service_rejects_before_documents(?bool $active): void
    {
        if ($active === null) $this->db->rows['services'] = [];
        else $this->db->rows['services'][20]['is_active'] = $active;
        $response = $this->controller->facturarCita($this->request(), 7);
        self::assertSame(422, $response->getStatusCode()); self::assertSame([], $this->db->rows['sales']); self::assertSame([], $this->db->effects);
    }

    public function test_discount_larger_than_catalogue_rejects_before_effects(): void
    {
        $response = $this->controller->facturarCita($this->request(['discount' => '100.02']), 7);
        self::assertSame(422, $response->getStatusCode()); self::assertSame([], $this->db->rows['sales']);
    }

    public function test_invalid_explicit_receipt_structure_or_non_finite_value_rejects_without_transaction(): void
    {
        foreach ([['bad' => ['metodo' => 'efectivo', 'moneda' => 'nio', 'monto' => 100.01, 'tasa' => 1]],
            [['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => 0, 'tasa' => 40]],
            [['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => '1e309', 'tasa' => 40]]] as $payments) {
            try { $this->controller->facturarCita($this->request(['payments' => $payments]), 7); self::fail('Pago ambiguo o inválido.'); }
            catch (ValidationException $e) { self::assertNotEmpty($e->errors()); }
        }
        self::assertSame([], $this->db->rows['sales']); self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_pos_cannot_keep_draft_appointment_header_after_removing_its_cart_link(): void
    {
        $this->db->rows['sales'][1] = ['id' => 1, 'appointment_id' => 7, 'status' => 'borrador'];
        $before = $this->db->rows;
        $line = ['id' => 10, 'type' => 'producto', 'quantity' => 1, 'precio_c' => '100.01'];
        $cart = Mockery::mock(CarritoVentaService::class);
        $cart->shouldReceive('preparar')->once()->andReturn(['cart' => [$line + ['appointment_id' => null]],
            'subtotal' => '100.01', 'discount' => '0.00', 'total' => '100.01']);
        app()->instance(CarritoVentaService::class, $cart);
        $response = $this->controller->store($this->request(['cart' => [$line], 'draft_id' => 1, 'action' => 'pagar']));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('conservar', $response->getData(true)['error']);
        self::assertSame($before, $this->db->rows); self::assertSame([], $this->db->effects);
    }

    public function test_direct_then_pos_and_direct_retry_reject_using_the_same_persisted_memory_link(): void
    {
        self::assertSame(200, $this->controller->facturarCita($this->request(), 7)->getStatusCode());
        $before = $this->db->rows; $effects = $this->db->effects;
        app()->instance(AgendaService::class, new H06SharedAppointment);
        $request = $this->request(['cart' => [['id' => 20, 'type' => 'servicio', 'quantity' => 1,
            'precio_c' => '100.01', 'appointment_id' => 7, 'stylist_id' => 1]], 'action' => 'pagar']);
        self::assertSame(422, $this->controller->store($request)->getStatusCode());
        self::assertSame(422, $this->controller->facturarCita($this->request(), 7)->getStatusCode());
        self::assertSame($before, $this->db->rows); self::assertSame($effects, $this->db->effects);
        self::assertSame(0, $this->db->transactionLevel());
    }

    public static function failureStages(): array { return [['inventory'], ['fx'], ['accounting']]; }

    #[DataProvider('failureStages')]
    public function test_error_after_partial_effects_restores_memory_state(string $stage): void
    {
        $before = $this->db->rows; $this->fail = $stage;
        $response = $this->controller->facturarCita($this->request(['payments' => [['metodo' => 'efectivo', 'moneda' => 'usd', 'monto' => 3, 'tasa' => 40]]]), 7);
        self::assertSame(500, $response->getStatusCode()); self::assertSame($before, $this->db->rows);
        self::assertSame([], $this->db->effects); self::assertContains('rollback', $this->db->trace); self::assertSame(0, $this->db->transactionLevel());
        if ($stage !== 'inventory') self::assertContains('insert:pagos', $this->db->trace);
    }
}

final class H06DirectController extends VentaController
{
    protected function notifyVentaPendiente(Venta $venta): void {} // Sin notificaciones externas.
}

/** Solo sustituye adquisición del mutex; usa la guardia real y la misma persistencia del controlador. */
final class H06SharedAppointment extends AgendaService
{
    public function bloquearCitas(array $ids, array $estilistasNuevos = []): SupportCollection
    {
        $cita = new Cita; $cita->setRawAttributes(['id' => 7, 'status' => 'completada'], true);
        return collect([7 => $cita]);
    }
}
