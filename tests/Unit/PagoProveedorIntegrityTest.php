<?php

namespace Tests\Unit;

use App\Exceptions\ContabilidadException;
use App\Models\{CuentaPorPagar, PagoProveedor};
use App\Services\{CompraService, ContabilidadService, PagoProveedorService};
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\{ConnectionResolver, MySqlConnection};
use Illuminate\Database\Eloquent\{Model, ModelNotFoundException};
use Illuminate\Support\{DateFactory, Facades\Facade};
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Dobles de persistencia e interleavings seriales: ninguna conexión SQL ni prueba InnoDB. */
final class PagoProveedorIntegrityTest extends TestCase
{
    private H09MemoryState $state;
    private H09MemoryPayment $service;
    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository);
        $app->instance('date', new DateFactory);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->previousResolver = Model::getConnectionResolver();
        $this->state = new H09MemoryState;
        $db = Mockery::mock();
        $db->shouldReceive('transaction')->andReturnUsing(function ($callback) {
            $before = [$this->state->account, $this->state->payments, $this->state->entries];
            $this->state->events[] = 'transaction'; ++$this->state->depth;
            try {
                $result = $callback(); $this->state->events[] = 'commit'; return $result;
            } catch (\Throwable $e) {
                [$this->state->account, $this->state->payments, $this->state->entries] = $before;
                $this->state->events[] = 'rollback'; throw $e;
            } finally { --$this->state->depth; }
        });
        $app->instance('db', $db);
        $contabilidad = Mockery::mock(ContabilidadService::class);
        $contabilidad->shouldReceive('contabilizarPagoProveedor')->andReturnUsing(function ($amount, $id, $method) {
            $this->state->events[] = 'accounting';
            $this->state->entries[] = [$amount, $id, $method];
            if ($this->state->failAccounting) throw new \Error('h09.partial_effect_sentinel');
        });
        $this->service = new H09MemoryPayment($contabilidad, $this->state);
        $resolver = Mockery::mock(\Illuminate\Database\ConnectionResolverInterface::class);
        $resolver->shouldNotReceive('connection'); Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_partial_then_full_payment_keep_exact_amounts_and_existing_payment_contract(): void
    {
        $payment = $this->service->registrar(1, '20.01', 'banco', 7);
        self::assertSame('20.01', $payment->getRawOriginal('monto'));
        self::assertSame('79.99', $this->state->account['saldo_pendiente']);
        self::assertSame('parcial', $this->state->account['estado']);
        self::assertSame([['20.01', 1, 'banco']], $this->state->entries);
        self::assertSame(['transaction', 'lock-account', 'read-payments', 'create-payment', 'save-balance', 'accounting', 'commit'], $this->state->events);
        $this->service->registrar(1, '79.99', 'efectivo', 7);
        self::assertSame('0.00', $this->state->account['saldo_pendiente']);
        self::assertSame('pagada', $this->state->account['estado']);
        self::assertCount(2, $this->state->payments);
        self::assertSame(0, $this->state->depth);
    }

    public static function badAmounts(): array
    {
        return array_map(fn ($amount) => [$amount], [0, -1, '0.001', '1.001', 'abc', '1e2', INF, NAN, true, null, [], '10000000000000.00']);
    }

    #[DataProvider('badAmounts')]
    public function test_invalid_amounts_reject_before_transaction_or_effects($amount): void
    {
        try { $this->service->registrar(1, $amount, 'efectivo', 7); self::fail('Debe rechazar.'); }
        catch (ContabilidadException $e) { self::assertNotEmpty($e->getMessage()); }
        self::assertSame([], $this->state->events);
        self::assertSame([], $this->state->payments);
    }

    public function test_overpayment_closes_simulated_transaction_without_effects(): void
    {
        try { $this->service->registrar(1, '100.01', 'efectivo', 7); self::fail('Sobrepago.'); }
        catch (ContabilidadException $e) { self::assertStringContainsString('supera', $e->getMessage()); }
        self::assertSame('100.00', $this->state->account['saldo_pendiente']);
        self::assertSame([], $this->state->payments);
        self::assertSame([], $this->state->entries);
        self::assertSame('rollback', array_last($this->state->events));
        self::assertSame(0, $this->state->depth);
    }

    public function test_missing_annulled_paid_and_inconsistent_obligations_are_rejected(): void
    {
        try { $this->service->registrar(999, '1', 'efectivo', 7); self::fail('No existe.'); }
        catch (ModelNotFoundException $e) { self::assertSame(0, $this->state->depth); }
        foreach (['anulada', 'pagada'] as $status) {
            $this->state->account['estado'] = $status;
            try { $this->service->registrar(1, '1', 'efectivo', 7); self::fail('Estado no pagable.'); }
            catch (ContabilidadException $e) { self::assertStringContainsString('estado', $e->getMessage()); }
        }
        $this->state->account['estado'] = 'parcial';
        $this->state->account['saldo_pendiente'] = '50.00'; // Sin recibos: no reparar historia.
        try { $this->service->registrar(1, '1', 'efectivo', 7); self::fail('Saldo inconsistente.'); }
        catch (ContabilidadException $e) { self::assertStringContainsString('conciliación', $e->getMessage()); }
        self::assertSame([], $this->state->payments);
        self::assertSame(0, $this->state->depth);
    }

    public function test_throwable_after_partial_effects_restores_the_memory_double(): void
    {
        $this->state->failAccounting = true;
        try { $this->service->registrar(1, '25', 'efectivo', 7); self::fail('Falta centinela.'); }
        catch (\Error $e) { self::assertSame('h09.partial_effect_sentinel', $e->getMessage()); }
        self::assertContains('create-payment', $this->state->events);
        self::assertContains('save-balance', $this->state->events);
        self::assertContains('accounting', $this->state->events);
        self::assertSame('100.00', $this->state->account['saldo_pendiente']);
        self::assertSame([], $this->state->payments);
        self::assertSame([], $this->state->entries);
        self::assertSame(0, $this->state->depth);
    }

    public function test_serial_interleaving_rejects_second_80_but_keeps_both_when_balance_suffices(): void
    {
        // Caracterización anterior: dos lecturas de 100 terminan escribiendo 20, con pagos 160.
        self::assertSame(20, 100 - 80); self::assertGreaterThan(100, 80 + 80);
        $this->service->registrar(1, '80', 'efectivo', 7);
        try { $this->service->registrar(1, '80', 'efectivo', 7); self::fail('Segundo pago excede saldo actual.'); }
        catch (ContabilidadException $e) { self::assertStringContainsString('supera', $e->getMessage()); }
        self::assertSame('20.00', $this->state->account['saldo_pendiente']);
        self::assertCount(1, $this->state->payments);
        $this->service->registrar(1, '10', 'efectivo', 7);
        $this->service->registrar(1, '10', 'banco', 7);
        self::assertSame('0.00', $this->state->account['saldo_pendiente']);
        self::assertCount(3, $this->state->entries);
    }

    public function test_repeated_request_without_key_is_two_payments_when_balance_allows(): void
    {
        $one = $this->service->registrar(1, '20', 'efectivo', 7);
        $two = $this->service->registrar(1, '20', 'efectivo', 7);
        self::assertNotSame($one->id, $two->id);
        self::assertSame('60.00', $this->state->account['saldo_pendiente']);
        self::assertCount(2, $this->state->payments); // Límite explícito: no prometer idempotencia.
    }

    private function querySpy(array $account, array $payments): H09QuerySpy
    {
        $spy = new H09QuerySpy($account, $payments);
        $resolver = new ConnectionResolver(['mysql' => $spy]); $resolver->setDefaultConnection('mysql');
        Model::setConnectionResolver($resolver);
        return $spy;
    }

    public function test_actual_eloquent_payment_queries_request_current_locked_reads(): void
    {
        $spy = $this->querySpy(['id' => 1, 'estado' => 'parcial', 'monto_original' => '100.00', 'saldo_pendiente' => '20.00'],
            [(object) ['id' => 8, 'cuenta_por_pagar_id' => 1, 'monto' => '80.00']]);
        try { (new PagoProveedorService(Mockery::mock(ContabilidadService::class)))->registrar(1, '80', 'efectivo', 7); self::fail('Sobrepago.'); }
        catch (ContabilidadException $e) { self::assertStringContainsString('supera', $e->getMessage()); }
        self::assertCount(2, $spy->queries);
        self::assertStringContainsString('`cuentas_por_pagar`', $spy->queries[0]);
        foreach ($spy->queries as $sql) self::assertStringEndsWith('for update', $sql);
    }

    public function test_actual_annulment_locks_invoice_then_payable_and_current_payment_rows(): void
    {
        $spy = $this->querySpy(['id' => 1, 'estado' => 'pendiente', 'monto_original' => '100.00', 'saldo_pendiente' => '100.00'],
            [(object) ['id' => 8, 'cuenta_por_pagar_id' => 1, 'monto' => '20.00']]);
        try { (new CompraService)->anularFactura(5, 'Fixture H09', 7); self::fail('Pago registrado bloquea anulación.'); }
        catch (\Exception $e) { self::assertStringContainsString('abonos', $e->getMessage()); }
        $locks = array_values(array_filter($spy->queries, fn ($sql) => str_ends_with($sql, 'for update')));
        self::assertCount(3, $locks);
        foreach (['purchase_invoices', 'cuentas_por_pagar', 'pagos_proveedor'] as $i => $table) {
            self::assertStringContainsString('`'.$table.'`', $locks[$i]);
        }
        self::assertSame(0, $this->state->depth);
    }

    public function test_annulment_committed_first_prevents_a_later_payment(): void
    {
        $this->state->account['estado'] = 'anulada'; $this->state->account['saldo_pendiente'] = '0.00';
        $this->expectException(ContabilidadException::class);
        $this->service->registrar(1, '20', 'efectivo', 7);
    }
}

