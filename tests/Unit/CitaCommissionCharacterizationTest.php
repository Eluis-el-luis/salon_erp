<?php

namespace Tests\Unit;

use App\Models\{DetalleVenta, Venta};
use App\Services\ComisionService;
use Illuminate\Config\Repository;
use Illuminate\Database\{ConnectionResolver, MySqlConnection};
use Illuminate\Database\Eloquent\{Collection, Model};
use Illuminate\Foundation\Application;
use Illuminate\Support\{DateFactory, Facades\Facade};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Evidencia del contrato bruto actual y de la base fraccionada incorrecta, pendiente de modelo. */
final class CitaCommissionCharacterizationTest extends TestCase
{
    private H06CommissionSpy $db;
    private $previousResolver;
    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 2)); $app->instance('config', new Repository); $app->instance('date', new DateFactory);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = new H06CommissionSpy;
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['mysql' => $this->db]); $resolver->setDefaultConnection('mysql'); Model::setConnectionResolver($resolver);
    }
    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver); else Model::unsetConnectionResolver();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); \Illuminate\Container\Container::setInstance(null);
    }
    public static function documents(): array { return [[1, 0], [1, 100], [2, 0], [2, 100]]; }

    #[DataProvider('documents')]
    public function test_current_engine_uses_gross_detail_not_discount_and_shared_fractions_understate_base(int $count, int $discount): void
    {
        $sale = new Venta; $sale->setRawAttributes(['id' => 1, 'appointment_id' => 7, 'subtotal' => '1000.00', 'discount' => $discount, 'total' => 1000 - $discount]);
        $details = [];
        for ($id = 1; $id <= $count; ++$id) {
            $d = new DetalleVenta; $d->setRawAttributes(['id' => $id, 'service_id' => 20, 'quantity' => 1,
                'unit_price' => $count === 1 ? '1000.00' : '500.00', 'stylist_id' => $id]); $details[] = $d;
        }
        $sale->setRelation('detalles', new Collection($details));
        (new ComisionService)->registrarPorVenta($sale);
        self::assertSame($count, count($this->db->commissions));
        self::assertSame($count === 1 ? [100.0] : [50.0, 75.0], array_column($this->db->commissions, 'monto_comision'));
        self::assertSame(array_fill(0, $count, $count === 1 ? 1000.0 : 500.0), array_column($this->db->commissions, 'monto_venta'));
        if ($count === 2) self::assertNotSame([100.0, 150.0], array_column($this->db->commissions, 'monto_comision'));
        // Es caracterización de un defecto pendiente: no se aprueban 50/75 para la prestación compartida.
    }
}

final class H06CommissionSpy extends MySqlConnection
{
    public array $commissions = [];
    public function __construct()
    {
        parent::__construct(fn () => throw new \LogicException('PDO prohibido.'), 'h06-memory-only', '', ['name' => 'mysql']);
    }
    public function select($sql, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (str_contains($sql, 'from `users`')) return [(object)['id' => $bindings[0], 'comision_servicio' => $bindings[0] === 1 ? 10 : 15]];
        if (str_contains($sql, 'from `esquemas_comision`')) return []; // Fixture de fallback de perfil, sin cambiar política de esquemas.
        throw new \LogicException('Consulta no permitida: '.$sql);
    }
    public function insert($sql, $bindings = [], $sequence = null)
    {
        if (!preg_match('/insert into `comisiones_generadas` \((.*?)\) values/', $sql, $m)) throw new \LogicException($sql);
        preg_match_all('/`([a-z_]+)`/', $m[1], $cols);
        $this->commissions[] = array_combine($cols[1], $bindings); $this->lastInsertId = count($this->commissions); return true;
    }
}
