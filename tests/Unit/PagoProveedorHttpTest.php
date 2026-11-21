<?php

namespace Tests\Unit;

use App\Exceptions\ContabilidadException;
use App\Http\Controllers\CuentaPorPagarController;
use App\Models\PagoProveedor;
use App\Services\PagoProveedorService;
use Illuminate\Container\Container;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Session\{ArraySessionHandler, Store};
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\{Factory, PresenceVerifierInterface, ValidationException};
use Mockery;
use PHPUnit\Framework\TestCase;

/** Request/controlador reales, sin kernel, DB o sesión empresarial. */
final class PagoProveedorHttpTest extends TestCase
{
    private Factory $validator;
    private Store $session;
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container; Container::setInstance($app);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->session = new Store('h09-fixture', new ArraySessionHandler(120));
        $redirector = Mockery::mock(\Illuminate\Routing\Redirector::class);
        $redirector->shouldReceive('back')->andReturnUsing(function () {
            $response = new RedirectResponse('/cuentas-por-pagar'); $response->setSession($this->session); return $response;
        });
        $app->instance('redirect', $redirector);
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Factory::class);
        $auth->shouldReceive('id')->andReturn(7); $app->instance('auth', $auth);
        $app->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
        $log = Mockery::mock(); $log->shouldNotReceive('error'); $app->instance('log', $log);
        $this->validator = new Factory(new Translator(new ArrayLoader, 'es'));
        $presence = Mockery::mock(PresenceVerifierInterface::class);
        $presence->shouldReceive('getCount')->andReturnUsing(fn ($table, $column, $value) => $table === 'cuentas_por_pagar' && (int)$value === 1 ? 1 : 0);
        $this->validator->setPresenceVerifier($presence);
    }
    protected function tearDown(): void
    {
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
        parent::tearDown();
    }
    private function request(array $extra = []): Request
    {
        $r = new class extends Request {
            public Factory $factory;
            public function validate(array $rules, ...$params): array { return $this->factory->make($this->all(), $rules)->validate(); }
        };
        $r->factory = $this->validator;
        $r->replace(array_replace(['cuenta_por_pagar_id' => 1, 'monto' => '20.01', 'forma_pago' => 'banco'], $extra));
        return $r;
    }
    public function test_valid_form_keeps_redirect_and_actor_cannot_be_mass_assigned(): void
    {
        $service = Mockery::mock(PagoProveedorService::class);
        $service->shouldReceive('registrar')->once()->with(1, '20.01', 'banco', 7)->andReturn(new PagoProveedor);
        $response = (new CuentaPorPagarController)->store($this->request(['usuario_id' => 999, 'origen_pago_id' => 999, 'estado' => 'pagada']), $service);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/cuentas-por-pagar', $response->getTargetUrl());
        self::assertSame('Abono registrado y contabilizado con éxito.', $this->session->get('success'));
    }
    public function test_http_rejects_invalid_amount_ids_and_methods_without_calling_service(): void
    {
        $service = Mockery::mock(PagoProveedorService::class); $service->shouldNotReceive('registrar');
        foreach ([['monto' => 0], ['monto' => -1], ['monto' => '0.001'], ['monto' => 'x'], ['monto' => INF],
            ['monto' => NAN], ['cuenta_por_pagar_id' => 999], ['cuenta_por_pagar_id' => []], ['forma_pago' => 'usd']] as $bad) {
            try { (new CuentaPorPagarController)->store($this->request($bad), $service); self::fail('Request inválido.'); }
            catch (ValidationException $e) { self::assertNotEmpty($e->errors()); }
        }
    }
    public function test_domain_rejection_keeps_form_error_without_success(): void
    {
        $service = Mockery::mock(PagoProveedorService::class);
        $service->shouldReceive('registrar')->once()->andThrow(new ContabilidadException('La obligación está anulada.'));
        $response = (new CuentaPorPagarController)->store($this->request(), $service);
        self::assertSame(302, $response->getStatusCode());
        self::assertFalse($this->session->has('success'));
        self::assertSame('La obligación está anulada.', $this->session->get('errors')->first('error'));
    }
}
