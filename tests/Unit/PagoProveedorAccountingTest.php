<?php

namespace Tests\Unit;

use App\Services\ContabilidadService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\TestCase;

final class PagoProveedorAccountingTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_real_posting_keeps_accounts_amounts_unique_payment_reference_and_throwable_rollback(): void
    {
        $app = new Container; Container::setInstance($app);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $app->instance('date', new \Illuminate\Support\DateFactory);
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Factory::class); $auth->shouldReceive('id')->andReturn(7); $app->instance('auth', $auth);
        $app->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
        $accounts = Mockery::mock('alias:App\Models\CuentaContable');
        $accounts->shouldReceive('where')->andReturnUsing(function ($field, $code) {
            $q = Mockery::mock(); $q->shouldReceive('first')->andReturn((object)['id' => ['2.1.1' => 21, '1.1.1' => 11, '1.1.2.1' => 12][$code]]); return $q;
        });
        $headers = []; $lines = []; $depth = 0; $rollbacks = 0;
        $entry = Mockery::mock('alias:App\Models\AsientoContable');
        $entry->shouldReceive('create')->andReturnUsing(function ($data) use (&$headers) { $headers[] = $data; return (object)['id' => count($headers)]; });
        $detail = Mockery::mock('alias:App\Models\DetalleAsiento');
        $detail->shouldReceive('create')->andReturnUsing(function ($data) use (&$lines) { $lines[] = $data; });
        $db = Mockery::mock();
        $currency = Mockery::mock(); $currency->shouldReceive('where')->with('es_base', true)->andReturnSelf(); $currency->shouldReceive('first')->andReturn((object)['id' => 1]);
        $db->shouldReceive('table')->with('monedas')->andReturn($currency);
        $db->shouldReceive('beginTransaction')->andReturnUsing(function () use (&$depth) { ++$depth; });
        $db->shouldReceive('commit')->andReturnUsing(function () use (&$depth) { --$depth; });
        $db->shouldReceive('rollBack')->once()->andReturnUsing(function () use (&$depth, &$rollbacks) { --$depth; ++$rollbacks; });
        $app->instance('db', $db);
        $service = new class extends ContabilidadService {
            public bool $fail = false;
            public int $checks = 0;
            protected function resolverPeriodo($fecha): object { return (object)['id' => 2]; }
            public function validarCuadre($asientoId, ?int $originalId = null) {
                ++$this->checks;
                if ($this->fail) throw new \Error('h09.posting_sentinel');
            }
        };
        \Carbon\Carbon::setTestNow('2026-11-21 10:00:00');
        $service->contabilizarPagoProveedor('20.01', 41, 'efectivo');
        $service->contabilizarPagoProveedor('30.02', 42, 'banco');
        self::assertSame(['PXP-PAGO-41', 'PXP-PAGO-42'], array_column($headers, 'numero_asiento'));
        self::assertSame([41, 42], array_column($headers, 'referencia_id'));
        self::assertSame(['pagos_proveedor', 'pagos_proveedor'], array_column($headers, 'modulo_origen'));
        self::assertSame([21, 11, 21, 12], array_column($lines, 'cuenta_id'));
        self::assertSame(['20.01', 0, '30.02', 0], array_column($lines, 'debe'));
        self::assertSame([0, '20.01', 0, '30.02'], array_column($lines, 'haber'));
        self::assertSame(0, $depth); self::assertSame(2, $service->checks);
        $service->fail = true;
        try { $service->contabilizarPagoProveedor('1.00', 43); self::fail('Falta Error centinela.'); }
        catch (\Error $e) { self::assertSame('h09.posting_sentinel', $e->getMessage()); }
        self::assertSame(0, $depth); self::assertSame(1, $rollbacks);
        \Carbon\Carbon::setTestNow();
        Mockery::close(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
    }
}