final class H09MemoryState
{
    public array $account = ['id' => 1, 'monto_original' => '100.00', 'saldo_pendiente' => '100.00', 'estado' => 'pendiente'];
    public array $payments = [], $entries = [], $events = [];
    public int $depth = 0, $nextId = 1;
    public bool $failAccounting = false;
}

final class H09MemoryPayment extends PagoProveedorService
{
    public function __construct(ContabilidadService $contabilidad, private H09MemoryState $state) { parent::__construct($contabilidad); }
    protected function bloquearCuenta(int $id): CuentaPorPagar
    {
        if ($this->state->depth !== 1) throw new \LogicException('Fuera de transacción.');
        $this->state->events[] = 'lock-account';
        if ($id !== 1) throw (new ModelNotFoundException)->setModel(CuentaPorPagar::class, [$id]);
        $account = new CuentaPorPagar; $account->setRawAttributes($this->state->account, true); return $account;
    }
    protected function pagosActuales(CuentaPorPagar $cuenta)
    {
        $this->state->events[] = 'read-payments';
        return array_map(function ($row) { $p = new PagoProveedor; $p->setRawAttributes($row, true); return $p; }, $this->state->payments);
    }
    protected function crearPago(array $datos): PagoProveedor
    {
        $this->state->events[] = 'create-payment'; $datos['id'] = $this->state->nextId++;
        $this->state->payments[] = $datos; $p = new PagoProveedor; $p->setRawAttributes($datos, true); return $p;
    }
    protected function guardarSaldo(CuentaPorPagar $cuenta, string $saldo, string $estado): void
    {
        $this->state->events[] = 'save-balance';
        $this->state->account['saldo_pendiente'] = $saldo; $this->state->account['estado'] = $estado;
    }
}

/** Compila SQL con Eloquent real; select devuelve filas sintéticas y PDO siempre está prohibido. */
final class H09QuerySpy extends MySqlConnection
{
    public array $queries = [];
    public function __construct(private array $account, private array $payments)
    {
        parent::__construct(fn () => throw new \LogicException('PDO prohibido en H09 puro.'), 'h09-no-sql', '', ['name' => 'mysql']);
    }
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->queries[] = $query;
        if (str_contains($query, '`purchase_invoices`')) return [(object) ['id' => 5, 'estado' => 'registrada', 'cuenta_por_pagar_id' => 1]];
        if (str_contains($query, '`purchase_invoice_lines`')) return [];
        if (str_contains($query, '`cuentas_por_pagar`')) return [(object) $this->account];
        if (str_contains($query, '`pagos_proveedor`')) return $this->payments;
        throw new \LogicException('Consulta inesperada: '.$query);
    }
}
