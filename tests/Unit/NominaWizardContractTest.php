<?php

namespace Tests\Unit;

use App\Http\Controllers\NominaController;
use App\Models\CorridaNomina;
use App\Services\NominaService;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Session\{ArraySessionHandler, Store};
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\{Factory, PresenceVerifierInterface, ValidationException};
use Mockery;
use PHPUnit\Framework\TestCase;

/** Controlador y validación HTTP aislados; sin kernel, rutas ejecutadas ni SQL. */
final class NominaWizardContractTest extends TestCase
{
    private Factory $validator;
    private Store $session;
    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container; Container::setInstance($app);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = Mockery::mock(\Illuminate\Database\ConnectionResolverInterface::class);
        $resolver->shouldNotReceive('connection'); Model::setConnectionResolver($resolver);
        $this->session = new Store('h07-fixture', new ArraySessionHandler(120));
        $redirect = Mockery::mock(\Illuminate\Routing\Redirector::class);
        $redirect->shouldReceive('to')->andReturnUsing(function ($url) {
            $response = new RedirectResponse($url); $response->setSession($this->session); return $response;
        });
        $app->instance('redirect', $redirect);
        $log = Mockery::mock(); $log->shouldReceive('error'); $app->instance('log', $log);
        $this->validator = new Factory(new Translator(new ArrayLoader, 'es'));
        $presence = Mockery::mock(PresenceVerifierInterface::class);
        $presence->shouldReceive('getCount')->andReturnUsing(fn ($table, $column, $value) => in_array($table, ['employees', 'salary_structures']) && (int)$value === 1 ? 1 : 0);
        $this->validator->setPresenceVerifier($presence);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
        parent::tearDown();
    }

    private function datos(): array
    {
        return ['periodo_inicio' => '2026-11-01', 'periodo_fin' => '2026-11-15', 'metodo_pago' => 'banco', 'empleado_ids' => ['1'], 'estructura_id' => '1'];
    }

    private function request(array $extra = []): Request
    {
        $request = new class extends Request {
            public Factory $factory;
            public function validate(array $rules, ...$params): array { return $this->factory->make($this->all(), $rules)->validate(); }
        };
        $request->factory = $this->validator; $request->replace(array_replace($this->datos(), $extra));
        return $request;
    }

    public function test_existing_wizard_payload_and_success_redirect_are_preserved(): void
    {
        $run = new CorridaNomina; $run->setRawAttributes(['id' => 42], true);
        $service = Mockery::mock(NominaService::class);
        $service->shouldReceive('procesar')->once()->with($this->datos())->andReturn($run);
        $response = (new NominaController($service))->procesar($this->request(['estado' => 'anulada', 'payroll_run_id' => 9, 'total_neto' => 0]));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/nomina/corrida/42', $response->getTargetUrl());
        self::assertStringContainsString('1 colillas procesadas', $this->session->get('success'));
    }

    public function test_domain_overlap_keeps_existing_error_response_and_no_success(): void
    {
        $service = Mockery::mock(NominaService::class);
        $service->shouldReceive('procesar')->once()->andThrow(new \Exception('Ya existe cobertura ordinaria.'));
        $response = (new NominaController($service))->procesar($this->request());
        self::assertSame('/nomina/wizard', $response->getTargetUrl());
        self::assertStringContainsString('cobertura ordinaria', $this->session->get('errors')->first('error'));
        self::assertFalse($this->session->has('success'));
    }

    public function test_http_rejects_unsupported_adjustment_dates_and_employee_before_service(): void
    {
        $service = Mockery::mock(NominaService::class); $service->shouldNotReceive('procesar');
        foreach ([['tipo_nomina' => 'extraordinaria'], ['periodo_fin' => '2026-10-31'], ['empleado_ids' => []], ['empleado_ids' => [999]]] as $bad) {
            try { (new NominaController($service))->procesar($this->request($bad)); self::fail('Solicitud inválida.'); }
            catch (ValidationException $e) { self::assertNotEmpty($e->errors()); }
        }
    }

    public function test_explicit_ordinary_type_is_optional_and_valid(): void
    {
        $run = new CorridaNomina; $run->setRawAttributes(['id' => 42], true);
        $service = Mockery::mock(NominaService::class);
        $service->shouldReceive('procesar')->once()->with($this->datos() + ['tipo_nomina' => 'ordinaria'])->andReturn($run);
        self::assertSame('/nomina/corrida/42', (new NominaController($service))->procesar($this->request(['tipo_nomina' => 'ordinaria']))->getTargetUrl());
    }
}
