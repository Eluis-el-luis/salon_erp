<?php

namespace Tests\Unit;

use App\Models\{Articulo, ConsumoVenta, DetalleVenta, Venta};
use App\Services\{CarritoVentaService, InventarioService};
use App\Support\InventoryDecimal as D;
use Illuminate\Config\Repository;
use Illuminate\Database\{ConnectionResolver, MySqlConnection};
use Illuminate\Database\Eloquent\{Collection, Model};
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Caracterización explícita del defecto pendiente, no aprobación de su política física. */
final class CitaConsumptionCharacterizationTest extends TestCase
{
    private $previousResolver;
    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 2)); $app->instance('env', 'local');
        $app->instance('config', new Repository(['salon' => ['inventory_fractional_v2' => false]]));
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['mysql' => new H06RecipeSpy]); $resolver->setDefaultConnection('mysql'); Model::setConnectionResolver($resolver);
    }
    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver); else Model::unsetConnectionResolver();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); \Illuminate\Container\Container::setInstance(null);
    }
    public static function cases(): array
    {
        $cases = [];
        foreach ([1, 2, 3] as $n) foreach ([null, '7.00', '0.00'] as $amount) $cases[] = [$n, $amount];
        return $cases;
    }
    private function details(int $count, ?string $actual): array
    {
        $item = new Articulo; $item->setRawAttributes(['id' => 80, 'is_fractionable' => true], true);
        $rows = []; $prices = CarritoVentaService::repartir(10001, $count);
        foreach ($prices as $i => $price) {
            $d = new DetalleVenta; $d->setRawAttributes(['id' => $i + 1, 'service_id' => 20, 'item_id' => null,
                'stylist_id' => $i + 1, 'quantity' => 1, 'unit_price' => D::text($price)], true);
            $consumptions = [];
            if ($actual !== null) {
                $c = new ConsumoVenta; $c->setRawAttributes(['quantity_actual' => $actual]); $c->setRelation('articulo', $item); $consumptions[] = $c;
            }
            $d->setRelation('consumos', new Collection($consumptions)); $rows[] = $d;
        }
        return $rows;
    }
    private function operations(array $details): array
    {
        $sale = new Venta; $sale->setRawAttributes(['id' => 1, 'appointment_id' => 7]); $sale->setRelation('detalles', new Collection($details));
        return (new \ReflectionMethod(InventarioService::class, 'operacionesVenta'))->invoke(new InventarioService, $sale);
    }
    #[DataProvider('cases')]
    public function test_current_reader_interprets_one_recipe_per_commercial_participation(int $count, ?string $actual): void
    {
        $details = $this->details($count, $actual); $operations = $this->operations($details);
        self::assertCount($count, $operations);
        self::assertEquals(($actual === '7.00' ? 7 : 10) * $count, array_sum(array_column($operations, 1)));
        self::assertSame(10001, array_sum(array_map(fn ($d) => D::scaled($d->getRawOriginal('unit_price')), $details)));
        // Con captura íntegra cero el lector legacy vuelve a estándar: límite H03/H05 conservado.
    }
    public function test_mixed_sale_has_no_persisted_detail_link_to_distinguish_execution_from_participation(): void
    {
        $details = $this->details(2, null);
        $product = new DetalleVenta; $product->setRawAttributes(['id' => 3, 'item_id' => 81, 'service_id' => null, 'quantity' => 2]); $details[] = $product;
        $operations = $this->operations($details);
        self::assertCount(3, $operations);
        self::assertSame(81, $operations[2][0]->id); self::assertSame(2.0, $operations[2][1]);
        self::assertArrayNotHasKey('appointment_id', $details[0]->getAttributes());
    }
}

final class H06RecipeSpy extends MySqlConnection
{
    public function __construct()
    {
        parent::__construct(fn () => throw new \LogicException('PDO prohibido.'), 'h06-memory-only', '', ['name' => 'mysql']);
    }
    public function select($sql, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (str_contains($sql, 'from `services`')) return [(object)['id' => 20]];
        if (str_contains($sql, 'from `service_formulas`')) return [(object)['id' => 1, 'service_id' => 20, 'item_id' => 80, 'quantity_used' => '10.00']];
        if (str_contains($sql, 'from `items`')) {
            preg_match('/ in \((\d+)\)/', $sql, $literal);
            $id = (int) ($bindings[0] ?? $literal[1] ?? 0);
            if (!in_array($id, [80, 81], true)) throw new \LogicException('Artículo sintético no reconocido: '.$sql);
            return [(object)['id' => $id, 'is_fractionable' => $id === 80, 'type' => $id === 80 ? 'producto_interno' : 'producto_venta']];
        }
        throw new \LogicException('SELECT inesperado: '.$sql);
    }
}
