<?php

namespace Tests\Unit;

use App\Http\Controllers\RespaldoController;
use App\Support\HttpRestoreGate;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\{JsonResponse, Response};
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunTestsInSeparateProcesses};
use PHPUnit\Framework\TestCase;
use Tests\Support\{R01RestoreTripwire, R01UnreadableRequest, R01UnreadableUpload};

/** Sin Application, .env, kernel, sesiones ni PDO. Toda interacción peligrosa falla. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HttpRestoreContainmentTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        require_once __DIR__.'/../Support/R01RestoreTripwires.php';
        $this->container = new Container; Container::setInstance($this->container);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->container);
        $this->container->instance('config', new Repository);
        foreach (['db','log','files','auth','session'] as $service) {
            $this->container->bind($service, fn () => R01RestoreTripwire::fail('resolve.'.$service));
        }
        R01RestoreTripwire::$calls = [];
        $response = Mockery::mock(ResponseFactory::class);
        $response->shouldReceive('make')->andReturnUsing(fn ($body, $status, $headers) => new Response($body, $status, $headers));
        $response->shouldReceive('json')->andReturnUsing(fn ($body, $status) => new JsonResponse($body, $status));
        $this->container->instance(ResponseFactory::class, $response);
    }
    protected function tearDown(): void
    {
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
    }
    public static function configurations(): array
    {
        return [
            'absent' => [[], false],
            'disabled' => [['backups' => ['http_restore_enabled' => false]], false],
            'true cannot release code latch' => [['backups' => ['http_restore_enabled' => true]], true],
            'null' => [['backups' => ['http_restore_enabled' => null]], true],
            'integer one' => [['backups' => ['http_restore_enabled' => 1]], false],
            'integer zero' => [['backups' => ['http_restore_enabled' => 0]], false],
            'string true' => [['backups' => ['http_restore_enabled' => 'true']], true],
            'string false' => [['backups' => ['http_restore_enabled' => 'false']], true],
            'unknown mode' => [['backups' => ['http_restore_enabled' => 'approved']], false],
            'array' => [['backups' => ['http_restore_enabled' => ['enabled' => true]]], false],
            'object' => [['backups' => ['http_restore_enabled' => (object)['enabled' => true]]], true],
            'unknown setting' => [['backups' => ['enabled' => true]], true],
            'malformed group' => [['backups' => 'enabled'], false],
        ];
    }
    #[DataProvider('configurations')]
    public function test_every_configuration_rejects_before_any_file_sql_log_or_session_access(array $config, bool $json): void
    {
        $this->container->instance('config', new Repository($config));
        $response = (new RespaldoController)->restore($this->request('synthetic.sql', $json));
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('temporalmente deshabilitada por seguridad', $response->getContent());
        if ($json) self::assertSame('http_restore_disabled', $response->getData(true)['code']);
        else self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame([], R01RestoreTripwire::$calls);
    }
    public static function uploads(): array { return [['synthetic.sql'], ['synthetic.txt'], ['synthetic.php'], ['SYNTHETIC.SQL'], [null]]; }
    #[DataProvider('uploads')]
    public function test_file_type_missing_file_and_http_flags_cannot_bypass_containment(?string $filename): void
    {
        $request = $this->request($filename, true);
        $request->query->set('http_restore_enabled', true); $request->headers->set('X-Restore-Authorized', 'true');
        $request->request->replace(['http_restore_enabled' => true, 'confirmed' => true, 'mode' => 'approved', 'password' => 'SYNTHETIC-NOT-A-CREDENTIAL']);
        $response = (new RespaldoController)->restore($request);
        self::assertSame(403, $response->getStatusCode()); self::assertSame([], R01RestoreTripwire::$calls);
        self::assertStringNotContainsString('SYNTHETIC-NOT-A-CREDENTIAL', $response->getContent());
    }
    public function test_repeat_is_always_a_denial_without_effects(): void
    {
        $this->container->instance('config', new Repository(['backups' => ['http_restore_enabled' => true]]));
        $request = $this->request('synthetic.sql', true); $controller = new RespaldoController;
        for ($i = 0; $i < 3; ++$i) self::assertSame(403, $controller->restore($request)->getStatusCode());
        self::assertSame([], R01RestoreTripwire::$calls);
    }
    public function test_gate_is_pure_and_independent_of_environment_or_inventory_flags(): void
    {
        // No servicios de entorno, H03 o H06 están registrados; consultarlos fallaría.
        foreach ([null, false, true, 0, 1, '', 'enabled', [], new \stdClass] as $value) self::assertFalse(HttpRestoreGate::allows($value));
        self::assertSame([], R01RestoreTripwire::$calls);
    }
    public function test_configuration_file_defaults_to_closed_without_environment_loading(): void
    {
        $settings = require dirname(__DIR__, 2).'/config/backups.php';
        self::assertSame(false, $settings['http_restore_enabled']); self::assertFalse(HttpRestoreGate::allows($settings['http_restore_enabled']));
        self::assertSame([], R01RestoreTripwire::$calls);
    }
    public function test_history_is_private_and_index_still_selects_existing_view(): void
    {
        self::assertTrue((new \ReflectionMethod(RespaldoController::class, 'restoreLegacy'))->isPrivate());
        self::assertTrue((new \ReflectionMethod(RespaldoController::class, 'restore'))->isPublic());
        $factory = Mockery::mock(ViewFactory::class); $view = Mockery::mock(\Illuminate\Contracts\View\View::class);
        $factory->shouldReceive('make')->once()->with('backups.index', [], [])->andReturn($view);
        $this->container->instance(ViewFactory::class, $factory);
        self::assertSame($view, (new RespaldoController)->index()); self::assertSame([], R01RestoreTripwire::$calls);
    }
    public function test_registered_backup_routes_keep_web_auth_role_and_public_handlers(): void
    {
        $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher($this->container), $this->container);
        $this->container->instance('router', $router);
        $router->group(['middleware' => 'web'], dirname(__DIR__, 2).'/routes/web.php');
        foreach ([['GET', '/backups', 'index'], ['GET', '/backups/descargar', 'download'], ['POST', '/backups/restaurar', 'restore']] as [$method, $uri, $action]) {
            $route = $router->getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
            self::assertSame(RespaldoController::class.'@'.$action, $route->getActionName());
            self::assertContains('web', $route->middleware()); self::assertContains('auth', $route->middleware()); self::assertContains('role:admin', $route->middleware());
        }
        foreach ($router->getRoutes() as $route) self::assertStringNotContainsString('restoreLegacy', $route->getActionName());
        self::assertSame([], R01RestoreTripwire::$calls);
    }
    private function request(?string $filename, bool $json): R01UnreadableRequest
    {
        $request = new R01UnreadableRequest([], [], [], [], $filename === null ? [] : ['backup_file' => new R01UnreadableUpload($filename)]);
        $request->headers->set('Accept', $json ? 'application/json' : 'text/html'); return $request;
    }
}
