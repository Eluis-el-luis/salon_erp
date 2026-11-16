<?php

namespace Tests\Feature;

use App\Services\AccountingEventAuthorizationService as Auth;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccountingEventFixtures;
use Tests\TestCase;

class AccountingAuthorizationConcurrencyTest extends TestCase
{
    use AccountingEventFixtures;
    public function test_competing_approvals_and_concurrent_withdrawal_keep_documentary_evidence(): void {
        $actor=$this->policyActor();$this->assertFalse(DB::table('accounting_policy_versions')->where('valid_from','2033-01-01 00:00:00')->exists(),'No repetir sobre evidencia previa.');
        [$v,$bank]=$this->pilotPolicy('2033-01-01T00:00:00Z','2033-02-01T00:00:00Z');$plan=['actor'=>$actor->id,'version'=>$v->id,'bank'=>$bank->id];
        $r1=$this->race($plan+['action'=>'approve'],$plan+['action'=>'approve'],2);
        $approvals=DB::table('accounting_policy_authorizations')->where('policy_version_id',$v->id)->where('action','approval')->get();$this->assertCount(1,$approvals);
        $g=app(Auth::class)->enableForSimulation($approvals[0]->id,'SYNTHETIC-ENABLE','No habilitación financiera');
        $r2=$this->race($plan+['action'=>'read','target'=>$g->id],$plan+['action'=>'revoke','target'=>$g->id],0);
        $this->assertSame(3,DB::table('accounting_policy_authorizations')->where('policy_version_id',$v->id)->count());
        try{DB::transaction(fn()=>app(Auth::class)->inspectSimulation($g->id,new DateTimeImmutable('2033-01-15T00:00:00Z')));$this->fail('Retirada debía impedir lectura elegible');}catch(ValidationException $e){$this->assertNotSame('',$e->getMessage());}
        $this->assertSame(0,DB::table('accounting_event_operations')->count());$this->assertSame(0,DB::table('accounting_entry_line_resolutions')->count());
        $this->assertSame(0,DB::transactionLevel());$file=storage_path('app/private/ac04b1-concurrency-'.bin2hex(random_bytes(8)).'.json');$h=fopen($file,'xb');fwrite($h,json_encode(['synthetic_only'=>true,'policy'=>$v->id,'grant'=>$g->id,'approval_race'=>$r1,'withdrawal_race'=>$r2]));fclose($h);
    }
    private function race(array $first,array $second,int $secondCode): array {
        $workers=[];$markers=[];
        try {
            foreach([array_replace($first,['hold'=>true]),array_replace($second,['hold'=>false])] as $p){$marker=storage_path('app/private/ac04b1-auth-'.bin2hex(random_bytes(8)).'.result');$markers[]=$marker;$p['result']=$marker;
                $proc=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/ac04b1-auth-worker.php'),json_encode($p)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());
                if(!is_resource($proc))throw new \RuntimeException('Worker no iniciado');stream_set_timeout($pipes[1],20);$workers[]=[$proc,$pipes];$this->assertSame("READY\n",str_replace("\r",'',fgets($pipes[1])));}
            fwrite($workers[0][1][0],"go\n");fflush($workers[0][1][0]);$this->assertSame("LOCKED\n",str_replace("\r",'',fgets($workers[0][1][1])));
            fwrite($workers[1][1][0],"go\n");fflush($workers[1][1][0]);$this->assertSame("ATTEMPT\n",str_replace("\r",'',fgets($workers[1][1][1])));
            usleep(200000);clearstatcache(true,$markers[1]);$this->assertFileDoesNotExist($markers[1]);
            fwrite($workers[0][1][0],"release\n");fflush($workers[0][1][0]);$codes=[];$out=[];
            foreach($workers as &$w){[$proc,$pipes]=$w;fclose($pipes[0]);$out[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$codes[]=proc_close($proc);$w=null;$this->assertSame('',$error);}unset($w);
            $this->assertSame([0,$secondCode],$codes);$this->assertSame(['RESULT:accepted',$secondCode===0?'RESULT:accepted':'RESULT:rejected'],$out);
            $r=array_map(fn($m)=>json_decode(file_get_contents($m),true),$markers);$this->assertNotSame($r[0]['connection_id'],$r[1]['connection_id']);$this->assertNotSame($r[0]['pid'],$r[1]['pid']);return $r;
        }finally{foreach($workers as $w)if($w!==null){foreach($w[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($w[0]);proc_close($w[0]);}foreach($markers as $m)if(is_file($m))unlink($m);}
    }
}
