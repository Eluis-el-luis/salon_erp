<?php
namespace Tests\Feature;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BankChargeRefundFixtures;
use Tests\TestCase;
class BankChargeRefundConcurrencyTest extends TestCase
{
    use BankChargeRefundFixtures;
    public function test_refunds_and_clearing_serialize_without_duplicate_funds(): void {
        $this->policyActor();$this->assertFalse(DB::table('cuentas_bancarias')->where('alias','like','AC04B2B3A%')->exists(),'No repetir evidencia persistente.');$all=[];
        try{
            foreach(['same_uuid','different_uuid','refund_first','clearing_first','posterior_credit'] as $index=>$case){
                $month='2034-'.str_pad((string)($index+5),2,'0',STR_PAD_LEFT);[$bank,$o,$p,$g,$seed]=$this->refundCase($month);$firstCut=$this->clearOriginal($bank,$o,$seed,$month);
                $original=json_encode(DB::table('movimientos_bancarios')->find($o->result_movement_id));$entry=json_encode(DB::table('asientos_contables')->find($o->result_asiento_id));$oldLines=DB::table('detalle_asientos')->where('asiento_id',$o->result_asiento_id)->get()->toJson();
                $meta=['actor'=>auth()->id(),'day'=>$month.'-20','bank'=>$bank->id];$refund=$meta+['action'=>'refund','original'=>$o->id,'payload'=>$p];$a=$refund;$b=$refund;$code=0;$expected=1000.0;$cuts=1;
                if($case==='different_uuid'){$b['payload']['uuid']=(string)\Illuminate\Support\Str::uuid();$code=2;}
                if(in_array($case,['refund_first','clearing_first','posterior_credit'])){
                    $clearing=$meta+['action'=>'reconcile','payload'=>$this->reconciliationPayload($month.'-15',950,[],$firstCut->id)];$cuts=2;
                    if($case==='refund_first')$b=$clearing;
                    else {$a=$clearing;$b=$refund;if($case==='clearing_first'){$code=2;$expected=950.0;}else{$b['payload']['effective_date']=$b['payload']['recognition_date']=$month.'-16';}}
                }
                $records=$this->race($a,$b,$code);$this->assertSame($expected,(float)$bank->refresh()->saldo_actual);$this->assertEquals($firstCut,DB::table('conciliaciones_bancarias')->find($firstCut->id));
                $this->assertSame($original,json_encode(DB::table('movimientos_bancarios')->find($o->result_movement_id)));$this->assertSame($entry,json_encode(DB::table('asientos_contables')->find($o->result_asiento_id)));$this->assertSame($oldLines,DB::table('detalle_asientos')->where('asiento_id',$o->result_asiento_id)->get()->toJson());
                $this->assertSame($cuts,DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$bank->id)->count());$docs=DB::table('bank_charge_reversals')->where('original_operation_id',$o->id)->get();$this->assertCount($case==='clearing_first'?0:1,$docs);
                if($docs->count()){$this->assertSame('confirmed',$docs[0]->status);$this->assertSame(2,DB::table('bank_charge_reversal_lines')->where('reversal_id',$docs[0]->id)->count());$inverse=DB::table('movimientos_bancarios')->find($docs[0]->result_movement_id);$this->assertSame($case==='refund_first'?'en_transito':'pendiente',$inverse->estado_conciliacion);$this->assertNull($inverse->conciliacion_id);}
                if($case==='same_uuid')$this->assertSame($records[0]['result'],$records[1]['result']);
                if($cuts===2){$last=DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$bank->id)->orderByDesc('id')->first();$this->assertSame($case==='refund_first'?'1000.00':'950.00',$last->saldo_libros);}
                $this->assertBankReconciled($bank,0);$ids=DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$bank->id)->pluck('asiento_contable_id');$lines=DB::table('detalle_asientos')->whereIn('asiento_id',$ids)->where('cuenta_id',$bank->cuenta_contable_id)->get();$this->assertSame((int)round($expected*100),(int)round(($lines->sum('debe')-$lines->sum('haber'))*100));
                $all[]=['case'=>$case,'bank'=>$bank->id,'original'=>$o->id,'balance'=>$expected,'records'=>$records];
            }
            $file=storage_path('app/private/ac04b2b3a-concurrency-'.bin2hex(random_bytes(8)).'.json');$h=fopen($file,'xb');fwrite($h,json_encode($all));fclose($h);$this->assertSame(0,DB::transactionLevel());
        }finally{Carbon::setTestNow();}
    }
    private function race(array $a,array $b,int $code): array {
        $workers=[];$files=[];
        try{
            foreach([array_replace($a,['hold'=>true]),array_replace($b,['hold'=>false])] as $p){$f=storage_path('app/private/ac04b2b3a-refund-'.bin2hex(random_bytes(8)).'.result');$files[]=$f;$p['result']=$f;$proc=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/ac04b2b3a-refund-worker.php'),json_encode($p)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());if(!is_resource($proc))throw new \RuntimeException('No se inició worker');stream_set_timeout($pipes[1],35);$workers[]=[$proc,$pipes];$this->assertSame("READY\n",str_replace("\r",'',fgets($pipes[1])));}
            fwrite($workers[0][1][0],"go\n");fflush($workers[0][1][0]);$this->assertSame("LOCKED\n",str_replace("\r",'',fgets($workers[0][1][1])));fwrite($workers[1][1][0],"go\n");fflush($workers[1][1][0]);$this->assertSame("ATTEMPT\n",str_replace("\r",'',fgets($workers[1][1][1])));
            usleep(200000);clearstatcache(true,$files[1]);$this->assertFileDoesNotExist($files[1]);fwrite($workers[0][1][0],"release\n");fflush($workers[0][1][0]);$codes=[];$out=[];
            foreach($workers as &$w){[$proc,$pipes]=$w;fclose($pipes[0]);$out[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$codes[]=proc_close($proc);$w=null;$this->assertSame('',$error);}unset($w);
            $r=array_map(fn($f)=>json_decode(file_get_contents($f),true),$files);$file=storage_path('app/private/ac04b2b3a-pair-'.bin2hex(random_bytes(8)).'.json');$h=fopen($file,'xb');fwrite($h,json_encode(['bank'=>$a['bank'],'actions'=>[$a['action'],$b['action']],'codes'=>$codes,'output'=>$out,'records'=>$r]));fclose($h);
            $this->assertSame([0,$code],$codes,json_encode($out));$this->assertNotSame($r[0]['connection_id'],$r[1]['connection_id']);$this->assertNotSame($r[0]['pid'],$r[1]['pid']);foreach($r as $record)$this->assertContains($a['bank'],$record['locks']);return $r;
        }finally{foreach($workers as $w)if($w!==null){foreach($w[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($w[0]);proc_close($w[0]);}foreach($files as $f)if(is_file($f))unlink($f);}
    }
}
