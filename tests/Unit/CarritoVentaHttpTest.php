<?php

namespace Tests\Unit;

use App\Exceptions\ContabilidadException;
use App\Http\Controllers\VentaController;
use App\Services\{AgendaService, CarritoVentaService, NotificationService};
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\Factory;
use Mockery;
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\TestCase;

/** Límite HTTP del controlador real con caja/DB simuladas; no es una prueba con kernel ni SQL. */
final class CarritoVentaHttpTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_invalid_http_cart_never_creates_documents_or_effects(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Factory::class); $auth->shouldReceive('id')->andReturn(70);
        $app->instance('auth', $auth);
        $logger = Mockery::mock(); $logger->shouldReceive('info', 'warning', 'error');
        $app->instance('log', $logger);
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new JsonResponse($data, $status));
        $app->instance(ResponseFactory::class, $responses);
        $factory = new Factory(new Translator(new ArrayLoader, 'es'));
        $box = Mockery::mock('alias:App\Models\SesionCaja');
        $box->shouldReceive('where')->andReturnSelf(); $box->shouldReceive('exists')->andReturn(true);
        foreach (['Venta', 'DetalleVenta', 'ConsumoVenta', 'Pago', 'StockLedger', 'ComisionGenerada', 'AsientoContable'] as $model) {
            Mockery::mock('alias:App\\Models\\'.$model)->shouldNotReceive('create');
        }
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldNotReceive('connection'); Model::setConnectionResolver($resolver);
        $db = Mockery::mock();
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('rollBack')->once();
        $db->shouldNotReceive('commit', 'table', 'insert', 'update', 'delete');
        $app->instance('db', $db);
        $agenda = Mockery::mock(AgendaService::class);
        $agenda->shouldReceive('validarParaFacturar')->once()->with([])->andReturn(collect());
        $agenda->shouldNotReceive('completarDesdeVenta');
        $app->instance(AgendaService::class, $agenda);
        $cart = Mockery::mock(CarritoVentaService::class);
        $cart->shouldReceive('preparar')->once()->andThrow(new ContabilidadException('Precio manipulado.'));
        $app->instance(CarritoVentaService::class, $cart);
        $controller = new VentaController(Mockery::mock(NotificationService::class));
        $line = ['id' => 10, 'type' => 'producto', 'quantity' => 1, 'precio_c' => '0.01'];
        foreach ([['quantity' => 0], ['quantity' => -1], ['quantity' => 'x'], ['quantity' => '1.001'],
            ['precio_c' => '-1'], ['type' => 'unknown'], []] as $extra) {
            $request = new class extends Request {
                public Factory $factory;
                public function validate(array $rules, ...$params): array { return $this->factory->make($this->all(), $rules)->validate(); }
            };
            $request->factory = $factory;
            $request->replace(['cart' => [array_replace($line, $extra)], 'discount' => '0',
                'subtotal' => 0, 'total' => 0, 'cashier_id' => 999, 'status' => 'pagada']);
            $request->headers->set('Accept', 'application/json');
            $response = $controller->store($request);
            self::assertSame(422, $response->getStatusCode());
            self::assertArrayNotHasKey('sale_id', $response->getData(true));
        }
        Mockery::close();
        Model::unsetConnectionResolver();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null);
        $app->flush(); \Illuminate\Container\Container::setInstance(null);
    }
}
