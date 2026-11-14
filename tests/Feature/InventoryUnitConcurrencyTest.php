<?php

namespace Tests\Feature;

use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithAppointmentWorkers;
use Tests\Support\InventoryH02Fixtures;
use Tests\TestCase;

/** Fixtures confirmados exclusivamente en test; se conservan para revisar resultados. */
class InventoryUnitConcurrencyTest extends TestCase
{
    use InteractsWithAppointmentWorkers, InventoryH02Fixtures;

    public function test_sale_sale_last_unit(): void
    {
        $this->race('sale');
    }

    public function test_sale_merma_last_unit(): void
    {
        $this->race('merma');
    }

    public function test_sale_reversal_reloads_after_wait(): void
    {
        $this->race('reverse');
    }

    private function stockWorker(array $data): int
    {
        $out = tempnam(storage_path('app/private'), 'h02-out-');
        $err = tempnam(storage_path('app/private'), 'h02-err-');
        $this->files[] = $out;
        $this->files[] = $err;
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'zend.exception_ignore_args=1', base_path('tests/Support/h02-concurrency-worker.php')],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'a'], 2 => ['file', $err, 'a']], $pipes, base_path());
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($data, JSON_THROW_ON_ERROR).PHP_EOL);
        fflush($pipes[0]);
        $this->children[] = compact('process', 'pipes', 'out', 'err');

        return array_key_last($this->children);
    }

    private function race(string $operation): void
    {
        $actor = $this->actorH02();
        $item = $this->stockH02($operation === 'reverse' ? 2 : 1);
        $saleId = null;
        if ($operation === 'reverse') {
            $saleId = $this->postJson('/sales', ['cart' => [$this->cartH02($item)]])->assertCreated()->json('sale_id');
        }
        $before = [];
        foreach (['sales', 'pagos', 'asientos_contables', 'detalle_asientos', 'comisiones_generadas', 'mermas'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        try {
            $a = $this->stockWorker(['actor' => $actor->id, 'operation' => 'sale', 'hold' => true, 'payload' => ['cart' => [$this->cartH02($item)]]]);
            $ca = $this->event($a, 'ready')['connection'];
            $this->event($a, 'locked');
            $payload = match ($operation) {
                'sale' => ['cart' => [$this->cartH02($item)]],
                'merma' => ['item_id' => $item->id, 'fecha' => today()->toDateString(), 'tipo' => 'unidad', 'cantidad' => 1, 'motivo' => 'H02 race'],
                'reverse' => ['motivo' => 'H02 race', 'pin_supervisor' => '4826']
            };
            $b = $this->stockWorker(['actor' => $actor->id, 'operation' => $operation, 'hold' => false, 'payload' => $payload, 'sale_id' => $saleId]);
            $cb = $this->event($b, 'ready')['connection'];
            $this->event($b, 'attempt');
            $this->assertNotSame($ca, $cb);
            $this->waitForUserLock($cb); // Auxiliar comprueba FOR UPDATE real en PROCESSLIST, también para items.
            $this->release($a);
            $ra = $this->event($a, 'result');
            $rb = $this->event($b, 'result');
            $this->assertSame(201, $ra['status']);
            $this->assertTrue($ra['ok']);
            $this->assertSame($operation === 'sale' ? 422 : 302, $rb['status']);
            $this->assertSame($operation === 'reverse', $rb['ok']);
            $expected = $operation === 'reverse' ? 1 : 0;
            $this->assertSame($expected, (int) $item->fresh()->existencia_actual);
            $this->assertSame((float) $expected, (float) DB::table('lotes')->where('item_id', $item->id)->sum('cantidad_disponible'));
            $ledger = DB::table('stock_ledger_entries')->where('item_id', $item->id)->orderByDesc('id')->first();
            $this->assertSame((float) $expected, (float) $ledger->saldo_cantidad);
            $this->assertSame($before['sales'] + 1, DB::table('sales')->count());
            $this->assertSame($before['mermas'], DB::table('mermas')->count());
            $this->assertSame($before['pagos'] + 1, DB::table('pagos')->count());
            $this->assertSame($before['comisiones_generadas'], DB::table('comisiones_generadas')->count());
            // La salida válida crea ingreso y CMV; la anulación añade sus dos espejos.
            $this->assertSame($before['asientos_contables'] + ($operation === 'reverse' ? 4 : 2), DB::table('asientos_contables')->count());
            if ($saleId) {
                $this->assertSame('anulada', Venta::findOrFail($saleId)->status);
            }
            $this->finish();
            $this->assertWorkersDisconnected([$ca, $cb]);
            file_put_contents(storage_path('app/private/h02a-race-evidence.jsonl'), json_encode([
                'race' => 'sale-'.$operation, 'connections' => [$ca, $cb], 'statuses' => [$ra['status'], $rb['status']],
                'confirmed' => [$ra['ok'], $rb['ok']], 'stock' => $expected, 'lot_units' => $expected,
                'ledger_units' => (float) $ledger->saldo_cantidad, 'workers_disconnected' => true,
            ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } finally {
            $this->stopAppointmentWorkers();
        }
    }
}
