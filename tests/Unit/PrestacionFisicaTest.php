<?php

namespace Tests\Unit;

use App\Models\{Articulo, DetalleVenta};
use App\Services\{CapturaConsumoService, InventarioService};
use App\Support\InventoryDecimal as D;
use Illuminate\Container\Container;
use Illuminate\Database\{ConnectionResolver, MySqlConnection};
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Bucle físico real: lector de fotografía y escritura de lotes son dobles explícitos. */
final class PrestacionFisicaTest extends TestCase
{
    public static function cases(): array
    {
        $cases = []; foreach ([1,2,3] as $n) foreach ([1,3] as $q) foreach (['0.00','2.00','7.25'] as $actual) $cases[] = [$n,$q,$actual]; return $cases;
    }
    #[DataProvider('cases')]
    public function test_physical_loop_uses_one_photo_per_execution_and_never_visits_participants(int $participants, int $quantity, string $actual): void
    {
        $previous = Model::getConnectionResolver();
        $db = new MySqlConnection(fn () => throw new \LogicException('PDO prohibido.'), 'memory-only');
        $resolver = new ConnectionResolver(['mysql' => $db]); $resolver->setDefaultConnection('mysql'); Model::setConnectionResolver($resolver);
        $container = new Container; Container::setInstance($container);
        $item = new Articulo; $item->setRawAttributes(['id' => 30]);
        $container->instance(CapturaConsumoService::class, new class($item) extends CapturaConsumoService {
            public function __construct(private Articulo $item) {}
            public function resolver($detail): array { $amount = $detail->consumption_snapshot['ingredients'][0]['actual']; return D::scaled($amount) ? [['articulo' => $this->item, 'cantidad' => $amount]] : []; }
        });
        try {
            $detail = new DetalleVenta; $detail->setRawAttributes(['id' => 1, 'service_id' => 20, 'quantity' => $quantity,
                'consumption_snapshot' => json_encode(['version' => 2, 'ingredients' => [['actual' => $actual]]])]);
            // Acceder a participantes intentaría SQL: el doble no admite esa consulta.
            $detail->declaredParticipantsForTest = $participants;
            $inventory = new class extends InventarioService {
                public array $written = [];
                public function apply($d): void { $critical = []; $this->descontarInsumosDeServicio($d, $critical, ['sale_detail_id' => $d->id]); }
                protected function consumirVolumen(Articulo $articulo, $cantidad, array &$stockCritico, ?array $contexto = null): void { $this->written[] = [$articulo->id, $cantidad, $contexto['sale_detail_id']]; }
            };
            $inventory->apply($detail);
            self::assertSame($actual === '0.00' ? [] : [[30, D::text(D::physical($actual, $quantity)), 1]], $inventory->written);
        } finally {
            if ($previous) Model::setConnectionResolver($previous); else Model::unsetConnectionResolver(); Container::setInstance(null);
        }
    }
}
