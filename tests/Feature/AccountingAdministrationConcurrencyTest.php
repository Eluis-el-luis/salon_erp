<?php

namespace Tests\Feature;

use App\Services\{AccountingEventAuthorizationService as Authorizations,AccountIntegrityService};
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingAdministrationFixtures;
use Tests\TestCase;

class AccountingAdministrationConcurrencyTest extends TestCase
{
    use AccountingAdministrationFixtures;
    public function test_documentary_administration_races_preserve_financial_history(): void {
        $this->policyActor();$this->assertFalse(DB::table('accounting_policy_versions')->whereBetween('valid_from',['2045-01-01','2045-08-01'])->exists(),'Ventana ya ocupada: no repetir ciegamente.');
        $financial=[];foreach(['asientos_contables','detalle_asientos','movimientos_bancarios','cuentas_bancarias','accounting_event_operations','accounting_entry_line_resolutions'] as $t)$financial[$t]=DB::table($t)->orderBy('id')->get()->toJson();
        $all=[];
        foreach(['approvals','account','enablements','withdrawals','read_withdrawal','publication','parent_withdrawal'] as $index=>$case){
            $expense=null;
            if($case==='account')$expense=app(AccountIntegrityService::class)->save(['codigo'=>'6.98'.random_int(100000,999999),'nombre'=>'AC04B2B1 SYNTHETIC ACCOUNT','tipo'=>'gasto','naturaleza'=>'deudora','cuenta_padre_id'=>DB::table('cuentas_contables')->where('codigo','6')->value('id'),'permite_movimiento'=>true,'activa'=>true]);
            [$v,$b,$f,$u]=$this->administrationFixture('2045-'.str_pad((string)($index+1),2,'0',STR_PAD_LEFT),$case!=='publication',$expense?->id);
            $meta=['actor'=>auth()->id(),'version'=>$v->id,'bank'=>$b->id,'hash'=>$v->content_hash,'from'=>$f,'until'=>$u];$a=$meta+['action'=>'approve'];$second=$a;$code=2;
            if($case==='account')$second=$meta+['action'=>'account','account'=>$expense->id,'account_data'=>array_replace($expense->only(['codigo','nombre','tipo','naturaleza','cuenta_padre_id','permite_movimiento','activa']),['activa'=>false])];
            if(in_array($case,['enablements','withdrawals','read_withdrawal','parent_withdrawal'])){
                $approval=app(Authorizations::class)->approveForAdministration($v->id,$b->id,$v->content_hash,$f,$u,'SYNTHETIC','Preparación de carrera');
                $a=$meta+['action'=>'enable','target'=>$approval->id];$second=$a;
                if(in_array($case,['withdrawals','read_withdrawal'])){
                    $g=app(Authorizations::class)->enableForAdministration($approval->id,$f,$u,'SYNTHETIC','Preparación de retirada');
                    $a=$meta+['action'=>'revoke','target'=>$g->id];$second=$a;
                    if($case==='read_withdrawal'){$a=$meta+['action'=>'read'];$code=0;}
                }
                if($case==='parent_withdrawal'){$a=$meta+['action'=>'revoke','target'=>$approval->id];$second=$meta+['action'=>'enable','target'=>$approval->id];}
            }
            if($case==='publication')$a=$meta+['action'=>'publish'];
            $records=$this->race($a,$second,$code);
            $rows=DB::table('accounting_policy_authorizations')->where('policy_version_id',$v->id)->get();
            $this->assertSame(match($case){'approvals','account'=>1,'enablements','parent_withdrawal'=>2,'withdrawals','read_withdrawal'=>3,'publication'=>0},$rows->count());
            if($expense)$this->assertTrue((bool)$expense->refresh()->activa);
            if($case==='publication')$this->assertSame('published',DB::table('accounting_policy_versions')->find($v->id)->status);
            foreach($financial as $t=>$before)$this->assertSame($before,DB::table($t)->orderBy('id')->get()->toJson(),$t);
            $all[]=['case'=>$case,'policy'=>$v->id,'records'=>$records];
        }
        $path=storage_path('app/private/ac04b2b1-concurrency-'.bin2hex(random_bytes(8)).'.json');$h=fopen($path,'xb');fwrite($h,json_encode($all));fclose($h);$this->assertSame(0,DB::transactionLevel());
    }
    private function race(array $a,array $b,int $code): array {
        $workers=[];$files=[];
        try {
            foreach([array_replace($a,['hold'=>true]),array_replace($b,['hold'=>false])] as $p){$f=storage_path('app/private/ac04b2b1-admin-'.bin2hex(random_bytes(8)).'.result');$files[]=$f;$p['result']=$f;
                $proc=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/ac04b2b1-administration-worker.php'),json_encode($p)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());if(!is_resource($proc))throw new \RuntimeException('Worker no iniciado');stream_set_timeout($pipes[1],30);$workers[]=[$proc,$pipes];$this->assertSame("READY\n",str_replace("\r",'',fgets($pipes[1])));}
            fwrite($workers[0][1][0],"go\n");fflush($workers[0][1][0]);$this->assertSame("LOCKED\n",str_replace("\r",'',fgets($workers[0][1][1])));
            fwrite($workers[1][1][0],"go\n");fflush($workers[1][1][0]);$this->assertSame("ATTEMPT\n",str_replace("\r",'',fgets($workers[1][1][1])));
            usleep(200000);clearstatcache(true,$files[1]);$this->assertFileDoesNotExist($files[1]);fwrite($workers[0][1][0],"release\n");fflush($workers[0][1][0]);$codes=[];$out=[];
            foreach($workers as &$w){[$proc,$pipes]=$w;fclose($pipes[0]);$out[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$codes[]=proc_close($proc);$w=null;$this->assertSame('',$error);}unset($w);
            $r=array_map(fn($f)=>json_decode(file_get_contents($f),true),$files);$audit=storage_path('app/private/ac04b2b1-pair-'.bin2hex(random_bytes(8)).'.json');$h=fopen($audit,'xb');fwrite($h,json_encode(['actions'=>[$a['action'],$b['action']],'output'=>$out,'records'=>$r]));fclose($h);
            $this->assertSame([0,$code],$codes,json_encode($out));$this->assertNotSame($r[0]['connection_id'],$r[1]['connection_id']);$this->assertNotSame($r[0]['pid'],$r[1]['pid']);return $r;
        }finally{foreach($workers as $w)if($w!==null){foreach($w[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($w[0]);proc_close($w[0]);}foreach($files as $f)if(is_file($f))unlink($f);}
    }
}
