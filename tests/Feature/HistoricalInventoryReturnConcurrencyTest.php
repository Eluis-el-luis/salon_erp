<?php

namespace Tests\Feature;

use App\Models\{Cita, Cliente, Venta};
use App\Services\{AgendaService, InventarioFraccionableService};
use Illuminate\Support\Facades\DB;
use Tests\Support\{FractionalV2Fixtures, InteractsWithAppointmentWorkers, InventoryH02Fixtures};
use Tests\TestCase;

class HistoricalInventoryReturnConcurrencyTest extends TestCase
{
    use FractionalV2Fixtures, InteractsWithAppointmentWorkers, InventoryH02Fixtures;

    private function worker(array $data): int
    {
        $out=tempnam(storage_path('app/private'),'h04b-out-'); $err=tempnam(storage_path('app/private'),'h04b-err-');
        $this->files[]=$out; $this->files[]=$err;
        $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1',base_path('tests/Support/h03b2-concurrency-worker.php')],
            [0=>['pipe','r'],1=>['file',$out,'a'],2=>['file',$err,'a']],$pipes,base_path());
        $this->assertIsResource($process); fwrite($pipes[0],json_encode($data,JSON_THROW_ON_ERROR).PHP_EOL); fflush($pipes[0]);
        $this->children[]=compact('process','pipes','out','err'); return array_key_last($this->children);
    }

    public function test_annulment_serializes_with_stale_appointment_cart(): void { $this->race('appointment'); }
    public function test_stale_cart_entering_first_cannot_duplicate_an_applied_execution(): void { $this->race('billing_first'); }
    public function test_closed_return_serializes_with_new_output(): void { $this->race('output'); }
    public function test_two_annulments_restore_only_once(): void { $this->race('annulment'); }
    public function test_distinct_documents_restore_same_continuing_open_without_lock_inversion(): void { $this->race('distinct_annulments'); }

    private function race(string $kind): void
    {
        $actor=$this->actorH02(); config(['salon.inventory_fractional_v2'=>true]);
        $item=$this->itemV2(100); $this->buyV2($item,1,'10');
        $cita=null;
        if (in_array($kind,['appointment','billing_first','distinct_annulments'],true)) {
            $service=$this->serviceV2([$item->id=>20]);
            $line=$this->lineV2($service);
            if ($kind!=='distinct_annulments') {
                $cita=app(AgendaService::class)->reservar(['client_id'=>Cliente::create(['name'=>'H04 race'])->id,
                    'service_id'=>$service->id,'stylists'=>[$actor->id],'appointment_date'=>'2026-12-01 10:00:00']);
                $line['appointment_id']=$cita->id;
            }
        } else $line=$this->cartH02($item);
        $id=$this->postJson('/sales',['cart'=>[$line]])->assertCreated()->json('sale_id');
        $distinct=$kind==='distinct_annulments';
        $secondId=$distinct?$this->postJson('/sales',['cart'=>[$line]])->assertCreated()->json('sale_id'):$id;
        $data=['motivo'=>'Anulación completa H04 race','pin_supervisor'=>'4826',
            'inventory_intent'=>$cita?'sin_restitucion':'devolucion_completa',
            'verification_reference'=>'ACTA-RACE','verification_description'=>'Recepción física verificada de ensayo',
            'verified_on'=>today()->toDateString(),'verification_confirmed'=>'1',
            'execution_declaration'=>'prestada','execution_reference'=>'EJEC-RACE','execution_description'=>'Ejecución constatada retrospectivamente',
            'execution_verified_on'=>today()->toDateString(),'execution_confirmed'=>'1'];
        $before=[]; foreach (['sales','pagos','stock_ledger_entries','stock_ledger_lot_allocations','asientos_contables'] as $t) $before[$t]=DB::table($t)->count();
        try {
            $billingFirst=$kind==='billing_first';
            $a=$this->worker(['actor'=>$actor->id,'operation'=>$billingFirst?'sale':'annul','sale_id'=>$id,'payload'=>$billingFirst?['cart'=>[$line]]:$data,'hold'=>true,'lock'=>$cita?'appointments':'items']);
            $ca=$this->event($a,'ready')['connection']; $this->event($a,'locked');
            $b=$this->worker(['actor'=>$actor->id,'operation'=>$kind==='annulment'||$billingFirst||$distinct?'annul':'sale','sale_id'=>$secondId,
                'payload'=>$kind==='annulment'||$billingFirst||$distinct?$data:['cart'=>[$line]],'hold'=>false]);
            $cb=$this->event($b,'ready')['connection']; $this->event($b,'attempt'); $this->assertNotSame($ca,$cb);
            $this->waitForUserLock($cb);
            if ($distinct) {
                $until=microtime(true)+5; $waiting=false;
                do {
                    $p=DB::selectOne('SELECT INFO AS sql_text FROM information_schema.PROCESSLIST WHERE ID=?',[$cb]);
                    $waiting=$p && str_contains($p->sql_text??'','from `items`') && str_contains($p->sql_text??'','for update');
                    if (!$waiting) usleep(20000);
                } while (!$waiting && microtime(true)<$until);
                $this->assertTrue($waiting,'B holds its own sale and waits for A on the same article.');
            }
            $this->release($a);
            $ra=$this->event($a,'result'); $rb=$this->event($b,'result');
            $this->assertSame(!$billingFirst,$ra['ok']); $this->assertSame($kind==='output'||$billingFirst||$distinct,$rb['ok']);
            $this->assertSame('anulada',Venta::findOrFail($id)->status);
            if ($distinct) $this->assertSame('anulada',Venta::findOrFail($secondId)->status);
            $this->assertNotNull(Venta::findOrFail($id)->annulment_evidence);
            $this->assertSame($before['sales']+($kind==='output'?1:0),DB::table('sales')->count());
            $this->assertSame($before['pagos']+($kind==='output'?1:0),DB::table('pagos')->count());
            $added=$cita?0:($kind==='output'||$distinct?2:1);
            $this->assertSame($before['stock_ledger_entries']+$added,DB::table('stock_ledger_entries')->count());
            $this->assertSame($before['stock_ledger_lot_allocations']+$added,DB::table('stock_ledger_lot_allocations')->count());
            DB::transaction(function () use ($item,$kind) {
                $s=app(InventarioFraccionableService::class)->estado($item->refresh());
                $this->assertSame(match($kind){'appointment','billing_first'=>8000,'output'=>0,default=>10000},$s['q']);
                $this->assertSame(match($kind){'appointment','billing_first'=>800,'output'=>0,default=>1000},$s['v']);
            });
            if ($cita) $this->assertSame('completada',$cita->fresh()->status);
            $this->finish(); $this->assertWorkersDisconnected([$ca,$cb]);
            file_put_contents(storage_path('app/private/h04b-races.jsonl'),json_encode(['race'=>'annul-'.$kind,'connections'=>[$ca,$cb],
                'committed'=>[$ra['ok'],$rb['ok']],'persistent_checks'=>true,'closed'=>true],JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);
        } finally {$this->stopAppointmentWorkers();}
    }
}
