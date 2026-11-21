<?php

namespace Tests\Unit;

use App\Http\Controllers\{CitaController, ClienteController};
use App\Http\Middleware\RoleMiddleware;
use App\Models\{Cita, Usuario};
use App\Services\AgendaService;
use Illuminate\Auth\{AuthenticationException, SessionGuard};
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\{Factory as AuthFactory, UserProvider};
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Session\{ArraySessionHandler, Store};
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\{Factory, PresenceVerifierInterface, ValidationException};
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** H12-R: registro de rutas y límites HTTP reales, sin kernel, SQL ni .env. */
final class ApiAgendaAccessReviewTest extends TestCase
{
    private Application $app;
    private Factory $validator;
    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        // No bootstrap/app.php, providers del proyecto, configuración privada ni tests/bootstrap.php.
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository(['auth.defaults.guard' => 'web']));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldNotReceive('connection'); // Cualquier intento de SQL falla antes de conectar.
        Model::setConnectionResolver($resolver);
        $this->validator = new Factory(new Translator(new ArrayLoader, 'es'));
        $presence = Mockery::mock(PresenceVerifierInterface::class);
        // Catálogos deliberadamente disjuntos: servicio 20; producto 90.
        $presence->shouldReceive('getCount')->andReturnUsing(
            fn ($table, $column, $id) => (int) $id === ([
                'clients' => 10, 'services' => 20, 'users' => 30, 'customer_groups' => 40,
            ][$table] ?? -1) ? 1 : 0
        );
        $this->validator->setPresenceVerifier($presence);
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new JsonResponse($data, $status));
        $this->app->instance(ResponseFactory::class, $responses);
        $router = $this->app['router'];
        $router->middlewareGroup('web', []);
        $router->group(['middleware' => 'web'], dirname(__DIR__, 2).'/routes/web.php');
        $router->group(['prefix' => 'api', 'middleware' => 'api'], dirname(__DIR__, 2).'/routes/api.php');
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Mockery::close();
        $this->app->flush();
        \Illuminate\Container\Container::setInstance(null);
        parent::tearDown();
    }

    private function request(array $data): Request
    {
        $request = new class extends Request {
            public Factory $factory;
            public function validate(array $rules, ...$params): array
            {
                return $this->factory->make($this->all(), $rules)->validate();
            }
        };
        $request->factory = $this->validator;
        $request->replace($data);
        return $request;
    }

    private function endpoints(): array
    {
        $rows = [];
        foreach (['clients', 'appointments'] as $resource) {
            foreach (['GET', 'POST'] as $method) $rows[] = [$method, '/api/'.$resource];
            foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) $rows[] = [$method, '/api/'.$resource.'/7'];
        }
        return [...$rows, ['PUT', '/api/appointments/7/details'], ['GET', '/agenda'],
            ['GET', '/clientes'], ['POST', '/clientes'], ['GET', '/clientes/7'],
            ['PUT', '/clientes/7'], ['DELETE', '/clientes/7']];
    }

    private function authentication(?string $role): Authenticate
    {
        $session = new Store('h12-r', new ArraySessionHandler(120));
        $provider = Mockery::mock(UserProvider::class);
        $guard = new SessionGuard('web', $provider, $session);
        if ($role !== null) {
            $user = new Usuario;
            $user->setRawAttributes(['id' => 17, 'name' => 'Fixture', 'role' => $role]);
            $provider->shouldReceive('retrieveById')->with(17)->andReturn($user);
            $session->put($guard->getName(), 17); // Autenticación por sesión, sin actingAs.
        }
        $auth = Mockery::mock(AuthFactory::class);
        $auth->shouldReceive('guard')->with(null)->andReturn($guard);
        $auth->shouldReceive('shouldUse')->with(null);
        $auth->shouldReceive('check')->andReturnUsing(fn () => $guard->check());
        $auth->shouldReceive('user')->andReturnUsing(fn () => $guard->user());
        $this->app->instance('auth', $auth);
        return new Authenticate($auth);
    }

    public function test_current_routes_keep_web_session_authentication_for_every_action(): void
    {
        foreach ($this->endpoints() as [$method, $url]) {
            $route = $this->app['router']->getRoutes()->match(Request::create($url, $method));
            self::assertContains('web', $route->gatherMiddleware(), $method.' '.$url);
            self::assertContains('auth', $route->gatherMiddleware(), $method.' '.$url);
        }
        self::assertSame('appointments.update', $this->app['router']->getRoutes()
            ->match(Request::create('/api/appointments/7', 'PUT'))->getName());
        self::assertSame('web', (require dirname(__DIR__, 2).'/config/auth.php')['defaults']['guard']);
    }

    public function test_anonymous_and_portal_only_or_bearer_credentials_cannot_reach_any_action(): void
    {
        $auth = $this->authentication(null);
        foreach ($this->endpoints() as [$method, $url]) {
            $request = Request::create($url, $method);
            $request->headers->set('Accept', 'application/json');
            $request->headers->set('Authorization', 'Bearer dummy-portal-token');
            $session = new Store('portal-only', new ArraySessionHandler(120));
            $session->put('portal_token', 'dummy-portal-token');
            $request->setLaravelSession($session);
            try {
                $auth->handle($request, fn () => self::fail('No debe alcanzar el controlador.'));
                self::fail('Falta autenticación.');
            } catch (AuthenticationException $e) {
                self::assertSame('Unauthenticated.', $e->getMessage());
            }
        }
    }

    public function test_all_five_roles_keep_general_access_but_stylist_has_no_administrative_permission(): void
    {
        foreach (['alvaro', 'admin', 'contador', 'recepcion', 'estilista'] as $role) {
            $auth = $this->authentication($role);
            foreach ($this->endpoints() as [$method, $url]) {
                self::assertSame('allowed', $auth->handle(Request::create($url, $method), fn () => 'allowed'));
            }
        }
        try {
            (new RoleMiddleware)->handle(Request::create('/clientes/grupos', 'POST'), fn () => 'allowed', 'admin');
            self::fail('Estilista no administra grupos.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    private function payload(): array
    {
        return ['client_id' => 10, 'service_id' => 20, 'stylists' => [30],
            'appointment_date' => '2026-12-01T10:00', 'notes' => 'Fixture'];
    }

    public function test_creation_accepts_services_catalogue_and_drops_unvalidated_administrative_fields(): void
    {
        $agenda = Mockery::mock(AgendaService::class);
        $agenda->shouldReceive('reservar')->once()->with($this->payload())->andReturn(new Cita);
        $response = (new CitaController)->store($this->request($this->payload() + [
            'id' => 999, 'status' => 'completada', 'created_at' => '2000-01-01', 'aprobada_por' => 1,
        ]), $agenda);
        self::assertSame(201, $response->getStatusCode());
    }

    public function test_invalid_relationships_and_items_only_service_are_rejected_before_agenda(): void
    {
        foreach (['client_id' => 999, 'service_id' => 90, 'stylists' => [999]] as $field => $value) {
            $agenda = Mockery::mock(AgendaService::class);
            $agenda->shouldNotReceive('reservar');
            try {
                (new CitaController)->store($this->request(array_replace($this->payload(), [$field => $value])), $agenda);
                self::fail('Debe rechazar '.$field);
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field === 'stylists' ? 'stylists.0' : $field, $e->errors());
            }
        }
    }

    public function test_state_transition_and_details_keep_separate_validated_payloads(): void
    {
        $cita = new Cita;
        $cita->setRawAttributes(['id' => 7]);
        $agenda = Mockery::mock(AgendaService::class);
        $agenda->shouldReceive('cambiarEstado')->once()->with(7, 'completada')->andReturn($cita);
        self::assertSame(200, (new CitaController)->update($this->request(['status' => 'completada', 'id' => 888]), $cita, $agenda)->getStatusCode());
        $data = ['edit_token' => str_repeat('a', 64), 'client_id' => 10, 'service_id' => 20, 'stylists' => [30], 'notes' => 'Nueva'];
        $agenda->shouldReceive('editar')->once()->with(7, $data)->andReturn($cita);
        self::assertSame(200, (new CitaController)->updateDetails($this->request($data + ['id' => 888, 'created_at' => '2000-01-01']), $cita, $agenda)->getStatusCode());
        foreach ([['status' => 'facturada'], ['status' => 'completada', 'service_id' => 90]] as $invalid) {
            try {
                (new CitaController)->update($this->request($invalid), $cita, $agenda);
                self::fail('Transición inválida.');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
        foreach ([['client_id' => 999], ['service_id' => 90], ['stylists' => [999]],
            ['stylists' => [30, 30]], ['status' => 'completada'], ['duration_minutes' => 999],
            ['edit_token' => ''], ['appointment_date' => 'invalid']] as $invalid) {
            try {
                (new CitaController)->updateDetails($this->request(array_replace($data, $invalid)), $cita, $agenda);
                self::fail('Edición inválida.');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
    }

    public function test_client_create_and_update_share_validation_and_exclude_protected_fields(): void
    {
        $controller = new ClienteController;
        $rules = (new \ReflectionMethod($controller, 'reglas'))->invoke($controller);
        $input = ['name' => 'Fixture', 'customer_group_id' => 40, 'credit_days' => 10];
        self::assertFalse($this->validator->make($input, $rules)->fails());
        self::assertTrue($this->validator->make($input + ['email' => 'invalid'], $rules)->fails());
        self::assertTrue($this->validator->make(array_replace($input, ['customer_group_id' => 999]), $rules)->fails());
        $filtered = (new \ReflectionMethod($controller, 'datos'))->invoke($controller, $this->request($input + [
            'id' => 888, 'deleted_at' => '2000-01-01', 'created_at' => '2000-01-01', 'role' => 'alvaro', 'saldo_pendiente' => 0,
        ]));
        self::assertSame($input, $filtered);
    }

    public function test_real_csrf_middleware_requires_session_token_on_mutations(): void
    {
        $middleware = new class($this->app, Mockery::mock(\Illuminate\Contracts\Encryption\Encrypter::class)) extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery {
            protected function runningUnitTests() { return false; }
            public function shouldAddXsrfTokenCookie() { return false; }
        };
        foreach ([['POST', '/api/clients'], ['POST', '/api/appointments'], ['PUT', '/api/appointments/7/details']] as [$method, $url]) {
            foreach (['', 'incorrect', 'fixture-csrf'] as $token) {
                $request = Request::create($url, $method);
                $session = new Store('csrf-fixture', new ArraySessionHandler(120));
                $session->put('_token', 'fixture-csrf');
                $request->setLaravelSession($session);
                $request->headers->set('X-CSRF-TOKEN', $token);
                try {
                    $response = $middleware->handle($request, fn () => new JsonResponse(['ok' => true]));
                    self::assertSame('fixture-csrf', $token);
                    self::assertSame(200, $response->getStatusCode());
                } catch (\Illuminate\Session\TokenMismatchException $e) {
                    self::assertNotSame('fixture-csrf', $token);
                }
            }
        }
    }
}
