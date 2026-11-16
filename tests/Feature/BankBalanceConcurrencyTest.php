<?php

namespace Tests\Feature;

use App\Models\{CuentaBancaria,SaldoMoneda};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BankBalanceFixtures;
use Tests\TestCase;

/** Documentos sintéticos confirmados conservados para revisión, sin recreación ni limpieza financiera. */
class BankBalanceConcurrencyTest extends TestCase
{
    use BankBalanceFixtures;
    public function test_real_bank_races_preserve_balances_documents_and_exact_entries(): void
    {
        $actor=$this->bankActor();$this->bankDate('2031-05-15');$allIds=[];
        try {
            foreach(['charge_charge','charge_deposit','charge_transfer','inverse_transfer','charge_exchange','insufficient','charge_insufficient'] as $case) {
                $a=$this->bankFixture(in_array($case,['insufficient','charge_insufficient'])?100:1000);
                $b=$this->bankFixture(1000,'LAFISE');$cash=$this->bankCash();$allIds[]=$a->id;$allIds[]=$b->id;
                $first=['action'=>'charge','payload'=>$this->charge($a),'bank_ids'=>[$a->id]];
                $second=['action'=>'charge','payload'=>$this->charge($a,70),'bank_ids'=>[$a->id]];
                $accepted=2;$expectedA=880.0;$expectedB=1000.0;$expectedMovements=2;$expectedTransfers=0;
                if($case==='charge_deposit') {$second=['action'=>'deposit','payload'=>['cuenta_destino_id'=>$a->id,'caja_origen_id'=>$cash->id,'moneda'=>'nio','monto'=>100,'fecha'=>now()->toDateString()],'bank_ids'=>[$a->id]];$expectedA=1050.0;$expectedTransfers=1;}
                if($case==='charge_transfer') {$second=['action'=>'transfer','payload'=>$this->transfer($a,$b,80,5),'bank_ids'=>[$a->id,$b->id]];$expectedA=865.0;$expectedB=1080.0;$expectedMovements=4;$expectedTransfers=1;}
                if($case==='inverse_transfer') {$first=['action'=>'transfer','payload'=>$this->transfer($a,$b,80),'bank_ids'=>[$a->id,$b->id]];$second=['action'=>'transfer','payload'=>$this->transfer($b,$a,70),'bank_ids'=>[$b->id,$a->id]];$expectedA=990.0;$expectedB=1010.0;$expectedMovements=4;$expectedTransfers=2;}
                if($case==='charge_exchange') {$second=['action'=>'exchange','payload'=>['tipo'=>'compra','monto_usd'=>10,'tasa_aplicada'=>10,'tasa_oficial'=>10,'origen_tipo'=>'banco','origen_id'=>$a->id,'destino_tipo'=>'sesion','destino_id'=>$cash->id],'bank_ids'=>[$a->id]];$expectedA=850.0;}
                if($case==='insufficient') {$first=['action'=>'transfer','payload'=>$this->transfer($a,$b,80),'bank_ids'=>[$a->id,$b->id]];$second=$first;$accepted=1;$expectedA=20.0;$expectedB=1080.0;$expectedMovements=2;$expectedTransfers=1;}
                if($case==='charge_insufficient') {$second=['action'=>'transfer','payload'=>$this->transfer($a,$b,80),'bank_ids'=>[$a->id,$b->id]];$accepted=1;$expectedA=50.0;$expectedMovements=1;}
                $beforeEntries=DB::table('asientos_contables')->count();$beforeTransfers=DB::table('transferencias')->count();
                $this->race($actor->id,$first,$second,$accepted,$case);
                $this->assertSame($expectedA,(float)$a->refresh()->saldo_actual,$case);$this->assertSame($expectedB,(float)$b->refresh()->saldo_actual,$case);
                $this->assertSame($expectedMovements,DB::table('movimientos_bancarios')->whereIn('cuenta_bancaria_id',[$a->id,$b->id])->count(),$case);
                $this->assertSame($accepted,DB::table('asientos_contables')->count()-$beforeEntries,$case);
                $this->assertSame($expectedTransfers,DB::table('transferencias')->count()-$beforeTransfers,$case);
                $opening=in_array($case,['insufficient','charge_insufficient'])?100:1000;
                $this->assertBankReconciled($a,$opening);$this->assertBankReconciled($b,1000);
                $entries=DB::table('movimientos_bancarios')->whereIn('cuenta_bancaria_id',[$a->id,$b->id])->pluck('asiento_contable_id')->unique();
                foreach([[$a,$opening],[$b,1000]] as [$bank,$initial]) {
                    $lines=DB::table('detalle_asientos')->whereIn('asiento_id',$entries)->where('cuenta_id',$bank->cuenta_contable_id)->get();
                    $this->assertSame((int)round(((float)$bank->saldo_actual-$initial)*100),(int)round(($lines->sum('debe')-$lines->sum('haber'))*100),$case);
                }
                if($case==='charge_exchange') {$s=SaldoMoneda::where('ubicacion_id',$cash->id)->where('ubicacion_tipo',\App\Services\FondosService::SESION)->firstOrFail();$this->assertSame(10.0,(float)$s->saldo_actual);$this->assertSame(10.0,(float)$s->costo_promedio_ponderado);}
                echo 'RACE '.$case.': persisted accepted='.$accepted.' rejected='.(2-$accepted).'; balances='.$expectedA.'/'.$expectedB.PHP_EOL;
            }
            $this->assertSame(0,DB::transactionLevel());
            $file=storage_path('app/private/ac04b0-fixtures-'.bin2hex(random_bytes(8)).'.json');$h=fopen($file,'xb');fwrite($h,json_encode(['bank_ids'=>$allIds,'retained'=>true]));fclose($h);
        } finally {Carbon::setTestNow();}
    }
    private function race(int $actor,array $first,array $second,int $accepted,string $case): void
    {
        $workers=[];$markers=[];
        try {
            foreach([array_replace($first,['hold'=>true]),array_replace($second,['hold'=>false])] as $job) {
                $marker=storage_path('app/private/ac04b0-bank-'.bin2hex(random_bytes(8)).'.result');$markers[]=$marker;$job+=['actor'=>$actor,'result'=>$marker];
                $p=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/ac04b0-bank-worker.php'),json_encode($job)],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());
                if(!is_resource($p))throw new \RuntimeException('Worker no iniciado');stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];
                $this->assertSame("READY\n",str_replace("\r",'',fgets($pipes[1])),$case);
            }
            fwrite($workers[0][1][0],"go\n");fflush($workers[0][1][0]);$this->assertSame("LOCKED\n",str_replace("\r",'',fgets($workers[0][1][1])),$case);
            fwrite($workers[1][1][0],"go\n");fflush($workers[1][1][0]);$this->assertSame("ATTEMPT\n",str_replace("\r",'',fgets($workers[1][1][1])),$case);
            usleep(200000);clearstatcache(true,$markers[1]);$this->assertFileDoesNotExist($markers[1],$case);
            fwrite($workers[0][1][0],"release\n");fflush($workers[0][1][0]);$results=[];$codes=[];
            foreach($workers as &$worker){[$p,$pipes]=$worker;fclose($pipes[0]);$results[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$codes[]=proc_close($p);$worker=null;$this->assertSame('',$error,$case);}unset($worker);
            $this->assertSame(['RESULT:accepted',$accepted===2?'RESULT:accepted':'RESULT:rejected'],$results,$case);$this->assertSame([0,$accepted===2?0:2],$codes,$case);
            $records=array_map(fn($f)=>json_decode(file_get_contents($f),true),$markers);
            $this->assertNotSame($records[0]['connection_id'],$records[1]['connection_id'],$case);$this->assertNotSame($records[0]['pid'],$records[1]['pid'],$case);
            foreach([$first,$second] as $index=>$job){$ids=array_unique($job['bank_ids']);sort($ids,SORT_NUMERIC);$this->assertSame(array_values($ids),$records[$index]['bank_lock_order'],$case);}
        } finally {
            foreach($workers as $w)if($w!==null){foreach($w[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($w[0]);proc_close($w[0]);}
            foreach($markers as $marker)if(is_file($marker))unlink($marker);
        }
    }
}
