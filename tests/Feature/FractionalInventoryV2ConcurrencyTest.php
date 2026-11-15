<?php

namespace Tests\Feature;

use App\Services\InventarioFraccionableService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FractionalV2Fixtures;
use Tests\Support\InteractsWithAppointmentWorkers;
use Tests\Support\InventoryH02Fixtures;
use Tests\TestCase;

/** Dos procesos MySQL controlados; fixtures confirmados solo en la base de pruebas. */
class FractionalInventoryV2ConcurrencyTest extends TestCase
{
    use FractionalV2Fixtures,InteractsWithAppointmentWorkers,InventoryH02Fixtures;

    public function test_purchase_consumption_reads_current_locked_stock(): void
    {
        $this->race('purchase', 'sale');
    }

    public function test_consumption_consumption_last_remainder(): void
    {
        $this->race('sale', 'sale');
    }

    public function test_consumption_merma_last_remainder(): void
    {
        $this->race('sale', 'merma');
    }

    private function worker(array $data): int
    {
        $out = tempnam(storage_path('app/private'), 'h03b2-out-');
        $err = tempnam(storage_path('app/private'), 'h03b2-err-');
        $this->files[] = $out;
        $this->files[] = $err;
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'zend.exception_ignore_args=1', base_path('tests/Support/h03b2-concurrency-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'a'], 2 => ['file', $err, 'a']], $pipes, base_path());
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($data, JSON_THROW_ON_ERROR).PHP_EOL);
        fflush($pipes[0]);
        $this->children[] = compact('process', 'pipes', 'out', 'err');

        return array_key_last($this->children);
    }

    private function race(string $first, string $second): void
    {
        $actor = $this->actorH02();
        config(['salon.inventory_fractional_v2' => true]);
        $item = $this->itemV2(100);
        $invoice = $this->buyV2($item, 1, '10');
        $service = $this->serviceV2([$item->id => 100]);
        $sale = ['cart' => [$this->lineV2($service)]];
        $purchase = ['provider_id' => $invoice->provider_id, 'condicion_pago' => 'credito', 'articulos' => [['id' => $item->id, 'cantidad' => 1, 'costo' => '20']]];
        $before = [];
        foreach (['sales', 'pagos', 'asientos_contables', 'mermas', 'comisiones_generadas'] as $t) {
            $before[$t] = DB::table($t)->count();
        }
        try {
            $a = $this->worker(['actor' => $actor->id, 'hold' => true, 'operation' => $first, 'payload' => $first === 'purchase' ? $purchase : $sale]);
            $ca = $this->event($a, 'ready')['connection'];
            $this->event($a, 'locked');
            $b = $this->worker(['actor' => $actor->id, 'hold' => false, 'operation' => $second, 'payload' => $second === 'sale' ? $sale :
                ['item_id' => $item->id, 'tipo' => 'volumen', 'cantidad' => 100, 'fecha' => today()->toDateString(), 'motivo' => 'H03 race']]);
            $cb = $this->event($b, 'ready')['connection'];
            $this->event($b, 'attempt');
            $this->assertNotSame($ca, $cb);
            $this->waitForUserLock($cb);
            $this->release($a);
            $ra = $this->event($a, 'result');
            $rb = $this->event($b, 'result');
            $this->assertTrue($ra['ok']);
            $this->assertSame($first === 'purchase', $rb['ok']);
            $this->assertSame($before['sales'] + 1, DB::table('sales')->count());
            $this->assertSame($before['pagos'] + 1, DB::table('pagos')->count());
            $this->assertSame($before['mermas'], DB::table('mermas')->count());
            $this->assertSame($before['comisiones_generadas'], DB::table('comisiones_generadas')->count());
            $this->assertSame($before['asientos_contables'] + ($first === 'purchase' ? 3 : 2), DB::table('asientos_contables')->count());
            DB::transaction(function () use ($item, $first) {
                $s = app(InventarioFraccionableService::class)->estado($item->refresh());
                $this->assertSame($first === 'purchase' ? 10000 : 0, $s['q']);
                $this->assertSame($first === 'purchase' ? 1500 : 0, $s['v']);
            });
            $this->finish();
            $this->assertWorkersDisconnected([$ca, $cb]);
            file_put_contents(storage_path('app/private/h03b2-race-evidence.jsonl'), json_encode(['race' => $first.'-'.$second, 'connections' => [$ca, $cb],
                'confirmed' => [$ra['ok'], $rb['ok']], 'statuses' => [$ra['status'], $rb['status']], 'persisted_stock' => $first === 'purchase' ? 100 : 0, 'allocation_reconciliation' => true, 'disconnected' => true], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } finally {
            $this->stopAppointmentWorkers();
        }
    }
}
