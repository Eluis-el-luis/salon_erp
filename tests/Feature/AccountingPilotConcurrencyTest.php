<?php

namespace Tests\Feature;

use App\Services\AccountingPolicyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingPilotFixtures;
use Tests\TestCase;

class AccountingPilotConcurrencyTest extends TestCase
{
    use AccountingPilotFixtures;
    public function test_same_uuid_distinct_charges_publication_withdrawal_and_legacy_races(): void {
        $year=getenv('AC04B2A_RACE_YEAR')?:'2035';$this->assertContains($year,['2031','2035','2036','2037']);
        $this->assertFalse(DB::table('accounting_policy_versions')->whereBetween('valid_from',[$year.'-01-01',$year.'-07-01'])->exists(),'No repetir sobre evidencia anterior.');$evidence=[];
        try {
            foreach(['same_uuid','different_uuid','withdrawal','publication','legacy','preparation'] as $index=>$case) {
                $month=$year.'-'.str_pad((string)($index+1),2,'0',STR_PAD_LEFT);[$bank,$g,$p,$v]=$this->pilotCase($month);
                $a=['action'=>'charge','grant'=>$g->id,'input'=>$p];$b=$a;$code=0;$expected=950.0;$entries=1;$operations=1;
                if($case==='different_uuid'){$b['input']['uuid']=(string)\Illuminate\Support\Str::uuid();$expected=900.0;$entries=2;$operations=2;}
                if($case==='withdrawal')$b=['action'=>'revoke','grant'=>$g->id];
                if($case==='publication'){
                    $service=app(AccountingPolicyService::class);$content=$service->contentForInspection($v);
                    $draft=$this->policyDraft(['valid_from'=>$month.'-20T00:00:00Z','valid_until'=>$month.'-28T00:00:00Z','coverage'=>$content['coverage']]);
                    $bindings=array_map(fn($r)=>array_intersect_key($r,array_flip(['role','scope_type','resource_id','account_id'])),$content['bindings']);
                    $draft=$service->replaceBindings($draft->id,$draft->content_hash,$bindings,'Candidato solapado de ensayo');$b=['action'=>'publish','version'=>$draft->id,'hash'=>$draft->content_hash];$code=2;
                }
                if($case==='legacy'){$b=['action'=>'legacy','legacy_payload'=>$this->charge($bank)];$expected=900.0;$entries=2;}
                if($case==='preparation')$a['action']='prepare';
                $beforeEntries=DB::table('asientos_contables')->count();$meta=['actor'=>auth()->id(),'day'=>$month.'-15'];
                $records=$this->race($a+$meta,$b+$meta,$code);$this->assertSame($expected,(float)$bank->refresh()->saldo_actual,$case);
                $this->assertSame($entries,DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$bank->id)->count(),$case);
                $this->assertSame($beforeEntries+$entries,DB::table('asientos_contables')->count(),$case);
                $this->assertSame($operations,DB::table('accounting_event_operations')->where('bank_account_id',$bank->id)->where('status','confirmed')->count(),$case);
                $this->assertSame($operations*2,DB::table('accounting_entry_line_resolutions')->where('bank_account_id',$bank->id)->count(),$case);$this->assertBankReconciled($bank,1000);
                if($case==='same_uuid'){$this->assertSame($records[0]['result'],$records[1]['result']);}
                if($case==='preparation'){$this->assertSame($records[0]['result']['operation_id'],$records[1]['result']['operation_id']);$this->assertSame('prepared',$records[0]['result']['status']);}
                if($case==='withdrawal'){$this->assertSame($records[0]['result']['operation_id'],$this->runPilot($g,$p)->id);$q=$p;$q['uuid']=(string)\Illuminate\Support\Str::uuid();try{$this->runPilot($g,$q);$this->fail('Retirada debía bloquear');}catch(\Illuminate\Validation\ValidationException $e){$this->assertNotSame('',$e->getMessage());}$this->assertSame(950.0,(float)$bank->refresh()->saldo_actual);}
                if($case==='publication')$this->assertSame('draft',DB::table('accounting_policy_versions')->find($draft->id)->status);
                $evidence[]=['case'=>$case,'bank'=>$bank->id,'grant'=>$g->id,'records'=>$records,'balance'=>$expected];
            }
            $file=storage_path('app/private/ac04b2a-concurrency-'.bin2hex(random_bytes(8)).'.json');$h=fopen($file,'xb');fwrite($h,json_encode($evidence));fclose($h);$this->assertSame(0,DB::transactionLevel());
        }finally{Carbon::setTestNow();}
    }
    private function race(array $a,array $b,int $code): array {
        $workers=[];$markers=[];
        try {
            foreach([array_replace($a,['hold'=>true]),array_replace($b,['hold'=>false])] as $p){$f=storage_path('app/private/ac04b2a-pilot-'.bin2hex(random_bytes(8)).'.result');$markers[]=$f;$p['result']=$f;
                $proc=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/ac04b2a-pilot-worker.php'),json_encode($p)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());if(!is_resource($proc))throw new \RuntimeException('Worker no iniciado');stream_set_timeout($pipes[1],25);$workers[]=[$proc,$pipes];$this->assertSame("READY\n",str_replace("\r",'',fgets($pipes[1])));}
            fwrite($workers[0][1][0],"go\n");fflush($workers[0][1][0]);$this->assertSame("LOCKED\n",str_replace("\r",'',fgets($workers[0][1][1])));
            fwrite($workers[1][1][0],"go\n");fflush($workers[1][1][0]);$this->assertSame("ATTEMPT\n",str_replace("\r",'',fgets($workers[1][1][1])));
            usleep(200000);clearstatcache(true,$markers[1]);$this->assertFileDoesNotExist($markers[1]);fwrite($workers[0][1][0],"release\n");fflush($workers[0][1][0]);$codes=[];$out=[];
            foreach($workers as &$w){[$proc,$pipes]=$w;fclose($pipes[0]);$out[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$codes[]=proc_close($proc);$w=null;$this->assertSame('',$err);}unset($w);
            $r=array_map(fn($f)=>json_decode(file_get_contents($f),true),$markers);
            $audit=storage_path('app/private/ac04b2a-pair-'.bin2hex(random_bytes(8)).'.json');$h=fopen($audit,'xb');fwrite($h,json_encode(['actions'=>[$a['action'],$b['action']],'codes'=>$codes,'output'=>$out,'records'=>$r]));fclose($h);
            $this->assertSame([0,$code],$codes,json_encode($out));$this->assertSame(['RESULT:accepted',$code===0?'RESULT:accepted':'RESULT:rejected'],$out);
            $this->assertNotSame($r[0]['connection_id'],$r[1]['connection_id']);$this->assertNotSame($r[0]['pid'],$r[1]['pid']);return $r;
        }finally{foreach($workers as $w)if($w!==null){foreach($w[1] as $p)if(is_resource($p))fclose($p);proc_terminate($w[0]);proc_close($w[0]);}foreach($markers as $m)if(is_file($m))unlink($m);}
    }
}
