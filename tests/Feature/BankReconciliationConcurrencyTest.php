<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BankReconciliationFixtures;
use Tests\TestCase;

class BankReconciliationConcurrencyTest extends TestCase
{
    use BankReconciliationFixtures;
    public function test_real_reconciliation_races_in_both_lock_orders(): void {
        $this->policyActor();$this->assertFalse(DB::table('cuentas_bancarias')->where('alias','like','AC04B2B2R%')->exists(),'No repetir evidencia anterior.');$all=[];
        try {
            foreach(['same_cut','next_cut','charge','credit','deposit','transfer','exchange','pilot'] as $case)foreach([false,true] as $writerFirst){
                $day=($case==='pilot'?($writerFirst?'2032-12':'2032-11'):'2032-08').'-20';$this->bankDate($day);$bank=$this->reconciliationBank();$seed=$this->documentedEntry($bank,1000,'abono',substr($day,0,8).'18');
                $original=DB::table('detalle_asientos')->where('asiento_id',$seed->asiento_contable_id)->get()->toJson();
                $recon=['action'=>'reconcile','bank'=>$bank->id,'payload'=>$this->reconciliationPayload($day,1000,[$seed->id])];$writer=['action'=>$case,'bank'=>$bank->id,'payload'=>[]];$net=0;$code=0;$other=null;$cash=null;
                if($case==='same_cut'){$writer=$recon;$code=2;}
                if($case==='next_cut'){$recon['payload']['fecha']=substr($day,0,8).'19';$writer=$recon;$writer['payload']['fecha']=$day;$code=2;}
                if($case==='charge'){$writer['payload']=$this->charge($bank,50);$net=-50;}
                if($case==='credit'){$writer['payload']=['cuenta_bancaria_id'=>$bank->id,'cuenta_contable_id'=>DB::table('cuentas_contables')->where('codigo','4.7')->value('id'),'monto'=>50,'fecha'=>$day,'concepto'=>'AC04B2B2R SYNTHETIC CREDIT'];$net=50;}
                if($case==='deposit'){$cash=$this->bankCash();$writer['payload']=['cuenta_destino_id'=>$bank->id,'caja_origen_id'=>$cash->id,'moneda'=>'nio','monto'=>100,'fecha'=>$day];$net=100;}
                if($case==='transfer'){$other=$this->reconciliationBank('LAFISE');$writer['payload']=$this->transfer($bank,$other,80,5);$net=-85;}
                if($case==='exchange'){$cash=$this->bankCash();$writer['payload']=['tipo'=>'compra','monto_usd'=>10,'tasa_aplicada'=>10,'tasa_oficial'=>10,'origen_tipo'=>'banco','origen_id'=>$bank->id,'destino_tipo'=>'sesion','destino_id'=>$cash->id];$net=-100;}
                if($case==='pilot'){$month=substr($day,0,7);Carbon::setTestNow();[$v]=$this->pilotPolicy($month.'-01T00:00:00Z',Carbon::parse($month.'-01')->addMonth()->format('Y-m-d').'T00:00:00Z',$bank);$g=$this->simulationGrant($v,$bank);$this->bankDate($day);$writer['grant']=$g->id;$writer['payload']=array_replace($this->pilotInput(),['recognition_date'=>$day]);$net=-50;}
                $meta=['day'=>$day,'actor'=>auth()->id()];$a=($writerFirst?$writer:$recon)+$meta;$b=($writerFirst?$recon:$writer)+$meta;
                $records=$this->race($a,$b,$code);$cuts=DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$bank->id)->get();$this->assertCount(1,$cuts);$cut=$cuts[0];
                $this->assertSame(1000.0,(float)$cut->saldo_extracto);$this->assertSame(0.0,(float)$cut->saldo_inicial_conciliado);$this->assertSame((float)(1000+($writerFirst?$net:0)),(float)$cut->saldo_libros);
                $this->assertSame($a['action']==='reconcile'?$a['payload']['fecha']:$b['payload']['fecha'],$cut->fecha);$this->assertSame((float)(1000+$net),(float)$bank->refresh()->saldo_actual);
                $movements=DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$bank->id)->orderBy('id')->get();$this->assertSame('conciliado',$movements[0]->estado_conciliacion);$this->assertSame((int)$cut->id,(int)$movements[0]->conciliacion_id);
                foreach($movements->slice(1) as $m){$this->assertSame($writerFirst?'en_transito':'pendiente',$m->estado_conciliacion);$this->assertNull($m->conciliacion_id);$this->assertSame($day,$m->fecha);}
                $this->assertSame($original,DB::table('detalle_asientos')->where('asiento_id',$seed->asiento_contable_id)->get()->toJson());$this->assertBankReconciled($bank,0);
                $ids=$movements->pluck('asiento_contable_id')->unique();$lines=DB::table('detalle_asientos')->whereIn('asiento_id',$ids)->where('cuenta_id',$bank->cuenta_contable_id)->get();$this->assertSame((int)round((1000+$net)*100),(int)round(($lines->sum('debe')-$lines->sum('haber'))*100));
                if($other){$this->assertBankReconciled($other,0);$this->assertSame(80.0,(float)$other->refresh()->saldo_actual);}
                if($case==='exchange'){$usd=DB::table('saldos_moneda')->where('ubicacion_id',$cash->id)->where('ubicacion_tipo',\App\Services\FondosService::SESION)->where('moneda_id',DB::table('monedas')->where('codigo','USD')->value('id'))->first();$this->assertSame('10.00',$usd->saldo_actual);$this->assertSame('10.000000',$usd->costo_promedio_ponderado);}
                $all[]=['case'=>$case,'writer_first'=>$writerFirst,'bank'=>$bank->id,'cut'=>$cut->id,'balance'=>(string)$bank->saldo_actual,'books'=>$cut->saldo_libros,'records'=>$records];
            }
            $path=storage_path('app/private/ac04b2b2r-concurrency-'.bin2hex(random_bytes(8)).'.json');$h=fopen($path,'xb');fwrite($h,json_encode($all));fclose($h);$this->assertSame(0,DB::transactionLevel());
        }finally{Carbon::setTestNow();}
    }
    private function race(array $a,array $b,int $code): array {
        $workers=[];$files=[];
        try{
            foreach([array_replace($a,['hold'=>true]),array_replace($b,['hold'=>false])] as $p){$f=storage_path('app/private/ac04b2b2r-recon-'.bin2hex(random_bytes(8)).'.result');$files[]=$f;$p['result']=$f;
                $proc=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/ac04b2b2r-reconciliation-worker.php'),json_encode($p)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());if(!is_resource($proc))throw new \RuntimeException('Worker no iniciado');stream_set_timeout($pipes[1],35);$workers[]=[$proc,$pipes];$this->assertSame("READY\n",str_replace("\r",'',fgets($pipes[1])));}
            fwrite($workers[0][1][0],"go\n");fflush($workers[0][1][0]);$this->assertSame("LOCKED\n",str_replace("\r",'',fgets($workers[0][1][1])));
            fwrite($workers[1][1][0],"go\n");fflush($workers[1][1][0]);$this->assertSame("ATTEMPT\n",str_replace("\r",'',fgets($workers[1][1][1])));
            usleep(200000);clearstatcache(true,$files[1]);$this->assertFileDoesNotExist($files[1]);fwrite($workers[0][1][0],"release\n");fflush($workers[0][1][0]);$codes=[];$out=[];
            foreach($workers as &$w){[$proc,$pipes]=$w;fclose($pipes[0]);$out[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$codes[]=proc_close($proc);$w=null;$this->assertSame('',$error);}unset($w);
            $r=array_map(fn($f)=>json_decode(file_get_contents($f),true),$files);$audit=storage_path('app/private/ac04b2b2r-pair-'.bin2hex(random_bytes(8)).'.json');$h=fopen($audit,'xb');fwrite($h,json_encode(['actions'=>[$a['action'],$b['action']],'bank'=>$a['bank'],'codes'=>$codes,'output'=>$out,'records'=>$r]));fclose($h);
            $this->assertSame([0,$code],$codes,json_encode($out));$this->assertNotSame($r[0]['connection_id'],$r[1]['connection_id']);$this->assertNotSame($r[0]['pid'],$r[1]['pid']);$this->assertContains($a['bank'],$r[0]['bank_lock_order']);$this->assertContains($a['bank'],$r[1]['bank_lock_order']);return $r;
        }finally{foreach($workers as $w)if($w!==null){foreach($w[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($w[0]);proc_close($w[0]);}foreach($files as $f)if(is_file($f))unlink($f);}
    }
}
